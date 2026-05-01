use std::fs;
use std::path::Path;

use crate::cli::Framework;

/// Detect the framework used by the project at `root`. Returns `None` if no
/// PHP framework signature is found; callers can use `--framework` to force.
///
/// Heuristics, in order:
///   1. `composer.json` lists `laravel/framework` in `require` -> Laravel.
///   2. `composer.json` lists `symfony/framework-bundle` (or any `symfony/`
///      bundle that strongly implies a framework app) in `require` -> Symfony.
///   3. `artisan` file present at root -> Laravel.
///   4. `bin/console` file present at root -> Symfony.
pub fn detect(root: &Path) -> Option<Framework> {
    if let Some(fw) = detect_from_composer(root) {
        return Some(fw);
    }
    if root.join("artisan").is_file() {
        return Some(Framework::Laravel);
    }
    if root.join("bin").join("console").is_file() {
        return Some(Framework::Symfony);
    }
    None
}

fn detect_from_composer(root: &Path) -> Option<Framework> {
    let composer = root.join("composer.json");
    let raw = fs::read_to_string(composer).ok()?;
    let value: serde_json::Value = serde_json::from_str(&raw).ok()?;

    let requires: Vec<&str> = value
        .get("require")
        .and_then(|v| v.as_object())
        .into_iter()
        .chain(value.get("require-dev").and_then(|v| v.as_object()))
        .flatten()
        .map(|(k, _)| k.as_str())
        .collect();

    if requires.contains(&"laravel/framework") {
        return Some(Framework::Laravel);
    }
    if requires.contains(&"symfony/framework-bundle") {
        return Some(Framework::Symfony);
    }
    None
}

/// Like `detect`, but errors with a friendly message instead of returning
/// `None`. Pass an explicit `--framework` to override.
pub fn detect_or_fail(root: &Path, override_: Option<Framework>) -> anyhow::Result<Framework> {
    if let Some(fw) = override_ {
        return Ok(fw);
    }
    detect(root).ok_or_else(|| {
        anyhow::anyhow!(
            "could not auto-detect a PHP framework at {}.\n\
             Pass `--framework symfony` or `--framework laravel` to force, or run\n\
             watson from a project root that has a composer.json / bin/console / artisan.",
            root.display()
        )
    })
}
