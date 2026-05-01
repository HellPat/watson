use std::io;

use anyhow::Result;
use clap::Parser;
use watson::analysis::list_entrypoints;
use watson::cli::{Cli, Language, PhpAnalysis};
use watson::engine::php::PhpEngine;
use watson::output::json::write_envelope;

fn main() -> Result<()> {
    let cli = Cli::parse();
    match cli.language {
        Language::Php { analysis } => match analysis {
            PhpAnalysis::Blastradius { .. } => {
                eprintln!("watson php blastradius: not implemented (phase-5)");
                std::process::exit(2);
            }
            PhpAnalysis::ListEntrypoints { root } => {
                let engine = PhpEngine::new();
                let envelope = list_entrypoints::run(&engine, &root)?;
                write_envelope(io::stdout().lock(), &envelope)?;
            }
        },
    }
    Ok(())
}
