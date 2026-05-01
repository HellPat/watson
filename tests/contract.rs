//! Engine-contract tests.
//!
//! These run the engine-agnostic algorithms (`graph::reach`,
//! `diff::hunks::intersect_changed_symbols`) against a fabricated `NoopEngine`
//! that has nothing to do with PHP. If reverse-reach can find affected entry
//! points purely via neutral types, then dropping in a TypeScript or Go engine
//! later will not require touching the algorithms — they cannot accidentally
//! depend on PHP-isms because they never see them in this test.
//!
//! The boundary lint at the bottom enforces this statically: it scans
//! `src/graph`, `src/diff`, and `src/output` for forbidden tokens.

use std::path::PathBuf;

use watson::diff::hunks::intersect_changed_symbols;
use watson::engine::{
    CallEdge, Confidence, Engine, EntryPoint, ProjectIndex, Symbol, SymbolKind,
};
use watson::git::diff::{ChangedFile, ChangeStatus, LineRange};
use watson::graph::reach::reverse_reach;

/// Fabricated engine for a tiny imaginary language. Knows nothing of PHP.
struct NoopEngine {
    fixture: ProjectIndex,
}

impl Engine for NoopEngine {
    fn lang_id(&self) -> &'static str {
        "noop"
    }

    fn extensions(&self) -> &'static [&'static str] {
        &["noop"]
    }

    fn analyze_project(&self, _root: &std::path::Path) -> anyhow::Result<ProjectIndex> {
        Ok(self.fixture.clone())
    }
}

fn fab_index(root: &str) -> ProjectIndex {
    // Topology:
    //
    //   entry: web/router.noop:greet  (handler = ::handle)
    //   entry: jobs/cleanup.noop:scrub (handler = ::run)
    //
    //   ::handle  -> service::format
    //   ::run     -> service::format
    //   service::format -> service::lower
    //
    // If service::format changes, both handlers should be reported.
    // If service::lower changes, both handlers should be reported (transitive).
    let mk_sym = |fqn: &str, kind, path: &str, ls, le| Symbol {
        fqn: fqn.to_string(),
        kind,
        path: PathBuf::from(path),
        line_start: ls,
        line_end: le,
    };

    let mk_edge = |from: &str, to: &str| CallEdge {
        from_fqn: from.to_string(),
        to_fqn: to.to_string(),
        site_path: PathBuf::new(),
        site_line: 0,
        confidence: Confidence::Confirmed,
    };

    ProjectIndex {
        root: PathBuf::from(root),
        symbols: vec![
            mk_sym("web::handle", SymbolKind::Method, "web/router.noop", 1, 10),
            mk_sym("jobs::run", SymbolKind::Method, "jobs/cleanup.noop", 1, 10),
            mk_sym("service::format", SymbolKind::Method, "service.noop", 1, 10),
            mk_sym("service::lower", SymbolKind::Method, "service.noop", 12, 20),
        ],
        edges: vec![
            mk_edge("web::handle", "service::format"),
            mk_edge("jobs::run", "service::format"),
            mk_edge("service::format", "service::lower"),
        ],
        entry_points: vec![
            EntryPoint {
                kind: "noop.web".to_string(),
                name: "greet".to_string(),
                handler_fqn: "web::handle".to_string(),
                handler_path: PathBuf::from("web/router.noop"),
                handler_line: 1,
                extra: serde_json::Value::Null,
            },
            EntryPoint {
                kind: "noop.job".to_string(),
                name: "scrub".to_string(),
                handler_fqn: "jobs::run".to_string(),
                handler_path: PathBuf::from("jobs/cleanup.noop"),
                handler_line: 1,
                extra: serde_json::Value::Null,
            },
        ],
        imports_per_file: Vec::new(),
    }
}

#[test]
fn reverse_reach_works_for_noop_engine() {
    let index = fab_index("/imaginary/project");
    let affected = reverse_reach(&index, &["service::format".to_string()]);

    assert_eq!(affected.len(), 2, "both entry points should be affected");
    let kinds: Vec<&str> = affected
        .iter()
        .map(|a| index.entry_points[a.entry_point_index].kind.as_str())
        .collect();
    assert!(kinds.contains(&"noop.web"));
    assert!(kinds.contains(&"noop.job"));
}

