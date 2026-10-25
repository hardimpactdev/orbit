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
pub(super) fn restart(units: &[String]) -> bool {
    restart_with(units, |unit| {
        Command::new("/usr/bin/systemctl")
            .args(["try-restart", unit])
            .status()
            .is_ok_and(|status| status.success())
    })
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
