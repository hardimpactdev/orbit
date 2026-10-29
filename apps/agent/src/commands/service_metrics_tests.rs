use super::*;
use std::os::unix::fs::{symlink, PermissionsExt};

#[derive(Default)]
struct FakeHost {
    rules: Vec<String>,
    active: bool,
    enabled: bool,
    mutations: Vec<Vec<String>>,
    downloads: usize,
    fail_ready: usize,
    bad_download: bool,
}

impl Host for FakeHost {
    fn command(&mut self, program: &str, args: &[String]) -> Result<(bool, String)> {
        match (
            program,
            args.iter()
                .map(String::as_str)
                .collect::<Vec<_>>()
                .as_slice(),
        ) {
            ("ufw", ["status"]) => return Ok((true, "Status: active\n".into())),
            ("ufw", ["show", "added"]) => return Ok((true, self.rules.join("\n"))),
            ("systemctl", ["is-active", "--quiet", SERVICE]) => {
                return Ok((self.active, String::new()))
            }
            ("systemctl", ["is-enabled", "--quiet", SERVICE]) => {
                return Ok((self.enabled, String::new()))
            }
            ("systemctl", ["enable", SERVICE]) => self.enabled = true,
            ("systemctl", ["disable", SERVICE]) => self.enabled = false,
            ("systemctl", ["disable", "--now", SERVICE]) => {
                self.enabled = false;
                self.active = false;
            }
            ("systemctl", ["restart", SERVICE]) => self.active = true,
            ("systemctl", ["stop", SERVICE]) => self.active = false,
            ("systemctl", ["daemon-reload"]) => (),
            ("ufw", ["--force", "delete", ..]) => self
                .rules
                .retain(|line| !line.contains(args.last().unwrap())),
            ("ufw", ["insert", "1", ..]) => {
                self.rules.insert(0, format!("ufw {}", args[2..].join(" ")))
            }
            _ => panic!("unexpected {program} {args:?}"),
        }
        self.mutations
            .push([vec![program.into()], args.to_vec()].concat());
        Ok((true, String::new()))
    }
    fn download(&mut self) -> Result<Vec<u8>> {
        self.downloads += 1;
        Ok(if self.bad_download {
            b"wrong".to_vec()
        } else {
            b"exporter".to_vec()
        })
    }
    fn ready(&mut self, _: &str) -> Result<()> {
        if self.fail_ready > 0 {
            self.fail_ready -= 1;
            return Err(failed("exporter readiness"));
        }
        Ok(())
    }
}

struct Fixture(Runtime<FakeHost>);
impl std::ops::Deref for Fixture {
    type Target = Runtime<FakeHost>;
    fn deref(&self) -> &Self::Target {
        &self.0
    }
}
impl std::ops::DerefMut for Fixture {
    fn deref_mut(&mut self) -> &mut Self::Target {
        &mut self.0
    }
}

fn fixture() -> Fixture {
    let base = std::env::temp_dir().join(format!(
        "orbit-service-metrics-{:016x}",
        rand::random::<u64>()
    ));
    fs::DirBuilder::new().mode(0o700).create(&base).unwrap();
    Fixture(Runtime {
        filesystem_root: base.clone(),
        root: base.join("service-metrics"),
        unit: base.join("exporter.service"),
        binary: base.join("exporter"),
        owner: rustix::process::getuid().as_raw(),
        checksum: format!("{:x}", Sha256::digest(b"exporter")),
        host: FakeHost::default(),
    })
}

impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.filesystem_root);
    }
}

fn empty() -> State {
    State {
        config: None,
        unit: None,
        rules: vec![],
        active: false,
        enabled: false,
        binary: false,
    }
}

fn desired() -> State {
    State {
        config: Some(format!("{MARKER}monitor:\n  listen_addr: 10.44.0.3:9114\n")),
        unit: Some(format!("{MARKER}[Service]\nExecStart=/usr/local/bin/orbit-fpm-exporter serve\n")),
        rules: firewall_rules("ufw allow in on orbit from 10.44.0.2 to 10.44.0.3 port 9114 proto tcp comment 'orbit:metrics-service-fpm-allow'\nufw deny in on orbit to 10.44.0.3 port 9114 proto tcp comment 'orbit:metrics-service-fpm-deny'").unwrap(),
        active: true, enabled: true, binary: true,
    }
}

