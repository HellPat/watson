use anyhow::Result;
use clap::Parser;
use watson::cli::{Cli, Language, PhpAnalysis};

fn main() -> Result<()> {
    let cli = Cli::parse();
    match cli.language {
        Language::Php { analysis } => match analysis {
            PhpAnalysis::Blastradius { .. } => {
                eprintln!("watson php blastradius: not implemented");
                std::process::exit(2);
            }
            PhpAnalysis::ListEntrypoints { .. } => {
                eprintln!("watson php list-entrypoints: not implemented");
                std::process::exit(2);
            }
        },
    }
}
