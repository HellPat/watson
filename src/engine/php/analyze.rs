use std::borrow::Cow;
use std::collections::HashMap;
use std::fs;
use std::path::{Path, PathBuf};

use anyhow::{Context, Result};
use bumpalo::Bump;
use foldhash::HashSet as FoldHashSet;
use mago_atom::{Atom, AtomSet};
use mago_codex::metadata::CodebaseMetadata;
use mago_codex::populator::populate_codebase;
use mago_codex::reference::SymbolReferences;
use mago_codex::scanner::scan_program;
use mago_codex::symbol::SymbolKind as MagoSymbolKind;
use mago_database::file::{File, FileId, FileType};
use mago_names::resolver::NameResolver;
use mago_span::Span;
use mago_syntax::parser::parse_file;

use crate::engine::{ProjectIndex, Symbol, SymbolKind};

/// Multi-file PHP analysis pipeline modelled on mago's
/// `crates/orchestrator/src/service/pipeline.rs`.
///
/// 1. Walk `root` for `.php` files.
/// 2. Per file: parse → resolve names → `scan_program` → partial `CodebaseMetadata`.
///    The arena is per-file and dropped after `scan_program` returns; mago interns
///    everything it keeps (`Atom`s, owned strings) so the metadata outlives the arena.
/// 3. Merge partials into one `CodebaseMetadata`.
/// 4. `populate_codebase` to resolve hierarchies and populate cross-references.
/// 5. Translate `class_likes` + `function_likes` into our neutral `Symbol` list.
pub fn analyze_project(root: &Path) -> Result<ProjectIndex> {
    let php_files = discover_php(root).with_context(|| format!("scan PHP files in {}", root.display()))?;

    // Keep `File`s alive after the per-file loop so we can map FileId -> path/line.
    let mut files_by_id: HashMap<FileId, Cow<'static, str>> = HashMap::new();
    let mut path_by_id: HashMap<FileId, PathBuf> = HashMap::new();
    let mut partial_metadatas: Vec<CodebaseMetadata> = Vec::new();
    let mut entry_points: Vec<crate::engine::EntryPoint> = Vec::new();

    for path in &php_files {
        let src = fs::read_to_string(path).with_context(|| format!("read {}", path.display()))?;
        let rel = path
            .strip_prefix(root)
            .unwrap_or(path)
            .display()
            .to_string();
        let file = File::ephemeral(Cow::Owned(rel.clone()), Cow::Owned(src));
        let file_id = file.id;
        let contents_clone = file.contents.clone();

        let arena = Bump::new();
        let program = parse_file(&arena, &file);
        if program.has_errors() {
            tracing_warn(format!(
                "parse errors in {} (continuing): {} error(s)",
                path.display(),
                program.errors.len()
            ));
        }

        let resolver = NameResolver::new(&arena);
        let resolved_names = resolver.resolve(program);

        // Walk AST while arena is alive to collect entry-point declarations.
        // Owned `EntryPoint`s are produced, so they outlive the arena.
        let file_eps = super::entrypoints::extract(program, &resolved_names, &file, path);
        entry_points.extend(file_eps);

        let metadata = scan_program(&arena, &file, program, &resolved_names);
        partial_metadatas.push(metadata);

        files_by_id.insert(file_id, contents_clone);
        path_by_id.insert(file_id, path.clone());
        // arena drops at end of iteration
    }

    let mut merged = CodebaseMetadata::new();
    for partial in partial_metadatas {
        merged.extend(partial);
    }

    let mut symbol_references = SymbolReferences::new();
    populate_codebase(&mut merged, &mut symbol_references, AtomSet::default(), FoldHashSet::default());

    let symbols = collect_symbols(&merged, &files_by_id, &path_by_id);

    // Sort for deterministic output.
    entry_points.sort_by(|a, b| {
        (a.handler_path.as_path(), a.handler_line, a.kind.as_str(), a.name.as_str())
            .cmp(&(b.handler_path.as_path(), b.handler_line, b.kind.as_str(), b.name.as_str()))
    });

    Ok(ProjectIndex {
        root: root.to_path_buf(),
        symbols,
        edges: Vec::new(),
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
                if matches!(file_name, ".git" | "vendor" | "node_modules" | "var" | "target") {
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
    files_by_id: &HashMap<FileId, Cow<'static, str>>,
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

        // Methods declared on this class (ignore inherited ones).
        for (method_name, method_id) in class_meta.declaring_method_ids.iter() {
            if let Some(method_meta) = codebase.get_method_by_id(method_id) {
                if let Some((path, ls, le)) = locate_span(method_meta.span, files_by_id, path_by_id) {
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
    }

    for ((scope, name), fn_meta) in codebase.function_likes.iter() {
        if !scope.as_str().is_empty() {
            continue; // methods handled above
        }
        if let Some(_) = fn_meta.name {
            if let Some((path, ls, le)) = locate_span(fn_meta.span, files_by_id, path_by_id) {
                symbols.push(Symbol {
                    fqn: name.as_str().to_string(),
                    kind: SymbolKind::Function,
                    path,
                    line_start: ls,
                    line_end: le,
                });
            }
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
    files_by_id: &HashMap<FileId, Cow<'static, str>>,
    path_by_id: &HashMap<FileId, PathBuf>,
) -> Option<(PathBuf, u32, u32)> {
    let path = path_by_id.get(&span.file_id)?.clone();
    let contents = files_by_id.get(&span.file_id)?;
    // Recompute line numbers from the kept contents — we don't need to retain the
    // full `File` struct, just its bytes.
    let line_start = line_number_for(contents, span.start.offset);
    let line_end = line_number_for(contents, span.end.offset);
    Some((path, line_start + 1, line_end + 1)) // 1-based for human-friendly output
}

fn line_number_for(contents: &str, offset: u32) -> u32 {
    let bytes = contents.as_bytes();
    let cap = bytes.len().min(offset as usize);
    let mut line: u32 = 0;
    for &b in &bytes[..cap] {
        if b == b'\n' {
            line += 1;
        }
    }
    line
}

fn map_class_kind(kind: MagoSymbolKind) -> SymbolKind {
    match kind {
        MagoSymbolKind::Class => SymbolKind::Class,
        MagoSymbolKind::Interface => SymbolKind::Interface,
        MagoSymbolKind::Trait => SymbolKind::Trait,
        MagoSymbolKind::Enum => SymbolKind::Enum,
    }
}

fn tracing_warn(msg: String) {
    // No tracing dep yet; print to stderr for now. Replaced with `tracing` later if needed.
    eprintln!("watson: {msg}");
}

#[allow(dead_code)]
fn _file_type_marker() {
    // Reference `FileType` so the import doesn't get cleaned up by clippy --fix.
    let _ = FileType::Host;
    let _: Atom = mago_atom::atom("placeholder");
}
