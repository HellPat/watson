use std::path::PathBuf;

use watson::engine::php::PhpEngine;
use watson::engine::{Engine, SymbolKind};

fn fixture_root() -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/symfony-micro/baseline")
}

fn run() -> watson::engine::ProjectIndex {
    let engine = PhpEngine::new();
    engine.analyze_project(&fixture_root()).expect("analyze fixture")
}

#[test]
fn finds_class_definitions() {
    let idx = run();
    let class_fqns: Vec<&str> = idx
        .symbols
        .iter()
        .filter(|s| s.kind == SymbolKind::Class)
        .map(|s| s.fqn.as_str())
        .collect();

    for expected in [
        "App\\Service\\Greeter",
        "App\\Controller\\GreetController",
        "App\\Command\\GreetCommand",
        "App\\Message\\Ping",
        "App\\MessageHandler\\PingHandler",
        "App\\Schedule\\CleanupTask",
        "App\\Kernel",
    ] {
        assert!(
            class_fqns.iter().any(|fqn| fqn.eq_ignore_ascii_case(expected)),
            "missing class {expected}; found: {class_fqns:?}"
        );
    }
}

#[test]
fn finds_method_definitions() {
    let idx = run();
    let method_fqns: Vec<&str> = idx
        .symbols
        .iter()
        .filter(|s| s.kind == SymbolKind::Method)
        .map(|s| s.fqn.as_str())
        .collect();

    for expected in [
        "App\\Service\\Greeter::format",
        "App\\Controller\\GreetController::show",
        "App\\Controller\\GreetController::__construct",
        "App\\Command\\GreetCommand::execute",
        "App\\MessageHandler\\PingHandler::__invoke",
        "App\\Schedule\\CleanupTask::__invoke",
    ] {
        assert!(
            method_fqns.iter().any(|fqn| fqn.eq_ignore_ascii_case(expected)),
            "missing method {expected}; found {} method symbols",
            method_fqns.len()
        );
    }
}

#[test]
fn symbol_paths_point_inside_fixture() {
    let idx = run();
    assert!(!idx.symbols.is_empty(), "expected at least one symbol");
    for sym in &idx.symbols {
        assert!(
            sym.path.starts_with(&idx.root),
            "symbol path {} should start with root {}",
            sym.path.display(),
            idx.root.display()
        );
        assert!(sym.line_start >= 1, "1-based line numbering");
        assert!(sym.line_end >= sym.line_start);
    }
}
