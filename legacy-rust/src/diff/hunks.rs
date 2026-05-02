use std::collections::HashMap;
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

/// Intersect the project's symbols with the diff hunks.
///
/// Hot path: a real Symfony / Laravel project has thousands of symbols and
/// each PR touches dozens of files. We index symbols by canonical absolute
/// path once, then look diffs up against that index — no `canonicalize` per
/// pair.
pub fn intersect_changed_symbols(
    project: &ProjectIndex,
    diffs: &[ChangedFile],
) -> Vec<ChangedSymbol> {
    let mut by_path: HashMap<PathBuf, Vec<&Symbol>> = HashMap::new();
    for sym in &project.symbols {
        let key = canonicalize_or_self(&sym.path);
        by_path.entry(key).or_default().push(sym);
    }

    let mut out: Vec<ChangedSymbol> = Vec::new();
    for diff in diffs {
        let key = canonicalize_or_self(&diff.path);
        let Some(syms) = by_path.get(&key) else {
            continue;
        };
        match diff.status {
            ChangeStatus::Deleted | ChangeStatus::Renamed => {
                for sym in syms {
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
                for sym in syms {
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

fn sym_overlaps_any_hunk(sym: &Symbol, hunks: &[LineRange]) -> bool {
    hunks.iter().any(|h| {
        // Symbol [line_start, line_end] inclusive vs hunk [start, end_exclusive).
        let sym_end_excl = sym.line_end.saturating_add(1);
        sym.line_start < h.end_exclusive && h.start < sym_end_excl
    })
}

/// Canonicalize a path; fall back to the input as-is if it doesn't exist on
/// disk. We canonicalize once per unique path, then key the symbol index by
/// the result. Critical: never call this in a per-symbol loop with O(n*m)
/// shape — we already saw it cost 8s on a 4k-file Laravel project.
fn canonicalize_or_self(p: &Path) -> PathBuf {
    p.canonicalize().unwrap_or_else(|_| p.to_path_buf())
}