#[test]
fn installs_once_reorders_isolation_then_removes_owned_state() {
    let mut runtime = fixture();
    assert!(
        matches!(runtime.run(Input::Snapshot {}).unwrap(), Output::Snapshot(s) if s == empty())
    );
    assert!(!runtime.root.exists());
    let state = desired();
    runtime
        .run(Input::Apply {
            state: state.clone(),
        })
        .unwrap();
    assert_eq!(runtime.inspect().unwrap(), state);
    assert_eq!(
        fs::metadata(runtime.root.join("fpm.yml")).unwrap().mode() & 0o777,
        0o600
    );
    assert_eq!(fs::metadata(&runtime.binary).unwrap().mode() & 0o777, 0o755);
    assert_eq!(runtime.host.downloads, 1);
    runtime.host.mutations.clear();
    runtime
        .run(Input::Apply {
            state: state.clone(),
        })
        .unwrap();
    assert!(runtime.host.mutations.is_empty());
    assert_eq!(runtime.host.downloads, 1);

    runtime
        .host
        .rules
        .insert(0, "ufw allow in on orbit from 10.44.0.0/24".into());
    runtime.run(Input::Apply { state }).unwrap();
    assert!(runtime.host.rules[0].ends_with("fpm-allow"));
    assert!(runtime.host.rules[1].ends_with("fpm-deny"));
    assert_eq!(
        runtime.host.mutations[runtime.host.mutations.len() - 2][..3],
        ["ufw", "insert", "1"]
    );

    runtime.run(Input::Apply { state: empty() }).unwrap();
    assert_eq!(runtime.inspect().unwrap(), empty());
    assert!(!runtime.binary.exists());
    assert!(runtime.root.join(".orbit-owner").exists());
    assert_eq!(
        runtime.host.rules,
        ["ufw allow in on orbit from 10.44.0.0/24"]
    );
    runtime.host.mutations.clear();
    runtime.run(Input::Apply { state: empty() }).unwrap();
    assert!(runtime.host.mutations.is_empty());
}

#[test]
fn refuses_misleading_firewall_markers_before_mutation() {
    for line in [
        "ufw allow in on eth0 proto tcp from 10.44.0.2 to 10.44.0.3 port 9114 comment 'orbit:metrics-service-fpm-allow'",
        "ufw allow in on orbit proto tcp from any to 10.44.0.3 port 9114 comment 'orbit:metrics-service-fpm-allow'",
        "ufw allow in on orbit proto tcp from 10.44.0.2 to 10.44.0.3 port 22 comment 'orbit:metrics-service-fpm-allow'",
        "ufw deny in on orbit proto tcp from 10.44.0.2 to 10.44.0.3 port 9114 comment 'orbit:metrics-service-fpm-deny'",
        "ufw allow in on orbit proto tcp from 10.44.0.2 to 10.44.0.3 port 9114 comment 'orbit:metrics-service-fpm-allow' port 22",
    ] {
        let mut runtime = fixture();
        runtime.host.rules.push(line.into());
        assert!(matches!(runtime.run(Input::Apply { state: desired() }), Err(ServiceMetricsError::Invalid { .. })));
        assert!(runtime.host.mutations.is_empty());
        assert!(!runtime.root.exists());
        assert_eq!(runtime.host.downloads, 0);
    }
    let rule = "ufw deny in on orbit to 10.44.0.3 port 9114 proto tcp comment 'orbit:metrics-service-fpm-deny'";
    assert!(firewall_rules(&format!("{rule}\n{rule}")).is_err());
}

#[test]
fn refuses_invalid_desired_state_before_installing_or_creating_files() {
    let mut invalid_states = Vec::new();
    let mut state = desired();
    state.config = Some("unmarked".into());
    invalid_states.push(state);
    let mut state = desired();
    state.unit = None;
    invalid_states.push(state);
    let mut state = desired();
    state.binary = false;
    invalid_states.push(state);
    let mut state = desired();
    state.rules[0][11] = "22".into();
    invalid_states.push(state);
    let mut state = desired();
    state.config = Some(format!(
        "{MARKER}monitor:\n  listen_addr: example.test:9114\n"
    ));
    invalid_states.push(state);
    let mut state = empty();
    state.active = true;
    invalid_states.push(state);
    for state in invalid_states {
        let mut runtime = fixture();
        assert!(matches!(
            runtime.run(Input::Apply { state }),
            Err(ServiceMetricsError::Invalid { .. })
        ));
        assert!(!runtime.root.exists());
        assert!(runtime.host.mutations.is_empty());
        assert_eq!(runtime.host.downloads, 0);
    }
}

