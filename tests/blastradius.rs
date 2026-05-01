use std::path::PathBuf;
use std::process::Command;

mod support;

use support::scenario;

fn fixtures() -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/symfony-micro")
}

fn binary() -> PathBuf {
    PathBuf::from(env!("CARGO_BIN_EXE_watson"))
}

fn run_blastradius(base: &str, head: &str, root: &std::path::Path) -> serde_json::Value {
    let output = Command::new(binary())
        .args(["php", "blastradius", "--base", base, "--head", head, "--root"])
        .arg(root)
        .output()
        .expect("spawn watson");
    assert!(
        output.status.success(),
        "watson blastradius exited non-zero. stderr: {}",
        String::from_utf8_lossy(&output.stderr)
    );
    let stdout = String::from_utf8(output.stdout).expect("utf-8");
    serde_json::from_str(&stdout).expect("valid envelope JSON")
}

#[test]
fn service_edit_affects_all_four_entry_points() {
    let scenario = fixtures().join("scenarios/service-edit");
    let baseline = fixtures().join("baseline");
    let built = scenario::build(&baseline, &scenario);

    let envelope = run_blastradius(&built.base_sha, &built.head_sha, built.root());

    assert_eq!(envelope["analyses"][0]["name"], "blastradius");
    let result = &envelope["analyses"][0]["result"];

    assert_eq!(result["summary"]["files_changed"], 1);
    let symbols_changed = result["summary"]["symbols_changed"].as_u64().unwrap();
    assert!(symbols_changed >= 1, "expected at least one changed symbol");

    let affected = result["affected_entry_points"].as_array().expect("array");
    let kinds: Vec<&str> = affected
        .iter()
        .map(|a| a["kind"].as_str().unwrap_or(""))
        .collect();

    for expected_kind in [
        "symfony.route",
        "symfony.command",
        "symfony.message_handler",
        "symfony.periodic_task",
    ] {
        assert!(
            kinds.contains(&expected_kind),
            "expected {expected_kind} in affected entry points; got {kinds:?}"
        );
    }

    // Each affected entry point should carry a non-empty witness path.
    for aep in affected {
        assert!(
            aep["witness_path"].as_array().map(|p| !p.is_empty()).unwrap_or(false),
            "entry point lacks witness path: {aep}"
        );
    }
}

#[test]
fn handler_edit_only_affects_route() {
    let scenario = fixtures().join("scenarios/handler-edit");
    let baseline = fixtures().join("baseline");
    let built = scenario::build(&baseline, &scenario);

    let envelope = run_blastradius(&built.base_sha, &built.head_sha, built.root());
    let result = &envelope["analyses"][0]["result"];

    let affected = result["affected_entry_points"].as_array().expect("array");
    assert_eq!(
        affected.len(),
        1,
        "handler-edit should only affect the route; got: {affected:#?}"
    );
    assert_eq!(affected[0]["kind"], "symfony.route");
    assert_eq!(affected[0]["name"], "greet_show");
}
