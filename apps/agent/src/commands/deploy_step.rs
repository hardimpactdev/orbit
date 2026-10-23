//! `instances.run-deploy-step`: runs one production deploy step as the Instance user in its release, and publishes
//! each line of its output on the deploy operation's Reverb channel while it runs. The result carries the output for
//! the deployment record. Without Reverb the step still runs; only the live lines are missing.

use super::production::valid_name;
use super::source::{canonical, is_real_directory};
use crate::command::{identity, Command, Context, Platform, Privilege};
use crate::command_errors;
use crate::logs::redact::Redactor;
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::{
    io::{BufRead, BufReader, Read, Write},
    os::unix::fs::OpenOptionsExt,
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    sync::mpsc,
    time::Duration,
};

/// The most output the result keeps; older output is dropped first, as the deployment record does.
const OUTPUT_LIMIT: usize = 131_072;
/// The longest line one event carries, below Reverb's frame limit.
const LINE_LIMIT: usize = 8_000;

command_errors! {
    pub enum DeployStepError {
        Invalid { check: String } => "instances.deploy_step_invalid", "The release or the step does not pass its checks.";
        StartFailed { reason: String } => "instances.deploy_step_start_failed", "The step could not be started.";
    }
}

fn check(condition: bool, what: &str) -> Result<(), DeployStepError> {
    if condition {
        Ok(())
    } else {
        Err(DeployStepError::Invalid { check: what.into() })
    }
}

pub struct RunDeployStep;

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RunDeployStepInput {
    pub user: String,
    pub home: PathBuf,
    pub release: String,
    /// The step's program, which runs with `bash -eu` in the release.
    pub script: String,
    /// The deploy operation, whose channel `private-operation.{id}` receives the lines. Without one, no line is live.
    #[serde(default)]
    pub operation: Option<u64>,
    pub step: String,
    /// Values that never leave the Node, such as the Instance's stored environment values.
    #[serde(default)]
    pub secrets: Vec<String>,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Chunk {
    pub stream: &'static str,
    pub value: String,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct RunDeployStepOutput {
    pub exit_code: i32,
    /// The redacted output in the order it was written.
    pub output: Vec<Chunk>,
    /// Whether older output was dropped.
    pub truncated: bool,
}

/// Redacts one line with the shared rules and the step's own secret values. `None` drops a line inside a PEM block.
fn redact(redactor: &mut Redactor, line: &str, secrets: &[String]) -> Option<String> {
    let mut line = redactor.line(line)?;
    for secret in secrets.iter().filter(|secret| !secret.is_empty()) {
        line = line.replace(secret.as_str(), "[REDACTED]");
    }
    Some(line)
}

/// Keeps the newest output within `OUTPUT_LIMIT`.
fn keep(output: &mut Vec<Chunk>, size: &mut usize, truncated: &mut bool, chunk: Chunk) {
    *size += chunk.value.len();
    output.push(chunk);
    while *size > OUTPUT_LIMIT && output.len() > 1 {
        *size -= output.remove(0).value.len();
        *truncated = true;
    }
}

/// Publishes each event on the operation's channel until the sender closes. Failures only end the live view.
fn publish(channel: String, mut events: tokio::sync::mpsc::UnboundedReceiver<Value>) {
    let result = (|| -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
        let runtime = tokio::runtime::Builder::new_current_thread()
            .enable_all()
            .build()?;
        runtime.block_on(async {
            let config = crate::Config::load()?;
            let secret = crate::AgentSecret::load(crate::SECRET_PATH)?;
            let client = crate::gateway_client(config.gateway_address, &secret)?;
            let discovery = crate::realtime::discover(&client, &config.gateway_url).await?;
            let (Some(url), Some(address), Some(key)) =
                (discovery.url, discovery.address, discovery.key)
            else {
                return Ok(());
            };
            let (mut socket, _) = crate::realtime::join(
                &client,
                &config.gateway_url,
                &url,
                &address,
                &key,
                &channel,
                crate::tls_config()?,
            )
            .await?;
            while let Some(data) = events.recv().await {
                crate::realtime::send_client(&mut socket, &channel, "client-output", data).await?;
            }
            let _ = futures_util::SinkExt::close(&mut socket).await;
            Ok(())
        })
    })();
    if let Err(error) = result {
        eprintln!("orbit-agent: live deploy output is off for this step: {error}");
    }
}

pub fn run(
    input: &RunDeployStepInput,
    home_root: &Path,
    live: Option<tokio::sync::mpsc::UnboundedSender<Value>>,
) -> Result<RunDeployStepOutput, DeployStepError> {
    check(
        input.home == home_root.join(&input.user),
        "the home is the user's",
    )?;
    check(valid_name(&input.release), "the release name is valid")?;
    let release = input.home.join("releases").join(&input.release);
    check(
        is_real_directory(&release) && canonical(&release),
        "the release is a canonical directory",
    )?;
    let (uid, gid, _) = identity::account(&input.user).map_err(|_| DeployStepError::Invalid {
        check: "the production user exists".into(),
    })?;

    // The program goes in a private file of the user, never in an argument that other users could read.
    let file = input
        .home
        .join(format!(".orbit-deploy.{:016x}", rand::random::<u64>()));
    let written = std::fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o600)
        .open(&file)
        .and_then(|mut handle| handle.write_all(input.script.as_bytes()))
        .and_then(|()| {
            rustix::fs::chown(
                &file,
                Some(rustix::fs::Uid::from_raw(uid)),
                Some(rustix::fs::Gid::from_raw(gid)),
            )
            .map_err(Into::into)
        });
    if let Err(error) = written {
        let _ = std::fs::remove_file(&file);
        return Err(DeployStepError::StartFailed {
            reason: error.to_string(),
        });
    }
    let result = run_program(input, &release, &file, live);
    let _ = std::fs::remove_file(&file);
    result
}

