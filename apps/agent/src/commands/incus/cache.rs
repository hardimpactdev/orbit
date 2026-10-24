//! The cache mount is a fixed read-only host disk, never caller-selected paths.
use super::{read, Host, Native};
use crate::commands::package_cache::{GUEST, HOST};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::time::Duration;
command_errors! {
 pub enum Error {
  Invalid {} => "incus.input_invalid", "The Incus guest identity is invalid.";
  Mount {} => "task_vm.package_cache_mount_invalid", "The package cache device is not the expected read-only host mount.";
  Failed {operation:String,reason:String} => "task_vm.package_cache_failed", "The package cache mount operation failed.";
 }
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    project: String,
    name: String,
}
#[derive(Debug, Serialize)]
pub struct Mounted {
    ready: bool,
}
fn run(host: &mut impl Host, input: &Input) -> Result<Mounted, Error> {
    if !super::valid_name(&input.project) || !super::valid_name(&input.name) {
        return Err(Error::Invalid {});
    }
    let mut call = |op: &str, args: &[&str]| {
        read(host, op, args).map_err(|e| Error::Failed {
            operation: op.into(),
            reason: match e {
                super::Error::Failed { stderr, .. } => stderr,
                super::Error::Output { reason, .. } => reason,
                _ => "The Incus operation failed".into(),
            },
        })
    };
    let text = call(
        "read device",
        &[
            "query",
            "--",
            &format!("/1.0/instances/{}?project={}", input.name, input.project),
        ],
    )?;
    let data: Value = serde_json::from_str(&text).map_err(|_| Error::Failed {
        operation: "read device".into(),
        reason: "The device result is not complete JSON".into(),
    })?;
    let devices = data["devices"].as_object().ok_or_else(|| Error::Failed {
        operation: "read device".into(),
        reason: "The device inventory is missing".into(),
    })?;
    let expected = json!({"type":"disk","source":HOST,"path":GUEST,"readonly":"true"});
    if let Some(device) = devices.get("package-cache") {
        return if device == &expected {
            Ok(Mounted { ready: true })
        } else {
            Err(Error::Mount {})
        };
    }
    call(
        "permit host mount",
        &[
            "--project",
            &input.project,
            "project",
            "set",
            "--",
            &input.project,
            "restricted.devices.disk=allow",
            &format!("restricted.devices.disk.paths={HOST}"),
        ],
    )?;
    call(
        "attach mount",
        &[
            "--project",
            &input.project,
            "config",
            "device",
            "add",
            "--",
            &input.name,
            "package-cache",
            "disk",
            &format!("source={HOST}"),
            &format!("path={GUEST}"),
            "readonly=true",
        ],
    )?;
    Ok(Mounted { ready: true })
}
pub struct MountCache;
impl Command for MountCache {
    const NAME: &'static str = "incus.package-cache";
    const SUMMARY: &'static str = "Attach the fixed read-only host package cache to one task VM.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    const RESOURCES: &'static [&'static str] = &["incus-volumes"];
    type Input = Input;
    type Output = Mounted;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        crate::commands::linux_only(platform, Privilege::Root)
    }
    fn run(input: Input, context: &Context) -> Result<Mounted, Error> {
        run(&mut Native(context), &input)
    }
}
#[cfg(test)]
mod tests {
    use super::*;
    struct Fake {
        calls: Vec<Vec<String>>,
        devices: Value,
        fail: usize,
    }
    impl Host for Fake {
        fn run(&mut self, args: &[&str], _: Option<&str>) -> super::super::Output {
            self.calls
                .push(args.iter().map(|s| s.to_string()).collect());
            super::super::Output {
                code: if self.calls.len() == self.fail { 1 } else { 0 },
                stdout: json!({"devices":self.devices}).to_string(),
                stderr: "denied".into(),
                truncated: false,
            }
        }
    }
    #[test]
    fn mounts_once_and_refuses_every_conflicting_device_before_changes() {
        let input = Input {
            project: "orbit-tasks".into(),
            name: "tvm-7".into(),
        };
        let mut f = Fake {
            calls: vec![],
            devices: json!({}),
            fail: 0,
        };
        run(&mut f, &input).unwrap();
        assert_eq!(f.calls.len(), 3);
        assert!(f.calls[2].contains(&"readonly=true".into()));
        let expected = json!({"type":"disk","source":"/var/lib/orbit/package-cache","path":"/opt/orbit/package-cache","readonly":"true"});
        f.devices = json!({"package-cache":expected});
        f.calls.clear();
        run(&mut f, &input).unwrap();
        assert_eq!(f.calls.len(), 1);
        for (key, value) in [
            ("readonly", "false"),
            ("source", "/other"),
            ("path", "/other"),
            ("extra", "x"),
        ] {
            f.devices = json!({"package-cache":expected});
            f.devices["package-cache"][key] = json!(value);
            f.calls.clear();
            assert!(matches!(run(&mut f, &input), Err(Error::Mount {})));
            assert_eq!(f.calls.len(), 1);
        }
    }
    #[test]
    fn refuses_invalid_input_and_stops_at_each_failed_mutation() {
        let mut f = Fake {
            calls: vec![],
            devices: json!({}),
            fail: 0,
        };
        assert!(matches!(
            run(
                &mut f,
                &Input {
                    project: "../other".into(),
                    name: "vm".into()
                }
            ),
            Err(Error::Invalid {})
        ));
        assert!(f.calls.is_empty());
        for fail in 1..=3 {
            f.calls.clear();
            f.fail = fail;
            assert!(matches!(
                run(
                    &mut f,
                    &Input {
                        project: "p".into(),
                        name: "vm".into()
                    }
                ),
                Err(Error::Failed { .. })
            ));
            assert_eq!(f.calls.len(), fail);
        }
        f.fail = 0;
        f.calls.clear();
        f.devices = Value::Null;
        assert!(run(
            &mut f,
            &Input {
                project: "p".into(),
                name: "vm".into()
            }
        )
        .is_err());
        assert_eq!(f.calls.len(), 1);
    }
}
