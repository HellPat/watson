use std::path::Path;

use anyhow::Result;

use crate::engine::{Engine, ProjectIndex};

pub mod analyze;
pub mod parse;

#[derive(Debug, Default)]
pub struct PhpEngine;

impl PhpEngine {
    pub fn new() -> Self {
        Self
    }
}

impl Engine for PhpEngine {
    fn lang_id(&self) -> &'static str {
        "php"
    }

    fn extensions(&self) -> &'static [&'static str] {
        &["php"]
    }

    fn analyze_project(&self, root: &Path) -> Result<ProjectIndex> {
        analyze::analyze_project(root)
    }
}
