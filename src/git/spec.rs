use std::path::Path;
use std::process::Command;

use anyhow::{Context, Result, anyhow, bail};

/// What kind of "head" the diff ends at. Mirrors `git diff` semantics:
///   - `Commit(SHA)` is a fixed tree.
///   - `WorkingTree` is on-disk content (uncommitted edits included).
///   - `Index` is the staged content.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum HeadKind {
    Commit(String),
    WorkingTree,
    Index,
}

/// Fully-resolved diff specification.
#[derive(Debug, Clone)]
pub struct DiffSpec {
    pub base_sha: String,
    pub head: HeadKind,
    /// Display-friendly base ref (what the user actually typed, falls back to
    /// the SHA for snapshot/output stability when the user gave none).
    pub base_display: String,
    pub head_display: String,
}

/// Resolve a CLI invocation into a `DiffSpec`.
///
/// `revisions` follow the shapes documented in the CLI:
///   []            -> working tree vs HEAD
///   [rev]         -> working tree vs rev
///   [a, b]        -> a vs b
///   [a..b]        -> a vs b
///   [a...b]       -> merge-base(a, b) vs b
///
/// `cached = true` overrides the head side to the index (only valid with
/// 0-arg form: `watson blastradius --cached`).
pub fn resolve(repo: &Path, revisions: &[String], cached: bool) -> Result<DiffSpec> {
    if cached && !revisions.is_empty() {
        bail!("--cached / --staged cannot be combined with explicit revisions");
    }

    if cached {
        let head_sha = rev_parse(repo, "HEAD")?;
        return Ok(DiffSpec {
            base_sha: head_sha,
            head: HeadKind::Index,
            base_display: "HEAD".to_string(),
            head_display: "<index>".to_string(),
        });
    }

    match revisions {
        [] => {
            let head_sha = rev_parse(repo, "HEAD")?;
            Ok(DiffSpec {
                base_sha: head_sha,
                head: HeadKind::WorkingTree,
                base_display: "HEAD".to_string(),
                head_display: "<working tree>".to_string(),
            })
        }
        [single] => {
            // <rev> may itself contain `..` or `...`.
            if let Some((a, b)) = split_three_dot(single) {
                let merge_base = git_merge_base(repo, &a, &b)?;
                let head_sha = rev_parse(repo, &b)?;
                return Ok(DiffSpec {
                    base_sha: merge_base,
                    head: HeadKind::Commit(head_sha),
                    base_display: format!("merge-base({},{})", a, b),
                    head_display: b,
                });
            }
            if let Some((a, b)) = split_two_dot(single) {
                let base_sha = rev_parse(repo, &a)?;
                let head_sha = rev_parse(repo, &b)?;
                return Ok(DiffSpec {
                    base_sha,
                    head: HeadKind::Commit(head_sha),
                    base_display: a,
                    head_display: b,
                });
            }
            // Plain <rev>: working tree vs <rev>.
            let base_sha = rev_parse(repo, single)?;
            Ok(DiffSpec {
                base_sha,
                head: HeadKind::WorkingTree,
                base_display: single.clone(),
                head_display: "<working tree>".to_string(),
            })
        }
        [a, b] => {
            if a.contains("..") || b.contains("..") {
                bail!(
                    "two positional revisions cannot themselves contain `..` / `...`. Use either `<a> <b>` OR `<a>..<b>`, not both"
                );
            }
            let base_sha = rev_parse(repo, a)?;
            let head_sha = rev_parse(repo, b)?;
            Ok(DiffSpec {
                base_sha,
                head: HeadKind::Commit(head_sha),
                base_display: a.clone(),
                head_display: b.clone(),
            })
        }
        _ => bail!("too many positional revisions (max 2)"),
    }
}

/// Watson reads files from the working tree to recover symbol locations.
/// That requires the head side of the diff to match the working tree (i.e.
/// `WorkingTree`, `Index`, or `Commit(HEAD)`). Two-commit diffs where head
/// is some other commit need `git worktree add` plumbing we don't have yet.
pub fn assert_head_matches_working_tree(repo: &Path, spec: &DiffSpec) -> Result<()> {
    match &spec.head {
        HeadKind::WorkingTree | HeadKind::Index => Ok(()),
        HeadKind::Commit(sha) => {
            let head_sha = rev_parse(repo, "HEAD")?;
            if sha == &head_sha {
                Ok(())
            } else {
                Err(anyhow!(
                    "head revision {} does not match HEAD ({}). Watson reads symbol \
                     locations from on-disk files, so the head side of the diff must \
                     equal the current working tree. Run `git checkout {}` first, or use \
                     a working-tree comparison instead.",
                    spec.head_display,
                    &head_sha[..7.min(head_sha.len())],
                    spec.head_display,
                ))
            }
        }
    }
}

fn split_two_dot(s: &str) -> Option<(String, String)> {
    if s.contains("...") {
        return None;
    }
    let parts: Vec<&str> = s.splitn(2, "..").collect();
    if parts.len() == 2 && !parts[0].is_empty() && !parts[1].is_empty() {
        Some((parts[0].to_string(), parts[1].to_string()))
    } else {
        None
    }
}

fn split_three_dot(s: &str) -> Option<(String, String)> {
    let parts: Vec<&str> = s.splitn(2, "...").collect();
    if parts.len() == 2 && !parts[0].is_empty() && !parts[1].is_empty() {
        Some((parts[0].to_string(), parts[1].to_string()))
    } else {
        None
    }
}

fn rev_parse(repo: &Path, rev: &str) -> Result<String> {
    let out = Command::new("git")
        .arg("-C")
        .arg(repo)
        .args(["rev-parse", "--verify"])
        .arg(rev)
        .output()
        .with_context(|| format!("git rev-parse {rev}"))?;
    if !out.status.success() {
        return Err(anyhow!(
            "git rev-parse {rev} failed: {}",
            String::from_utf8_lossy(&out.stderr).trim()
        ));
    }
    Ok(String::from_utf8(out.stdout)?.trim().to_string())
}

fn git_merge_base(repo: &Path, a: &str, b: &str) -> Result<String> {
    let out = Command::new("git")
        .arg("-C")
        .arg(repo)
        .args(["merge-base", a, b])
        .output()
        .with_context(|| format!("git merge-base {a} {b}"))?;
    if !out.status.success() {
        return Err(anyhow!(
            "git merge-base {a} {b} failed: {}",
            String::from_utf8_lossy(&out.stderr).trim()
        ));
    }
    Ok(String::from_utf8(out.stdout)?.trim().to_string())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn split_two_dot_basic() {
        assert_eq!(split_two_dot("main..feature"), Some(("main".into(), "feature".into())));
        assert_eq!(split_two_dot("main...feature"), None); // three-dot returns None
        assert_eq!(split_two_dot("main"), None);
        assert_eq!(split_two_dot("..feature"), None);
        assert_eq!(split_two_dot("main.."), None);
    }

    #[test]
    fn split_three_dot_basic() {
        assert_eq!(split_three_dot("main...feature"), Some(("main".into(), "feature".into())));
        assert_eq!(split_three_dot("main..feature"), None);
        assert_eq!(split_three_dot("main"), None);
    }
}
