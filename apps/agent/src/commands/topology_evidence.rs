//! Bounded evidence bytes from a task VM. The Gateway owns review eligibility and JSON record interpretation.
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use base64::{engine::general_purpose::STANDARD, Engine};
use serde::{Deserialize, Serialize};
use std::{
    fs::File,
    io::{Read, Seek, SeekFrom},
    path::PathBuf,
    time::Duration,
};

const LIMIT: u64 = 1024 * 1024;

command_errors! {
    pub enum Error {
        Invalid {} => "task_vm.evidence_input_invalid", "The topology evidence checkout must be an absolute path.";
        Unreadable {} => "task_vm.evidence_unreadable", "The topology evidence file could not be read.";
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    checkout: PathBuf,
}

#[derive(Debug, PartialEq, Serialize)]
pub struct Output {
    /// Encoding bounds the JSON result even when the file contains non-text bytes.
    content_base64: Option<String>,
}

fn read(input: &Input) -> Result<Output, Error> {
    if !super::doctor_route::absolute(&input.checkout) {
        return Err(Error::Invalid {});
    }
    let path = input.checkout.join(".e2e/evidence.jsonl");
    let metadata = match path.metadata() {
        Ok(metadata) if metadata.is_file() => metadata,
        Ok(_) => {
            return Ok(Output {
                content_base64: None,
            })
        }
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => {
            return Ok(Output {
                content_base64: None,
            })
        }
        Err(_) => return Err(Error::Unreadable {}),
    };
    let mut file = File::open(path).map_err(|_| Error::Unreadable {})?;
    let length = metadata.len();
    file.seek(SeekFrom::Start(length.saturating_sub(LIMIT)))
        .map_err(|_| Error::Unreadable {})?;
    let mut content = Vec::new();
    file.take(length.min(LIMIT))
        .read_to_end(&mut content)
        .map_err(|_| Error::Unreadable {})?;
    Ok(Output {
        content_base64: Some(STANDARD.encode(content)),
    })
}

pub struct ReadTopologyEvidence;
impl Command for ReadTopologyEvidence {
    const NAME: &'static str = "task-vms.read-topology-evidence";
    const SUMMARY: &'static str = "Read the newest MiB of a task VM checkout's topology evidence.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(input: Input, _: &Context) -> Result<Output, Error> {
        read(&input)
    }
}

#[cfg(test)]
#[path = "topology_evidence_tests.rs"]
mod tests;
