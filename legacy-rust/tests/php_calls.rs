use std::path::PathBuf;

use watson::engine::php::PhpEngine;
use watson::engine::Engine;

fn fixture_root() -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/symfony-micro/baseline")
}

#[test]
fn analyzer_populates_call_edges() {
    let engine = PhpEngine::new();
    let idx = engine.analyze_project(&fixture_root()).expect("analyze");

    assert!(
        !idx.edges.is_empty(),
        "expected at least one call edge from the analyzer pass"
    );

    // Collect (from_fqn, to_fqn) pairs for case-insensitive matching: PHP is
    // case-insensitive on function/method names so mago lower-cases members
    // when interning. We compare with lower-cased expectations.
    let lowered: Vec<(String, String)> = idx
        .edges
        .iter()
        .map(|e| (e.from_fqn.to_lowercase(), e.to_fqn.to_lowercase()))
        .collect();

    let to_format = "app\\service\\greeter::format";

    let callers_of_format: Vec<&String> = lowered
        .iter()
        .filter(|(_, to)| to == to_format)
        .map(|(from, _)| from)
        .collect();

    for expected_caller in [
        "app\\controller\\greetcontroller::show",
        "app\\command\\greetcommand::execute",
        "app\\messagehandler\\pinghandler::__invoke",
        "app\\schedule\\cleanuptask::__invoke",
    ] {
        assert!(
            callers_of_format.iter().any(|c| c.as_str() == expected_caller),
            "expected {expected_caller} -> {to_format} edge; got callers of format: {callers_of_format:?}"
        );
    }
}
