use std::borrow::Cow;
use std::collections::HashMap;
use std::fs;
use std::path::{Path, PathBuf};

use anyhow::{Context, Result};
use bumpalo::Bump;
use foldhash::HashSet as FoldHashSet;
use mago_analyzer::Analyzer;
use mago_analyzer::analysis_result::AnalysisResult;
use mago_analyzer::plugin::PluginRegistry;
use mago_analyzer::settings::Settings;
use mago_atom::AtomSet;
use mago_codex::metadata::CodebaseMetadata;
use mago_codex::populator::populate_codebase;
use mago_codex::reference::SymbolReferences;
use mago_codex::scanner::scan_program;
use mago_codex::symbol::SymbolKind as MagoSymbolKind;
use mago_database::file::{File, FileId};
use mago_names::ResolvedNames;
use mago_names::resolver::NameResolver;
use mago_span::Span;
use mago_syntax::ast::Program;
use mago_syntax::parser::parse_file;

use crate::engine::{CallEdge, Confidence, EntryPoint, ProjectIndex, Symbol, SymbolKind};

/// Multi-file PHP analysis pipeline modelled on mago's
/// `crates/orchestrator/src/service/pipeline.rs`.
///
/// Strategy: a single `Bump` arena spans the whole call. We parse + scan in the
/// first pass (collecting entry points along the way while the AST is alive),
/// merge `CodebaseMetadata`, populate cross-references, then run
/// `mago_analyzer::Analyzer` over every program a second time so that
/// `SymbolReferences` is fully populated. The reverse-call-graph data lives in
/// `SymbolReferences::get_back_references()` after the analyzer pass; we convert
/// it into our neutral `CallEdge` list before handing the `ProjectIndex` back.
pub fn analyze_project(root: &Path) -> Result<ProjectIndex> {
    let php_files = discover_php(root).with_context(|| format!("scan PHP files in {}", root.display()))?;

    let arena = Bump::new();

    // Owned per-file context held for the analyzer pass.
    let mut files: Vec<File> = Vec::with_capacity(php_files.len());
    let mut paths: Vec<PathBuf> = Vec::with_capacity(php_files.len());
    let mut path_by_id: HashMap<FileId, PathBuf> = HashMap::new();
    let mut programs: Vec<&Program<'_>> = Vec::with_capacity(php_files.len());
    let mut resolved_names_list: Vec<ResolvedNames<'_>> = Vec::with_capacity(php_files.len());
    let mut partial_metadatas: Vec<CodebaseMetadata> = Vec::with_capacity(php_files.len());
    let mut entry_points: Vec<EntryPoint> = Vec::new();

    for path in &php_files {
        let src = fs::read_to_string(path).with_context(|| format!("read {}", path.display()))?;
        let rel = path.strip_prefix(root).unwrap_or(path).display().to_string();
        let file = File::ephemeral(Cow::Owned(rel), Cow::Owned(src));

        path_by_id.insert(file.id, path.clone());
        files.push(file);
        paths.push(path.clone());
    }

    // Pass 1: parse + resolve + entry-point extract + scan_program.
    for idx in 0..files.len() {
        let file = &files[idx];
        let path = &paths[idx];

        let program = parse_file(&arena, file);
        if program.has_errors() {
            tracing_warn(format!(
                "parse errors in {} (continuing): {} error(s)",
                path.display(),
                program.errors.len()
            ));
        }

        let resolver = NameResolver::new(&arena);
        let resolved_names = resolver.resolve(program);

        // Walk the AST while it is alive to extract entry points.
        let file_eps = super::entrypoints::extract(program, &resolved_names, file, path);
        entry_points.extend(file_eps);

        let metadata = scan_program(&arena, file, program, &resolved_names);
        partial_metadatas.push(metadata);

        programs.push(program);
        resolved_names_list.push(resolved_names);
    }

    // Merge per-file metadata into the global codebase view.
    let mut merged = CodebaseMetadata::new();
    for partial in partial_metadatas {
        merged.extend(partial);
    }

    // Populate hierarchies + initial symbol references (signature-level edges).
    let mut symbol_references = SymbolReferences::new();
    populate_codebase(&mut merged, &mut symbol_references, AtomSet::default(), FoldHashSet::default());

    // Pass 2: run the analyzer on each program so body-level call edges flow
    // into `analysis_result.symbol_references`.
    let plugin_registry = PluginRegistry::with_library_providers();
    let settings = Settings::default();
    let mut analysis_result = AnalysisResult::new(symbol_references);

    for idx in 0..files.len() {
        let file = &files[idx];
        let program = programs[idx];
        let names = &resolved_names_list[idx];

        let analyzer = Analyzer::new(&arena, file, names, &merged, &plugin_registry, settings.clone());
        if let Err(err) = analyzer.analyze(program, &mut analysis_result) {
            tracing_warn(format!("analyzer error in {} (continuing): {err}", paths[idx].display()));
        }
    }

    let edges = symbol_references_to_edges(&analysis_result.symbol_references);

    // Sort entry points for deterministic output.
    entry_points.sort_by(|a, b| {
        (a.handler_path.as_path(), a.handler_line, a.kind.as_str(), a.name.as_str())
            .cmp(&(b.handler_path.as_path(), b.handler_line, b.kind.as_str(), b.name.as_str()))
    });

    let symbols = collect_symbols(&merged, &files, &path_by_id);

    Ok(ProjectIndex {
        root: root.to_path_buf(),
        symbols,
        edges,
        entry_points,
        imports_per_file: Vec::new(),
    })
}

