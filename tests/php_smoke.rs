use std::path::PathBuf;

use watson::engine::php::parse::parse_smoke;

fn fixture(rel: &str) -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR"))
        .join("tests/fixtures/symfony-micro/baseline")
        .join(rel)
}

#[test]
fn parse_greet_controller() {
    let path = fixture("src/Controller/GreetController.php");
    let n = parse_smoke(&path).expect("controller fixture parses cleanly");
    assert!(n > 0, "expected at least one top-level statement, got {}", n);
}

#[test]
fn parse_greeter_service() {
    let path = fixture("src/Service/Greeter.php");
    let n = parse_smoke(&path).expect("service fixture parses cleanly");
    assert!(n > 0, "expected at least one top-level statement, got {}", n);
}

#[test]
fn parse_greet_command() {
    let path = fixture("src/Command/GreetCommand.php");
    let n = parse_smoke(&path).expect("command fixture parses cleanly");
    assert!(n > 0, "expected at least one top-level statement, got {}", n);
}

#[test]
fn parse_ping_handler() {
    let path = fixture("src/MessageHandler/PingHandler.php");
    let n = parse_smoke(&path).expect("message handler fixture parses cleanly");
    assert!(n > 0, "expected at least one top-level statement, got {}", n);
}

#[test]
fn parse_cleanup_task() {
    let path = fixture("src/Schedule/CleanupTask.php");
    let n = parse_smoke(&path).expect("scheduled task fixture parses cleanly");
    assert!(n > 0, "expected at least one top-level statement, got {}", n);
}
