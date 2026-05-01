use std::path::{Path, PathBuf};

use crate::engine::{ProjectIndex, Symbol};
use crate::git::diff::{ChangedFile, ChangeStatus, LineRange};

/// A symbol intersecting a diff. `whole_file_gone` is true when the symbol's
/// host file was deleted or renamed away — useful for reporting entry points
/// that vanished entirely.
#[derive(Debug, Clone)]
pub struct ChangedSymbol {
    pub fqn: String,
    pub path: PathBuf,
    pub line_start: u32,
    pub line_end: u32,
    pub whole_file_gone: bool,
}

pub fn intersect_changed_symbols(
    project: &ProjectIndex,
    diffs: &[ChangedFile],
) -> Vec<ChangedSymbol> {
    let mut out = Vec::new();

    for diff in diffs {
        match diff.status {
            ChangeStatus::Deleted | ChangeStatus::Renamed => {
                for sym in symbols_in_file(&project.symbols, &diff.path) {
                    out.push(ChangedSymbol {
                        fqn: sym.fqn.clone(),
                        path: sym.path.clone(),
                        line_start: sym.line_start,
                        line_end: sym.line_end,
                        whole_file_gone: true,
                    });
                }
            }
            _ => {
                for sym in symbols_in_file(&project.symbols, &diff.path) {
                    if sym_overlaps_any_hunk(sym, &diff.hunks) {
                        out.push(ChangedSymbol {
                            fqn: sym.fqn.clone(),
                            path: sym.path.clone(),
                            line_start: sym.line_start,
                            line_end: sym.line_end,
                            whole_file_gone: false,
                        });
                    }
                }
            }
        }
    }

    // De-dup on FQN, prefer non-whole-file-gone entries when both exist.
    out.sort_by(|a, b| (a.fqn.as_str(), a.whole_file_gone).cmp(&(b.fqn.as_str(), b.whole_file_gone)));
    out.dedup_by(|a, b| a.fqn == b.fqn);
    out
}

fn symbols_in_file<'a>(symbols: &'a [Symbol], path: &Path) -> impl Iterator<Item = &'a Symbol> {
    let target = path.to_path_buf();
    symbols.iter().filter(move |s| paths_match(&s.path, &target))
}

fn sym_overlaps_any_hunk(sym: &Symbol, hunks: &[LineRange]) -> bool {
    hunks.iter().any(|h| {
        // Symbol [line_start, line_end] inclusive vs hunk [start, end_exclusive).
        let sym_end_excl = sym.line_end.saturating_add(1);
        sym.line_start < h.end_exclusive && h.start < sym_end_excl
    })
}

fn paths_match(a: &Path, b: &Path) -> bool {
    if a == b {
        return true;
    }
    // Tolerate one being absolute and the other relative if their tails match.
    let a_canon = a.canonicalize().ok();
    let b_canon = b.canonicalize().ok();
    match (a_canon, b_canon) {
        (Some(x), Some(y)) => x == y,
        _ => false,
    }
}
