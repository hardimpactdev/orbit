//! One production deploy step run as the Instance user in its release. The result carries the redacted output for
//! the deployment record.

use super::production::valid_name;
use super::source::{canonical, is_real_directory};
use crate::command::identity;
use crate::command_errors;
use crate::logs::redact::Redactor;
use serde::{Deserialize, Serialize};
use std::collections::BTreeMap;
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

command_errors! {
    pub enum DeployStepError {
        Invalid { check: String } => "instance.deploy_step_invalid", "The release or the step does not pass its checks.";
        StartFailed { reason: String } => "instance.deploy_step_start_failed", "The step could not be started.";
    }
}

fn check(condition: bool, what: &str) -> Result<(), DeployStepError> {
    if condition {
        Ok(())
    } else {
        Err(DeployStepError::Invalid { check: what.into() })
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RunDeployStepInput {
    pub user: String,
    pub home: PathBuf,
    pub release: String,
    /// The step's program, which runs with `bash -eu` in the release.
    pub script: String,
    #[serde(default)]
    pub vp_environment: Option<super::vp_store::Environment>,
    pub step: String,
    /// The step's own bound. At its end the step's process group stops, and the step fails with its output.
    pub timeout_seconds: u64,
    /// Values that never leave the Node, such as the Instance's stored environment values.
    #[serde(default)]
    pub secrets: Vec<String>,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Chunk {
    pub stream: &'static str,
    pub value: String,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct RunDeployStepOutput {
    pub exit_code: i32,
    /// Whether the step's own bound stopped it.
    pub timed_out: bool,
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
fn keep(output: &mut Vec<Chunk>, size: &mut usize, truncated: &mut bool, mut chunk: Chunk) {
    if chunk.value.len() > OUTPUT_LIMIT {
        let mut start = chunk.value.len() - OUTPUT_LIMIT;
        while !chunk.value.is_char_boundary(start) {
            start += 1;
        }
        chunk.value = chunk.value[start..].to_string();
        *truncated = true;
    }
    *size += chunk.value.len();
    output.push(chunk);
    while *size > OUTPUT_LIMIT && output.len() > 1 {
        *size -= output.remove(0).value.len();
        *truncated = true;
    }
}

pub fn run(
    input: &RunDeployStepInput,
    home_root: &Path,
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

    let vp = prepare_vp(input.vp_environment.as_ref())?;
    if let Some(vp) = &vp {
        vp.allow_instance(&input.user)
            .map_err(|_| DeployStepError::Invalid {
                check: "the Instance can execute its shared Vite+ runtime".into(),
            })?;
    }

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
    let result = run_program(&Program {
        user: Some(&input.user),
        directory: &release,
        command: StepCommand::script(&file, vp.as_ref()),
        secrets: &input.secrets,
        environment: &BTreeMap::new(),
        timeout: Duration::from_secs(input.timeout_seconds),
    });
    let _ = std::fs::remove_file(&file);
    result
}

/// Runs a step's program file with `bash -eu` in its own session in `directory`, as `user` when one is given, and
/// keeps its redacted output.
/// A step program to run: its account, directory, script file, secret values, environment, and bound.
pub(super) struct Program<'a> {
    pub user: Option<&'a str>,
    pub directory: &'a Path,
    pub command: StepCommand<'a>,
    pub secrets: &'a [String],
    pub environment: &'a BTreeMap<String, String>,
    pub timeout: Duration,
}

#[derive(Clone, Copy)]
pub(super) enum StepCommand<'a> {
    Script(&'a Path),
    VpScript {
        file: &'a Path,
        execution: &'a super::vp_store::Deployment,
    },
    Fixed {
        executable: &'a str,
        arguments: &'a [&'a std::ffi::OsStr],
    },
}

impl<'a> StepCommand<'a> {
    pub(super) fn script(file: &'a Path, vp: Option<&'a super::vp_store::Deployment>) -> Self {
        match vp {
            Some(execution) => Self::VpScript { file, execution },
            None => Self::Script(file),
        }
    }
}

pub(super) fn prepare_vp(
    environment: Option<&super::vp_store::Environment>,
) -> Result<Option<super::vp_store::Deployment>, DeployStepError> {
    environment
        .map(|environment| {
            environment
                .deployment()
                .map_err(|_| DeployStepError::Invalid {
                    check: "the Vite+ environment is installed in its owner's store".into(),
                })
        })
        .transpose()
}

pub(super) fn run_program(program: &Program) -> Result<RunDeployStepOutput, DeployStepError> {
    let Program {
        user,
        directory,
        command: execution,
        secrets,
        environment,
        timeout,
    } = *program;
    let mut command = Process::new("setsid");
    command
        .envs(environment)
        .arg("--wait")
        .current_dir(directory);
    match execution {
        StepCommand::Script(file) => {
            command.args(["bash", "-eu"]).arg(file);
        }
        StepCommand::VpScript { file, execution } => {
            command
                .env("VP_HOME", &execution.store)
                .args(&execution.arguments)
                .args(["bash", "-eu"])
                .arg(file);
        }
        StepCommand::Fixed {
            executable,
            arguments,
        } => {
            command.arg(executable).args(arguments);
        }
    }
    use std::os::unix::process::CommandExt;
    // SAFETY: umask is async-signal-safe; only this child changes its file creation mask.
    unsafe {
        command.pre_exec(|| {
            libc::umask(0o077);
            Ok(())
        });
    }
    command
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped());
    if let Some(user) = user {
        identity::drop_to(&mut command, user)
            .map_err(|reason| DeployStepError::StartFailed { reason })?;
    }
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
    // The step ends with its shell, or at its timeout. A process it left behind, such as a daemon, would keep the
    // output open, so the step's process group stops then: TERM, and KILL after 100 milliseconds. `setsid` runs the
    // shell as the group's leader, so the group has the child's pid.
    let group = rustix::process::Pid::from_child(&child);
    let stop = move || {
        for signal in [rustix::process::Signal::TERM, rustix::process::Signal::KILL] {
            if rustix::process::kill_process_group(group, signal).is_err() {
                break;
            }
            std::thread::sleep(Duration::from_millis(100));
        }
    };
    let end = std::time::Instant::now() + timeout;
    let waiter = std::thread::spawn(move || loop {
        match child.try_wait() {
            Ok(Some(status)) => {
                stop();
                break Ok((status, false));
            }
            Ok(None) if std::time::Instant::now() >= end => {
                stop();
                break child.wait().map(|status| (status, true));
            }
            Ok(None) => std::thread::sleep(Duration::from_millis(50)),
            Err(error) => break Err(error),
        }
    });

    let mut redactors = (Redactor::default(), Redactor::default());
    let (mut output, mut size, mut truncated) = (Vec::new(), 0, false);
    for (stream, line) in received {
        let redactor = if stream == "stdout" {
            &mut redactors.0
        } else {
            &mut redactors.1
        };
        let ending = if line.ends_with('\n') { "\n" } else { "" };
        let Some(text) = redact(redactor, line.trim_end_matches('\n'), secrets) else {
            continue;
        };
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
    let (status, timed_out) = waiter
        .join()
        .map_err(|_| DeployStepError::StartFailed {
            reason: "the step's waiter stopped".into(),
        })?
        .map_err(|error| DeployStepError::StartFailed {
            reason: error.to_string(),
        })?;
    let exit_code = status.code().unwrap_or_else(|| {
        128 + std::os::unix::process::ExitStatusExt::signal(&status).unwrap_or(0)
    });
    Ok(RunDeployStepOutput {
        exit_code,
        timed_out,
        output,
        truncated,
    })
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
            vp_environment: None,
            step: "build".into(),
            timeout_seconds: 60,
            secrets: vec!["s3cret".into()],
        };
        (base, input)
    }

    #[test]
    fn runs_in_the_release_and_keeps_each_redacted_line() {
        let (base, mut input) = fixture();
        input.script = input.script.replace("$SECRET_VALUE", "s3cret");
        let output = run(&input, &base).unwrap();
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
        let output = run(&input, &base).unwrap();
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
            run(&input, &base),
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

    #[test]
    fn keeps_only_the_tail_of_a_line_above_the_limit() {
        let (mut output, mut size, mut truncated) = (Vec::new(), 0, false);
        keep(
            &mut output,
            &mut size,
            &mut truncated,
            Chunk {
                stream: "stderr",
                value: format!("{}tail\n", "x".repeat(OUTPUT_LIMIT * 2)),
            },
        );
        assert_eq!(output[0].value.len(), OUTPUT_LIMIT);
        assert!(output[0].value.ends_with("tail\n") && truncated);
    }
}
