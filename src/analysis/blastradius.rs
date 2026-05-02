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
    strict: bool,
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
    let mut reach = reverse_reach(&project, &changed_fqns);

    // File-level fallback (default). mago's static analyzer can't follow
    // interface dispatch (Laravel projects bind contracts to implementations
    // at the container, dispatch via `$this->repo->find()` where `$repo` is
    // an interface — invisible to a static call graph). Augment the call-
    // graph result with: any entry point whose handler *file* is in the
    // diff is also marked affected, with `NameOnly` confidence. Pass
    // `--strict` to drop the file-level matches entirely.
    if !strict {
        augment_file_level_reach(&mut reach, &project, &diffs, &changed_symbols);
    }

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

/// File-level augmentation. For every entry point whose handler file is in
/// the diff, ensure it appears in `reach.affected`. If reach already added
/// it via the call-graph path, leave the existing higher-confidence record
/// alone. Otherwise add it with an empty witness and `NameOnly`.
fn augment_file_level_reach(
    reach: &mut crate::graph::reach::ReachResult,
    project: &crate::engine::ProjectIndex,
    diffs: &[crate::git::diff::ChangedFile],
    changed_symbols: &[crate::diff::hunks::ChangedSymbol],
) {
    use crate::engine::Confidence;
    use crate::graph::reach::AffectedEntryPoint;
    use crate::util::canonicalize_or_self;
    use std::collections::HashSet;

    let changed_files: HashSet<std::path::PathBuf> =
        diffs.iter().map(|d| canonicalize_or_self(&d.path)).collect();

    let already: HashSet<usize> = reach.affected.iter().map(|a| a.entry_point_index).collect();

    let mut additions: Vec<AffectedEntryPoint> = Vec::new();
    for (idx, ep) in project.entry_points.iter().enumerate() {
        if already.contains(&idx) {
            continue;
        }
        let handler_canonical = canonicalize_or_self(&ep.handler_path);
        if changed_files.contains(&handler_canonical) {
            additions.push(AffectedEntryPoint {
                entry_point_index: idx,
                witness: Vec::new(),
                min_confidence: Confidence::NameOnly,
            });
        }
    }

    // Build per-changed-symbol affects entries for the file-level matches.
    // For each new ep, pair it with every changed symbol whose path equals
    // the handler's file — that's the "what change touched this" link.
    let symbol_paths: Vec<(String, std::path::PathBuf)> = changed_symbols
        .iter()
        .map(|c| (crate::util::normalize_identifier(&c.fqn), canonicalize_or_self(&c.path)))
        .collect();
    for add in &additions {
        if let Some(ep) = project.entry_points.get(add.entry_point_index) {
            let handler_canonical = canonicalize_or_self(&ep.handler_path);
            for (lower_fqn, sym_path) in &symbol_paths {
                if sym_path == &handler_canonical {
                    let bucket = reach.affects_by_changed.entry(lower_fqn.clone()).or_default();
                    if !bucket.contains(&add.entry_point_index) {
                        bucket.push(add.entry_point_index);
                    }
                }
            }
        }
    }

    reach.affected.extend(additions);
    reach.affected.sort_by_key(|a| a.entry_point_index);
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
