use std::path::Path;

use anyhow::Result;
use serde::Serialize;
use serde_json::json;

use crate::engine::{Engine, EntryPoint};
use crate::output::envelope::{AnalysisEntry, Context, Envelope};

pub const NAME: &str = "list-entrypoints";
pub const VERSION: &str = "0.1.0";

#[derive(Debug, Serialize)]
struct Result_ {
    entry_points: Vec<EntryPoint>,
}

pub fn run(engine: &dyn Engine, root: &Path) -> Result<Envelope> {
    let canonical_root = root.canonicalize().unwrap_or_else(|_| root.to_path_buf());
    let mut envelope = Envelope::new(
        "php",
        "symfony",
        Context { root: canonical_root.clone(), base: None, head: None },
    );

    let project = engine.analyze_project(root)?;

    let mut entry_points: Vec<EntryPoint> = project
        .entry_points
        .into_iter()
        .map(|mut ep| {
            // Make handler_path relative to the project root so snapshots are stable
            // across machines.
            if let Ok(rel) = ep.handler_path.strip_prefix(&project.root) {
                ep.handler_path = rel.to_path_buf();
            } else if let Ok(rel) = ep.handler_path.strip_prefix(&canonical_root) {
                ep.handler_path = rel.to_path_buf();
            }
            ep
        })
        .collect();
    entry_points.sort_by(|a, b| {
        (a.handler_path.as_path(), a.handler_line, a.kind.as_str(), a.name.as_str())
            .cmp(&(b.handler_path.as_path(), b.handler_line, b.kind.as_str(), b.name.as_str()))
    });

    let result = Result_ { entry_points };
    envelope.push(AnalysisEntry {
        name: NAME,
        version: VERSION,
        ok: true,
        result: Some(json!(result)),
        error: None,
    });

    Ok(envelope)
}
