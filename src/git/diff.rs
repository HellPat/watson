use std::path::{Path, PathBuf};
use std::process::Command;

use anyhow::{Context, Result, anyhow};

/// A line range within a file. Inclusive start (1-based), exclusive end.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct LineRange {
    pub start: u32,
    pub end_exclusive: u32,
}

#[derive(Debug, Clone)]
pub struct ChangedFile {
    pub path: PathBuf,
    pub status: ChangeStatus,
    pub hunks: Vec<LineRange>,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ChangeStatus {
    Modified,
    Added,
    Deleted,
    Renamed,
    Other,
}

/// Compute changed files and per-file new-side hunks between `base` and `head`
/// inside `repo` by shelling out to `git diff --unified=0`.
///
/// We use `git` rather than `gix` for v1 — git is on every dev machine and the
/// hunk parser is ~30 lines of straight string handling. `gix` becomes worth
/// pulling in once we need richer history walking.
pub fn diff(repo: &Path, base: &str, head: &str) -> Result<Vec<ChangedFile>> {
    let out = Command::new("git")
        .arg("-C")
        .arg(repo)
        .args(["diff", "--unified=0", "--no-color", "--no-renames"])
        .arg(base)
        .arg(head)
        .output()
        .with_context(|| format!("git diff {base}..{head} in {}", repo.display()))?;

    if !out.status.success() {
        return Err(anyhow!(
            "git diff exited with {} — stderr: {}",
            out.status,
            String::from_utf8_lossy(&out.stderr)
        ));
    }

    let stdout = String::from_utf8(out.stdout).context("git diff stdout not utf-8")?;
    parse_unified(&stdout, repo)
}

fn parse_unified(text: &str, repo: &Path) -> Result<Vec<ChangedFile>> {
    let mut files: Vec<ChangedFile> = Vec::new();
    let mut current: Option<ChangedFile> = None;

    for line in text.lines() {
        if let Some(rest) = line.strip_prefix("diff --git ") {
            // Flush previous.
            if let Some(prev) = current.take() {
                files.push(prev);
            }
            // "a/<path> b/<path>" — pick the b/ path.
            let path = rest
                .split_whitespace()
                .find_map(|tok| tok.strip_prefix("b/"))
                .unwrap_or("");
            current = Some(ChangedFile {
                path: repo.join(path),
                status: ChangeStatus::Modified,
                hunks: Vec::new(),
            });
        } else if line.starts_with("new file mode") {
            if let Some(c) = current.as_mut() {
                c.status = ChangeStatus::Added;
            }
        } else if line.starts_with("deleted file mode") {
            if let Some(c) = current.as_mut() {
                c.status = ChangeStatus::Deleted;
            }
        } else if line.starts_with("rename ") || line.starts_with("similarity index") {
            if let Some(c) = current.as_mut() {
                c.status = ChangeStatus::Renamed;
            }
        } else if let Some(rest) = line.strip_prefix("@@ ")
            && let Some((plus_part, _)) = rest.split_once(" @@")
            && let Some(plus) = plus_part.split_whitespace().find(|t| t.starts_with('+'))
        {
            // @@ -A,B +C,D @@ ... — we only care about the +C,D part on the new side.
            // C may be 0 for fully-deleted regions; D defaults to 1 when omitted.
            let plus = plus.trim_start_matches('+');
            let (start_str, count_str) = plus.split_once(',').unwrap_or((plus, "1"));
            let start: u32 = start_str.parse().unwrap_or(0);
            let count: u32 = count_str.parse().unwrap_or(1);
            if start > 0
                && count > 0
                && let Some(c) = current.as_mut()
            {
                c.hunks.push(LineRange { start, end_exclusive: start + count });
            }
        }
    }
    if let Some(prev) = current.take() {
        files.push(prev);
    }
    Ok(files)
}
