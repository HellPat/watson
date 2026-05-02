use std::path::Path;

use anyhow::{Context, Result};
use serde::Serialize;
use serde_json::json;

use crate::cli::{Framework, Verbosity};
use crate::diff::hunks::intersect_changed_symbols;
use crate::engine::{Confidence, Engine};
use crate::git::diff::diff;
use crate::git::spec::{DiffSpec, assert_head_matches_working_tree};
use crate::graph::reach::{reverse_reach, WitnessStep};
use crate::output::envelope::{AnalysisEntry, Context as Ctx, Envelope};
use crate::util::normalize_identifier;

pub const NAME: &str = "blastradius";
pub const VERSION: &str = "0.1.0";

#[derive(Debug, Serialize)]
struct Result_ {
    summary: Summary,
    /// Populated at `-v` (`Verbosity::WithChangedSymbols`) and above. At the
    /// default verbosity the field is omitted to keep the JSON / Markdown
    /// payload tight for LLM consumers.
    #[serde(skip_serializing_if = "Option::is_none")]
    changed_symbols: Option<Vec<ChangedSymbolOut>>,
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
    /// Which entry points this changed symbol transitively reaches.
    /// `ep_index` is an index into `affected_entry_points`.
    affects: Vec<AffectsRef>,
}

#[derive(Debug, Serialize)]
struct AffectsRef {
    kind: String,
    name: String,
    ep_index: usize,
}

#[derive(Debug, Serialize)]
struct AffectedEntryPointOut {
    kind: String,
    name: String,
    handler: HandlerOut,
    #[serde(skip_serializing_if = "serde_json::Value::is_null")]
    extra: serde_json::Value,
    /// Populated at `-vv` (`Verbosity::WithWitnessPaths`) only.
    #[serde(skip_serializing_if = "Option::is_none")]
    witness_path: Option<Vec<WitnessStepOut>>,
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
    verbosity: Verbosity,
) -> Result<Envelope> {
    let canonical_root = root
        .canonicalize()
        .with_context(|| format!("--root path does not exist or is unreadable: {}", root.display()))?;

    assert_head_matches_working_tree(&canonical_root, spec)?;

    let mut envelope = Envelope::new(
        "php",
        framework_label(framework),
        Ctx {
            root: canonical_root.clone(),
            base: Some(spec.base_display.clone()),
            head: Some(spec.head_display.clone()),
        },
    );

    let project = engine.analyze_project(&canonical_root)?;
    let diffs = diff(&canonical_root, spec)?;
    let changed_symbols = intersect_changed_symbols(&project, &diffs);

    let changed_fqns: Vec<String> = changed_symbols.iter().map(|c| c.fqn.clone()).collect();
    let reach = reverse_reach(&project, &changed_fqns);
    let affected = &reach.affected;

    // Map source ep_index -> position in the (possibly truncated/sorted)
    // affected list so `ep_index` in changed_symbols.affects[] matches.
    let ep_index_in_output: std::collections::HashMap<usize, usize> = affected
        .iter()
        .enumerate()
        .map(|(out_idx, a)| (a.entry_point_index, out_idx))
        .collect();

    let summary = Summary {
        files_changed: diffs.len(),
        symbols_changed: changed_symbols.len(),
        entry_points_affected: affected.len(),
    };

    let changed_symbols_out = if verbosity.includes_changed_symbols() {
        Some(
            changed_symbols
                .iter()
                .map(|c| ChangedSymbolOut {
                    fqn: c.fqn.clone(),
                    path: rel(&c.path, &canonical_root),
                    line_start: c.line_start,
                    line_end: c.line_end,
                    whole_file_gone: c.whole_file_gone,
                    affects: collect_affects(c, &reach, &ep_index_in_output, &project),
                })
                .collect(),
        )
    } else {
        None
    };

    let affected_entry_points = affected
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
                witness_path: if verbosity.includes_witness_paths() {
                    Some(a.witness.iter().map(witness_step_out).collect())
                } else {
                    None
                },
                min_confidence: a.min_confidence,
            })
        })
        .collect();

    let result = Result_ { summary, changed_symbols: changed_symbols_out, affected_entry_points };

    envelope.push(AnalysisEntry {
        name: NAME,
        version: VERSION,
        ok: true,
        result: Some(json!(result)),
        error: None,
    });

    Ok(envelope)
}

fn collect_affects(
    c: &crate::diff::hunks::ChangedSymbol,
    reach: &crate::graph::reach::ReachResult,
    ep_index_in_output: &std::collections::HashMap<usize, usize>,
    project: &crate::engine::ProjectIndex,
) -> Vec<AffectsRef> {
    let lower = normalize_identifier(&c.fqn);
    reach
        .affects_by_changed
        .get(&lower)
        .map(|eps| {
            eps.iter()
                .filter_map(|orig_idx| {
                    let out_idx = ep_index_in_output.get(orig_idx)?;
                    let ep = project.entry_points.get(*orig_idx)?;
                    Some(AffectsRef {
                        kind: ep.kind.clone(),
                        name: ep.name.clone(),
                        ep_index: *out_idx,
                    })
                })
                .collect()
        })
        .unwrap_or_default()
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
