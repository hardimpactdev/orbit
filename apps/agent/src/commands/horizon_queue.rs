//! `instance.read-queue`: a read-only report of an Instance's Laravel Horizon queue. The agent runs its fixed report
//! script, `horizon_queue.php`, with the Instance's PHP in the Process's working directory, as the Process's user. The
//! PHP reads the script from standard input, the script takes only the validated state and limit as arguments, and prints one
//! JSON document after a marker line, never a job payload.

use crate::command::{identity, Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::{
    io::Write,
    path::PathBuf,
    process::{Command as Process, Stdio},
    time::Duration,
};

const SCRIPT: &str = include_str!("horizon_queue.php");
const MARKER: &str = "\n--orbit-queue--\n";

command_errors! {
    pub enum QueueError {
        Invalid { check: String } => "instance.queue_invalid", "The queue request does not pass its checks.";
        Failed {} => "instance.queue_failed", "The queue report did not run or printed no report.";
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct QueueInput {
    pub user: String,
    pub directory: PathBuf,
    pub php: PathBuf,
    pub state: String,
    pub limit: u8,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct QueueReport {
    pub report: Value,
}

/// The report after the last marker line of the script's output.
fn report(stdout: &str) -> Option<Value> {
    let start = stdout.rfind(MARKER)? + MARKER.len();
    serde_json::from_str::<Value>(&stdout[start..])
        .ok()
        .filter(Value::is_object)
}

pub fn read(input: &QueueInput) -> Result<QueueReport, QueueError> {
    if !matches!(input.state.as_str(), "pending" | "completed" | "failed") {
        return Err(QueueError::Invalid {
            check: "the state is pending, completed, or failed".into(),
        });
    }
    if !(1..=50).contains(&input.limit)
        || !input.directory.is_absolute()
        || !input.php.is_absolute()
    {
        return Err(QueueError::Invalid {
            check: "the limit is 1 to 50 and the paths are absolute".into(),
        });
    }
    let mut command = Process::new("timeout");
    command
        .arg("25")
        .arg(&input.php)
        .args([
            "-d",
            "display_errors=0",
            "--",
            &input.state,
            &input.limit.to_string(),
        ])
        .current_dir(&input.directory)
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::null());
    identity::drop_to(&mut command, &input.user).map_err(|_| QueueError::Failed {})?;
    let mut child = command.spawn().map_err(|_| QueueError::Failed {})?;
    if let Some(mut stdin) = child.stdin.take() {
        let _ = stdin.write_all(SCRIPT.as_bytes());
    }
    let output = child
        .wait_with_output()
        .map_err(|_| QueueError::Failed {})?;
    let stdout = String::from_utf8_lossy(&output.stdout);
    match (output.status.success(), report(&stdout)) {
        (true, Some(report)) => Ok(QueueReport { report }),
        _ => Err(QueueError::Failed {}),
    }
}

pub struct ReadQueue;

impl Command for ReadQueue {
    const NAME: &'static str = "instance.read-queue";
    const SUMMARY: &'static str = "Report an Instance's Horizon queue, without job payloads.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = QueueInput;
    type Output = QueueReport;
    type Error = QueueError;

    /// The report runs as the Process's user, which only root can switch to.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(input: QueueInput, _: &Context) -> Result<QueueReport, QueueError> {
        read(&input)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_the_report_after_the_last_marker() {
        assert_eq!(
            report("noise\n--orbit-queue--\n{\"a\":1}\n--orbit-queue--\n{\"installed\":false}"),
            Some(serde_json::json!({"installed": false}))
        );
        assert_eq!(report("{\"installed\":false}"), None);
        assert_eq!(report("\n--orbit-queue--\n[1]"), None);
    }

    #[test]
    fn refuses_a_state_or_limit_outside_the_report_before_running() {
        let input = |state: &str, limit: u8| QueueInput {
            user: "nobody".into(),
            directory: "/srv".into(),
            php: "/usr/bin/php".into(),
            state: state.into(),
            limit,
        };
        assert!(matches!(
            read(&input("deleted", 5)),
            Err(QueueError::Invalid { .. })
        ));
        assert!(matches!(
            read(&input("failed", 0)),
            Err(QueueError::Invalid { .. })
        ));
        assert!(matches!(
            read(&input("failed", 51)),
            Err(QueueError::Invalid { .. })
        ));
    }
}
