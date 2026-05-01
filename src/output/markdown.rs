use std::io::Write;

use anyhow::Result;
use serde_json::Value;

use crate::output::envelope::Envelope;

/// Render an envelope as Markdown for PR descriptions / AI reviewers.
///
/// Layout is opinionated: a top heading per analysis, summary stats up top,
/// affected entry points before changed symbols (the actionable signal first),
/// witness paths as nested fenced code blocks. AI reviewers downstream can
/// section-parse on `##` and `###` headings.
pub fn write_envelope<W: Write>(mut w: W, envelope: &Envelope) -> Result<()> {
    let serialized = serde_json::to_value(envelope)?;
    writeln!(w, "# watson — {} {}", envelope.language, envelope.framework)?;
    writeln!(w)?;
    writeln!(w, "_tool {} v{}_", envelope.tool, envelope.version)?;
    writeln!(w)?;

    if let (Some(base), Some(head)) = (&envelope.context.base, &envelope.context.head) {
        writeln!(w, "Diff: `{}` → `{}`", short(base), short(head))?;
    }
    writeln!(w, "Root: `{}`", envelope.context.root.display())?;
    writeln!(w)?;

    let analyses = serialized["analyses"].as_array().cloned().unwrap_or_default();
    for analysis in &analyses {
        let name = analysis["name"].as_str().unwrap_or("?");
        let version = analysis["version"].as_str().unwrap_or("");
        let ok = analysis["ok"].as_bool().unwrap_or(false);

        writeln!(w, "## {} {}", name, if ok { "" } else { "(failed)" }.trim())?;
        writeln!(w, "_v{}_", version)?;
        writeln!(w)?;

        if !ok {
            if let Some(err) = analysis.get("error") {
                writeln!(w, "**Error**: {}", err["message"].as_str().unwrap_or("(unknown)"))?;
            }
            continue;
        }

        let result = &analysis["result"];
        match name {
            "blastradius" => render_blastradius(&mut w, result)?,
            "list-entrypoints" => render_list_entrypoints(&mut w, result)?,
            _ => {
                writeln!(w, "```json\n{}\n```", serde_json::to_string_pretty(result)?)?;
            }
        }
        writeln!(w)?;
    }
    Ok(())
}

