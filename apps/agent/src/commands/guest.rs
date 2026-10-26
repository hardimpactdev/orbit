//! Guest readiness and identity, with the same rules before and after enrollment.
use super::incus::{bounded, Output};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::time::Duration;
command_errors! {
    pub enum Error {
        Invalid { reason:String } => "task_vm.invalid_host_output", "The guest returned invalid output.";
        Failed { operation:String, exit_code:i32, stderr:String } => "task_vm.host_command_failed", "The guest command failed.";
        Bootstrap { errors:String } => "task_vm.bootstrap_failed", "Cloud-init failed in the guest.";
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Ready {
    ready: bool,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Fingerprint {
    pub(super) fingerprint: String,
}
fn invalid(reason: &str) -> Error {
    Error::Invalid {
        reason: reason.into(),
    }
}
fn readiness(output: Output) -> Result<Ready, Error> {
    if output.code != 0 && output.stdout.trim().is_empty() && !output.truncated {
        return Ok(Ready { ready: false });
    }
    let data: Value = if output.truncated {
        return Err(invalid("the cloud-init status is not complete JSON"));
    } else {
        serde_json::from_str(&output.stdout)
            .map_err(|_| invalid("the cloud-init status is not complete JSON"))?
    };
    let status = data.get("status").and_then(Value::as_str);
    let errors = data.get("errors").and_then(Value::as_array);
    let (Some(status), Some(errors)) = (status, errors) else {
        return Err(invalid("the cloud-init status has no status or errors"));
    };
    if status == "error" || (status == "done" && !errors.is_empty()) {
        return Err(Error::Bootstrap {
            errors: serde_json::to_string(errors)
                .unwrap()
                .chars()
                .take(2000)
                .collect(),
        });
    }
    Ok(Ready {
        ready: status == "done",
    })
}
pub(super) fn fingerprint(output: Output) -> Result<Fingerprint, Error> {
    if output.code != 0 {
        return Err(Error::Failed {
            operation: "ssh-keygen".into(),
            exit_code: output.code,
            stderr: output.stderr.chars().take(2000).collect(),
        });
    }
    let pattern =
        regex::Regex::new(r"\A256 (SHA256:[A-Za-z0-9+/]{43}) [^\n]* \(ED25519\)\n?\z").unwrap();
    let value = if output.truncated {
        None
    } else {
        pattern.captures(&output.stdout)
    }
    .ok_or_else(|| invalid("the host key fingerprint is not an ed25519 SHA256 fingerprint"))?;
    Ok(Fingerprint {
        fingerprint: value[1].into(),
    })
}
pub struct ReadCloudInit;
impl Command for ReadCloudInit {
    const NAME: &'static str = "guest.cloud-init";
    const SUMMARY: &'static str =
        "Wait briefly for cloud-init and return typed bootstrap readiness.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(45);
    type Input = Input;
    type Output = Ready;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(_: Input, context: &Context) -> Result<Ready, Error> {
        readiness(bounded(
            context,
            "/usr/bin/timeout",
            &[
                "30",
                "/usr/bin/cloud-init",
                "status",
                "--wait",
                "--format=json",
            ],
            None,
        ))
    }
}
pub struct ReadFingerprint;
impl Command for ReadFingerprint {
    const NAME: &'static str = "guest.fingerprint";
    const SUMMARY: &'static str =
        "Read the guest's ed25519 SSH host fingerprint through its trusted transport.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = Input;
    type Output = Fingerprint;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(_: Input, context: &Context) -> Result<Fingerprint, Error> {
        fingerprint(bounded(
            context,
            "/usr/bin/ssh-keygen",
            &["-l", "-f", "/etc/ssh/ssh_host_ed25519_key.pub"],
            None,
        ))
    }
}
#[cfg(test)]
mod tests {
    use super::*;
    fn out(code: i32, text: &str) -> Output {
        Output {
            code,
            stdout: text.into(),
            stderr: String::new(),
            truncated: false,
        }
    }
    #[test]
    fn accepts_done_repeatedly_and_keeps_incomplete_boots_pending() {
        for _ in 0..2 {
            assert_eq!(
                readiness(out(0, r#"{"status":"done","errors":[]}"#)),
                Ok(Ready { ready: true })
            );
        }
        for (code, value) in [
            (0, r#"{"status":"running","errors":[]}"#),
            (124, ""),
            (255, ""),
        ] {
            assert_eq!(readiness(out(code, value)), Ok(Ready { ready: false }));
        }
    }
    #[test]
    fn bootstrap_errors_are_typed_and_bounded() {
        for status in ["error", "done"] {
            let text = serde_json::json!({"status":status,"errors":["apt failed"]}).to_string();
            assert!(
                matches!(readiness(out(2,&text)),Err(Error::Bootstrap{errors}) if errors=="[\"apt failed\"]")
            );
        }
        assert!(matches!(
            readiness(out(1, r#"{"status":"error","errors":[]}"#)),
            Err(Error::Bootstrap { .. })
        ));
    }
    #[test]
    fn malformed_and_truncated_status_never_report_pending_or_ready() {
        for value in [
            "done",
            "[]",
            "{}",
            r#"{"status":2,"errors":[]}"#,
            r#"{"status":"done","errors":{}}"#,
        ] {
            assert!(matches!(
                readiness(out(0, value)),
                Err(Error::Invalid { .. })
            ));
        }
        let mut value = out(0, r#"{"status":"done","errors":[]}"#);
        value.truncated = true;
        assert!(matches!(readiness(value), Err(Error::Invalid { .. })));
    }
    #[test]
    fn fingerprint_checks_algorithm_shape_failures_truncation_and_repeats() {
        let pin = format!("SHA256:{}", "a".repeat(43));
        let text = format!("256 {pin} root@image (ED25519)\n");
        for _ in 0..2 {
            assert_eq!(
                fingerprint(out(0, &text)),
                Ok(Fingerprint {
                    fingerprint: pin.clone()
                })
            );
        }
        for value in [
            "bad",
            &text.replace("ED25519", "RSA"),
            &text.replace("256 ", "2048 "),
            &format!("{text}second\n"),
        ] {
            assert!(matches!(
                fingerprint(out(0, value)),
                Err(Error::Invalid { .. })
            ));
        }
        assert!(matches!(fingerprint(out(1, "")), Err(Error::Failed { .. })));
        let mut output = out(0, &text);
        output.truncated = true;
        assert!(matches!(fingerprint(output), Err(Error::Invalid { .. })));
        assert_eq!(ReadCloudInit::privilege(Platform::Macos), None);
        assert!(serde_json::from_str::<Input>(r#"{"program":"x"}"#).is_err());
    }
}
