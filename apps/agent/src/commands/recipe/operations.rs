use super::{Failure, Files};
use crate::command::CommandError;
use serde_json::{json, Value};
use std::{fs, path::PathBuf};

impl CommandError for Failure {
    const CODES: &'static [&'static str] = &["recipe.failed"];
    fn code(&self) -> &'static str {
        "recipe.failed"
    }
    fn message(&self) -> String {
        "The machine recipe could not complete.".into()
    }
    fn details(&self) -> Value {
        let mut details = json!({"stage":format!("recipe-stage:{}",self.stage),"reason":self.reason,"packages":self.packages});
        if let Some(diagnostic) = &self.diagnostic {
            details["command"] = json!(diagnostic.command);
            details["exit_code"] = json!(diagnostic.exit_code);
            details["stdout"] = json!(diagnostic.stdout);
            details["stderr"] = json!(diagnostic.stderr);
        }
        details
    }
}
pub(crate) struct Policy(PathBuf);
impl Policy {
    pub(crate) fn install(files: &Files) -> Result<Self, Failure> {
        let path = files.path("/usr/sbin/policy-rc.d");
        if fs::symlink_metadata(&path).is_ok() {
            return Err(Failure::new(
                "preflight",
                "A package restart policy already exists",
            ));
        }
        files.publish(
            &path,
            b"#!/bin/sh\nexit 101\n",
            0o755,
            files.uid,
            files.gid,
            false,
        )?;
        Ok(Self(path))
    }
}
impl Drop for Policy {
    fn drop(&mut self) {
        let _ = fs::remove_file(&self.0);
    }
}

#[cfg(test)]
mod tests;
