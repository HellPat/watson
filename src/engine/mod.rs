use std::path::{Path, PathBuf};

use anyhow::Result;
use serde::Serialize;

pub mod php;

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum SymbolKind {
    Function,
    Method,
    Class,
    Interface,
    Trait,
    Enum,
}

#[derive(Debug, Clone, PartialEq, Eq, Hash, Serialize)]
pub struct Symbol {
    pub fqn: String,
    pub kind: SymbolKind,
    pub path: PathBuf,
    pub line_start: u32,
    pub line_end: u32,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, PartialOrd, Ord, Serialize)]
pub enum Confidence {
    NameOnly,
    Probable,
    Confirmed,
}

#[derive(Debug, Clone, Serialize)]
pub struct CallEdge {
    pub from_fqn: String,
    pub to_fqn: String,
    pub site_path: PathBuf,
    pub site_line: u32,
    pub confidence: Confidence,
}

#[derive(Debug, Clone, Serialize)]
pub struct EntryPoint {
    pub kind: String,
    pub name: String,
    pub handler_fqn: String,
    pub handler_path: PathBuf,
    pub handler_line: u32,
    #[serde(skip_serializing_if = "serde_json::Value::is_null")]
    pub extra: serde_json::Value,
}

#[derive(Debug, Clone, Serialize)]
pub struct ImportEntry {
    pub short: String,
    pub fqn: String,
    pub imported: bool,
}

#[derive(Debug, Default, Clone)]
pub struct ProjectIndex {
    pub root: PathBuf,
    pub symbols: Vec<Symbol>,
    pub edges: Vec<CallEdge>,
    pub entry_points: Vec<EntryPoint>,
    pub imports_per_file: Vec<(PathBuf, Vec<ImportEntry>)>,
}

pub trait Engine: Send + Sync {
    fn lang_id(&self) -> &'static str;
    fn extensions(&self) -> &'static [&'static str];
    fn analyze_project(&self, root: &Path) -> Result<ProjectIndex>;
}
