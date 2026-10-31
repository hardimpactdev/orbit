use super::*;
use std::collections::BTreeMap;

struct FakeHost {
    kind: Kind,
    active: bool,
    package: bool,
    rule: Option<Ipv4Addr>,
    raw_firewall: Option<String>,
    commands: Vec<Vec<String>>,
    occurrences: BTreeMap<Vec<String>, usize>,
    failures: Vec<(Vec<String>, usize)>,
    disable_works: bool,
    download: Vec<u8>,
    downloads: usize,
}
impl Host for FakeHost {
    fn command(&mut self, program: &str, args: &[&str]) -> io::Result<(i32, String)> {
        let command: Vec<_> = std::iter::once(program)
            .chain(args.iter().copied())
            .map(str::to_owned)
            .collect();
        self.commands.push(command.clone());
        let count = self.occurrences.entry(command.clone()).or_default();
        *count += 1;
        if self.failures.contains(&(command, *count)) {
            return Ok((1, "failed".into()));
        }
        let mut output = String::new();
        let mut code = 0;
        match (program, args) {
            ("systemctl", ["is-active", service]) if *service == self.kind.service() => {
                code = if self.active { 0 } else { 3 };
                output = if self.active {
                    "active\n"
                } else {
                    "inactive\n"
                }
                .into();
            }
            ("systemctl", ["enable", "--now", service] | ["restart", service])
                if *service == self.kind.service() =>
            {
                self.active = true
            }
            ("systemctl", ["disable", "--now", service]) if *service == self.kind.service() => {
                if self.disable_works {
                    self.active = false;
                }
            }
            ("systemctl", ["daemon-reload"]) => {}
            ("systemctl", ["reset-failed", "orbit-cadvisor"]) => {
                code = 1;
            }
            (
                "dpkg-query",
                ["--show", "--showformat=${Status}", "--", "prometheus-node-exporter"],
            ) => {
                code = if self.package { 0 } else { 1 };
                output = if self.package {
                    "install ok installed"
                } else {
                    ""
                }
                .into();
            }
            (
                "apt-get",
                ["-o", "DPkg::Lock::Timeout=60", "install", "--yes", "--no-install-recommends", "--", "prometheus-node-exporter"],
            ) => self.package = true,
            ("ufw", ["status", "numbered"]) => {
                output = self.raw_firewall.clone().unwrap_or_else(|| match self.rule {
                    Some(source) => format!("Status: active\n[ 5] 10.44.0.4 {}/tcp on orbit ALLOW IN {source} # {}\n", self.kind.port(), self.kind.comment()),
                    None => "Status: active\n".into(),
                });
            }
            ("ufw", ["--force", "delete", "5"]) => self.rule = None,
            (
                "ufw",
                ["allow", "in", "on", "orbit", "proto", "tcp", "from", source, "to", "10.44.0.4", "port", port, "comment", comment],
            ) if *port == self.kind.port() && *comment == self.kind.comment() => {
                assert!(self.rule.is_none());
                self.rule = Some(source.parse().unwrap());
            }
            _ => panic!("unexpected command: {program} {args:?}"),
        }
        Ok((code, output))
    }
    fn download(&mut self) -> io::Result<Vec<u8>> {
        self.downloads += 1;
        Ok(self.download.clone())
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
impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.root);
    }
}
fn fixture(kind: Kind) -> Fixture {
    let root = std::env::temp_dir().join(format!("orbit-metrics-{:016x}", rand::random::<u64>()));
    fs::create_dir_all(&root).unwrap();
    let binary = b"pinned binary fixture".to_vec();
    Fixture(Runtime {
        root,
        kind,
        address: "10.44.0.4".parse().unwrap(),
        metrics_address: "10.44.0.3".parse().unwrap(),
        checksum: format!("{:x}", Sha256::digest(&binary)),
        host: FakeHost {
            kind,
            active: false,
            package: false,
            rule: None,
            raw_firewall: None,
            commands: vec![],
            occurrences: BTreeMap::new(),
            failures: vec![],
            disable_works: true,
            download: binary,
            downloads: 0,
        },
    })
}
fn fail(f: &mut Fixture, command: &[&str], occurrence: usize) {
    f.host
        .failures
        .push((command.iter().map(|s| s.to_string()).collect(), occurrence));
}
fn owned(f: &mut Fixture) -> State {
    let state = State {
        configuration: Some(format!(
            "{}[Service]\n# previous configuration\n",
            f.kind.marker()
        )),
        service_active: true,
        firewall_source: Some("10.44.0.2".parse().unwrap()),
    };
    atomic_write(
        &f.path(),
        state.configuration.as_ref().unwrap().as_bytes(),
        0o644,
    )
    .unwrap();
    if f.kind == Kind::Cadvisor {
        atomic_write(&f.root.join(CADVISOR_BINARY), &f.host.download, 0o755).unwrap();
    }
    f.host.active = true;
    f.host.package = true;
    f.host.rule = state.firewall_source;
    state
}