fn run_program(
    input: &RunDeployStepInput,
    release: &Path,
    file: &Path,
    live: Option<tokio::sync::mpsc::UnboundedSender<Value>>,
) -> Result<RunDeployStepOutput, DeployStepError> {
    let mut command = Process::new("setsid");
    command
        .args([
            "--wait",
            "bash",
            "-eu",
            "-c",
            "umask 077; cd -- \"$1\"; exec bash -eu \"$2\"",
            "bash",
        ])
        .arg(release)
        .arg(file)
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped());
    identity::drop_to(&mut command, &input.user)
        .map_err(|reason| DeployStepError::StartFailed { reason })?;
    let mut child = command
        .spawn()
        .map_err(|error| DeployStepError::StartFailed {
            reason: error.to_string(),
        })?;
    let (lines, received) = mpsc::channel::<(&'static str, String)>();
    let readers = [
        (
            "stdout",
            child
                .stdout
                .take()
                .map(|pipe| Box::new(pipe) as Box<dyn Read + Send>),
        ),
        (
            "stderr",
            child
                .stderr
                .take()
                .map(|pipe| Box::new(pipe) as Box<dyn Read + Send>),
        ),
    ]
    .into_iter()
    .filter_map(|(stream, pipe)| pipe.map(|pipe| (stream, pipe)))
    .map(|(stream, pipe)| {
        let lines = lines.clone();
        std::thread::spawn(move || {
            let mut reader = BufReader::new(pipe);
            let mut line = Vec::new();
            while reader
                .read_until(b'\n', &mut line)
                .is_ok_and(|read| read > 0)
            {
                let _ = lines.send((stream, String::from_utf8_lossy(&line).into_owned()));
                line.clear();
            }
        })
    })
    .collect::<Vec<_>>();
    drop(lines);

    let mut redactors = (Redactor::default(), Redactor::default());
    let (mut output, mut size, mut truncated, mut sequence) = (Vec::new(), 0, false, 0u64);
    for (stream, line) in received {
        let redactor = if stream == "stdout" {
            &mut redactors.0
        } else {
            &mut redactors.1
        };
        let ending = if line.ends_with('\n') { "\n" } else { "" };
        let Some(text) = redact(redactor, line.trim_end_matches('\n'), &input.secrets) else {
            continue;
        };
        if let Some(live) = &live {
            sequence += 1;
            let shown: String = text.chars().take(LINE_LIMIT).collect();
            let _ = live.send(
                json!({"step": input.step, "stream": stream, "sequence": sequence, "line": shown}),
            );
        }
        keep(
            &mut output,
            &mut size,
            &mut truncated,
            Chunk {
                stream,
                value: format!("{text}{ending}"),
            },
        );
    }
    for reader in readers {
        let _ = reader.join();
    }
    let status = child.wait().map_err(|error| DeployStepError::StartFailed {
        reason: error.to_string(),
    })?;
    let exit_code = status.code().unwrap_or_else(|| {
        128 + std::os::unix::process::ExitStatusExt::signal(&status).unwrap_or(0)
    });
    Ok(RunDeployStepOutput {
        exit_code,
        output,
        truncated,
    })
}

