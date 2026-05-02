use std::io;

use anyhow::Result;
use clap::Parser;
use watson::analysis::{blastradius, list_entrypoints};
use watson::cli::{Cli, Command, Verbosity};
use watson::engine::php::PhpEngine;
use watson::framework::detect_or_fail;
use watson::git::spec::resolve;
use watson::output;

fn main() -> Result<()> {
    let cli = Cli::parse();
    match cli.command {
        Command::Blastradius(args) => {
            let framework = detect_or_fail(&args.root, args.framework)?;
            let spec = resolve(&args.root, &args.revisions, args.cached)?;
            let verbosity = Verbosity::from_count(args.verbose);
            let engine = PhpEngine::new();
            let envelope = blastradius::run(&engine, &args.root, &spec, framework, verbosity, args.strict)?;
            output::write(args.format, io::stdout().lock(), &envelope)?;
        }
        Command::ListEntrypoints(args) => {
            let framework = detect_or_fail(&args.root, args.framework)?;
            let engine = PhpEngine::new();
            let envelope = list_entrypoints::run(&engine, &args.root, framework)?;
            output::write(args.format, io::stdout().lock(), &envelope)?;
        }
    }
    Ok(())
}
