use std::process::Command;

pub const LAUNCHD_PREFIX: &str = "dev.hardimpact.orbit.";
pub const MACOS_LABEL: &str = "dev.hardimpact.orbit.macos";

pub fn launchd_bootout_target(uid: &str, label: &str) -> String {
    format!("gui/{uid}/{label}")
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ShutdownError(pub String);

pub trait ShutdownPort {
    fn discover_launchd(&mut self) -> Result<Vec<String>, ShutdownError>;
    fn stop_launchd(&mut self, label: &str) -> Result<(), ShutdownError>;
    fn discover_docker(&mut self) -> Result<Vec<String>, ShutdownError>;
    fn stop_docker(&mut self, id: &str) -> Result<(), ShutdownError>;
    fn stop_agent(&mut self) -> Result<(), ShutdownError>;
}

pub fn owned_launchd_labels(labels: impl IntoIterator<Item = String>) -> Vec<String> {
    labels
        .into_iter()
        .filter(|label| label.starts_with(LAUNCHD_PREFIX) && label != MACOS_LABEL)
        .collect()
}

pub fn shutdown<P: ShutdownPort>(port: &mut P) -> Result<(), ShutdownError> {
    let launchd = owned_launchd_labels(port.discover_launchd()?);
    for label in &launchd {
        port.stop_launchd(label)?;
    }

    for id in port.discover_docker()? {
        port.stop_docker(&id)?;
    }

    // The Agent is supervised by this process. Stop it only after all other
    // Orbit-owned runtime work has been blocked and stopped.
    port.stop_agent()?;

    let remaining_launchd = owned_launchd_labels(port.discover_launchd()?);
    let remaining_docker = port.discover_docker()?;
    if remaining_launchd.is_empty() && remaining_docker.is_empty() {
        Ok(())
    } else {
        Err(ShutdownError(format!(
            "Orbit runtime remains: launchd={remaining_launchd:?}, docker={remaining_docker:?}"
        )))
    }
}

pub struct SystemShutdownPort {
    pub stop_agent: Box<dyn FnMut() -> Result<(), ShutdownError> + Send>,
}

impl ShutdownPort for SystemShutdownPort {
    fn discover_launchd(&mut self) -> Result<Vec<String>, ShutdownError> {
        let output = Command::new("launchctl")
            .args(["list"])
            .output()
            .map_err(|error| ShutdownError(format!("launchctl list failed: {error}")))?;
        if !output.status.success() {
            return Err(ShutdownError("launchctl list failed".to_string()));
        }

        Ok(String::from_utf8_lossy(&output.stdout)
            .lines()
            .skip(1)
            .filter_map(|line| line.split_whitespace().last())
            .map(str::to_string)
            .collect())
    }

    fn stop_launchd(&mut self, label: &str) -> Result<(), ShutdownError> {
        let target = launchd_bootout_target(&users_uid()?, label);
        let output = Command::new("launchctl")
            .args(["bootout", &target])
            .output()
            .map_err(|error| ShutdownError(format!("launchctl bootout failed: {error}")))?;
        if output.status.success() || launchd_not_found_output(&output.stdout, &output.stderr) {
            Ok(())
        } else {
            Err(ShutdownError(format!(
                "launchctl bootout failed for {label}"
            )))
        }
    }

    fn discover_docker(&mut self) -> Result<Vec<String>, ShutdownError> {
        let output = match Command::new("docker")
            .args(["ps", "-q", "--filter", "label=orbit.managed=true"])
            .output()
        {
            Ok(output) => output,
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(Vec::new()),
            Err(error) => return Err(ShutdownError(format!("docker discovery failed: {error}"))),
        };
        if !output.status.success() {
            if docker_provider_absent(&output.stderr) {
                return Ok(Vec::new());
            }
            return Err(ShutdownError("docker discovery failed".to_string()));
        }
        Ok(String::from_utf8_lossy(&output.stdout)
            .lines()
            .map(str::trim)
            .filter(|id| !id.is_empty())
            .map(str::to_string)
            .collect())
    }

    fn stop_docker(&mut self, id: &str) -> Result<(), ShutdownError> {
        let output = Command::new("docker")
            .args(["stop", id])
            .output()
            .map_err(|error| ShutdownError(format!("docker stop failed: {error}")))?;
        if output.status.success() {
            Ok(())
        } else {
            Err(ShutdownError(format!("docker stop failed for {id}")))
        }
    }

    fn stop_agent(&mut self) -> Result<(), ShutdownError> {
        (self.stop_agent)()
    }
}

fn launchd_not_found_output(stdout: &[u8], stderr: &[u8]) -> bool {
    let text = format!(
        "{}\n{}",
        String::from_utf8_lossy(stdout),
        String::from_utf8_lossy(stderr)
    );
    [
        "Could not find service",
        "No such process",
        "Unknown service",
    ]
    .iter()
    .any(|needle| text.contains(needle))
}

fn docker_provider_absent(stderr: &[u8]) -> bool {
    let text = String::from_utf8_lossy(stderr);
    text.contains("Cannot connect to the Docker daemon")
        || text.contains("Is the docker daemon running")
}

fn users_uid() -> Result<String, ShutdownError> {
    let output = Command::new("id")
        .arg("-u")
        .output()
        .map_err(|error| ShutdownError(format!("id -u failed: {error}")))?;
    if !output.status.success() {
        return Err(ShutdownError("id -u failed".to_string()));
    }
    let uid = String::from_utf8_lossy(&output.stdout).trim().to_string();
    if uid.is_empty() {
        Err(ShutdownError("id -u returned no uid".to_string()))
    } else {
        Ok(uid)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    struct Fake {
        launchd: Vec<String>,
        docker: Vec<String>,
        events: Vec<String>,
        fail: Option<String>,
    }

    impl ShutdownPort for Fake {
        fn discover_launchd(&mut self) -> Result<Vec<String>, ShutdownError> {
            self.events.push("discover-launchd".into());
            if self.fail.as_deref() == Some("discover-launchd") {
                return Err(ShutdownError("discover-launchd".into()));
            }
            Ok(self.launchd.clone())
        }
        fn stop_launchd(&mut self, label: &str) -> Result<(), ShutdownError> {
            self.events.push(format!("stop-launchd:{label}"));
            if self.fail.as_deref() == Some(label) {
                return Err(ShutdownError(label.into()));
            }
            self.launchd.retain(|item| item != label);
            Ok(())
        }
        fn discover_docker(&mut self) -> Result<Vec<String>, ShutdownError> {
            self.events.push("discover-docker".into());
            if self.fail.as_deref() == Some("discover-docker") {
                return Err(ShutdownError("discover-docker".into()));
            }
            Ok(self.docker.clone())
        }
        fn stop_docker(&mut self, id: &str) -> Result<(), ShutdownError> {
            self.events.push(format!("stop-docker:{id}"));
            if id != "stuck" {
                self.docker.retain(|item| item != id);
            }
            Ok(())
        }
        fn stop_agent(&mut self) -> Result<(), ShutdownError> {
            self.events.push("stop-agent".into());
            if self.fail.as_deref() == Some("agent") {
                return Err(ShutdownError("agent".into()));
            }
            Ok(())
        }
    }

    #[test]
    fn filters_only_orbit_launchd_labels_and_excludes_menu_app() {
        assert_eq!(
            owned_launchd_labels(vec![
                MACOS_LABEL.into(),
                "dev.hardimpact.orbit.caddy".into(),
                "com.apple.other".into(),
            ]),
            vec!["dev.hardimpact.orbit.caddy"]
        );
    }

    #[test]
    fn builds_launchctl_target_with_uid_and_label_as_one_argument() {
        assert_eq!(
            launchd_bootout_target("501", "dev.hardimpact.orbit.caddy"),
            "gui/501/dev.hardimpact.orbit.caddy"
        );
    }

    #[test]
    fn tolerates_only_known_launchd_not_found_races() {
        assert!(launchd_not_found_output(b"", b"Could not find service"));
        assert!(launchd_not_found_output(b"", b"No such process"));
        assert!(launchd_not_found_output(b"", b"Unknown service"));
        assert!(!launchd_not_found_output(b"", b"permission denied"));
    }

    #[test]
    fn classifies_only_absent_docker_provider_states_as_empty() {
        assert!(docker_provider_absent(
            b"Cannot connect to the Docker daemon"
        ));
        assert!(docker_provider_absent(b"Is the docker daemon running?"));
        assert!(!docker_provider_absent(b"permission denied"));
    }

    #[test]
    fn stops_runtime_then_agent_and_verifies_empty() {
        let mut fake = Fake {
            launchd: vec!["dev.hardimpact.orbit.caddy".into()],
            docker: vec!["abc".into()],
            events: vec![],
            fail: None,
        };
        assert!(shutdown(&mut fake).is_ok());
        assert_eq!(
            fake.events,
            vec![
                "discover-launchd",
                "stop-launchd:dev.hardimpact.orbit.caddy",
                "discover-docker",
                "stop-docker:abc",
                "stop-agent",
                "discover-launchd",
                "discover-docker"
            ]
        );
    }

    #[test]
    fn failure_keeps_agent_and_app_running() {
        let mut fake = Fake {
            launchd: vec!["dev.hardimpact.orbit.caddy".into()],
            docker: vec![],
            events: vec![],
            fail: Some("dev.hardimpact.orbit.caddy".into()),
        };
        assert!(shutdown(&mut fake).is_err());
        assert!(!fake.events.contains(&"stop-agent".into()));
    }

    #[test]
    fn verification_failure_is_reported_after_agent_stop() {
        let mut fake = Fake {
            launchd: vec![],
            docker: vec!["stuck".into()],
            events: vec![],
            fail: None,
        };
        assert!(shutdown(&mut fake).is_err());
        assert_eq!(
            fake.events.last().map(String::as_str),
            Some("discover-docker")
        );
    }

    #[test]
    fn discovery_failure_is_reported_before_agent_stop() {
        let mut fake = Fake {
            launchd: vec![],
            docker: vec![],
            events: vec![],
            fail: Some("discover-docker".into()),
        };
        assert!(shutdown(&mut fake).is_err());
        assert!(!fake.events.contains(&"stop-agent".into()));
    }

    #[test]
    fn agent_failure_is_reported_after_runtime_stops() {
        let mut fake = Fake {
            launchd: vec![],
            docker: vec![],
            events: vec![],
            fail: Some("agent".into()),
        };
        assert!(shutdown(&mut fake).is_err());
        assert_eq!(fake.events.last().map(String::as_str), Some("stop-agent"));
    }
}
