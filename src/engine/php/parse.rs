use std::borrow::Cow;
use std::fs;
use std::path::Path;

use anyhow::{Context, Result, bail};
use bumpalo::Bump;
use mago_database::file::File;
use mago_syntax::parser::parse_file;

/// Phase-1 spike: prove `mago-syntax` can parse a PHP file from disk.
///
/// Returns the number of top-level statements in the program as a small
/// observable signal that parsing succeeded. Real engine work lands in phase-2.
pub fn parse_smoke(path: &Path) -> Result<usize> {
    let src = fs::read_to_string(path)
        .with_context(|| format!("read PHP source: {}", path.display()))?;

    let arena = Bump::new();
    let file = File::ephemeral(
        Cow::Owned(path.display().to_string()),
        Cow::Owned(src),
    );

    let program = parse_file(&arena, &file);

    if program.has_errors() {
        bail!("parse errors in {}: {:?}", path.display(), program.errors);
    }

    Ok(program.statements.len())
}
