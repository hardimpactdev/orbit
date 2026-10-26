use super::*;
use serde_json::json;

struct Fixture {
    root: PathBuf,
    paths: Paths,
}
impl Fixture {
    fn new() -> Self {
        let root = std::env::temp_dir().join(format!("orbit-hub-{:016x}", rand::random::<u64>()));
        fs::create_dir_all(&root).unwrap();
        let paths = Paths {
            rules: root.join("rules/hub.nft"),
            unit: root.join("systemd").join(UNIT),
            uid: rustix::process::geteuid().as_raw(),
        };
        Self { root, paths }
    }
    fn host(&self) -> Fake {
        Fake {
            rules_path: self.paths.rules.clone(),
            ..Fake::default()
        }
    }
}
impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.root);
    }
}
#[derive(Default)]
struct Fake {
    rules_path: PathBuf,
    table: Option<String>,
    enabled: bool,
    active: bool,
    calls: Vec<(Tool, Vec<String>, Option<String>)>,
    fail: Option<usize>,
    fail_recovery: bool,
}
fn output(code: i32, stdout: impl Into<String>) -> Output {
    Output {
        code,
        stdout: stdout.into(),
        stderr: "injected failure".into(),
        truncated: false,
    }
}
impl Host for Fake {
    fn run(&mut self, tool: Tool, args: &[&str], input: Option<&str>) -> Output {
        self.calls.push((
            tool,
            args.iter().map(|v| (*v).into()).collect(),
            input.map(str::to_owned),
        ));
        if self.fail == Some(self.calls.len())
            || (self.fail_recovery && tool == Tool::Nft && args == ["-f", "-"])
        {
            return output(1, "");
        }
        match (tool, args) {
            (Tool::Nft, ["-j", "list", "tables"]) => output(0, if self.table.is_some() { json!({"nftables":[{"metainfo":{}},{"table":{"name":TABLE,"family":"inet"}},{"table":{"name":"foreign","family":"ip"}}]}) } else { json!({"nftables":[]}) }.to_string()),
            (Tool::Nft, ["list", "table", "inet", TABLE]) => match &self.table { Some(table) => output(0, table), None => output(1, "") },
            (Tool::Nft, ["-c", "-f", "-"]) => { assert!(input.unwrap().contains("chain forward")); output(0, "") },
            (Tool::Nft, ["-f", "-"]) => {
                let prefix = format!("table inet {TABLE}\ndelete table inet {TABLE}\n");
                let table = input.unwrap().strip_prefix(&prefix).unwrap();
                self.table = (!table.is_empty()).then(|| table.into());
                output(0, "")
            },
            (Tool::Systemctl, ["is-enabled", UNIT]) => output(if self.enabled { 0 } else { 1 }, if self.enabled { "enabled\n" } else { "disabled\n" }),
            (Tool::Systemctl, ["is-active", UNIT]) => output(if self.active { 0 } else { 3 }, if self.active { "active\n" } else { "inactive\n" }),
            (Tool::Systemctl, ["enable", "--quiet", UNIT]) => { self.enabled = true; output(0, "") },
            (Tool::Systemctl, ["disable", "--quiet", UNIT]) => { self.enabled = false; output(0, "") },
            (Tool::Systemctl, ["restart", UNIT]) => { self.table = Some(fs::read_to_string(&self.rules_path).unwrap()); self.active = true; output(0, "") },
            (Tool::Systemctl, ["stop", UNIT]) => { self.active = false; output(0, "") },
            (Tool::Systemctl, ["daemon-reload"]) => output(0, ""),
            _ => panic!("unexpected call {tool:?} {args:?}"),
        }
    }
}
fn input() -> Input {
    serde_json::from_value(json!({"range":"10.44.0.128/25","dns":"10.44.0.1","gateway":"10.44.0.2","pi_port":3774,"reverb":"10.44.0.3:443","model":"10.44.0.3:8317","routers":["10.44.0.7"]})).unwrap()
}
#[test]
fn renders_the_reserved_boundary_and_installs_it_repeatedly() {
    let fixture = Fixture::new();
    let mut host = fixture.host();
    for _ in 0..2 {
        assert_eq!(
            prepare(&input(), &mut host, &fixture.paths).unwrap(),
            Prepared { ready: true }
        );
        assert!(host.enabled && host.active);
        let rules = fs::read_to_string(&fixture.paths.rules).unwrap();
        assert_eq!(host.table.as_deref(), Some(rules.as_str()));
        assert_eq!(fs::read(&fixture.paths.unit).unwrap(), UNIT_TEXT.as_bytes());
        assert_eq!(
            fs::metadata(&fixture.paths.rules).unwrap().mode() & 0o777,
            0o644
        );
        for allow in [
            "ip saddr 10.44.0.128/25 ip daddr 10.44.0.2 tcp dport 443 accept",
            "ip saddr 10.44.0.128/25 ip daddr 10.44.0.3 tcp dport 443 accept",
            "ip saddr 10.44.0.128/25 ip daddr 10.44.0.3 tcp dport 8317 accept",
            "ip saddr 10.44.0.2 ip daddr 10.44.0.128/25 tcp dport { 22, 3774 } accept",
            "ip saddr { 10.44.0.7 } ip daddr 10.44.0.128/25 tcp dport { 80, 443, 5173 } accept",
            "ip daddr 10.44.0.1 udp dport 53 accept",
            "ip daddr 10.44.0.1 tcp dport 53 accept",
        ] {
            assert!(rules.contains(allow), "missing {allow}");
        }
        let forward = rules
            .split("chain forward")
            .nth(1)
            .unwrap()
            .split("chain input")
            .next()
            .unwrap();
        assert!(
            forward.find("meta nfproto != ipv4 accept").unwrap() < forward.find("drop").unwrap()
        );
        assert!(
            forward
                .find("ip saddr != 10.44.0.128/25 ip daddr != 10.44.0.128/25 accept")
                .unwrap()
                < forward.find("ct state established,related accept").unwrap()
        );
        assert!(
            forward.contains("ip saddr 10.44.0.128/25 drop\n        ip daddr 10.44.0.128/25 drop")
        );
        let incoming = rules.split("chain input").nth(1).unwrap();
        assert!(
            incoming.find("ip saddr != 10.44.0.128/25 accept").unwrap()
                < incoming.find("drop").unwrap()
        );
    }
}
#[test]
fn invalid_input_refuses_before_host_access_or_files() {
    for (field, value) in [
        ("range", json!("10.44.0.129/25")),
        ("range", json!("10.0.0.0/8")),
        ("range", json!("10.44.0.128/25\ndrop")),
        ("pi_port", json!(0)),
        ("reverb", json!("10.44.0.3:0")),
        ("model", json!("10.44.0.3:0")),
        ("routers", json!([])),
    ] {
        let fixture = Fixture::new();
        let mut host = fixture.host();
        let mut value_input = json!({"range":"10.44.0.128/25","dns":"10.44.0.1","gateway":"10.44.0.2","pi_port":3774,"reverb":"10.44.0.3:443","model":"10.44.0.3:8317","routers":["10.44.0.7"]});
        value_input[field] = value;
        let input = serde_json::from_value(value_input).unwrap();
        assert!(
            matches!(prepare(&input, &mut host, &fixture.paths), Err(Error::Failed { stage, .. }) if stage == "input")
        );
        assert!(host.calls.is_empty());
        assert!(!fixture.paths.rules.exists());
    }
    for (field, value) in [
        ("dns", json!("10.44.0.1 accept")),
        ("routers", json!(["10.44.0.7 accept"])),
        ("reverb", json!("10.44.0.3:99999")),
        ("pi_port", json!("22 }")),
    ] {
        let mut data = json!({"range":"10.44.0.128/25","dns":"10.44.0.1","gateway":"10.44.0.2","pi_port":3774,"reverb":"10.44.0.3:443","model":"10.44.0.3:8317","routers":["10.44.0.7"]});
        data[field] = value;
        assert!(serde_json::from_value::<Input>(data).is_err());
    }
}
#[test]
fn foreign_files_and_unowned_tables_refuse_before_changes() {
    for kind in ["rules", "unit", "symlink", "table"] {
        let fixture = Fixture::new();
        let mut host = fixture.host();
        match kind {
            "rules" => write(&fixture.paths.rules, b"operator rules", 0o640).unwrap(),
            "unit" => write(&fixture.paths.unit, b"operator unit", 0o640).unwrap(),
            "symlink" => {
                fs::create_dir_all(fixture.paths.rules.parent().unwrap()).unwrap();
                std::os::unix::fs::symlink("missing", &fixture.paths.rules).unwrap();
            }
            "table" => host.table = Some("table inet orbit_task_vms {}".into()),
            _ => unreachable!(),
        }
        assert!(
            matches!(prepare(&input(), &mut host, &fixture.paths), Err(Error::Failed { stage, .. }) if stage == "ownership")
        );
        assert!(!host
            .calls
            .iter()
            .any(|(tool, _, _)| *tool == Tool::Systemctl));
        if kind == "rules" {
            assert_eq!(fs::read(&fixture.paths.rules).unwrap(), b"operator rules");
        }
    }
}
#[test]
fn inspection_and_validation_failures_leave_no_files() {
    for fail in 1..=4 {
        let fixture = Fixture::new();
        let mut host = fixture.host();
        host.fail = Some(fail);
        assert!(prepare(&input(), &mut host, &fixture.paths).is_err());
        assert!(!fixture.paths.rules.exists() && !fixture.paths.unit.exists());
        assert!(!host.enabled && !host.active && host.table.is_none());
    }
}
#[test]
fn every_activation_failure_restores_files_live_rules_and_service_state() {
    for existing in [false, true] {
        for step in 0..4 {
            let fixture = Fixture::new();
            let mut host = fixture.host();
            let old = format!("{MARKER}10.44.0.0/26.\ntable inet orbit_task_vms {{}}\n");
            if existing {
                write(&fixture.paths.rules, old.as_bytes(), 0o640).unwrap();
                write(&fixture.paths.unit, UNIT_TEXT.as_bytes(), 0o600).unwrap();
                host.table = Some("table inet orbit_task_vms { chain old {} }\n".into());
                host.enabled = true;
                host.active = true;
            }
            let previous_table = host.table.clone();
            host.fail = Some(if existing { 6 } else { 5 } + step);
            assert!(prepare(&input(), &mut host, &fixture.paths).is_err());
            assert_eq!((host.enabled, host.active), (existing, existing));
            assert_eq!(host.table, previous_table);
            if existing {
                assert_eq!(fs::read(&fixture.paths.rules).unwrap(), old.as_bytes());
                assert_eq!(
                    fs::metadata(&fixture.paths.rules).unwrap().mode() & 0o777,
                    0o640
                );
                assert_eq!(fs::read(&fixture.paths.unit).unwrap(), UNIT_TEXT.as_bytes());
                assert_eq!(
                    fs::metadata(&fixture.paths.unit).unwrap().mode() & 0o777,
                    0o600
                );
            } else {
                assert!(!fixture.paths.rules.exists() && !fixture.paths.unit.exists());
            }
        }
    }
}
#[test]
fn failed_recovery_reports_its_stage() {
    let fixture = Fixture::new();
    let mut host = fixture.host();
    host.fail = Some(7);
    host.fail_recovery = true;
    assert!(
        matches!(prepare(&input(), &mut host, &fixture.paths), Err(Error::Failed { stage, .. }) if stage == "recovery")
    );
}

#[test]
fn a_partial_file_publication_restores_the_previous_rules() {
    let fixture = Fixture::new();
    let mut host = fixture.host();
    let old = format!("{MARKER}10.44.0.0/26.\ntable inet orbit_task_vms {{}}\n");
    write(&fixture.paths.rules, old.as_bytes(), 0o640).unwrap();
    fs::create_dir_all(fixture.paths.unit.parent().unwrap()).unwrap();
    fs::set_permissions(
        fixture.paths.unit.parent().unwrap(),
        fs::Permissions::from_mode(0o500),
    )
    .unwrap();
    assert!(
        matches!(prepare(&input(), &mut host, &fixture.paths), Err(Error::Failed { stage, .. }) if stage == "publish")
    );
    assert_eq!(fs::read(&fixture.paths.rules).unwrap(), old.as_bytes());
    assert!(!fixture.paths.unit.exists());
    assert!(!host.active && !host.enabled && host.table.is_none());
    fs::set_permissions(
        fixture.paths.unit.parent().unwrap(),
        fs::Permissions::from_mode(0o700),
    )
    .unwrap();
}
