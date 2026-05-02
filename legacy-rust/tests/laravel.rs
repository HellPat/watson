use std::path::PathBuf;
use std::process::Command;

fn fixture_root() -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/laravel-micro/baseline")
}

fn binary() -> PathBuf {
    PathBuf::from(env!("CARGO_BIN_EXE_watson"))
}

fn list_entrypoints() -> serde_json::Value {
    let output = Command::new(binary())
        .args(["list-entrypoints", "--root"])
        .arg(fixture_root())
        .output()
        .expect("spawn watson");
    assert!(
        output.status.success(),
        "watson exited non-zero. stderr: {}",
        String::from_utf8_lossy(&output.stderr)
    );
    serde_json::from_slice(&output.stdout).expect("envelope JSON")
}

#[test]
fn auto_detects_laravel_framework() {
    let envelope = list_entrypoints();
    assert_eq!(envelope["framework"], "laravel");
}

#[test]
fn detects_all_core_laravel_kinds() {
    let envelope = list_entrypoints();
    let eps = envelope["analyses"][0]["result"]["entry_points"]
        .as_array()
        .expect("entry_points array");

    let kinds: std::collections::HashSet<&str> = eps
        .iter()
        .map(|ep| ep["kind"].as_str().unwrap_or(""))
        .collect();

    for required in [
        "laravel.route",
        "laravel.command",
        "laravel.job",
        "laravel.listener",
        "laravel.scheduled_task",
    ] {
        assert!(
            kinds.contains(required),
            "missing kind {required}; got {kinds:?}"
        );
    }
}

#[test]
fn detects_artisan_command_class_with_signature() {
    let envelope = list_entrypoints();
    let eps = envelope["analyses"][0]["result"]["entry_points"]
        .as_array()
        .unwrap();

    let cmd = eps
        .iter()
        .find(|ep| ep["kind"] == "laravel.command" && ep["name"] == "app:ping")
        .expect("app:ping command detected");
    assert_eq!(cmd["source"], "interface");
    assert_eq!(
        cmd["handler_fqn"].as_str().unwrap(),
        "App\\Console\\Commands\\PingCommand::handle"
    );
}

#[test]
fn detects_closure_artisan_command() {
    let envelope = list_entrypoints();
    let eps = envelope["analyses"][0]["result"]["entry_points"]
        .as_array()
        .unwrap();

    let cmd = eps
        .iter()
        .find(|ep| ep["kind"] == "laravel.command" && ep["name"] == "app:hello")
        .expect("app:hello closure command detected");
    assert_eq!(cmd["source"], "static-call");
}

#[test]
fn detects_should_queue_job() {
    let envelope = list_entrypoints();
    let eps = envelope["analyses"][0]["result"]["entry_points"]
        .as_array()
        .unwrap();

    let job = eps
        .iter()
        .find(|ep| {
            ep["kind"] == "laravel.job"
                && ep["handler_fqn"].as_str().unwrap_or("").ends_with("PingJob::handle")
        })
        .expect("PingJob::handle detected");
    assert_eq!(job["source"], "interface");
}

#[test]
fn detects_listener_via_namespace_convention() {
    let envelope = list_entrypoints();
    let eps = envelope["analyses"][0]["result"]["entry_points"]
        .as_array()
        .unwrap();

    let listener = eps
        .iter()
        .find(|ep| ep["kind"] == "laravel.listener")
        .expect("App\\Listeners\\* listener detected");
    let event = listener["extra"]["event"].as_str().unwrap_or("");
    assert!(
        event.ends_with("PingEvent"),
        "listener should record its event class via the handle param; got {event}"
    );
}

#[test]
fn detects_route_with_array_action() {
    let envelope = list_entrypoints();
    let eps = envelope["analyses"][0]["result"]["entry_points"]
        .as_array()
        .unwrap();

    let route = eps
        .iter()
        .find(|ep| {
            ep["kind"] == "laravel.route"
                && ep["handler_fqn"].as_str().unwrap_or("").ends_with("HomeController::index")
        })
        .expect("Route::get('/', [HomeController::class, 'index']) detected");
    assert_eq!(route["extra"]["path"], "/");
    assert_eq!(route["extra"]["methods"][0], "GET");
}

#[test]
fn detects_invokable_controller_route() {
    let envelope = list_entrypoints();
    let eps = envelope["analyses"][0]["result"]["entry_points"]
        .as_array()
        .unwrap();

    eps.iter()
        .find(|ep| {
            ep["kind"] == "laravel.route"
                && ep["handler_fqn"].as_str().unwrap_or("").ends_with("PingController::__invoke")
        })
        .expect("invokable controller route detected");
}

#[test]
fn detects_string_action_route() {
    let envelope = list_entrypoints();
    let eps = envelope["analyses"][0]["result"]["entry_points"]
        .as_array()
        .unwrap();

    eps.iter()
        .find(|ep| {
            ep["kind"] == "laravel.route"
                && ep["name"].as_str().unwrap_or("").ends_with("HomeController::about")
        })
        .expect("'Class@method' string action route detected");
}

#[test]
fn detects_schedule_command_and_job() {
    let envelope = list_entrypoints();
    let eps = envelope["analyses"][0]["result"]["entry_points"]
        .as_array()
        .unwrap();

    let scheduled: Vec<&str> = eps
        .iter()
        .filter(|ep| ep["kind"] == "laravel.scheduled_task")
        .filter_map(|ep| ep["name"].as_str())
        .collect();

    assert!(scheduled.iter().any(|n| n.contains("app:ping")));
    assert!(scheduled.iter().any(|n| n.contains("PingJob")));
}
