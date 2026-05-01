//! Small cross-module helpers. Anything used in two or more places lands here
//! so we don't end up with two copies drifting apart.

use std::path::{Path, PathBuf};

/// Truncate a 40-char SHA to a 12-char display form. Pass anything else
/// through unchanged.
pub fn short(sha_or_ref: &str) -> &str {
    if sha_or_ref.len() > 12 && sha_or_ref.chars().all(|c| c.is_ascii_hexdigit()) {
        &sha_or_ref[..12]
    } else {
        sha_or_ref
    }
}

/// PHP is case-insensitive on class / function / method names. mago lower-cases
/// internally; we follow suit when comparing FQNs across our neutral types.
pub fn normalize_identifier(fqn: &str) -> String {
    fqn.to_lowercase()
}

/// `path.canonicalize()` with a graceful fall-back when the path doesn't
/// exist on disk.
pub fn canonicalize_or_self(p: &Path) -> PathBuf {
    p.canonicalize().unwrap_or_else(|_| p.to_path_buf())
}

/// Single sink for analyser warnings. We're not on `tracing` yet — stderr is
/// fine and matches what cargo's `--nocapture` shows during tests.
pub fn warn(msg: impl AsRef<str>) {
    eprintln!("watson: {}", msg.as_ref());
}
