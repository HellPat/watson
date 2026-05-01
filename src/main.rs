use std::io;

use anyhow::Result;
use clap::Parser;
use watson::analysis::{blastradius, list_entrypoints};
use watson::cli::{Cli, Language, PhpAnalysis};
use watson::engine::php::PhpEngine;
use watson::output;

fn main() -> Result<()> {
    let cli = Cli::parse();
    match cli.language {
        Language::Php { analysis } => match analysis {
            PhpAnalysis::Blastradius { base, head, root, format, .. } => {
                let engine = PhpEngine::new();
                let envelope = blastradius::run(&engine, &root, &base, &head)?;
                output::write(format, io::stdout().lock(), &envelope)?;
            }
            PhpAnalysis::ListEntrypoints { root, format } => {
                let engine = PhpEngine::new();
                let envelope = list_entrypoints::run(&engine, &root)?;
                output::write(format, io::stdout().lock(), &envelope)?;
            }
        },
    }
    Ok(())
}
