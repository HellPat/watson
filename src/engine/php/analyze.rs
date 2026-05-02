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
use mago_names::resolver::NameResolver;
use mago_span::Span;
use mago_syntax::parser::parse_file;
use rayon::prelude::*;

use crate::engine::{CallEdge, Confidence, EntryPoint, ProjectIndex, Symbol, SymbolKind};
use crate::util::warn as tracing_warn;

/// Multi-file PHP analysis pipeline modelled on mago's
/// `crates/orchestrator/src/service/pipeline.rs`. Runs in parallel via rayon
/// — each thread keeps its own `bumpalo::Bump` arena and the per-file outputs
/// (partial `CodebaseMetadata`, entry points) merge after the parallel pass.
///
/// Pass 1 (per-file, parallel): parse → resolve → entry-point AST walk →
/// `scan_program`. Returns owned `CodebaseMetadata` (interned, arena-free) and
/// owned `EntryPoint`s.
/// Merge: extend partials into a single `CodebaseMetadata`; run
/// `populate_codebase` once.
/// Pass 2 (per-file, parallel): re-parse → resolve → run `Analyzer::analyze`,
/// returning a per-file `AnalysisResult` whose `symbol_references` we merge
/// into a single map at the end.
pub fn analyze_project(root: &Path) -> Result<ProjectIndex> {
    let php_files = discover_php(root).with_context(|| format!("scan PHP files in {}", root.display()))?;

    // Build owned File objects up-front so per-thread re-parsing can borrow them.
    let mut files: Vec<File> = Vec::with_capacity(php_files.len());
    let mut path_by_id: HashMap<FileId, PathBuf> = HashMap::new();
    for path in &php_files {
        let src = fs::read_to_string(path).with_context(|| format!("read {}", path.display()))?;
        let rel = path.strip_prefix(root).unwrap_or(path).display().to_string();
        let file = File::ephemeral(Cow::Owned(rel), Cow::Owned(src));
        path_by_id.insert(file.id, path.clone());
        files.push(file);
    }

    // ----- Pass 1: parse + resolve + entrypoints + scan_program in parallel -----
    let pass1_outputs: Vec<(CodebaseMetadata, Vec<EntryPoint>)> = files
        .par_iter()
        .zip(php_files.par_iter())
        .map_init(Bump::new, |arena, (file, abs_path)| {
            let program = parse_file(arena, file);
            if program.has_errors() {
                tracing_warn(format!(
                    "parse errors in {} (continuing): {} error(s)",
                    abs_path.display(),
                    program.errors.len()
                ));
            }
            let resolver = NameResolver::new(arena);
            let resolved_names = resolver.resolve(program);

            let eps = super::entrypoints::extract(program, &resolved_names, file, abs_path);
            let metadata = scan_program(arena, file, program, &resolved_names);

            arena.reset();
            (metadata, eps)
        })
        .collect();

    // Merge per-file metadata + entry points sequentially.
    let mut merged = CodebaseMetadata::new();
    let mut entry_points: Vec<EntryPoint> = Vec::new();
    for (partial, eps) in pass1_outputs {
        merged.extend(partial);
        entry_points.extend(eps);
    }

    // Populate hierarchies + signature-level edges.
    let mut symbol_references = SymbolReferences::new();
    populate_codebase(&mut merged, &mut symbol_references, AtomSet::default(), FoldHashSet::default());

    // ----- Pass 2: per-file analyzer in parallel -----
    let plugin_registry = PluginRegistry::with_library_providers();
    let settings = Settings::default();

    // Pass 2: per-file analyzer in parallel. Each rayon worker keeps its own
    // arena + a local AnalysisResult; we merge at the end. mago expects
    // analyzer.analyze() to mutate its `analysis_result` in place, so we run
    // one local result per file and merge SymbolReferences after the parallel
    // map.
    let local_results: Vec<SymbolReferences> = files
        .par_iter()
        .zip(php_files.par_iter())
        .map_init(Bump::new, |arena, (file, abs_path)| {
            let program = parse_file(arena, file);
            let resolver = NameResolver::new(arena);
            let resolved_names = resolver.resolve(program);

            let mut local = AnalysisResult::new(SymbolReferences::new());
            let analyzer = Analyzer::new(
                arena,
                file,
                &resolved_names,
                &merged,
                &plugin_registry,
                settings.clone(),
            );
            if let Err(err) = analyzer.analyze(program, &mut local) {
                tracing_warn(format!("analyzer error in {} (continuing): {err}", abs_path.display()));
            }
            arena.reset();
            local.symbol_references
        })
        .collect();

    for local in local_results {
        symbol_references.extend(local);
    }
    let edges = symbol_references_to_edges(&symbol_references);

    entry_points = dedupe_entry_points(entry_points);

    // Sort entry points for deterministic output.
    entry_points.sort_by(|a, b| {
        (a.handler_path.as_path(), a.handler_line, a.kind.as_str(), a.name.as_str())
            .cmp(&(b.handler_path.as_path(), b.handler_line, b.kind.as_str(), b.name.as_str()))
    });

    let files_by_id: HashMap<FileId, &File> = files.iter().map(|f| (f.id, f)).collect();
    let symbols = collect_symbols(&merged, &files_by_id, &path_by_id);

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
                // PHP-engine ignore list: only directories that *every* PHP
                // project has and that never contain first-party source.
                //   vendor       — composer-managed dependencies
                //   var          — Symfony / Laravel runtime cache + logs
                //   .git         — git plumbing (required so we don't crawl pack files)
                //   .worktrees   — git-attached, often holds duplicate PHP trees
                if matches!(file_name, ".git" | ".worktrees" | "vendor" | "var") {
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
    files_by_id: &HashMap<FileId, &File>,
    path_by_id: &HashMap<FileId, PathBuf>,
) -> Vec<Symbol> {
    let mut symbols = Vec::new();

    for (fqn, class_meta) in codebase.class_likes.iter() {
        let kind = map_class_kind(class_meta.kind);
        if let Some((path, ls, le)) = locate_span(class_meta.span, files_by_id, path_by_id) {
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
                && let Some((path, ls, le)) = locate_span(method_meta.span, files_by_id, path_by_id)
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
            && let Some((path, ls, le)) = locate_span(fn_meta.span, files_by_id, path_by_id)
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

fn locate_span(
    span: Span,
    files_by_id: &HashMap<FileId, &File>,
    path_by_id: &HashMap<FileId, PathBuf>,
) -> Option<(PathBuf, u32, u32)> {
    let path = path_by_id.get(&span.file_id)?.clone();
    let file = files_by_id.get(&span.file_id)?;
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
/// during analysis. Site path/line are not stored in the back-reference index.
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


/// Two detectors can fire for the same logical entry point — e.g. a class
/// with `#[AsCommand]` also extends `Command`. Keep the most informative
/// detection. Source priority:
///   CompiledCache / BinConsole / Artisan  (runtime-authoritative)
///   Attribute                              (carries literal route / command name)
///   Interface                              (fallback to handler FQN)
///   StaticCall                             (Laravel `Route::*` calls)
///
/// IMPORTANT: dedup key is per-kind. A single controller method can carry
/// several `#[Route]` attributes (multiple routes pointing at the same
/// handler) — we MUST keep them as distinct entry points. The user-facing
/// identity for a route is the HTTP method + path, for a command it is the
/// command name. Falling back to handler_fqn here used to silently collapse
/// every route on a multi-route handler into one. Don't.
fn dedupe_entry_points(eps: Vec<EntryPoint>) -> Vec<EntryPoint> {
    use crate::engine::EntryPointSource as S;

    let priority = |s: S| -> u8 {
        match s {
            S::CompiledCache | S::BinConsole | S::Artisan => 4,
            S::Attribute => 3,
            S::Interface => 2,
            S::StaticCall => 1,
        }
    };

    let mut by_key: HashMap<(String, String), EntryPoint> = HashMap::new();
    for ep in eps {
        let key = (ep.kind.clone(), entry_point_dedup_key(&ep));
        match by_key.get(&key) {
            Some(existing) if priority(existing.source) >= priority(ep.source) => {}
            _ => {
                by_key.insert(key, ep);
            }
        }
    }
    by_key.into_values().collect()
}

/// Per-kind identity used for dedup. The user-facing identity differs by
/// kind:
///   - routes are identified by HTTP method + path (one method may carry
///     several `#[Route]` attributes — every distinct path is a separate
///     route);
///   - everything else (commands, jobs, message handlers, listeners,
///     scheduled tasks, schedule providers, tests) is "one logical thing per
///     handler" — collapse on handler FQN so that an attribute + interface
///     double-detection on the same class merges via source priority.
fn entry_point_dedup_key(ep: &EntryPoint) -> String {
    match ep.kind.as_str() {
        "symfony.route" | "laravel.route" => {
            let path = ep.extra.get("path").and_then(|v| v.as_str()).unwrap_or("");
            let methods = ep
                .extra
                .get("methods")
                .and_then(|v| v.as_array())
                .map(|arr| {
                    let mut ms: Vec<&str> = arr.iter().filter_map(|x| x.as_str()).collect();
                    ms.sort_unstable();
                    ms.join(",")
                })
                .unwrap_or_default();
            if path.is_empty() && methods.is_empty() {
                ep.handler_fqn.to_lowercase()
            } else {
                format!("{} {}", methods, path)
            }
        }
        _ => ep.handler_fqn.to_lowercase(),
    }
}
