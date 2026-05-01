use std::path::Path;

use anyhow::Result;

use crate::engine::{Engine, ProjectIndex};

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
        // phase-1 stub. Phase-2+ fills in symbols/imports; phase-3 entry points;
        // phase-4 calls; phase-5 wires everything into the project index.
        Ok(ProjectIndex { root: root.to_path_buf(), ..Default::default() })
    }
}
