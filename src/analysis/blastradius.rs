use std::path::Path;

use anyhow::Result;
use serde::Serialize;
use serde_json::json;

use crate::cli::Framework;
use crate::diff::hunks::intersect_changed_symbols;
use crate::engine::{Confidence, Engine, EntryPoint};
use crate::git::diff::diff;
use crate::git::spec::{DiffSpec, assert_head_matches_working_tree};
use crate::graph::reach::{reverse_reach, AffectedEntryPoint, WitnessStep};
use crate::output::envelope::{AnalysisEntry, Context, Envelope};

pub const NAME: &str = "blastradius";
pub const VERSION: &str = "0.1.0";

#[derive(Debug, Serialize)]
struct Result_ {
    summary: Summary,
    changed_symbols: Vec<ChangedSymbolOut>,
    affected_entry_points: Vec<AffectedEntryPointOut>,
}

#[derive(Debug, Serialize)]
struct Summary {
    files_changed: usize,
    symbols_changed: usize,
    entry_points_affected: usize,
}

#[derive(Debug, Serialize)]
struct ChangedSymbolOut {
    fqn: String,
    path: String,
    line_start: u32,
    line_end: u32,
    whole_file_gone: bool,
}

#[derive(Debug, Serialize)]
struct AffectedEntryPointOut {
    kind: String,
    name: String,
    handler: HandlerOut,
    #[serde(skip_serializing_if = "serde_json::Value::is_null")]
    extra: serde_json::Value,
    witness_path: Vec<WitnessStepOut>,
    min_confidence: Confidence,
}

#[derive(Debug, Serialize)]
struct HandlerOut {
    fqn: String,
    path: String,
    line: u32,
}

#[derive(Debug, Serialize)]
struct WitnessStepOut {
    from: String,
    to: String,
    site: String,
    confidence: Confidence,
}

pub fn run(
    engine: &dyn Engine,
    root: &Path,
    spec: &DiffSpec,
    framework: Framework,
) -> Result<Envelope> {
    let canonical_root = root.canonicalize().unwrap_or_else(|_| root.to_path_buf());

    assert_head_matches_working_tree(&canonical_root, spec)?;

    let mut envelope = Envelope::new(
        "php",
        framework_label(framework),
        Context {
            root: canonical_root.clone(),
            base: Some(spec.base_display.clone()),
            head: Some(spec.head_display.clone()),
        },
    );

    let project = engine.analyze_project(&canonical_root)?;
    let diffs = diff(&canonical_root, spec)?;
    let changed_symbols = intersect_changed_symbols(&project, &diffs);

    let changed_fqns: Vec<String> = changed_symbols.iter().map(|c| c.fqn.clone()).collect();
    let affected = reverse_reach(&project, &changed_fqns);

    let result = Result_ {
        summary: Summary {
            files_changed: diffs.len(),
            symbols_changed: changed_symbols.len(),
            entry_points_affected: affected.len(),
        },
        changed_symbols: changed_symbols
            .iter()
            .map(|c| ChangedSymbolOut {
                fqn: c.fqn.clone(),
                path: rel(&c.path, &canonical_root),
                line_start: c.line_start,
                line_end: c.line_end,
                whole_file_gone: c.whole_file_gone,
            })
            .collect(),
        affected_entry_points: affected
            .iter()
            .filter_map(|a| {
                project.entry_points.get(a.entry_point_index).map(|ep| AffectedEntryPointOut {
                    kind: ep.kind.clone(),
                    name: ep.name.clone(),
                    handler: HandlerOut {
                        fqn: ep.handler_fqn.clone(),
                        path: rel(&ep.handler_path, &canonical_root),
                        line: ep.handler_line,
                    },
                    extra: ep.extra.clone(),
                    witness_path: a.witness.iter().map(witness_step_out).collect(),
                    min_confidence: a.min_confidence,
                })
            })
            .collect(),
    };

    envelope.push(AnalysisEntry {
        name: NAME,
        version: VERSION,
        ok: true,
        result: Some(json!(result)),
        error: None,
    });

    Ok(envelope)
}

fn witness_step_out(s: &WitnessStep) -> WitnessStepOut {
    WitnessStepOut {
        from: s.from_fqn.clone(),
        to: s.to_fqn.clone(),
        site: if s.site_line > 0 {
            format!("{}:{}", s.site_path, s.site_line)
        } else {
            s.site_path.clone()
        },
        confidence: s.confidence,
    }
}

fn rel(p: &Path, root: &Path) -> String {
    p.strip_prefix(root).unwrap_or(p).display().to_string()
}

fn framework_label(framework: Framework) -> &'static str {
    match framework {
        Framework::Symfony => "symfony",
        Framework::Laravel => "laravel",
    }
}

// Keep handler/EntryPoint types referenced so they're in scope for tests.
#[allow(dead_code)]
fn _ep_marker(_: &EntryPoint, _: &AffectedEntryPoint) {}
