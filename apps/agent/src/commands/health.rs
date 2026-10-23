//! `http.await-healthy`: waits from the Node until a local service answers its health URL with a 2xx status.

use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    process::{Command as Process, Stdio},
    time::Duration,
};

command_errors! {
    pub enum HealthError {
        Unhealthy {} => "http.unhealthy", "The service did not answer its health URL in time.";
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct HealthInput {
    pub url: String,
    pub attempts: u32,
    pub interval_seconds: u64,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Healthy {}

fn answers(url: &str) -> bool {
    Process::new("curl")
        .args([
            "--fail",
            "--silent",
            "--show-error",
            "--max-time",
            "3",
            "--output",
            "/dev/null",
            url,
        ])
        .stdin(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .is_ok_and(|status| status.success())
}

pub fn wait(input: &HealthInput) -> Result<Healthy, HealthError> {
    for attempt in 1..=input.attempts {
        if answers(&input.url) {
            return Ok(Healthy {});
        }
        if attempt < input.attempts {
            std::thread::sleep(Duration::from_secs(input.interval_seconds));
        }
    }
    Err(HealthError::Unhealthy {})
}

pub struct AwaitHealthy;

impl Command for AwaitHealthy {
    const NAME: &'static str = "http.await-healthy";
    const SUMMARY: &'static str = "Wait until a service answers its health URL.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(600);
    type Input = HealthInput;
    type Output = Healthy;
    type Error = HealthError;

    /// An HTTP request needs no identity.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(input: HealthInput, _: &Context) -> Result<Healthy, HealthError> {
        wait(&input)
    }
}