#[test]
fn installs_configures_verifies_and_repeats_both_exporters() {
    for kind in [Kind::Exporter, Kind::Cadvisor] {
        let mut f = fixture(kind);
        f.execute(Operation::Converge {}).unwrap();
        let text = fs::read_to_string(f.path()).unwrap();
        assert!(text.starts_with(kind.marker()));
        assert!(text.contains("Restart=always\nRestartSec=2\n"));
        if kind == Kind::Exporter {
            assert!(text.contains("--web.listen-address=10.44.0.4:9100"));
            assert!(text.contains(
                "--collector.systemd --collector.systemd.unit-include=orbit-node-worker[.]service"
            ));
        } else {
            assert!(text.contains("--listen_ip=10.44.0.4 --port=9102 --store_container_labels=false --docker_only=false"));
            assert!(text.contains("--disable_metrics=sched,percpu,memory_numa,cpuLoad,diskIO,disk,network,tcp,advtcp,udp,app,process,hugetlb,referenced_memory,cpu_topology,resctrl,cpuset,oom_event,pressure"));
            assert_eq!(
                fs::metadata(f.root.join(CADVISOR_BINARY))
                    .unwrap()
                    .permissions()
                    .mode()
                    & 0o777,
                0o755
            );
        }
        assert_eq!(
            fs::metadata(f.path()).unwrap().permissions().mode() & 0o777,
            0o644
        );
        assert_eq!(f.actual(), "active");
        let before = f.snapshot().unwrap();
        f.execute(Operation::Converge {}).unwrap();
        assert_eq!(f.snapshot().unwrap(), before);
        assert_eq!(f.host.downloads, usize::from(kind == Kind::Cadvisor));
        assert_eq!(
            f.host.commands.iter().filter(|c| c[0] == "apt-get").count(),
            usize::from(kind == Kind::Exporter)
        );
        assert_eq!(fs::read_dir(f.path().parent().unwrap()).unwrap().count(), 1);
    }
}
#[test]
fn removes_repeatedly_and_restores_a_fleet_snapshot_after_binary_removal() {
    for kind in [Kind::Exporter, Kind::Cadvisor] {
        let mut f = fixture(kind);
        let state = owned(&mut f);
        for _ in 0..2 {
            f.execute(Operation::Remove {}).unwrap();
            assert!(!f.path().exists());
            assert!(!f.root.join(CADVISOR_BINARY).exists());
            assert!(!f.host.active);
            assert_eq!(f.host.rule, None);
            assert_eq!(f.actual(), "inactive");
        }
        for _ in 0..2 {
            f.execute(Operation::Restore {
                state: state.clone(),
            })
            .unwrap();
            assert_eq!(f.snapshot().unwrap(), state);
        }
        if kind == Kind::Cadvisor {
            assert!(f.root.join(CADVISOR_BINARY).is_file());
            assert_eq!(f.host.downloads, 1);
        }
    }
}
#[test]
fn refuses_foreign_configurations_and_links_before_any_program() {
    for kind in [Kind::Exporter, Kind::Cadvisor] {
        for link in [false, true] {
            let mut f = fixture(kind);
            fs::create_dir_all(f.path().parent().unwrap()).unwrap();
            if link {
                std::os::unix::fs::symlink(f.root.join("absent"), f.path()).unwrap();
            } else {
                fs::write(f.path(), "[Service]\n# foreign\n").unwrap();
            }
            for operation in [
                Operation::Snapshot {},
                Operation::Converge {},
                Operation::Remove {},
            ] {
                assert_eq!(
                    f.execute(operation).unwrap_err().step,
                    Step::ConfigurationOwnership
                );
                assert!(f.host.commands.is_empty());
            }
            assert!(!f.root.join("absent").exists());
        }
    }
}
#[test]
fn firewall_allows_only_a_single_host_source_change_and_refuses_other_drift() {
    for kind in [Kind::Exporter, Kind::Cadvisor] {
        let row = format!(
            "[ 5] 10.44.0.4 {}/tcp on orbit ALLOW IN 10.44.0.3 # {}",
            kind.port(),
            kind.comment()
        );
        let address = "10.44.0.4".parse().unwrap();
        for source in ["10.44.0.3", "10.44.0.2", "10.44.0.2/32"] {
            let text = format!("Status: active\n{}", row.replace("10.44.0.3", source));
            assert_eq!(
                rule(&text, kind, address).unwrap().unwrap().source,
                source
                    .strip_suffix("/32")
                    .unwrap_or(source)
                    .parse::<Ipv4Addr>()
                    .unwrap()
            );
        }
        for wrong in [
            row.replace("910", "920"),
            row.replace("/tcp", "/udp"),
            row.replace("ALLOW", "DENY"),
            row.replace(" IN ", " OUT "),
            row.replace("orbit ALLOW", "eth0 ALLOW"),
            row.replace("10.44.0.4 ", "10.44.0.5 "),
            row.replace("10.44.0.3", "10.44.0.0/24"),
            row.replace("10.44.0.3", "Anywhere"),
            row.replace("10.44.0.3", "::1 (v6)"),
            format!("{row}\n{row}"),
            format!("unreadable # {}", kind.comment()),
        ] {
            let mut f = fixture(kind);
            f.host.raw_firewall = Some(format!("Status: active\n{wrong}"));
            assert_eq!(
                f.execute(Operation::Converge {}).unwrap_err().step,
                Step::FirewallOwnership,
                "{wrong}"
            );
            assert_eq!(f.host.commands, vec![vec!["ufw", "status", "numbered"]]);
            assert!(!f.path().exists());
        }
        assert_eq!(
            rule("Status: inactive\n", kind, address).unwrap_err().step,
            Step::FirewallInactive
        );
        assert!(
            rule(&format!("Status: active\n{row}:unrelated"), kind, address)
                .unwrap()
                .is_none()
        );
    }
}
#[test]
fn repoints_stale_sources_and_restores_them_after_verification_failure() {
    for kind in [Kind::Exporter, Kind::Cadvisor] {
        for fails in [false, true] {
            let mut f = fixture(kind);
            let before = owned(&mut f);
            if fails {
                fail(&mut f, &["ufw", "status", "numbered"], 3);
            }
            let result = f.execute(Operation::Converge {});
            if fails {
                assert_eq!(result.unwrap_err().step, Step::FirewallInspection);
                assert_eq!(f.snapshot().unwrap(), before);
            } else {
                result.unwrap();
                assert_eq!(f.host.rule, Some("10.44.0.3".parse().unwrap()));
            }
            assert!(f.host.commands.contains(&vec![
                "ufw".into(),
                "--force".into(),
                "delete".into(),
                "5".into()
            ]));
        }
    }
}
#[test]
fn failed_convergence_restores_absent_state_and_stops_before_removing_the_unit() {
    for kind in [Kind::Exporter, Kind::Cadvisor] {
        let mut f = fixture(kind);
        fail(&mut f, &["ufw", "status", "numbered"], 3);
        assert_eq!(
            f.execute(Operation::Converge {}).unwrap_err().step,
            Step::FirewallInspection
        );
        assert_eq!(
            f.snapshot().unwrap(),
            State {
                configuration: None,
                service_active: false,
                firewall_source: None
            }
        );
        let disable = f
            .host
            .commands
            .iter()
            .position(|c| c == &vec!["systemctl", "disable", "--now", kind.service()])
            .unwrap();
        let reload = f
            .host
            .commands
            .iter()
            .rposition(|c| c == &vec!["systemctl", "daemon-reload"])
            .unwrap();
        assert!(disable < reload);
    }
}
#[test]
fn failed_removal_restores_configuration_service_and_firewall() {
    for kind in [Kind::Exporter, Kind::Cadvisor] {
        let mut f = fixture(kind);
        let before = owned(&mut f);
        fail(&mut f, &["systemctl", "daemon-reload"], 1);
        assert_eq!(
            f.execute(Operation::Remove {}).unwrap_err().step,
            Step::ConfigurationRemove
        );
        assert_eq!(f.snapshot().unwrap(), before);
        let mut f = fixture(kind);
        let before = owned(&mut f);
        f.host.disable_works = false;
        assert_eq!(
            f.execute(Operation::Remove {}).unwrap_err().step,
            Step::ServiceRemoveVerify
        );
        assert_eq!(f.snapshot().unwrap(), before);
    }
}
#[test]
fn a_wrong_download_never_replaces_the_previous_binary_or_configuration() {
    let mut f = fixture(Kind::Cadvisor);
    let before = owned(&mut f);
    fs::write(f.root.join(CADVISOR_BINARY), b"previous release").unwrap();
    f.host.download = b"wrong download".to_vec();
    assert_eq!(
        f.execute(Operation::Converge {}).unwrap_err().step,
        Step::Checksum
    );
    assert_eq!(
        fs::read(f.root.join(CADVISOR_BINARY)).unwrap(),
        b"previous release"
    );
    assert_eq!(f.snapshot().unwrap(), before);
}
#[test]
fn restore_input_is_validated_before_observation_or_mutation() {
    for kind in [Kind::Exporter, Kind::Cadvisor] {
        let mut f = fixture(kind);
        assert_eq!(
            f.execute(Operation::Restore {
                state: State {
                    configuration: Some("foreign".into()),
                    service_active: false,
                    firewall_source: None
                }
            })
            .unwrap_err()
            .step,
            Step::Invalid
        );
        assert!(f.host.commands.is_empty());
        assert_eq!(fs::read_dir(&f.root).unwrap().count(), 0);
        assert_eq!(
            run(
                kind,
                Input {
                    address: "bad address".into(),
                    metrics_address: "10.44.0.3".into(),
                    action: Operation::Converge {}
                }
            )
            .unwrap_err()
            .step,
            Step::Address
        );
    }
    assert!(serde_json::from_value::<Input>(serde_json::json!({"address":"10.44.0.4","metrics_address":"10.44.0.3","action":{"operation":"converge","script":"anything"}})).is_err());
}
#[test]
fn reports_initial_and_rollback_failures_without_program_output() {
    let mut f = fixture(Kind::Exporter);
    owned(&mut f);
    fail(
        &mut f,
        &["systemctl", "restart", "prometheus-node-exporter"],
        1,
    );
    fail(
        &mut f,
        &["systemctl", "restart", "prometheus-node-exporter"],
        2,
    );
    let error = f.execute(Operation::Converge {}).unwrap_err();
    assert_eq!(error.code(), "metrics.exporter_rollback_failed");
    assert_eq!(
        error.recovery,
        Some([
            "metrics.exporter_service_failed".into(),
            "metrics.exporter_service_failed".into()
        ])
    );
}
#[test]
fn observes_drift_and_unknown_without_repairing() {
    let mut f = fixture(Kind::Exporter);
    assert_eq!(f.actual(), "inactive");
    owned(&mut f);
    assert_eq!(f.actual(), "drift");
    let address = f.metrics_address;
    f.host.rule = Some(address);
    f.host.active = false;
    assert_eq!(f.actual(), "inactive");
    f.host.active = true;
    assert_eq!(f.actual(), "active");
    fail(&mut f, &["ufw", "status", "numbered"], 5);
    assert_eq!(f.actual(), "unknown");
    assert!(f
        .host
        .commands
        .iter()
        .all(|c| c[1] == "status" || c[1] == "is-active"));
}
