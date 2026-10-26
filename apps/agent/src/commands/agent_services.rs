//! Pin replacement restarts only running compiled services, never an in-flight command.
use std::{collections::BTreeSet, fs, io, path::Path, process::Command};

pub(super) fn running(proc: &Path, binary: &str, names: &[&str]) -> io::Result<Vec<String>> {
    let mut units = BTreeSet::new();
    for entry in fs::read_dir(proc)? {
        let entry = entry?;
        if !entry
            .file_name()
            .to_string_lossy()
            .bytes()
            .all(|b| b.is_ascii_digit())
        {
            continue;
        }
        let path = entry.path();
        let Ok(command) = fs::read(path.join("cmdline")) else {
            continue;
        };
        let args: Vec<_> = command
            .split(|b| *b == 0)
            .filter(|v| !v.is_empty())
            .collect();
        if args.len() != 3
            || args[0] != binary.as_bytes()
            || args[1] != b"service"
            || !names.iter().any(|name| name.as_bytes() == args[2])
        {
            continue;
        }
        let Ok(executable) = fs::read_link(path.join("exe")) else {
            continue;
        };
        if executable != Path::new(binary)
            && executable != Path::new(&format!("{binary} (deleted)"))
        {
            continue;
        }
        let group = match fs::read_to_string(path.join("cgroup")) {
            Ok(group) => group,
            Err(error) if error.kind() == io::ErrorKind::NotFound => continue,
            Err(error) => return Err(error),
        };
        // A container's service may already have exec'd its application. Discover those from Docker's
        // persisted command and mount metadata, not the current executable inside the container.
        if group.split('/').any(|part| {
            part.trim()
                .strip_prefix("docker-")
                .and_then(|part| part.strip_suffix(".scope"))
                .is_some_and(|id| id.len() == 64 && id.bytes().all(|b| b.is_ascii_hexdigit()))
        }) {
            continue;
        }
        let unit = group
            .lines()
            .filter_map(|line| line.strip_prefix("0::"))
            .flat_map(|group| group.split('/'))
            .find(|unit| {
                unit.starts_with("orbit-")
                    && unit.ends_with(".service")
                    && unit
                        .bytes()
                        .all(|b| b.is_ascii_alphanumeric() || b"-_.@\\:".contains(&b))
            })
            .ok_or_else(|| {
                io::Error::other("A compiled agent service has no Orbit systemd unit")
            })?;
        units.insert(unit.into());
    }
    Ok(units.into_iter().collect())
}
pub(super) fn restart(units: &[String], containers: &[String]) -> bool {
    let units_ok = restart_with(units, |unit| {
        Command::new("/usr/bin/systemctl")
            .args(["try-restart", unit])
            .status()
            .is_ok_and(|status| status.success())
    });
    let containers_ok = restart_with(containers, |container| {
        Command::new("/usr/bin/docker")
            .args(["container", "restart", container])
            .stdout(std::process::Stdio::null())
            .stderr(std::process::Stdio::null())
            .status()
            .is_ok_and(|status| status.success())
    });
    units_ok && containers_ok
}

const CONTAINER_VIEW: &str = r#"{"command":{{json .Config.Cmd}},"mounts":{{json .Mounts}},"labels":{{json .Config.Labels}},"running":{{json .State.Running}}}"#;

pub(super) fn containers(binary: &str, names: &[&str]) -> io::Result<Vec<String>> {
    if !Path::new("/var/run/docker.sock").exists() {
        return Ok(Vec::new());
    }
    containers_with(binary, names, |args| {
        let result = Command::new("/usr/bin/docker").args(args).output()?;
        if !result.status.success() {
            return Err(io::Error::other(
                "Could not inspect compiled container services",
            ));
        }
        String::from_utf8(result.stdout)
            .map_err(|_| io::Error::other("Invalid container service metadata"))
    })
}

