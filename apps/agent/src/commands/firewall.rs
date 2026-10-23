//! The Node's UFW firewall. `firewall.status` reads `ufw status numbered` and, on request, the stored rule tuples
//! that `ufw status` does not show. `firewall.ufw` runs one `ufw` change. The Gateway owns the rules, parses the
//! status, and decides each change.

use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    fs,
    process::{Command as Process, Output, Stdio},
    time::Duration,
};

command_errors! {
    pub enum UfwError {
        Failed { exit_code: i32, stdout: String, stderr: String } => "firewall.ufw_failed", "UFW failed.";
    }
}

/// The files that hold the rules UFW applies, with the family each holds.
const STORED: [(&str, &str); 2] = [
    ("/etc/ufw/user.rules", "v4"),
    ("/etc/ufw/user6.rules", "v6"),
];

fn ufw(arguments: &[String]) -> Result<Output, UfwError> {
    Process::new("ufw")
        .args(arguments)
        .stdin(Stdio::null())
        .output()
        .map_err(|error| UfwError::Failed {
            exit_code: 127,
            stdout: String::new(),
            stderr: error.to_string(),
        })
}

fn checked(output: Output) -> Result<String, UfwError> {
    let stdout = String::from_utf8_lossy(&output.stdout).into_owned();
    if output.status.success() {
        return Ok(stdout);
    }
    Err(UfwError::Failed {
        exit_code: output.status.code().unwrap_or(1),
        stdout,
        stderr: String::from_utf8_lossy(&output.stderr).into_owned(),
    })
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct StatusInput {
    /// Also read the stored rule tuples.
    pub stored: bool,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Status {
    pub status: String,
    /// `__orbit_ufw_tuple:<family>:<line>` for each `### tuple ###` line of the stored rules.
    pub stored: Vec<String>,
}

/// The `### tuple ###` lines of the stored rules, tagged with their family.
pub fn stored_tuples(files: &[(&str, &str)]) -> Result<Vec<String>, UfwError> {
    let mut tuples = Vec::new();
    for (file, family) in files {
        let text = fs::read_to_string(file).map_err(|error| UfwError::Failed {
            exit_code: 2,
            stdout: String::new(),
            stderr: format!("{file}: {error}"),
        })?;
        tuples.extend(
            text.lines()
                .filter(|line| line.starts_with("### tuple ### "))
                .map(|line| format!("__orbit_ufw_tuple:{family}:{line}")),
        );
    }
    Ok(tuples)
}

pub fn status(input: &StatusInput) -> Result<Status, UfwError> {
    let status = checked(ufw(&["status".into(), "numbered".into()])?)?;
    let stored = if input.stored {
        stored_tuples(&STORED)?
    } else {
        Vec::new()
    };
    Ok(Status { status, stored })
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct UfwInput {
    /// The arguments after `ufw`, such as `["--force", "delete", "3"]`.
    pub arguments: Vec<String>,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Changed {
    pub stdout: String,
}

pub struct FirewallStatus;

impl Command for FirewallStatus {
    const NAME: &'static str = "firewall.status";
    const SUMMARY: &'static str =
        "Read the numbered UFW status and, on request, the stored rule tuples.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = StatusInput;
    type Output = Status;
    type Error = UfwError;

    /// UFW and its rule files answer only root.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(input: StatusInput, _: &Context) -> Result<Status, UfwError> {
        status(&input)
    }
}

pub struct ChangeFirewall;

impl Command for ChangeFirewall {
    const NAME: &'static str = "firewall.ufw";
    const SUMMARY: &'static str = "Run one UFW change.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = UfwInput;
    type Output = Changed;
    type Error = UfwError;

    /// UFW changes the kernel firewall, which only root may do.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn target(_: &UfwInput) -> Option<String> {
        Some("firewall".into())
    }

    fn run(input: UfwInput, _: &Context) -> Result<Changed, UfwError> {
        checked(ufw(&input.arguments)?).map(|stdout| Changed { stdout })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn stored_tuples_keep_only_tuple_lines_with_their_family() {
        let root = std::env::temp_dir().join(format!("orbit-ufw-{:016x}", rand::random::<u64>()));
        fs::create_dir_all(&root).unwrap();
        let v4 = root.join("user.rules");
        let v6 = root.join("user6.rules");
        fs::write(
            &v4,
            "*filter\n### tuple ### allow tcp 22 0.0.0.0/0 any 0.0.0.0/0 in\n-A x\n",
        )
        .unwrap();
        fs::write(&v6, "### tuple ### deny udp 53 ::/0 any ::/0 in\n").unwrap();
        let tuples =
            stored_tuples(&[(v4.to_str().unwrap(), "v4"), (v6.to_str().unwrap(), "v6")]).unwrap();
        assert_eq!(
            tuples,
            [
                "__orbit_ufw_tuple:v4:### tuple ### allow tcp 22 0.0.0.0/0 any 0.0.0.0/0 in",
                "__orbit_ufw_tuple:v6:### tuple ### deny udp 53 ::/0 any ::/0 in",
            ]
        );
        let _ = fs::remove_dir_all(&root);
    }
}
