//! A step program run in its own session as an account, with its redacted output kept for the result.

use crate::command::identity;
use crate::command_errors;
use crate::logs::redact::Redactor;
use serde::Serialize;
use std::collections::BTreeMap;
use std::{
    io::{BufRead, BufReader, Read},
    path::Path,
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
    Fixed {
        executable: &'a str,
        arguments: &'a [&'a std::ffi::OsStr],
    },
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
