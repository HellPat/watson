pub mod envelope;
pub mod json;
pub mod markdown;
pub mod text;

use std::io::Write;

use anyhow::Result;
use serde_json::Value;

use crate::cli::Format;
use crate::output::envelope::Envelope;

/// Display order for entry-point kinds. Known framework kinds first, then
/// anything unknown alphabetical. Shared between every renderer that groups
/// by kind so a new kind only needs adding here.
pub const KIND_ORDER: &[&str] = &[
    "symfony.route",
    "symfony.command",
    "symfony.message_handler",
    "symfony.event_listener",
    "symfony.cron_task",
    "symfony.periodic_task",
    "symfony.schedule_provider",
    "laravel.route",
    "laravel.command",
    "laravel.job",
    "laravel.listener",
    "laravel.scheduled_task",
];

/// Group an envelope's `affected_entry_points` by `kind` in stable order:
/// `KIND_ORDER` entries first, then anything else alphabetical. Within a
/// group, entries are sorted by `name`.
pub fn group_by_kind(affected: &[Value]) -> Vec<(String, Vec<&Value>)> {
    use std::collections::BTreeMap;

    let mut buckets: BTreeMap<String, Vec<&Value>> = BTreeMap::new();
    for ep in affected {
        let kind = ep["kind"].as_str().unwrap_or("?").to_string();
        buckets.entry(kind).or_default().push(ep);
    }
    for entries in buckets.values_mut() {
        entries.sort_by(|a, b| {
            a["name"].as_str().unwrap_or("").cmp(b["name"].as_str().unwrap_or(""))
        });
    }

    let mut out: Vec<(String, Vec<&Value>)> = Vec::with_capacity(buckets.len());
    for kind in KIND_ORDER {
        if let Some(entries) = buckets.remove(*kind) {
            out.push((kind.to_string(), entries));
        }
    }
    // Anything else, alphabetical (BTreeMap drains in key order).
    for (kind, entries) in buckets {
        out.push((kind, entries));
    }
    out
}

/// Dispatch on the user-requested format.
pub fn write<W: Write>(format: Format, w: W, envelope: &Envelope) -> Result<()> {
    match format {
        Format::Json => json::write_envelope(w, envelope),
        Format::Md => markdown::write_envelope(w, envelope),
        Format::Text => text::write_envelope(w, envelope),
    }
}