fn render_blastradius<W: Write>(w: &mut W, result: &Value) -> Result<()> {
    let summary = &result["summary"];
    writeln!(
        w,
        "**Summary** — {} files changed · {} symbols changed · {} entry points affected",
        summary["files_changed"].as_u64().unwrap_or(0),
        summary["symbols_changed"].as_u64().unwrap_or(0),
        summary["entry_points_affected"].as_u64().unwrap_or(0),
    )?;
    writeln!(w)?;

    let affected = result["affected_entry_points"].as_array().cloned().unwrap_or_default();
    if affected.is_empty() {
        writeln!(w, "### Affected entry points")?;
        writeln!(w)?;
        writeln!(w, "_None._ The diff did not transitively reach any HTTP route, console command, message handler, or scheduled task.")?;
    } else {
        writeln!(w, "### Affected entry points ({})", affected.len())?;
        writeln!(w)?;
        for ep in &affected {
            let kind = ep["kind"].as_str().unwrap_or("?");
            let name = ep["name"].as_str().unwrap_or("?");
            let handler_fqn = ep["handler"]["fqn"].as_str().unwrap_or("?");
            let handler_path = ep["handler"]["path"].as_str().unwrap_or("?");
            let handler_line = ep["handler"]["line"].as_u64().unwrap_or(0);
            let confidence = ep["min_confidence"].as_str().unwrap_or("Confirmed");

            writeln!(w, "#### `{}` — {}", kind, name)?;
            writeln!(w)?;
            writeln!(w, "- **Handler**: `{}` (`{}:{}`)", handler_fqn, handler_path, handler_line)?;
            writeln!(w, "- **Confidence**: {}", confidence)?;

            // Kind-specific extras.
            if kind == "symfony.route" {
                if let Some(extra) = ep.get("extra") {
                    let methods = extra["methods"].as_array().map(|m| {
                        m.iter().filter_map(|v| v.as_str()).collect::<Vec<_>>().join(", ")
                    });
                    if let (Some(p), Some(m)) = (extra["path"].as_str(), methods) {
                        writeln!(w, "- **HTTP**: {} `{}`", m, p)?;
                    } else if let Some(p) = extra["path"].as_str() {
                        writeln!(w, "- **HTTP path**: `{}`", p)?;
                    }
                }
            } else if kind == "symfony.cron_task"
                && let Some(expr) = ep.pointer("/extra/expression").and_then(|v| v.as_str())
            {
                writeln!(w, "- **Cron**: `{}`", expr)?;
            } else if kind == "symfony.periodic_task"
                && let Some(freq) = ep.pointer("/extra/frequency").and_then(|v| v.as_str())
            {
                writeln!(w, "- **Frequency**: `{}`", freq)?;
            }

            let witness = ep["witness_path"].as_array().cloned().unwrap_or_default();
            if !witness.is_empty() {
                writeln!(w)?;
                writeln!(w, "Witness path:")?;
                writeln!(w, "```text")?;
                for step in &witness {
                    let from = step["from"].as_str().unwrap_or("?");
                    let to = step["to"].as_str().unwrap_or("?");
                    let conf = step["confidence"].as_str().unwrap_or("?");
                    let site = step["site"].as_str().unwrap_or("");
                    if site.is_empty() {
                        writeln!(w, "  {} → {}  [{}]", from, to, conf)?;
                    } else {
                        writeln!(w, "  {} → {}  [{}] @ {}", from, to, conf, site)?;
                    }
                }
                writeln!(w, "```")?;
            }
            writeln!(w)?;
        }
    }

    let changed = result["changed_symbols"].as_array().cloned().unwrap_or_default();
    if !changed.is_empty() {
        writeln!(w, "### Changed symbols ({})", changed.len())?;
        writeln!(w)?;
        for c in &changed {
            let fqn = c["fqn"].as_str().unwrap_or("?");
            let path = c["path"].as_str().unwrap_or("?");
            let ls = c["line_start"].as_u64().unwrap_or(0);
            let le = c["line_end"].as_u64().unwrap_or(0);
            let gone = c["whole_file_gone"].as_bool().unwrap_or(false);
            if gone {
                writeln!(w, "- `{}` — `{}` (file removed)", fqn, path)?;
            } else if ls == le {
                writeln!(w, "- `{}` — `{}:{}`", fqn, path, ls)?;
            } else {
                writeln!(w, "- `{}` — `{}:{}-{}`", fqn, path, ls, le)?;
            }
        }
    }

    Ok(())
}

fn render_list_entrypoints<W: Write>(w: &mut W, result: &Value) -> Result<()> {
    let eps = result["entry_points"].as_array().cloned().unwrap_or_default();
    writeln!(w, "**{} entry point{}**", eps.len(), if eps.len() == 1 { "" } else { "s" })?;
    writeln!(w)?;
    if eps.is_empty() {
        writeln!(w, "_None detected._")?;
        return Ok(());
    }
    writeln!(w, "| kind | name | handler |")?;
    writeln!(w, "|---|---|---|")?;
    for ep in &eps {
        let kind = ep["kind"].as_str().unwrap_or("?");
        let name = ep["name"].as_str().unwrap_or("?");
        let handler = ep["handler_fqn"].as_str().unwrap_or("?");
        let path = ep["handler_path"].as_str().unwrap_or("");
        let line = ep["handler_line"].as_u64().unwrap_or(0);
        let handler_loc = if path.is_empty() {
            format!("`{}`", handler)
        } else {
            format!("`{}` (`{}:{}`)", handler, path, line)
        };
        writeln!(w, "| `{}` | `{}` | {} |", kind, name, handler_loc)?;
    }
    Ok(())
}

fn short(sha_or_ref: &str) -> &str {
    if sha_or_ref.len() > 12 && sha_or_ref.chars().all(|c| c.is_ascii_hexdigit()) {
        &sha_or_ref[..12]
    } else {
        sha_or_ref
    }
}