fn containers_with(
    binary: &str,
    names: &[&str],
    mut docker: impl FnMut(&[&str]) -> io::Result<String>,
) -> io::Result<Vec<String>> {
    let ids = docker(&[
        "container",
        "ls",
        "--filter",
        "label=orbit.managed=true",
        "--filter",
        "label=orbit.container.kind=process",
        "--no-trunc",
        "--format",
        "{{.ID}}",
    ])?;
    let mut services = Vec::new();
    for id in ids.lines() {
        if id.len() != 64 || !id.bytes().all(|b| b.is_ascii_hexdigit()) {
            return Err(io::Error::other("Invalid managed container ID"));
        }
        let view = docker(&["container", "inspect", "--format", CONTAINER_VIEW, id])?;
        let view: serde_json::Value = serde_json::from_str(&view)
            .map_err(|_| io::Error::other("Invalid container service metadata"))?;
        let args = view["command"].as_array();
        if view["running"] != true
            || view["labels"]["orbit.managed"] != "true"
            || view["labels"]["orbit.container.kind"] != "process"
            || !view["labels"]["orbit.process.id"]
                .as_str()
                .and_then(|id| id.parse::<u64>().ok())
                .is_some_and(|id| id > 0)
            || !args.is_some_and(|args| {
                args.len() == 3
                    && args[0] == binary
                    && args[1] == "service"
                    && names.iter().any(|name| args[2] == *name)
            })
        {
            continue;
        }
        if !view["mounts"].as_array().is_some_and(|mounts| {
            mounts.iter().any(|mount| {
                mount["Type"] == "bind"
                    && mount["Source"] == binary
                    && mount["Destination"] == binary
                    && mount["RW"] == false
            })
        }) {
            return Err(io::Error::other(
                "A compiled container service has no read-only installed binary mount",
            ));
        }
        services.push(id.to_owned());
    }
    Ok(services)
}
fn restart_with(units: &[String], mut restart: impl FnMut(&str) -> bool) -> bool {
    let mut healthy = true;
    for unit in units {
        healthy = restart(unit) && healthy;
    }
    healthy
}
#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn discovers_running_managed_container_services_after_the_agent_execs_the_application() {
        let binary = "/usr/local/bin/orbit-agent";
        let selected = "a".repeat(64);
        let other = "b".repeat(64);
        let view = json!({
            "command": [binary, "service", "plausible"], "running": true,
            "labels": {"orbit.managed":"true", "orbit.container.kind":"process", "orbit.process.id":"7"},
            "mounts": [{"Type":"bind", "Source":binary, "Destination":binary, "RW":false}]
        });
        for excluded in [
            json!({"running": false}),
            json!({"command": [binary, "command", "agent.status"]}),
            json!({"command": [binary, "service", "unregistered"]}),
            json!({"labels": {"orbit.managed":"false", "orbit.container.kind":"process"}}),
            json!({"labels": {"orbit.managed":"true", "orbit.container.kind":"process"}}),
        ] {
            let mut excluded_view = view.clone();
            excluded_view
                .as_object_mut()
                .unwrap()
                .extend(excluded.as_object().unwrap().clone());
            let found = containers_with(binary, &["plausible"], |args| {
                Ok(if args[1] == "ls" {
                    format!("{selected}\n{other}\n")
                } else if args.last() == Some(&selected.as_str()) {
                    view.to_string()
                } else {
                    excluded_view.to_string()
                })
            })
            .unwrap();
            assert_eq!(found.as_slice(), std::slice::from_ref(&selected));
        }
        for mount in [
            json!([]),
            json!([{"Type":"bind","Source":binary,"Destination":binary,"RW":true}]),
        ] {
            let mut invalid = view.clone();
            invalid["mounts"] = mount;
            assert!(
                containers_with(binary, &["plausible"], |args| Ok(if args[1] == "ls" {
                    selected.clone()
                } else {
                    invalid.to_string()
                }))
                .is_err()
            );
        }
        assert!(
            containers_with(binary, &["plausible"], |_| Err(io::Error::other(
                "unavailable"
            )))
            .is_err()
        );
        assert!(containers_with(binary, &["plausible"], |_| Ok("invalid ID".into())).is_err());
    }
    #[test]
    fn discovers_only_running_registered_services_and_restarts_every_selected_unit() {
        let root =
            std::env::temp_dir().join(format!("agent-services-{:x}", rand::random::<u128>()));
        let binary = "/usr/local/bin/orbit-agent";
        let fixtures = [
            (
                "1",
                format!("{binary}\0service\0example\0"),
                "orbit-process-one.service",
                binary,
            ),
            (
                "2",
                format!("{binary}\0command\0instances.deploy\0"),
                "orbit-command-two.service",
                binary,
            ),
            (
                "3",
                format!("{binary}\0service\0schedules-run\0id\0"),
                "orbit-schedule-three.service",
                binary,
            ),
            (
                "4",
                format!("{binary}\0service\0example\0"),
                "orbit-process-four.service",
                "/usr/local/bin/orbit-agent (deleted)",
            ),
            (
                "5",
                format!("{binary}\0service\0example\0"),
                "orbit-process-five.service",
                "/usr/bin/unrelated",
            ),
            ("6", format!("{binary}\0"), "orbit-agent.service", binary),
        ];
        for (pid, args, unit, executable) in fixtures {
            let path = root.join(pid);
            fs::create_dir_all(&path).unwrap();
            fs::write(path.join("cmdline"), args).unwrap();
            fs::write(path.join("cgroup"), format!("0::/system.slice/{unit}\n")).unwrap();
            std::os::unix::fs::symlink(executable, path.join("exe")).unwrap();
        }
        let container = root.join("7");
        fs::create_dir(&container).unwrap();
        fs::write(
            container.join("cmdline"),
            format!("{binary}\0service\0example\0"),
        )
        .unwrap();
        fs::write(
            container.join("cgroup"),
            format!("0::/system.slice/docker-{}.scope\n", "a".repeat(64)),
        )
        .unwrap();
        std::os::unix::fs::symlink(binary, container.join("exe")).unwrap();
        let units = running(&root, binary, &["example"]).unwrap();
        assert_eq!(
            units,
            ["orbit-process-four.service", "orbit-process-one.service"]
        );
        let mut restarted = Vec::new();
        assert!(!restart_with(&units, |unit| {
            restarted.push(unit.to_owned());
            unit != "orbit-process-four.service"
        }));
        assert_eq!(restarted, units);
        fs::remove_dir_all(root).unwrap();
    }
}