#[test]
fn reverse_reach_walks_transitively() {
    let index = fab_index("/imaginary/project");
    let affected = reverse_reach(&index, &["service::lower".to_string()]);

    assert_eq!(affected.len(), 2, "both entry points reach lower transitively");
    for aep in &affected {
        assert_eq!(aep.witness.len(), 2, "two-hop witness path expected");
    }
}

#[test]
fn intersect_changed_symbols_is_engine_agnostic() {
    let index = fab_index("/imaginary/project");
    let diffs = vec![ChangedFile {
        path: PathBuf::from("service.noop"),
        status: ChangeStatus::Modified,
        hunks: vec![LineRange { start: 5, end_exclusive: 6 }],
    }];

    let changed = intersect_changed_symbols(&index, &diffs);
    let fqns: Vec<&str> = changed.iter().map(|c| c.fqn.as_str()).collect();
    assert!(fqns.contains(&"service::format"), "format spans line 5; got {fqns:?}");
    assert!(!fqns.contains(&"service::lower"), "lower starts at line 12, should not match");
}

#[test]
fn changed_engine_handler_is_self_affected() {
    // When the changed symbol IS an entry-point handler, reach reports it
    // with an empty witness path. Pure logic, no engine involved.
    let index = fab_index("/imaginary/project");
    let affected = reverse_reach(&index, &["web::handle".to_string()]);

    assert_eq!(affected.len(), 1);
    assert!(affected[0].witness.is_empty());
}

#[test]
fn engine_trait_works_for_noop_engine() {
    // Exercise the Engine trait against the fake impl — the contract is
    // structural, but proving NoopEngine satisfies it ensures we don't
    // accidentally bake PHP-specific bounds into the trait.
    let engine = NoopEngine { fixture: fab_index("/imaginary/project") };
    let project = engine.analyze_project(std::path::Path::new("/imaginary")).unwrap();
    assert_eq!(engine.lang_id(), "noop");
    assert_eq!(engine.extensions(), &["noop"]);
    assert_eq!(project.entry_points.len(), 2);
    assert_eq!(project.symbols.len(), 4);
    assert_eq!(project.edges.len(), 3);
}

// ---- boundary lint ---------------------------------------------------------

#[test]
fn engine_agnostic_modules_have_no_php_or_mago_isms() {
    let manifest = PathBuf::from(env!("CARGO_MANIFEST_DIR"));
    let forbidden_tokens = [
        "mago_",
        "PhpEngine",
        "\"php\"",
        "\"symfony\"",
        "Symfony\\\\",
    ];
    let dirs = ["src/graph", "src/diff", "src/output"];

    let mut leaks: Vec<String> = Vec::new();
    for d in dirs {
        let dir = manifest.join(d);
        let entries = walk_files(&dir);
        for path in entries {
            let content = std::fs::read_to_string(&path).expect("read file");
            for tok in forbidden_tokens {
                if content.contains(tok) {
                    leaks.push(format!(
                        "{} contains forbidden token `{}`",
                        path.strip_prefix(&manifest).unwrap_or(&path).display(),
                        tok
                    ));
                }
            }
        }
    }

    assert!(
        leaks.is_empty(),
        "engine-specific tokens leaked into the engine-agnostic layer:\n  {}",
        leaks.join("\n  ")
    );
}

fn walk_files(dir: &std::path::Path) -> Vec<PathBuf> {
    let mut out = Vec::new();
    let mut stack = vec![dir.to_path_buf()];
    while let Some(d) = stack.pop() {
        if let Ok(entries) = std::fs::read_dir(&d) {
            for entry in entries.flatten() {
                let p = entry.path();
                if p.is_dir() {
                    stack.push(p);
                } else if p.extension().and_then(|s| s.to_str()) == Some("rs") {
                    out.push(p);
                }
            }
        }
    }
    out
}
