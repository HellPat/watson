use std::path::{Path, PathBuf};
use std::process::Command;

use tempfile::TempDir;

pub struct Built {
    pub tmp: TempDir,
    pub base_sha: String,
    pub head_sha: String,
}

impl Built {
    pub fn root(&self) -> &Path {
        self.tmp.path()
    }
}

/// Build a throwaway git repo from `baseline` and overlay `scenario` on top.
/// Returns the temp repo plus the two commit SHAs (`base_sha` first, then
/// `head_sha`). When the returned `Built` is dropped, the temp dir disappears.
pub fn build(baseline: &Path, scenario: &Path) -> Built {
    let tmp = TempDir::new().expect("create tempdir");
    let repo = tmp.path();

    copy_tree(baseline, repo);

    run(repo, &["init", "-q", "-b", "main"]);
    // Deterministic identity for repeatable shas.
    run(repo, &["config", "user.email", "watson-tests@example.com"]);
    run(repo, &["config", "user.name", "watson tests"]);
    run(repo, &["config", "commit.gpgsign", "false"]);

    run(repo, &["add", "-A"]);
    run_env(
        repo,
        &["commit", "-q", "--allow-empty-message", "-m", "baseline"],
        &[
            ("GIT_COMMITTER_DATE", "2026-01-01T00:00:00+0000"),
            ("GIT_AUTHOR_DATE", "2026-01-01T00:00:00+0000"),
        ],
    );
    let base_sha = capture(repo, &["rev-parse", "HEAD"]);

    copy_tree(scenario, repo);

    run(repo, &["add", "-A"]);
    run_env(
        repo,
        &["commit", "-q", "--allow-empty-message", "-m", "scenario"],
        &[
            ("GIT_COMMITTER_DATE", "2026-01-01T01:00:00+0000"),
            ("GIT_AUTHOR_DATE", "2026-01-01T01:00:00+0000"),
        ],
    );
    let head_sha = capture(repo, &["rev-parse", "HEAD"]);

    Built { tmp, base_sha, head_sha }
}

fn copy_tree(src: &Path, dst: &Path) {
    let mut stack: Vec<(PathBuf, PathBuf)> = vec![(src.to_path_buf(), dst.to_path_buf())];
    while let Some((from, to)) = stack.pop() {
        if from.is_dir() {
            std::fs::create_dir_all(&to).expect("mkdir");
            for entry in std::fs::read_dir(&from).expect("read_dir") {
                let entry = entry.expect("entry");
                stack.push((entry.path(), to.join(entry.file_name())));
            }
        } else if from.is_file() {
            if let Some(parent) = to.parent() {
                std::fs::create_dir_all(parent).expect("mkdir parent");
            }
            std::fs::copy(&from, &to).expect("copy");
        }
    }
}

fn run(cwd: &Path, args: &[&str]) {
    let out = Command::new("git").current_dir(cwd).args(args).output().expect("spawn git");
    if !out.status.success() {
        panic!(
            "git {:?} failed: status {} stderr {}",
            args,
            out.status,
            String::from_utf8_lossy(&out.stderr)
        );
    }
}

fn run_env(cwd: &Path, args: &[&str], envs: &[(&str, &str)]) {
    let mut cmd = Command::new("git");
    cmd.current_dir(cwd);
    for (k, v) in envs {
        cmd.env(k, v);
    }
    let out = cmd.args(args).output().expect("spawn git");
    if !out.status.success() {
        panic!(
            "git {:?} failed: status {} stderr {}",
            args,
            out.status,
            String::from_utf8_lossy(&out.stderr)
        );
    }
}

fn capture(cwd: &Path, args: &[&str]) -> String {
    let out = Command::new("git").current_dir(cwd).args(args).output().expect("spawn git");
    if !out.status.success() {
        panic!(
            "git {:?} failed: status {} stderr {}",
            args,
            out.status,
            String::from_utf8_lossy(&out.stderr)
        );
    }
    String::from_utf8(out.stdout).expect("utf-8").trim().to_string()
}
