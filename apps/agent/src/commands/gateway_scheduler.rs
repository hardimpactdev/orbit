//! Native scheduler drain and fixed service actions. Cache mutexes remain Gateway intent.
use super::{
    processes,
    recipe::{self, Files, Invocation},
};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    fs,
    io::{self, Read},
    os::unix::fs::MetadataExt,
    path::Path,
    time::{Duration, Instant},
};

command_errors! {
    pub enum Error {
        Invalid {} => "gateway.release_scheduler_invalid", "The scheduler Process identity or drain limit is invalid.";
        Failed {stage: String} => "gateway.release_scheduler_failed", "A Gateway scheduler handoff step failed.";
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub process_id: u64,
    pub name: String,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct DrainInput {
    pub process_id: u64,
    pub name: String,
    pub drain_seconds: u64,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
#[serde(rename_all = "snake_case")]
enum Outcome {
    Drained,
    Forced,
    NotRunning,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct DrainResult {
    outcome: Outcome,
    waited_ms: u64,
    running: Vec<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    stopped: Option<Vec<String>>,
    #[serde(skip_serializing_if = "Option::is_none")]
    reason: Option<&'static str>,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Done {}
trait Host: recipe::Host {
    fn now(&self) -> Instant;
}
impl Host for recipe::Native<'_> {
    fn now(&self) -> Instant {
        Instant::now()
    }
}
fn failed(stage: &str) -> Error {
    Error::Failed {
        stage: stage.into(),
    }
}
fn read(path: &Path, limit: u64) -> io::Result<String> {
    let mut bytes = Vec::new();
    fs::File::open(path)?
        .take(limit + 1)
        .read_to_end(&mut bytes)?;
    if bytes.len() as u64 > limit {
        return Err(io::Error::other("oversized scheduler observation"));
    }
    Ok(String::from_utf8_lossy(&bytes).into_owned())
}
pub(super) fn checked(files: &Files, input: &Input) -> Result<(), Error> {
    if input.process_id == 0 || input.process_id > i64::MAX as u64 {
        return Err(Error::Invalid {});
    }
    processes::check_name(processes::Runtime::Systemd, input.process_id, &input.name)
        .map_err(|_| Error::Invalid {})?;
    let path = files.path(&format!("/etc/systemd/system/{}", input.name));
    let meta = fs::symlink_metadata(&path).map_err(|_| failed("unit"))?;
    if !meta.is_file() || meta.uid() != files.uid || meta.mode() & 0o022 != 0 {
        return Err(failed("unit"));
    }
    let text = read(&path, 1024 * 1024).map_err(|_| failed("unit"))?;
    if !processes::marked(&text, input.process_id) {
        return Err(failed("unit"));
    }
    Ok(())
}
fn execute(
    host: &mut impl Host,
    args: &[&str],
    seconds: u64,
    stage: &str,
    required: bool,
) -> Result<recipe::Output, Error> {
    let mut call = Invocation::new(args);
    call.timeout = Duration::from_secs(seconds);
    recipe::run(host, &call, required).map_err(|_| failed(stage))
}
fn systemctl(host: &mut impl Host, verb: &str, name: &str) -> Result<(), Error> {
    execute(
        host,
        &["/usr/bin/systemctl", verb, name],
        if verb == "stop" { 120 } else { 60 },
        verb,
        true,
    )?;
    Ok(())
}
fn property(host: &mut impl Host, name: &str, property: &str) -> Result<String, Error> {
    Ok(execute(
        host,
        &[
            "/usr/bin/systemctl",
            "show",
            "-p",
            property,
            "--value",
            name,
        ],
        10,
        "observe",
        true,
    )?
    .stdout
    .trim()
    .into())
}
fn main_pid(host: &mut impl Host, name: &str) -> Result<u32, Error> {
    let value = property(host, name, "MainPID")?;
    if value.is_empty() || !value.bytes().all(|b| b.is_ascii_digit()) {
        return Err(failed("observe"));
    }
    value.parse::<u32>().map_err(|_| failed("observe"))
}
fn members(files: &Files, host: &mut impl Host, name: &str) -> Result<Vec<String>, Error> {
    let group = property(host, name, "ControlGroup")?;
    if group.is_empty() {
        return Ok(vec![]);
    }
    if !group.starts_with('/')
        || !group.split('/').skip(1).all(|p| {
            !p.is_empty() && p != "." && p != ".." && !p.bytes().any(|b| b.is_ascii_control())
        })
    {
        return Err(failed("observe"));
    }
    let path = files.path(&format!("/sys/fs/cgroup{group}/cgroup.procs"));
    let pids = match read(&path, 64 * 1024) {
        Ok(value) => value,
        Err(error) if error.kind() == io::ErrorKind::NotFound => return Ok(vec![]),
        Err(_) => return Err(failed("observe")),
    };
    let mut result = Vec::new();
    for line in pids.lines() {
        let pid = line.parse::<u32>().map_err(|_| failed("observe"))?;
        if pid == 0 {
            return Err(failed("observe"));
        }
        let path = files.path(&format!("/proc/{pid}/cmdline"));
        let command = match read(&path, 64 * 1024) {
            Ok(value) => value.replace('\0', " "),
            Err(error) if error.kind() == io::ErrorKind::NotFound => continue,
            Err(_) => return Err(failed("observe")),
        };
        if !command.trim().is_empty() && !command.contains("schedule:work") {
            result.push(
                crate::logs::redact::redact_text(command.trim())
                    .chars()
                    .take(200)
                    .collect(),
            );
            if result.len() == 20 {
                break;
            }
        }
    }
    Ok(result)
}
fn pcntl(host: &mut impl Host) -> Result<bool, Error> {
    let result = execute(
        host,
        &[
            "/usr/bin/env",
            "-i",
            "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
            "LANG=C.UTF-8",
            "/usr/bin/php8.5",
            "--ri",
            "pcntl",
        ],
        15,
        "pcntl",
        false,
    )?;
    match result.code {
        0 => Ok(result
            .stdout
            .lines()
            .any(|line| line.trim() == "pcntl support => enabled")),
        1 => Ok(false),
        _ => Err(failed("pcntl")),
    }
}
fn drain(files: &Files, host: &mut impl Host, input: &DrainInput) -> Result<DrainResult, Error> {
    if input.drain_seconds > 3600 {
        return Err(Error::Invalid {});
    }
    checked(
        files,
        &Input {
            process_id: input.process_id,
            name: input.name.clone(),
        },
    )?;
    if !pcntl(host)? {
        let running = members(files, host, &input.name)?;
        return Ok(DrainResult {
            outcome: Outcome::Forced,
            waited_ms: 0,
            stopped: Some(running.clone()),
            running,
            reason: Some("no_pcntl"),
        });
    }
    let started = host.now();
    let main = main_pid(host, &input.name)?;
    if main == 0 {
        return Ok(DrainResult {
            outcome: Outcome::NotRunning,
            waited_ms: 0,
            running: vec![],
            stopped: None,
            reason: None,
        });
    }
    let running = members(files, host, &input.name)?;
    execute(
        host,
        &[
            "/usr/bin/systemctl",
            "kill",
            "--kill-whom=main",
            "--signal=SIGTERM",
            &input.name,
        ],
        30,
        "signal",
        true,
    )?;
    let deadline = started + Duration::from_secs(input.drain_seconds);
    while main_pid(host, &input.name)? == main {
        if host.now() >= deadline {
            return Ok(DrainResult {
                outcome: Outcome::Forced,
                waited_ms: host.now().saturating_duration_since(started).as_millis() as u64,
                running,
                stopped: Some(members(files, host, &input.name)?),
                reason: None,
            });
        }
        host.wait(Duration::from_millis(500))
            .map_err(|_| failed("drain"))?;
    }
    Ok(DrainResult {
        outcome: Outcome::Drained,
        waited_ms: host.now().saturating_duration_since(started).as_millis() as u64,
        running,
        stopped: None,
        reason: None,
    })
}
fn start(files: &Files, host: &mut impl Host, input: &Input) -> Result<Done, Error> {
    checked(files, input)?;
    systemctl(host, "start", &input.name)?;
    let deadline = host.now() + Duration::from_secs(30);
    loop {
        let output = execute(
            host,
            &["/usr/bin/systemctl", "is-active", "--quiet", &input.name],
            10,
            "start",
            false,
        )?;
        if output.code == 0 {
            return Ok(Done {});
        }
        if host.now() >= deadline {
            return Err(failed("start"));
        }
        host.wait(Duration::from_millis(500))
            .map_err(|_| failed("start"))?;
    }
}
fn control(files: &Files, host: &mut impl Host, input: &Input, verb: &str) -> Result<Done, Error> {
    checked(files, input)?;
    systemctl(host, verb, &input.name)?;
    Ok(Done {})
}
macro_rules! command {
    ($type:ident, $name:literal, $input:ty, $output:ty, $seconds:literal, $run:expr) => {
        pub struct $type;
        impl Command for $type {
            const NAME: &'static str = $name;
            const SUMMARY: &'static str =
                "Apply one fixed Gateway scheduler handoff step to an owned Process unit.";
            const MUTATES: bool = true;
            const TIMEOUT: Duration = Duration::from_secs($seconds);
            type Input = $input;
            type Output = $output;
            type Error = Error;
            fn privilege(platform: Platform) -> Option<Privilege> {
                super::linux_only(platform, Privilege::Root)
            }
            fn target(input: &$input) -> Option<String> {
                Some(format!("process:{}", input.process_id))
            }
            fn run(input: $input, context: &Context) -> Result<$output, Error> {
                $run(&Files::system(), &mut recipe::Native(context), &input)
            }
        }
    };
}
command!(
    Drain,
    "gateway.drain-scheduler",
    DrainInput,
    DrainResult,
    3660,
    drain
);
command!(Start, "gateway.start-scheduler", Input, Done, 120, start);
command!(
    Stop,
    "gateway.stop-scheduler",
    Input,
    Done,
    150,
    |files, host, input| control(files, host, input, "stop")
);
command!(
    Restart,
    "gateway.restart-release-process",
    Input,
    Done,
    90,
    |files, host, input| control(files, host, input, "try-restart")
);
#[cfg(test)]
mod tests;
