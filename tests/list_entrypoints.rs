use std::path::PathBuf;
use std::process::Command;

fn fixture_root() -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/symfony-micro/baseline")
}

fn binary() -> PathBuf {
    PathBuf::from(env!("CARGO_BIN_EXE_watson"))
}

#[test]
fn cli_emits_envelope_with_entry_points() {
    let output = Command::new(binary())
        .args(["list-entrypoints", "--root"])
        .arg(fixture_root())
        .output()
        .expect("run watson");

    assert!(
        output.status.success(),
        "watson exited non-zero. stderr: {}",
        String::from_utf8_lossy(&output.stderr)
    );

    let stdout = String::from_utf8(output.stdout).expect("utf-8 stdout");
    let envelope: serde_json::Value = serde_json::from_str(&stdout).expect("valid JSON");

    // Envelope-level invariants.
    assert_eq!(envelope["tool"], "watson");
    assert_eq!(envelope["language"], "php");
    assert_eq!(envelope["framework"], "symfony");

    let analyses = envelope["analyses"].as_array().expect("analyses array");
    assert_eq!(analyses.len(), 1, "single analysis expected for v1");
    assert_eq!(analyses[0]["name"], "list-entrypoints");
    assert_eq!(analyses[0]["ok"], true);

    let entry_points = analyses[0]["result"]["entry_points"]
        .as_array()
        .expect("entry_points array");

    let summarised: Vec<(String, String, String)> = entry_points
        .iter()
        .map(|ep| {
            (
                ep["kind"].as_str().unwrap_or("").to_string(),
                ep["name"].as_str().unwrap_or("").to_string(),
                ep["handler_fqn"].as_str().unwrap_or("").to_string(),
            )
        })
        .collect();

    let expected = vec![
        // Attribute-based
        (
            "symfony.command".to_string(),
            "app:greet".to_string(),
            "App\\Command\\GreetCommand::execute".to_string(),
        ),
        (
            "symfony.route".to_string(),
            "greet_show".to_string(),
            "App\\Controller\\GreetController::show".to_string(),
        ),
        (
            "symfony.message_handler".to_string(),
            "App\\MessageHandler\\PingHandler::__invoke".to_string(),
            "App\\MessageHandler\\PingHandler::__invoke".to_string(),
        ),
        (
            "symfony.periodic_task".to_string(),
            "App\\Schedule\\CleanupTask::__invoke".to_string(),
            "App\\Schedule\\CleanupTask::__invoke".to_string(),
        ),
        // Interface-based (phase-8)
        (
            "symfony.command".to_string(),
            "app:ping".to_string(),
            "App\\Command\\PingCommand::execute".to_string(),
        ),
        (
            "symfony.message_handler".to_string(),
            "App\\MessageHandler\\LegacyHandler::__invoke".to_string(),
            "App\\MessageHandler\\LegacyHandler::__invoke".to_string(),
        ),
        (
            "symfony.event_listener".to_string(),
            "App\\EventSubscriber\\PingSubscriber::getSubscribedEvents".to_string(),
            "App\\EventSubscriber\\PingSubscriber::getSubscribedEvents".to_string(),
        ),
        (
            "symfony.schedule_provider".to_string(),
            "App\\Schedule\\AppSchedule::getSchedule".to_string(),
            "App\\Schedule\\AppSchedule::getSchedule".to_string(),
        ),
    ];

    for entry in &expected {
        assert!(
            summarised.contains(entry),
            "missing entry point {entry:?}; got: {summarised:?}"
        );
    }
    assert_eq!(
        summarised.len(),
        expected.len(),
        "unexpected extra entry points: {summarised:?}"
    );
}

#[test]
fn route_entry_point_carries_http_metadata() {
    let output = Command::new(binary())
        .args(["list-entrypoints", "--root"])
        .arg(fixture_root())
        .output()
        .expect("run watson");
    assert!(output.status.success());
    let envelope: serde_json::Value =
        serde_json::from_slice(&output.stdout).expect("valid JSON");
    let route = envelope["analyses"][0]["result"]["entry_points"]
        .as_array()
        .unwrap()
        .iter()
        .find(|ep| ep["kind"] == "symfony.route")
        .expect("route entry point present");

    assert_eq!(route["extra"]["path"], "/greet/{name}");
    assert_eq!(route["extra"]["methods"][0], "GET");
}

#[test]
fn periodic_task_carries_frequency() {
    let output = Command::new(binary())
        .args(["list-entrypoints", "--root"])
        .arg(fixture_root())
        .output()
        .expect("run watson");
    assert!(output.status.success());
    let envelope: serde_json::Value =
        serde_json::from_slice(&output.stdout).expect("valid JSON");
    let task = envelope["analyses"][0]["result"]["entry_points"]
        .as_array()
        .unwrap()
        .iter()
        .find(|ep| ep["kind"] == "symfony.periodic_task")
        .expect("periodic task entry point present");

    assert_eq!(task["extra"]["frequency"], "1 day");
}