impl Command for RunDeployStep {
    const NAME: &'static str = "instances.run-deploy-step";
    const SUMMARY: &'static str =
        "Run a production deploy step as the Instance user and publish its output live.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(3600);
    type Input = RunDeployStepInput;
    type Output = RunDeployStepOutput;
    type Error = DeployStepError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn run(input: RunDeployStepInput, _: &Context) -> Result<RunDeployStepOutput, DeployStepError> {
        let Some(operation) = input.operation else {
            return run(&input, Path::new("/home"), None);
        };
        let (events, received) = tokio::sync::mpsc::unbounded_channel();
        let publisher =
            std::thread::spawn(move || publish(format!("private-operation.{operation}"), received));
        let result = run(&input, Path::new("/home"), Some(events));
        // The sender is gone, so the publisher sends what it has and closes.
        let _ = publisher.join();
        result
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn fixture() -> (PathBuf, RunDeployStepInput) {
        let base = std::fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!("orbit-deploy-step-{:016x}", rand::random::<u64>()));
        let uid = rustix::process::geteuid().as_raw();
        let user = super::super::source::user_name(uid);
        let home = base.join(&user);
        std::fs::create_dir_all(home.join("releases/r1")).unwrap();
        let input = RunDeployStepInput {
            user,
            home,
            release: "r1".into(),
            script: "echo building\necho \"key=$SECRET_VALUE\" >&2\npwd\n".into(),
            operation: Some(7),
            step: "build".into(),
            secrets: vec!["s3cret".into()],
        };
        (base, input)
    }

    #[test]
    fn runs_in_the_release_and_publishes_each_redacted_line_in_order() {
        let (base, mut input) = fixture();
        input.script = input.script.replace("$SECRET_VALUE", "s3cret");
        let (live, mut received) = tokio::sync::mpsc::unbounded_channel();
        let output = run(&input, &base, Some(live)).unwrap();
        let release = input.home.join("releases/r1");
        assert_eq!(output.exit_code, 0);
        // stdout and stderr are separate pipes, so only each stream keeps its own order.
        for chunk in [
            Chunk {
                stream: "stdout",
                value: "building\n".into(),
            },
            Chunk {
                stream: "stderr",
                value: "key=[REDACTED]\n".into(),
            },
            Chunk {
                stream: "stdout",
                value: format!("{}\n", release.display()),
            },
        ] {
            assert!(output.output.contains(&chunk), "{chunk:?}");
        }
        assert_eq!(output.output.len(), 3);
        let mut events = Vec::new();
        while let Ok(event) = received.try_recv() {
            events.push(event);
        }
        assert_eq!(events.len(), 3);
        assert!(events
            .iter()
            .all(|event| event["step"] == "build"
                && !event["line"].as_str().unwrap().contains("s3cret")));
        assert!(std::fs::read_dir(&input.home).unwrap().all(|entry| !entry
            .unwrap()
            .file_name()
            .to_string_lossy()
            .starts_with(".orbit-deploy.")));
        std::fs::remove_dir_all(base).unwrap();
    }

    #[test]
    fn a_failing_step_returns_its_exit_code_and_output() {
        let (base, mut input) = fixture();
        input.script = "echo before\nexit 3\n".into();
        let output = run(&input, &base, None).unwrap();
        assert_eq!(output.exit_code, 3);
        assert_eq!(
            output.output,
            [Chunk {
                stream: "stdout",
                value: "before\n".into()
            }]
        );
        std::fs::remove_dir_all(base).unwrap();
    }

    #[test]
    fn refuses_a_release_outside_the_home() {
        let (base, mut input) = fixture();
        input.release = "../r1".into();
        assert_eq!(
            run(&input, &base, None),
            Err(DeployStepError::Invalid {
                check: "the release name is valid".into()
            })
        );
        std::fs::remove_dir_all(base).unwrap();
    }

    #[test]
    fn keeps_only_the_newest_output_within_the_limit() {
        let (mut output, mut size, mut truncated) = (Vec::new(), 0, false);
        for _ in 0..3 {
            keep(
                &mut output,
                &mut size,
                &mut truncated,
                Chunk {
                    stream: "stdout",
                    value: "x".repeat(OUTPUT_LIMIT / 2),
                },
            );
        }
        assert_eq!(output.len(), 2);
        assert!(truncated);
    }
}
