pub mod envelope;
pub mod json;
pub mod markdown;
pub mod text;

use std::io::Write;

use anyhow::Result;

use crate::cli::Format;
use crate::output::envelope::Envelope;

/// Dispatch on the user-requested format. Single helper so binaries don't
/// repeat the match.
pub fn write<W: Write>(format: Format, w: W, envelope: &Envelope) -> Result<()> {
    match format {
        Format::Json => json::write_envelope(w, envelope),
        Format::Md => markdown::write_envelope(w, envelope),
        Format::Text => text::write_envelope(w, envelope),
    }
}
