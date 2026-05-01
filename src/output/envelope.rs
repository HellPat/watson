use std::path::PathBuf;

use serde::Serialize;

const TOOL: &str = "watson";
const TOOL_VERSION: &str = env!("CARGO_PKG_VERSION");

#[derive(Debug, Serialize)]
pub struct Envelope {
    pub tool: &'static str,
    pub version: &'static str,
    pub language: &'static str,
    pub framework: &'static str,
    pub context: Context,
    pub analyses: Vec<AnalysisEntry>,
}

#[derive(Debug, Serialize)]
pub struct Context {
    pub root: PathBuf,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub base: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub head: Option<String>,
}

#[derive(Debug, Serialize)]
pub struct AnalysisEntry {
    pub name: &'static str,
    pub version: &'static str,
    pub ok: bool,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub result: Option<serde_json::Value>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub error: Option<AnalysisError>,
}

#[derive(Debug, Serialize)]
pub struct AnalysisError {
    pub kind: String,
    pub message: String,
}

impl Envelope {
    pub fn new(language: &'static str, framework: &'static str, context: Context) -> Self {
        Self { tool: TOOL, version: TOOL_VERSION, language, framework, context, analyses: Vec::new() }
    }

    pub fn push(&mut self, entry: AnalysisEntry) {
        self.analyses.push(entry);
    }
}
