use std::collections::{HashMap, HashSet, VecDeque};

use crate::engine::{CallEdge, Confidence, EntryPoint, ProjectIndex};

/// One step on a witness path: caller -> callee at a specific site.
#[derive(Debug, Clone)]
pub struct WitnessStep {
    pub from_fqn: String,
    pub to_fqn: String,
    pub confidence: Confidence,
    pub site_path: String,
    pub site_line: u32,
}

/// Result of reverse-reach from a set of changed symbol FQNs through the
/// project's reverse call graph. Each entry-point handler that reaches a
/// changed symbol is reported once with the shortest witness path and the
/// minimum confidence along that path.
#[derive(Debug, Clone)]
pub struct AffectedEntryPoint {
    pub entry_point_index: usize,
    pub witness: Vec<WitnessStep>,
    pub min_confidence: Confidence,
}

/// Result of `reverse_reach`. `affected` is the per-entry-point list (with
/// witness paths). `affects_by_changed` is the inverse mapping: for each
/// changed FQN, which entry points it reaches. Both views derive from the
/// same BFS pass.
pub struct ReachResult {
    pub affected: Vec<AffectedEntryPoint>,
    pub affects_by_changed: HashMap<String, Vec<usize>>,
}

pub fn reverse_reach(
    project: &ProjectIndex,
    changed_fqns: &[String],
) -> ReachResult {
    if changed_fqns.is_empty() || project.entry_points.is_empty() {
        return ReachResult { affected: Vec::new(), affects_by_changed: HashMap::new() };
    }

    let changed_set: HashSet<String> = changed_fqns.iter().map(|f| normalise(f)).collect();

    // callee -> [edge_index] (via to_fqn). Forward index built once.
    let mut callee_to_edges: HashMap<String, Vec<usize>> = HashMap::new();
    for (idx, e) in project.edges.iter().enumerate() {
        callee_to_edges.entry(normalise(&e.to_fqn)).or_default().push(idx);
    }

    // handler_fqn (normalised) -> [entry_point_index]. One handler may map to
    // multiple entry points (rare but possible — same handler with two routes).
    let mut handler_to_eps: HashMap<String, Vec<usize>> = HashMap::new();
    for (idx, ep) in project.entry_points.iter().enumerate() {
        handler_to_eps.entry(normalise(&ep.handler_fqn)).or_default().push(idx);
    }

    let mut affected: HashMap<usize, AffectedEntryPoint> = HashMap::new();
    let mut affects_by_changed: HashMap<String, Vec<usize>> = HashMap::new();

    // Reverse-BFS from every changed symbol. The frontier walks "callers of"
    // the current node by following back-edges (we have callee -> callers via
    // callee_to_edges since each edge stores from -> to where to is the callee).
    for changed_fqn in &changed_set {
        let mut visited: HashSet<String> = HashSet::new();
        let mut parent_edge: HashMap<String, usize> = HashMap::new(); // node -> edge-idx that led to it
        let mut queue: VecDeque<String> = VecDeque::new();

        visited.insert(changed_fqn.clone());
        queue.push_back(changed_fqn.clone());

        while let Some(node) = queue.pop_front() {
            // Did this node represent an entry-point handler? (May be the
            // changed symbol itself — when the change lands directly inside
            // a handler, that handler is affected with an empty witness.)
            if let Some(ep_indices) = handler_to_eps.get(&node) {
                let witness = if &node == changed_fqn {
                    Vec::new()
                } else {
                    reconstruct_witness(&node, &parent_edge, &project.edges, changed_fqn)
                };
                let min_conf = witness
                    .iter()
                    .map(|w| w.confidence)
                    .min()
                    .unwrap_or(Confidence::Confirmed);

                for &ep_idx in ep_indices {
                    affected
                        .entry(ep_idx)
                        .and_modify(|existing| {
                            if witness.len() < existing.witness.len()
                                || (witness.len() == existing.witness.len()
                                    && min_conf > existing.min_confidence)
                            {
                                existing.witness = witness.clone();
                                existing.min_confidence = min_conf;
                            }
                        })
                        .or_insert(AffectedEntryPoint {
                            entry_point_index: ep_idx,
                            witness: witness.clone(),
                            min_confidence: min_conf,
                        });

                    // Track inverse: which changed symbol reached this ep.
                    let bucket = affects_by_changed.entry(changed_fqn.clone()).or_default();
                    if !bucket.contains(&ep_idx) {
                        bucket.push(ep_idx);
                    }
                }
            }

            // Expand: callers of `node`. Edges where to_fqn == node give us caller_fqn = from_fqn.
            if let Some(edge_indices) = callee_to_edges.get(&node) {
                for &eid in edge_indices {
                    let edge = &project.edges[eid];
                    let caller = normalise(&edge.from_fqn);
                    if visited.insert(caller.clone()) {
                        parent_edge.insert(caller.clone(), eid);
                        queue.push_back(caller);
                    }
                }
            }
        }
    }

    // Stable order: by entry_point_index.
    let mut out: Vec<AffectedEntryPoint> = affected.into_values().collect();
    out.sort_by_key(|a| a.entry_point_index);

    // Sort each affects_by_changed bucket for deterministic output.
    for bucket in affects_by_changed.values_mut() {
        bucket.sort_unstable();
    }

    ReachResult { affected: out, affects_by_changed }
}

/// Reconstruct the witness path from a handler node back to the changed symbol
/// using the parent_edge map populated during BFS. Resulting steps go
/// caller-most first (the handler itself) and finish at the changed symbol.
fn reconstruct_witness(
    handler: &str,
    parent_edge: &HashMap<String, usize>,
    edges: &[CallEdge],
    changed_fqn: &str,
) -> Vec<WitnessStep> {
    let mut steps: Vec<WitnessStep> = Vec::new();
    let mut node = handler.to_string();
    while node != changed_fqn {
        let Some(&eid) = parent_edge.get(&node) else {
            break;
        };
        let edge = &edges[eid];
        steps.push(WitnessStep {
            from_fqn: edge.from_fqn.clone(),
            to_fqn: edge.to_fqn.clone(),
            confidence: edge.confidence,
            site_path: edge.site_path.display().to_string(),
            site_line: edge.site_line,
        });
        node = normalise(&edge.to_fqn);
    }
    steps
}

#[inline]
fn normalise(fqn: &str) -> String {
    // PHP is case-insensitive on class/function/method names. Mago normalises
    // to lowercase internally; we follow suit for matching while preserving the
    // original case in the displayed output (witness steps store the original).
    fqn.to_lowercase()
}

/// Look up an entry point by index — used by callers building the JSON output.
pub fn entry_point(project: &ProjectIndex, idx: usize) -> Option<&EntryPoint> {
    project.entry_points.get(idx)
}
