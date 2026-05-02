use std::io::Write;

use anyhow::Result;
use serde_json::Value;

use crate::output::envelope::Envelope;
use crate::output::group_by_kind;
use crate::util::short;

/// Render an envelope as plain ASCII text for terminals. Stripped down vs.
/// Markdown — no tables, indentation rather than headings, no code fences.
/// Stays useful through pagers and pipes (`watson ... | less`).
pub fn write_envelope<W: Write>(mut w: W, envelope: &Envelope) -> Result<()> {
    let serialized = serde_json::to_value(envelope)?;
    let header = format!(
        "watson {} {} (root: {})",
        envelope.language,
        envelope.framework,
        envelope.context.root.display(),
    );
    let bar: String = "=".repeat(header.len().min(80));
    writeln!(w, "{}", bar)?;
    writeln!(w, "{}", header)?;
    if let (Some(base), Some(head)) = (&envelope.context.base, &envelope.context.head) {
        writeln!(w, "diff: {} -> {}", short(base), short(head))?;
    }
    writeln!(w, "{}", bar)?;
    writeln!(w)?;

    let analyses = serialized["analyses"].as_array().cloned().unwrap_or_default();
    for analysis in &analyses {
        let name = analysis["name"].as_str().unwrap_or("?");
        let ok = analysis["ok"].as_bool().unwrap_or(false);
        writeln!(w, "[{}]{}", name, if ok { "" } else { " (FAILED)" })?;
        writeln!(w)?;

        if !ok {
            if let Some(err) = analysis.get("error") {
                writeln!(w, "  error: {}", err["message"].as_str().unwrap_or("(unknown)"))?;
            }
            continue;
        }

        let result = &analysis["result"];
        match name {
            "blastradius" => render_blastradius(&mut w, result)?,
            "list-entrypoints" => render_list_entrypoints(&mut w, result)?,
            _ => writeln!(w, "  {}", serde_json::to_string(result)?)?,
        }
        writeln!(w)?;
    }
    Ok(())
}

fn render_blastradius<W: Write>(w: &mut W, result: &Value) -> Result<()> {
    let summary = &result["summary"];
    writeln!(
        w,
        "  summary: {} files, {} symbols changed, {} entry points affected",
        summary["files_changed"].as_u64().unwrap_or(0),
        summary["symbols_changed"].as_u64().unwrap_or(0),
        summary["entry_points_affected"].as_u64().unwrap_or(0),
    )?;
    writeln!(w)?;

    let affected = result["affected_entry_points"].as_array().cloned().unwrap_or_default();
    if affected.is_empty() {
        writeln!(w, "  no entry points affected")?;
    } else {
        for (kind, eps) in group_by_kind(&affected) {
            emit_kind_section(w, &kind, &eps)?;
        }
    }
    writeln!(w)?;

    let changed = result["changed_symbols"].as_array().cloned().unwrap_or_default();
    if !changed.is_empty() {
        writeln!(w, "  changed symbols ({}):", changed.len())?;
        for c in &changed {
            let fqn = c["fqn"].as_str().unwrap_or("?");
            let path = c["path"].as_str().unwrap_or("?");
            let ls = c["line_start"].as_u64().unwrap_or(0);
            let le = c["line_end"].as_u64().unwrap_or(0);
            if c["whole_file_gone"].as_bool().unwrap_or(false) {
                writeln!(w, "    - {} ({}, file removed)", fqn, path)?;
            } else if ls == le {
                writeln!(w, "    - {} ({}:{})", fqn, path, ls)?;
            } else {
                writeln!(w, "    - {} ({}:{}-{})", fqn, path, ls, le)?;
            }
            if let Some(affects) = c["affects"].as_array()
                && !affects.is_empty()
            {
                let parts: Vec<String> = affects
                    .iter()
                    .map(|a| {
                        format!(
                            "{} {}",
                            a["kind"].as_str().unwrap_or("?"),
                            a["name"].as_str().unwrap_or("?")
                        )
                    })
                    .collect();
                writeln!(w, "        affects: {}", parts.join(", "))?;
            }
        }
    }
    Ok(())
}

fn render_list_entrypoints<W: Write>(w: &mut W, result: &Value) -> Result<()> {
    let eps = result["entry_points"].as_array().cloned().unwrap_or_default();
    writeln!(w, "  {} entry point(s):", eps.len())?;
    for ep in &eps {
        let kind = ep["kind"].as_str().unwrap_or("?");
        let name = ep["name"].as_str().unwrap_or("?");
        let handler = ep["handler_fqn"].as_str().unwrap_or("?");
        let path = ep["handler_path"].as_str().unwrap_or("");
        let line = ep["handler_line"].as_u64().unwrap_or(0);
        if path.is_empty() {
            writeln!(w, "    - {:24} {:30} {}", kind, name, handler)?;
        } else {
            writeln!(w, "    - {:24} {:30} {} ({}:{})", kind, name, handler, path, line)?;
        }
    }
    Ok(())
}

fn emit_kind_section<W: Write>(w: &mut W, kind: &str, eps: &[&Value]) -> Result<()> {
    writeln!(w, "  {} ({}):", kind, eps.len())?;
    for ep in eps {
        let name = ep["name"].as_str().unwrap_or("?");
        let handler = ep["handler"]["fqn"].as_str().unwrap_or("?");
        let path = ep["handler"]["path"].as_str().unwrap_or("?");
        let line = ep["handler"]["line"].as_u64().unwrap_or(0);
        let conf = ep["min_confidence"].as_str().unwrap_or("Confirmed");
        writeln!(w, "    - {} [{}]", name, conf)?;
        writeln!(w, "        handler: {} ({}:{})", handler, path, line)?;
        if let Some(extra) = ep.get("extra") {
            if let Some(p) = extra.get("path").and_then(|v| v.as_str()) {
                let methods: String = extra
                    .get("methods")
                    .and_then(|v| v.as_array())
                    .map(|m| m.iter().filter_map(|x| x.as_str()).collect::<Vec<_>>().join(","))
                    .unwrap_or_default();
                if methods.is_empty() {
                    writeln!(w, "        http: {}", p)?;
                } else {
                    writeln!(w, "        http: {} {}", methods, p)?;
                }
            }
            if let Some(expr) = extra.get("expression").and_then(|v| v.as_str()) {
                writeln!(w, "        cron: {}", expr)?;
            }
            if let Some(freq) = extra.get("frequency").and_then(|v| v.as_str()) {
                writeln!(w, "        every: {}", freq)?;
            }
        }
        let witness = ep["witness_path"].as_array().cloned().unwrap_or_default();
        for step in &witness {
            let from = step["from"].as_str().unwrap_or("?");
            let to = step["to"].as_str().unwrap_or("?");
            let conf = step["confidence"].as_str().unwrap_or("?");
            writeln!(w, "        via {} -> {} [{}]", from, to, conf)?;
        }
    }
    Ok(())
}
