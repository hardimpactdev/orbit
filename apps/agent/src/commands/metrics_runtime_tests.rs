use super::*;
use std::{
    collections::{HashMap, HashSet},
    sync::atomic::{AtomicU64, Ordering},
};
static NEXT: AtomicU64 = AtomicU64::new(0);
#[derive(Clone)]
struct Container {
    labels: Labels,
    running: bool,
    health: String,
}
struct Fake {
    containers: HashMap<String, Container>,
    volumes: HashMap<String, Labels>,
    images: HashSet<String>,
    calls: Vec<Vec<String>>,
    owners: Vec<(u32, u32)>,
    faults: Vec<(String, bool)>,
    absent: bool,
    daemon: bool,
    unhealthy: Option<String>,
    pauses: usize,
    credential_calls: Vec<(Ipv4Addr, String, Option<String>)>,
    accepts: bool,
}
impl Default for Fake {
    fn default() -> Self {
        Self {
            containers: HashMap::new(),
            volumes: HashMap::new(),
            images: HashSet::new(),
            calls: vec![],
            owners: vec![],
            faults: vec![],
            absent: false,
            daemon: true,
            unhealthy: None,
            pauses: 0,
            credential_calls: vec![],
            accepts: true,
        }
    }
}
impl Fake {
    fn fail(&mut self, needle: &str, after: bool) {
        self.faults.push((needle.into(), after));
    }
    fn writes(&self) -> Vec<&Vec<String>> {
        self.calls
            .iter()
            .filter(|args| {
                matches!(
                    args.get(1).map(String::as_str),
                    Some("run" | "create" | "rm" | "rename" | "start" | "stop" | "pull")
                )
            })
            .collect()
    }
    fn respond(&mut self, args: &[String]) -> (i32, String) {
        let ok = || (0, String::new());
        let name = args.last().unwrap();
        if !self.daemon || self.absent {
            return (1, String::new());
        }
        match (args[0].as_str(), args.get(1).map(String::as_str)) {
            ("info", _) => ok(),
            ("image", Some("inspect")) => (
                if self.images.contains(name) { 0 } else { 1 },
                String::new(),
            ),
            ("image", Some("pull")) => {
                self.images.insert(name.clone());
                ok()
            }
            ("container", Some("inspect")) => {
                let Some(container) = self.containers.get(name) else {
                    return (1, String::new());
                };
                (
                    0,
                    match args[2].as_str() {
                        "--format={{json .Config.Labels}}" => {
                            serde_json::to_string(&container.labels).unwrap()
                        }
                        "--format={{.State.Running}}" => format!("{}\n", container.running),
                        "--format={{.State.Status}} {{.State.Health.Status}}" => format!(
                            "{} {}",
                            if container.running {
                                "running"
                            } else {
                                "exited"
                            },
                            container.health
                        ),
                        _ => panic!("unexpected inspect: {args:?}"),
                    },
                )
            }
            ("volume", Some("inspect")) => match self.volumes.get(name) {
                Some(labels) => (0, serde_json::to_string(labels).unwrap()),
                None => (1, String::new()),
            },
            ("container" | "volume", Some("ls")) => {
                let filter = args.iter().find(|arg| arg.starts_with("name=")).unwrap();
                let name = filter
                    .trim_start_matches("name=^")
                    .trim_start_matches('/')
                    .trim_end_matches('$');
                let exists = if args[0] == "container" {
                    self.containers.contains_key(name)
                } else {
                    self.volumes.contains_key(name)
                };
                (0, if exists { name.into() } else { String::new() })
            }
            ("volume", Some("create")) => {
                self.volumes.insert(name.clone(), labels(args));
                ok()
            }
            ("volume", Some("rm")) => {
                self.volumes.remove(name);
                ok()
            }
            ("container", Some("run")) if args.contains(&"--entrypoint".into()) => ok(),
            ("container", Some("run")) => {
                let name = args[args.iter().position(|arg| arg == "--name").unwrap() + 1].clone();
                self.containers.insert(
                    name.clone(),
                    Container {
                        labels: labels(args),
                        running: true,
                        health: if self.unhealthy.as_ref() == Some(&name) {
                            "unhealthy"
                        } else {
                            "healthy"
                        }
                        .into(),
                    },
                );
                ok()
            }
            ("container", Some("stop" | "start")) => {
                self.containers.get_mut(name).unwrap().running = args[1] == "start";
                ok()
            }
            ("container", Some("rename")) => {
                let container = self.containers.remove(&args[2]).unwrap();
                self.containers.insert(args[3].clone(), container);
                ok()
            }
            ("container", Some("rm")) => {
                self.containers.remove(name);
                ok()
            }
            _ => panic!("unexpected Docker command: {args:?}"),
        }
    }
}
fn labels(args: &[String]) -> Labels {
    args.windows(2)
        .filter(|pair| pair[0] == "--label")
        .map(|pair| {
            let (key, value) = pair[1].split_once('=').unwrap();
            (key.into(), value.into())
        })
        .collect()
}
impl Host for Fake {
    fn docker(
        &mut self,
        args: &[String],
        _: Option<&str>,
        seconds: u64,
    ) -> io::Result<(i32, String)> {
        assert!(seconds <= 300);
        self.calls.push(args.to_vec());
        let needle = args.join(" ");
        let fault = self
            .faults
            .iter()
            .position(|(key, _)| needle.contains(key))
            .map(|i| self.faults.remove(i));
        if matches!(fault, Some((_, false))) {
            return Ok((1, String::new()));
        }
        let response = self.respond(args);
        Ok(if fault.is_some() {
            (1, String::new())
        } else {
            response
        })
    }
    fn docker_present(&self) -> bool {
        !self.absent
    }
    fn chown(&mut self, _: &Path, uid: u32, gid: u32) -> io::Result<()> {
        self.owners.push((uid, gid));
        Ok(())
    }
    fn pause(&mut self) {
        self.pauses += 1;
    }
    fn credential(&mut self, address: Ipv4Addr, password: &str, pending: Option<&str>) -> bool {
        self.credential_calls
            .push((address, password.into(), pending.map(str::to_owned)));
        self.accepts
    }
}
struct Fixture(Runtime<Fake>);
impl Fixture {
    fn new() -> Self {
        let root = std::env::temp_dir().join(format!(
            "orbit-metrics-runtime-{}-{}",
            std::process::id(),
            NEXT.fetch_add(1, Ordering::Relaxed)
        ));
        fs::create_dir_all(&root).unwrap();
        Self(Runtime {
            root,
            host: Fake::default(),
        })
    }
    fn converge(&mut self, configuration: &Configuration) -> Result<()> {
        self.0
            .converge("10.44.0.3".parse().unwrap(), 41, configuration)
    }
    fn seed(&mut self) {
        self.converge(&configuration()).unwrap();
        self.0.host.calls.clear();
    }
}
impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0.root);
    }
}
fn configuration() -> Configuration {
    PATHS
        .iter()
        .map(|path| ((*path).into(), format!("contents:{path}")))
        .collect()
}
fn changed() -> Configuration {
    configuration()
        .into_iter()
        .map(|(key, value)| (key, format!("new:{value}")))
        .collect()
}
fn hashes(f: &Fixture) -> Vec<String> {
    SERVICES
        .iter()
        .map(|s| f.0.host.containers[s.name()].labels[HASH].clone())
        .collect()
}
#[test]
fn converges_fixed_policy_and_repeats_without_replacing_healthy_containers() {
    let mut f = Fixture::new();
    f.seed();
    assert_eq!(fs::read(f.0.path(MARKER)).unwrap(), b"metrics\n");
    assert_eq!(
        fs::metadata(f.0.path(PATHS[7])).unwrap().mode() & 0o777,
        0o400
    );
    assert_eq!(
        fs::metadata(f.0.path(PATHS[0])).unwrap().mode() & 0o777,
        0o644
    );
    assert!(f.0.host.owners.contains(&(472, 472)));
    let before = hashes(&f);
    f.converge(&configuration()).unwrap();
    assert_eq!(hashes(&f), before);
    assert_eq!(f.0.host.writes().len(), 1);
    for service in SERVICES {
        let spec = Spec::new(service, 41, "10.44.0.3".parse().unwrap(), &configuration());
        let args = spec.args.join(" ");
        assert!(args.contains(
            "--network host --log-driver json-file --log-opt max-size=10m --log-opt max-file=3"
        ));
        assert!(args.contains("--health-start-period 30s --health-interval 5s --health-retries 12"));
        assert!(!args.contains("--publish"));
        assert!(args.contains(service.image()));
    }
}
#[test]
fn hashes_follow_only_service_startup_configuration() {
    let mut f = Fixture::new();
    f.seed();
    let original = hashes(&f);
    let mut config = configuration();
    config.insert(PATHS[0].into(), "new targets".into());
    f.converge(&config).unwrap();
    let targets = hashes(&f);
    assert_ne!(targets[0], original[0]);
    assert_eq!(targets[1], original[1]);
    config.insert(PATHS[4].into(), "new dashboard".into());
    config.insert(PATHS[7].into(), "rotated password".into());
    f.converge(&config).unwrap();
    assert_eq!(hashes(&f), targets);
    config.insert(PATHS[1].into(), "new settings".into());
    f.converge(&config).unwrap();
    assert_ne!(hashes(&f)[1], targets[1]);
    assert_eq!(hashes(&f)[0], targets[0]);
}
#[test]
fn refuses_each_foreign_resource_before_mutation() {
    for (volume, backup) in [(false, false), (false, true), (true, false)] {
        let mut f = Fixture::new();
        let service = Service::Grafana;
        if volume {
            f.0.host.volumes.insert(service.volume(), Labels::new());
        } else {
            f.0.host.containers.insert(
                if backup {
                    service.backup()
                } else {
                    service.name().into()
                },
                Container {
                    labels: Labels::new(),
                    running: true,
                    health: "healthy".into(),
                },
            );
        }
        assert_eq!(
            f.converge(&configuration()).unwrap_err(),
            if volume {
                Error::VolumeOwnership
            } else {
                Error::ContainerOwnership
            }
        );
        assert!(f.0.host.writes().is_empty());
        assert!(!f.0.path(BASE).exists());
        assert_eq!(
            f.0.remove(true).unwrap_err(),
            if volume {
                Error::VolumeOwnership
            } else {
                Error::ContainerOwnership
            }
        );
        assert!(f.0.host.writes().is_empty());
    }
}
#[test]
fn refuses_foreign_configuration_and_symlinks_before_mutation() {
    for symlink in [false, true] {
        let mut f = Fixture::new();
        fs::create_dir_all(f.0.path(BASE)).unwrap();
        if symlink {
            fs::write(f.0.path(MARKER), b"metrics\n").unwrap();
            std::os::unix::fs::symlink("/tmp", f.0.path("/etc/orbit/metrics/grafana")).unwrap();
        }
        assert_eq!(
            f.converge(&configuration()).unwrap_err(),
            Error::ConfigurationOwnership
        );
        assert!(f.0.host.calls.is_empty());
        assert_eq!(f.0.remove(true).unwrap_err(), Error::ConfigurationOwnership);
    }
}
#[test]
fn invalid_configuration_and_assignment_refuse_before_any_effect() {
    let mut f = Fixture::new();
    let mut config = configuration();
    config.insert("/tmp/injected".into(), "bad".into());
    assert_eq!(f.converge(&config).unwrap_err(), Error::Invalid);
    assert!(f.0.host.calls.is_empty());
    assert_eq!(
        f.0.converge("10.44.0.3".parse().unwrap(), 0, &configuration())
            .unwrap_err(),
        Error::Invalid
    );
    for input in [
        json!({"address":"10.44.0.3","action":{"operation":"remove","purge_data":true,"script":"x"}}),
        json!({"address":"10.44.0.3","action":{"operation":"remove","purge_data":true},"extra":1}),
    ] {
        assert!(serde_json::from_value::<Input>(input).is_err());
    }
}
#[test]
fn failed_validation_and_image_pull_leave_configuration_and_containers_untouched() {
    for fault in ["image pull", "--entrypoint /bin/promtool"] {
        let mut f = Fixture::new();
        f.0.host.fail(fault, false);
        assert_eq!(
            f.converge(&configuration()).unwrap_err(),
            if fault == "image pull" {
                Error::ImagePull
            } else {
                Error::PrometheusInvalid
            }
        );
        assert!(!f.0.path(BASE).exists());
        assert!(f.0.host.containers.is_empty());
        assert!(f.0.host.volumes.is_empty());
    }
    let mut f = Fixture::new();
    f.0.host.daemon = false;
    assert_eq!(
        f.converge(&configuration()).unwrap_err(),
        Error::ContainerInspection
    );
    assert!(f.0.host.writes().is_empty());
}
#[test]
fn each_failed_replacement_transition_restores_both_services_and_exact_files() {
    for fault in [
        "stop --time 30 -- orbit-metrics-grafana",
        "rename orbit-metrics-grafana ",
        "run --detach --pull=never --name orbit-metrics-grafana",
    ] {
        let mut f = Fixture::new();
        f.seed();
        let original = hashes(&f);
        fs::set_permissions(f.0.path(PATHS[7]), fs::Permissions::from_mode(0o600)).unwrap();
        fs::write(f.0.path(PATHS[7]), "older credential").unwrap();
        f.0.host.fail(fault, false);
        assert_eq!(
            f.converge(&changed()).unwrap_err(),
            Error::ContainerConvergence
        );
        assert_eq!(hashes(&f), original);
        assert_eq!(f.0.host.containers.len(), 2);
        assert!(f.0.host.containers.values().all(|c| c.running));
        assert_eq!(
            fs::read_to_string(f.0.path(PATHS[7])).unwrap(),
            "older credential"
        );
        assert_eq!(
            fs::metadata(f.0.path(PATHS[7])).unwrap().mode() & 0o777,
            0o600
        );
        assert_eq!(
            fs::read_to_string(f.0.path(PATHS[0])).unwrap(),
            configuration()[PATHS[0]]
        );
    }
}
#[test]
fn observes_transitions_that_completed_despite_failed_command_results() {
    for fault in [
        "stop --time 30 -- orbit-metrics-grafana",
        "rename orbit-metrics-grafana ",
        "run --detach --pull=never --name orbit-metrics-grafana",
        "rm --force -- orbit-metrics-grafana-orbit-rollback",
    ] {
        let mut f = Fixture::new();
        f.seed();
        let original = hashes(&f);
        f.0.host.fail(fault, true);
        f.converge(&changed()).unwrap();
        assert_ne!(hashes(&f), original);
        assert_eq!(f.0.host.containers.len(), 2);
    }
}
#[test]
fn cleanup_failure_preserves_committed_configuration_and_retries_cleanly() {
    for service in SERVICES {
        let mut f = Fixture::new();
        f.seed();
        let original = hashes(&f);
        f.0.host
            .fail(&format!("rm --force -- {}", service.backup()), false);
        assert_eq!(f.converge(&changed()).unwrap_err(), Error::ContainerCleanup);
        assert_ne!(hashes(&f), original);
        assert_eq!(
            fs::read_to_string(f.0.path(PATHS[0])).unwrap(),
            changed()[PATHS[0]]
        );
        f.converge(&changed()).unwrap();
        assert_eq!(f.0.host.containers.len(), 2);
    }
}
#[test]
fn first_convergence_failure_removes_new_volumes_files_and_containers() {
    let mut f = Fixture::new();
    f.0.host.unhealthy = Some(Service::Grafana.name().into());
    assert_eq!(
        f.converge(&configuration()).unwrap_err(),
        Error::ContainerConvergence
    );
    assert!(!f.0.path(BASE).exists());
    assert!(f.0.host.containers.is_empty());
    assert!(f.0.host.volumes.is_empty());
    f.0.host.unhealthy = None;
    f.converge(&configuration()).unwrap();
}
#[test]
fn reports_failed_container_recovery_and_still_restores_configuration() {
    let mut f = Fixture::new();
    f.seed();
    f.0.host.unhealthy = Some(Service::Grafana.name().into());
    f.0.host.fail("rm --force -- orbit-metrics-grafana", false);
    assert_eq!(
        f.converge(&changed()).unwrap_err(),
        Error::ContainerRollback
    );
    assert_eq!(
        fs::read_to_string(f.0.path(PATHS[0])).unwrap(),
        configuration()[PATHS[0]]
    );
}
#[test]
fn recovers_a_retained_backup_and_removes_obsolete_backups() {
    let mut f = Fixture::new();
    f.seed();
    let original = hashes(&f);
    let container = f.0.host.containers.remove(Service::Grafana.name()).unwrap();
    f.0.host
        .containers
        .insert(Service::Grafana.backup(), container);
    f.converge(&configuration()).unwrap();
    assert_eq!(hashes(&f), original);
    assert_eq!(f.0.host.containers.len(), 2);
    let container = f.0.host.containers[Service::Grafana.name()].clone();
    f.0.host
        .containers
        .insert(Service::Grafana.backup(), container);
    f.converge(&configuration()).unwrap();
    assert_eq!(f.0.host.containers.len(), 2);
}
#[test]
fn removal_preserves_data_unless_purge_is_requested_and_is_repeatable() {
    let mut f = Fixture::new();
    f.seed();
    f.0.remove(false).unwrap();
    f.0.remove(false).unwrap();
    assert!(f.0.host.containers.is_empty());
    assert_eq!(f.0.host.volumes.len(), 2);
    assert!(!f.0.path(BASE).exists());
    f.0.remove(true).unwrap();
    f.0.remove(true).unwrap();
    assert!(f.0.host.volumes.is_empty());
    f.0.host.absent = true;
    f.0.host.calls.clear();
    f.0.remove(true).unwrap();
    assert!(f.0.host.calls.is_empty());
    f.0.host.absent = false;
    f.0.host.daemon = false;
    assert_eq!(f.0.remove(true).unwrap_err(), Error::ContainerInspection);
}
#[test]
fn health_requires_a_running_container_and_waits_only_during_startup() {
    let mut f = Fixture::new();
    f.seed();
    let service = Service::Grafana;
    f.0.host.containers.get_mut(service.name()).unwrap().health = "starting".into();
    assert!(!f.0.healthy(service, true).unwrap());
    assert_eq!(f.0.host.pauses, 29);
    f.0.host.containers.get_mut(service.name()).unwrap().health = "healthy".into();
    f.0.host.containers.get_mut(service.name()).unwrap().running = false;
    assert!(!f.0.healthy(service, false).unwrap());
}
#[test]
fn credentials_use_the_http_boundary_and_return_no_secret() {
    let mut host = Fake::default();
    let output = credentials(
        &mut host,
        CredentialInput {
            address: "10.44.0.3".into(),
            action: CredentialAction::Verify {
                password: "active-secret".into(),
            },
        },
    )
    .unwrap();
    assert_eq!(
        serde_json::to_value(output).unwrap(),
        json!({"verified":true})
    );
    credentials(
        &mut host,
        CredentialInput {
            address: "10.44.0.3".into(),
            action: CredentialAction::Apply {
                active_password: "active-secret".into(),
                pending_password: "pending-secret".into(),
            },
        },
    )
    .unwrap();
    assert_eq!(
        host.credential_calls[1].2.as_deref(),
        Some("pending-secret")
    );
    assert!(host.calls.is_empty());
    host.accepts = false;
    assert_eq!(
        credentials(
            &mut host,
            CredentialInput {
                address: "10.44.0.3".into(),
                action: CredentialAction::Apply {
                    active_password: "active-secret".into(),
                    pending_password: "pending-secret".into()
                }
            }
        )
        .unwrap_err(),
        Error::Credentials
    );
}

