use std::io::Write;

use anyhow::Result;

use crate::output::envelope::Envelope;

pub fn write_envelope<W: Write>(mut w: W, envelope: &Envelope) -> Result<()> {
    serde_json::to_writer_pretty(&mut w, envelope)?;
    writeln!(w)?;
    Ok(())
}