/// Hand-rolled recursive scan. Skips common irrelevant directories.
fn discover_php(root: &Path) -> Result<Vec<PathBuf>> {
    let mut out = Vec::new();
    let mut stack: Vec<PathBuf> = vec![root.to_path_buf()];
    while let Some(dir) = stack.pop() {
        let entries = fs::read_dir(&dir).with_context(|| format!("read_dir {}", dir.display()))?;
        for entry in entries {
            let entry = entry?;
            let path = entry.path();
            let file_name = path.file_name().and_then(|s| s.to_str()).unwrap_or("");
            if path.is_dir() {
                // Skip third-party / build / tooling artefacts. .worktrees is a
                // common git worktrees parent that can contain massive
                // duplicate trees on real projects.
                if matches!(
                    file_name,
                    ".git"
                        | ".worktrees"
                        | ".idea"
                        | ".vscode"
                        | "vendor"
                        | "node_modules"
                        | "var"
                        | "target"
                        | "tmp"
                        | "cache"
                        | "build"
                ) {
                    continue;
                }
                stack.push(path);
            } else if path.extension().and_then(|s| s.to_str()) == Some("php") {
                out.push(path);
            }
        }
    }
    out.sort();
    Ok(out)
}

fn collect_symbols(
    codebase: &CodebaseMetadata,
    files: &[File],
    path_by_id: &HashMap<FileId, PathBuf>,
) -> Vec<Symbol> {
    let mut symbols = Vec::new();

    for (fqn, class_meta) in codebase.class_likes.iter() {
        let kind = map_class_kind(class_meta.kind);
        if let Some((path, ls, le)) = locate_span(class_meta.span, files, path_by_id) {
            symbols.push(Symbol {
                fqn: fqn.as_str().to_string(),
                kind,
                path,
                line_start: ls,
                line_end: le,
            });
        }

        for (method_name, method_id) in class_meta.declaring_method_ids.iter() {
            if let Some(method_meta) = codebase.get_method_by_id(method_id)
                && let Some((path, ls, le)) = locate_span(method_meta.span, files, path_by_id)
            {
                symbols.push(Symbol {
                    fqn: format!("{}::{}", fqn.as_str(), method_name.as_str()),
                    kind: SymbolKind::Method,
                    path,
                    line_start: ls,
                    line_end: le,
                });
            }
        }
    }

    for ((scope, name), fn_meta) in codebase.function_likes.iter() {
        if !scope.as_str().is_empty() {
            continue;
        }
        if fn_meta.name.is_some()
            && let Some((path, ls, le)) = locate_span(fn_meta.span, files, path_by_id)
        {
            symbols.push(Symbol {
                fqn: name.as_str().to_string(),
                kind: SymbolKind::Function,
                path,
                line_start: ls,
                line_end: le,
            });
        }
    }

    symbols.sort_by(|a, b| {
        (a.path.as_path(), a.line_start, a.fqn.as_str())
            .cmp(&(b.path.as_path(), b.line_start, b.fqn.as_str()))
    });
    symbols
}

fn locate_span(span: Span, files: &[File], path_by_id: &HashMap<FileId, PathBuf>) -> Option<(PathBuf, u32, u32)> {
    let path = path_by_id.get(&span.file_id)?.clone();
    let file = files.iter().find(|f| f.id == span.file_id)?;
    let line_start = file.line_number(span.start.offset) + 1;
    let line_end = file.line_number(span.end.offset) + 1;
    Some((path, line_start, line_end))
}

fn map_class_kind(kind: MagoSymbolKind) -> SymbolKind {
    match kind {
        MagoSymbolKind::Class => SymbolKind::Class,
        MagoSymbolKind::Interface => SymbolKind::Interface,
        MagoSymbolKind::Trait => SymbolKind::Trait,
        MagoSymbolKind::Enum => SymbolKind::Enum,
    }
}

/// Convert mago's populated [`SymbolReferences`] into our neutral [`CallEdge`]
/// list. We use the back-reference map (callee -> callers) which mago populates
/// during analysis. Site path/line are not stored in the back-reference index;
/// phase-5 will enrich edges with sites if/when we need them for witness paths.
fn symbol_references_to_edges(refs: &SymbolReferences) -> Vec<CallEdge> {
    let mut out = Vec::new();
    for (callee, callers) in refs.get_back_references() {
        let to_fqn = format_symbol_id(&callee);
        for caller in callers {
            out.push(CallEdge {
                from_fqn: format_symbol_id(&caller),
                to_fqn: to_fqn.clone(),
                site_path: PathBuf::new(),
                site_line: 0,
                confidence: Confidence::Confirmed,
            });
        }
    }
    // Stable order for snapshots.
    out.sort_by(|a, b| (a.from_fqn.as_str(), a.to_fqn.as_str()).cmp(&(b.from_fqn.as_str(), b.to_fqn.as_str())));
    out.dedup_by(|a, b| a.from_fqn == b.from_fqn && a.to_fqn == b.to_fqn);
    out
}

fn format_symbol_id(id: &mago_codex::symbol::SymbolIdentifier) -> String {
    let scope = id.0.as_str();
    let name = id.1.as_str();
    if scope.is_empty() {
        name.to_string()
    } else {
        format!("{}::{}", scope, name)
    }
}

fn tracing_warn(msg: String) {
    eprintln!("watson: {msg}");
}