#[test]
fn refuses_foreign_files_and_services_without_touching_them() {
    for kind in [
        "link",
        "unmarked",
        "writable",
        "binary",
        "service",
        "directory",
        "journal",
    ] {
        let mut runtime = fixture();
        match kind {
            "link" => symlink("/does-not-exist", &runtime.unit).unwrap(),
            "unmarked" => fs::write(&runtime.unit, "foreign").unwrap(),
            "writable" => {
                fs::write(&runtime.unit, MARKER).unwrap();
                fs::set_permissions(&runtime.unit, fs::Permissions::from_mode(0o666)).unwrap();
            }
            "binary" => fs::write(&runtime.binary, "foreign").unwrap(),
            "service" => runtime.host.active = true,
            "directory" => fs::create_dir(&runtime.root).unwrap(),
            "journal" => {
                runtime.run(Input::Apply { state: empty() }).unwrap();
                symlink("/does-not-exist", runtime.root.join("transaction.json")).unwrap();
            }
            _ => unreachable!(),
        }
        assert!(
            matches!(
                runtime.run(Input::Apply { state: desired() }),
                Err(ServiceMetricsError::Ownership { .. })
            ),
            "{kind}"
        );
        assert!(runtime.host.mutations.is_empty());
        assert_eq!(runtime.host.downloads, 0);
    }
}

#[test]
fn restores_exact_previous_state_when_readiness_fails() {
    let mut runtime = fixture();
    let prior = desired();
    runtime
        .run(Input::Apply {
            state: prior.clone(),
        })
        .unwrap();
    let mut next = prior.clone();
    next.config
        .as_mut()
        .unwrap()
        .push_str("# changed pool fingerprint\n");
    next.rules[0][7] = "10.44.0.9".into();
    runtime.host.fail_ready = 1;
    assert_eq!(
        runtime.run(Input::Apply { state: next }).err(),
        Some(failed("exporter readiness"))
    );
    assert_eq!(runtime.inspect().unwrap(), prior);
    assert!(!runtime.root.join("transaction.json").exists());
}

#[test]
fn keeps_failed_recovery_for_the_next_snapshot_to_finish() {
    let mut runtime = fixture();
    let prior = desired();
    runtime
        .run(Input::Apply {
            state: prior.clone(),
        })
        .unwrap();
    let mut next = prior.clone();
    next.config
        .as_mut()
        .unwrap()
        .push_str("# new fingerprint\n");
    runtime.host.fail_ready = 2;
    assert_eq!(
        runtime.run(Input::Apply { state: next }).err(),
        Some(ServiceMetricsError::Recovery {})
    );
    let journal = runtime.root.join("transaction.json");
    assert!(journal.exists());
    assert_eq!(fs::metadata(&journal).unwrap().mode() & 0o777, 0o600);
    assert!(
        matches!(runtime.run(Input::Snapshot {}).unwrap(), Output::Snapshot(state) if state == prior)
    );
    assert!(!journal.exists());
}

#[test]
fn checksum_failure_does_not_publish_or_start_the_exporter() {
    let mut runtime = fixture();
    runtime.host.bad_download = true;
    assert_eq!(
        runtime.run(Input::Apply { state: desired() }).err(),
        Some(failed("exporter checksum"))
    );
    assert_eq!(runtime.inspect().unwrap(), empty());
    assert!(runtime.host.mutations.is_empty());
    assert!(!runtime.binary.exists());
    assert!(!runtime.root.join("transaction.json").exists());
}

#[test]
fn pins_the_exporter_only_for_x86_64() {
    assert!(exporter_checksum("x86_64").is_ok());
    for architecture in ["aarch64", "riscv64"] {
        assert!(matches!(
            exporter_checksum(architecture),
            Err(ServiceMetricsError::Invalid { reason }) if reason == "exporter architecture"
        ));
    }
}
