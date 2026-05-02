use std::path::PathBuf;
use std::process::Command;

fn fixture_root() -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/symfony-micro/baseline")
}

fn binary() -> PathBuf {
    PathBuf::from(env!("CARGO_BIN_EXE_watson"))
}

fn run(format: &str) -> String {
    let output = Command::new(binary())
        .args(["list-entrypoints", "--format", format, "--root"])
        .arg(fixture_root())
        .output()
        .expect("spawn watson");
    assert!(
        output.status.success(),
        "watson exited non-zero. stderr: {}",
        String::from_utf8_lossy(&output.stderr)
    );
    String::from_utf8(output.stdout).expect("utf-8 stdout")
}

#[test]
fn json_format_is_machine_readable() {
    let stdout = run("json");
    let v: serde_json::Value = serde_json::from_str(&stdout).expect("valid JSON");
    assert_eq!(v["tool"], "watson");
}

#[test]
fn markdown_format_has_canonical_sections() {
    let stdout = run("md");
    assert!(stdout.starts_with("# watson — php symfony"), "missing top heading: {stdout}");
    assert!(stdout.contains("## list-entrypoints"));
    assert!(stdout.contains("**8 entry points**"));
    // Routes get a Markdown table.
    assert!(stdout.contains("| kind | name | handler |"));
    // Symfony attribute kinds appear by FQN.
    assert!(stdout.contains("symfony.route"));
    assert!(stdout.contains("symfony.command"));
    assert!(stdout.contains("symfony.message_handler"));
    assert!(stdout.contains("symfony.periodic_task"));
}

#[test]
fn text_format_is_terminal_friendly() {
    let stdout = run("text");
    // Header bar + label.
    assert!(stdout.contains("watson php symfony"));
    assert!(stdout.contains("[list-entrypoints]"));
    assert!(stdout.contains("8 entry point(s):"));
    // No HTML/Markdown decoration.
    assert!(!stdout.contains("```"));
    assert!(!stdout.contains("|---|"));
    assert!(!stdout.starts_with("#"));
    // Specific entries from the fixture.
    assert!(stdout.contains("symfony.route"));
    assert!(stdout.contains("greet_show"));
    assert!(stdout.contains("app:greet"));
}

#[test]
fn blastradius_md_renders_witness_path() {
    // Build a minimal git repo from the fixture so blastradius has something
    // to diff. Use the baseline as both base and head with a touched file in
    // between to get a deterministic result.
    let base_dir = fixture_root();
    let tmp = tempfile::TempDir::new().expect("tempdir");
    copy_tree(&base_dir, tmp.path());

    // Initialise + commit.
    git(tmp.path(), &["init", "-q", "-b", "main"]);
    git(tmp.path(), &["config", "user.email", "x@y.z"]);
    git(tmp.path(), &["config", "user.name", "x"]);
    git(tmp.path(), &["add", "-A"]);
    git(tmp.path(), &["commit", "-q", "-m", "base"]);
    let base_sha = capture(tmp.path(), &["rev-parse", "HEAD"]);

    // Touch the service.
    let target = tmp.path().join("src/Service/Greeter.php");
    let s = std::fs::read_to_string(&target).unwrap();
    let s = s.replace("Hello, %s!", "Greetings, %s!");
    std::fs::write(&target, s).unwrap();

    git(tmp.path(), &["add", "-A"]);
    git(tmp.path(), &["commit", "-q", "-m", "edit"]);
    let head_sha = capture(tmp.path(), &["rev-parse", "HEAD"]);

    let output = Command::new(binary())
        .args([
            "blastradius",
            "-vv",
            &format!("{}..{}", base_sha, head_sha),
            "--format",
            "md",
            "--root",
        ])
        .arg(tmp.path())
        .output()
        .expect("spawn");
    assert!(output.status.success());
    let stdout = String::from_utf8(output.stdout).unwrap();

    assert!(stdout.contains("## blastradius"));
    assert!(stdout.contains("**Summary**"));
    // 7 handlers reach Greeter::format directly:
    //   GreetController::show, GreetCommand::execute, PingCommand::execute,
    //   PingHandler::__invoke, LegacyHandler::__invoke,
    //   CleanupTask::__invoke, AppSchedule::getSchedule
    // (PingSubscriber's *handler* in v0.2 is getSubscribedEvents, which
    // doesn't call Greeter::format — its onKernelRequest body does.)
    assert!(
        stdout.contains("### Affected entry points (7)"),
        "expected 7 affected entry points; output:\n{stdout}"
    );
    // Markdown code-fenced witness blocks.
    assert!(stdout.contains("Witness path:"));
    assert!(stdout.contains("```text"));
    // Specific entry-point kinds.
    // Per-kind grouping: kind appears as a section heading.
    assert!(stdout.contains("#### symfony.route ("));
    assert!(stdout.contains("##### greet_show"));
}

fn copy_tree(src: &std::path::Path, dst: &std::path::Path) {
    let mut stack: Vec<(PathBuf, PathBuf)> = vec![(src.to_path_buf(), dst.to_path_buf())];
    while let Some((from, to)) = stack.pop() {
        if from.is_dir() {
            std::fs::create_dir_all(&to).unwrap();
            for entry in std::fs::read_dir(&from).unwrap() {
                let entry = entry.unwrap();
                stack.push((entry.path(), to.join(entry.file_name())));
            }
        } else if from.is_file() {
            if let Some(p) = to.parent() {
                std::fs::create_dir_all(p).unwrap();
            }
            std::fs::copy(&from, &to).unwrap();
        }
    }
}

fn git(cwd: &std::path::Path, args: &[&str]) {
    let out = Command::new("git").current_dir(cwd).args(args).output().expect("git");
    assert!(out.status.success(), "git {:?} failed: {}", args, String::from_utf8_lossy(&out.stderr));
}

fn capture(cwd: &std::path::Path, args: &[&str]) -> String {
    let out = Command::new("git").current_dir(cwd).args(args).output().expect("git");
    String::from_utf8(out.stdout).unwrap().trim().to_string()
}
