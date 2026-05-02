use std::path::PathBuf;

use clap::{Args, Parser, Subcommand, ValueEnum};

#[derive(Parser, Debug)]
#[command(
    name = "watson",
    version,
    about = "PR blast-radius analyzer for PHP (Symfony today; Laravel in progress)."
)]
pub struct Cli {
    #[command(subcommand)]
    pub command: Command,
}

#[derive(Subcommand, Debug)]
pub enum Command {
    /// Report entry points whose handlers transitively reach changed code.
    ///
    /// Revision argument shapes mirror `git diff` (https://git-scm.com/docs/git-diff):
    ///   (none)              working tree vs HEAD
    ///   --cached / --staged index vs HEAD
    ///   <rev>               working tree vs <rev>
    ///   <a> <b>             <a> vs <b>
    ///   <a>..<b>            same as `<a> <b>`
    ///   <a>...<b>           merge-base(<a>,<b>) vs <b>
    Blastradius(BlastradiusArgs),

    /// List every detected entry point in the project (debug aid).
    ListEntrypoints(ListEntrypointsArgs),
}

#[derive(Args, Debug)]
pub struct BlastradiusArgs {
    /// Revision specifier(s). See `watson blastradius --help` for the full
    /// list of supported shapes (no-arg, one rev, two revs, `..`, `...`).
    #[arg(num_args = 0..=2, value_name = "REV")]
    pub revisions: Vec<String>,

    /// Compare the staged index against HEAD instead of the working tree.
    #[arg(long, alias = "staged")]
    pub cached: bool,

    /// Project root containing the .git directory.
    #[arg(long, default_value = ".", value_name = "PATH")]
    pub root: PathBuf,

    /// Output format.
    #[arg(long, default_value = "json")]
    pub format: Format,

    /// Force framework (overrides auto-detection).
    #[arg(long, value_enum)]
    pub framework: Option<Framework>,

    /// Include unresolved call sites in output.
    #[arg(long)]
    pub include_unresolved: bool,

    /// Verbosity. Default emits only the affected entry points (smallest
    /// payload, ideal for piping into LLMs). `-v` adds the changed-symbol
    /// list with each symbol's `affects` mapping. `-vv` adds the witness
    /// path for every affected entry point (full call-graph trace).
    #[arg(short = 'v', long = "verbose", action = clap::ArgAction::Count)]
    pub verbose: u8,
}

/// Verbosity tier derived from `-v` count. Used by `analysis::blastradius`
/// to decide which sections to populate.
#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord)]
pub enum Verbosity {
    /// Default: affected entry points + summary only.
    EntryPointsOnly,
    /// `-v`: + per-symbol `affects` mapping.
    WithChangedSymbols,
    /// `-vv` (or higher): + witness path per entry point.
    WithWitnessPaths,
}

impl Verbosity {
    pub fn from_count(n: u8) -> Self {
        match n {
            0 => Verbosity::EntryPointsOnly,
            1 => Verbosity::WithChangedSymbols,
            _ => Verbosity::WithWitnessPaths,
        }
    }
    pub fn includes_changed_symbols(self) -> bool {
        self >= Verbosity::WithChangedSymbols
    }
    pub fn includes_witness_paths(self) -> bool {
        self >= Verbosity::WithWitnessPaths
    }
}

#[derive(Args, Debug)]
pub struct ListEntrypointsArgs {
    /// Project root.
    #[arg(long, default_value = ".", value_name = "PATH")]
    pub root: PathBuf,

    /// Output format.
    #[arg(long, default_value = "json")]
    pub format: Format,

    /// Force framework (overrides auto-detection).
    #[arg(long, value_enum)]
    pub framework: Option<Framework>,
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

#[derive(ValueEnum, Clone, Copy, Debug, PartialEq, Eq)]
pub enum Framework {
    Symfony,
    Laravel,
}