#[test]
fn native_http_authenticates_verification_and_reset_and_rejects_unauthorized() {
    use std::io::{BufRead, BufReader, Read};
    for (pending, status) in [(None, 200), (None, 401), (Some("pending-password"), 200)] {
        let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
        let address = listener.local_addr().unwrap().to_string();
        let server = std::thread::spawn(move || {
            let (mut stream, _) = listener.accept().unwrap();
            stream
                .set_read_timeout(Some(Duration::from_secs(2)))
                .unwrap();
            let mut reader = BufReader::new(stream.try_clone().unwrap());
            let mut first = String::new();
            reader.read_line(&mut first).unwrap();
            let mut headers = String::new();
            let mut length = 0;
            loop {
                let mut line = String::new();
                reader.read_line(&mut line).unwrap();
                if line == "\r\n" {
                    break;
                }
                if let Some(value) = line.to_lowercase().strip_prefix("content-length:") {
                    length = value.trim().parse::<usize>().unwrap();
                }
                headers.push_str(&line);
            }
            assert!(headers
                .to_lowercase()
                .contains("authorization: basic ywrta",));
            if pending.is_some() {
                assert_eq!(first, "PUT /api/admin/users/1/password HTTP/1.1\r\n");
                let mut body = vec![0; length];
                reader.read_exact(&mut body).unwrap();
                assert_eq!(
                    serde_json::from_slice::<Value>(&body).unwrap(),
                    json!({"password":"pending-password"})
                );
            } else {
                assert_eq!(first, "GET /api/user HTTP/1.1\r\n");
            }
            write!(
                stream,
                "HTTP/1.1 {status} Test\r\nContent-Length: 0\r\nConnection: close\r\n\r\n"
            )
            .unwrap();
        });
        assert_eq!(
            credential_request(&address, "active-password", pending),
            status == 200
        );
        server.join().unwrap();
    }
}
