//! Smoke tests against the user's real Symfony apps.
//!
//! These tests are `#[ignore]`d so a stock `cargo test` stays hermetic. To
//! run them against your local checkouts:
//!
//! ```ignore
//! cargo test --test real_apps -- --ignored --nocapture
//! ```
//!
//! Override the discovered roots with environment variables when invoking:
//!
//! ```ignore
//! WATSON_TEAM_UP_ROOT=/path/to/your/symfony \
//! WATSON_PROJECT_L_ROOT=/path/to/another \
//!   cargo test --test real_apps -- --ignored --nocapture
//! ```
//!
//! The tests do not depend on any specific commit content — they pick a recent
//! commit pair from the local history that touches PHP, run watson against it,
//! and assert that the binary succeeds and emits a well-formed envelope. They
//! print the summary so you can eyeball numbers when iterating on watson.

use std::path::{Path, PathBuf};
use std::process::Command;

fn binary() -> PathBuf {
    PathBuf::from(env!("CARGO_BIN_EXE_watson"))
}

fn home() -> PathBuf {
    PathBuf::from(std::env::var_os("HOME").expect("HOME env"))
}

/// Pick a commit pair `<base, head>` in `repo` such that the diff between them
/// touches at least one `.php` file. Walks up to 30 commits from `HEAD`.
/// Returns `None` if nothing PHP-touching is found.
fn find_php_diff_pair(repo: &Path) -> Option<(String, String)> {
    let log = Command::new("git")
        .args(["-C"])
        .arg(repo)
        .args(["log", "--format=%H", "-30", "HEAD"])
        .output()
        .ok()?;
    if !log.status.success() {
        return None;
    }
    let shas: Vec<String> = String::from_utf8_lossy(&log.stdout)
        .lines()
        .map(|s| s.trim().to_string())
        .filter(|s| !s.is_empty())
        .collect();

    for window in shas.windows(2) {
        let head = &window[0];
        let base = &window[1];
        let names = Command::new("git")
            .arg("-C")
            .arg(repo)
            .args(["diff", "--name-only", base, head])
            .output()
            .ok()?;
        if !names.status.success() {
            continue;
        }
        let any_php = String::from_utf8_lossy(&names.stdout)
            .lines()
            .any(|line| line.ends_with(".php"));
        if any_php {
            return Some((base.clone(), head.clone()));
        }
    }
    None
}

fn run_blastradius_against(repo: &Path, base: &str, head: &str) -> serde_json::Value {
    let output = Command::new(binary())
        .args(["blastradius", &format!("{base}..{head}"), "--root"])
        .arg(repo)
        .output()
        .expect("spawn watson");

    assert!(
        output.status.success(),
        "watson exited non-zero on {}\nstderr:\n{}",
        repo.display(),
        String::from_utf8_lossy(&output.stderr)
    );

    let stdout = String::from_utf8(output.stdout).expect("utf-8 stdout");
    serde_json::from_str(&stdout).unwrap_or_else(|e| {
        panic!("envelope JSON did not parse: {e}\n--- stdout ---\n{stdout}")
    })
}

fn smoke(repo_env: &str, default_rel: &str) {
    let repo = std::env::var_os(repo_env)
        .map(PathBuf::from)
        .unwrap_or_else(|| home().join(default_rel));

    if !repo.is_dir() {
        eprintln!("skipping: {} not present at {}", repo_env, repo.display());
        return;
    }

    let Some((base, head)) = find_php_diff_pair(&repo) else {
        eprintln!("skipping: no PHP-touching diff found in last 30 commits of {}", repo.display());
        return;
    };

    eprintln!("\n=== {} ({}..{}) ===", repo.display(), &base[..7], &head[..7]);

    let envelope = run_blastradius_against(&repo, &base, &head);

    // Envelope-level invariants.
    assert_eq!(envelope["tool"], "watson");
    assert_eq!(envelope["language"], "php");
    assert_eq!(envelope["framework"], "symfony");
    assert_eq!(envelope["analyses"][0]["name"], "blastradius");
    assert_eq!(envelope["analyses"][0]["ok"], true);

    let result = &envelope["analyses"][0]["result"];
    let summary = &result["summary"];
    eprintln!("summary: {}", summary);

    let kinds: Vec<String> = result["affected_entry_points"]
        .as_array()
        .unwrap_or(&Vec::new())
        .iter()
        .map(|a| a["kind"].as_str().unwrap_or("").to_string())
        .collect();
    eprintln!("affected entry-point kinds: {:?}", kinds);
    let names: Vec<String> = result["affected_entry_points"]
        .as_array()
        .unwrap_or(&Vec::new())
        .iter()
        .map(|a| {
            format!(
                "{}={}",
                a["kind"].as_str().unwrap_or(""),
                a["name"].as_str().unwrap_or("")
            )
        })
        .collect();
    if !names.is_empty() {
        eprintln!("affected entry points:");
        for n in &names {
            eprintln!("  {n}");
        }
    }

    // Schema sanity: every affected entry point has the expected shape.
    if let Some(arr) = result["affected_entry_points"].as_array() {
        for ep in arr {
            assert!(ep["kind"].is_string());
            assert!(ep["handler"]["fqn"].is_string());
            assert!(ep["min_confidence"].is_string());
            assert!(ep["witness_path"].is_array());
        }
    }
}

#[test]
#[ignore]
fn team_up_smoke() {
    smoke("WATSON_TEAM_UP_ROOT", "team-up");
}

#[test]
#[ignore]
fn project_l_smoke() {
    smoke("WATSON_PROJECT_L_ROOT", "project-l");
}
