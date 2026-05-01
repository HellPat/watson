use std::path::PathBuf;

use clap::{Parser, Subcommand, ValueEnum};

#[derive(Parser, Debug)]
#[command(
    name = "watson",
    version,
    about = "PR blast-radius analyzer (PHP/Symfony for v1)"
)]
pub struct Cli {
    #[command(subcommand)]
    pub language: Language,
}

#[derive(Subcommand, Debug)]
pub enum Language {
    /// PHP analyses (Symfony for v1).
    Php {
        #[command(subcommand)]
        analysis: PhpAnalysis,
    },
}

#[derive(Subcommand, Debug)]
pub enum PhpAnalysis {
    /// Report entry points whose handlers transitively call code changed in `base..head`.
    Blastradius {
        /// Base git ref (e.g. main).
        #[arg(long)]
        base: String,
        /// Head git ref (e.g. HEAD).
        #[arg(long)]
        head: String,
        /// Project root containing the .git directory.
        #[arg(long, default_value = ".")]
        root: PathBuf,
        /// Output format. `json` is machine-readable; `md` is tuned for
        /// pasting into PR descriptions and for AI reviewers; `text` is a
        /// terminal-friendly summary for humans.
        #[arg(long, default_value = "json")]
        format: Format,
        /// Include unresolved call sites in output.
        #[arg(long)]
        include_unresolved: bool,
    },
    /// List every detected entry point in the project (debug aid).
    ListEntrypoints {
        #[arg(long, default_value = ".")]
        root: PathBuf,
        /// Output format (json|md|text).
        #[arg(long, default_value = "json")]
        format: Format,
    },
}

#[derive(ValueEnum, Clone, Copy, Debug, PartialEq, Eq)]
pub enum Format {
    /// Multi-analysis envelope (machine-readable). Default.
    Json,
    /// Markdown — for PR descriptions and AI reviewers.
    Md,
    /// Plain-text — for humans reading on a terminal.
    Text,
}
