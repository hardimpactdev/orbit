use super::*;
use crate::commands::incus::Output;
use serde_json::json;
struct Fixture {
    root: std::path::PathBuf,
    calls: Vec<Vec<String>>,
    volumes: Value,
    instances: Value,
    fail: Option<usize>,
    archive: bool,
    symlink: bool,
    device: bool,
}
impl Fixture {
    fn new() -> Self {
        let root =
            std::env::temp_dir().join(format!("orbit-seed-test-{:016x}", rand::random::<u64>()));
        fs::create_dir(&root).unwrap();
        Self {
            root,
            calls: vec![],
            volumes: json!([]),
            instances: json!([{"name":TEMPLATE,"type":"virtual-machine","status":"Stopped","profiles":[],"devices":{"root":{"pool":POOL}}}]),
            fail: None,
            archive: true,
            symlink: false,
            device: true,
        }
    }
    fn build(&mut self) -> Result<Built, Error> {
        build(self, &input(), &self.root.clone(), || {})
    }
}
impl Drop for Fixture {
    fn drop(&mut self) {
        fs::remove_dir_all(&self.root).unwrap();
    }
}
fn input() -> Input {
    Input {
        project: "orbit-tasks".into(),
        pool: "orbit-tasks".into(),
        vm: "tvm-image-7-smoke".into(),
        size: "45GiB".into(),
        fingerprint: "ab".repeat(32),
    }
}
impl Host for Fixture {
    fn run(&mut self, args: &[&str], stdin: Option<&str>) -> Output {
        assert!(stdin.is_none());
        self.calls
            .push(args.iter().map(|s| s.to_string()).collect());
        let fail = self.fail == Some(self.calls.len()) || (args.contains(&"test") && !self.device);
        let stdout = if args.get(2..5) == Some(&["storage", "volume", "list"]) {
            self.volumes.to_string()
        } else if args.ends_with(&["incus", "list", "--format", "json"]) {
            self.instances.to_string()
        } else {
            String::new()
        };
        if !fail && args.get(2..4) == Some(&["image", "export"]) && self.archive {
            let path = format!("{}.tar", args[5]);
            if self.symlink {
                std::os::unix::fs::symlink("/missing", path).unwrap();
            } else {
                fs::write(path, "archive").unwrap();
            }
        }
        if !fail && args.get(2..5) == Some(&["storage", "volume", "create"]) {
            self.volumes = json!([{"name":args[6],"type":"custom"}]);
        }
        Output {
            code: if fail { 1 } else { 0 },
            stdout,
            stderr: if fail {
                "fixture failure".into()
            } else {
                String::new()
            },
            truncated: false,
        }
    }
}
#[test]
fn builds_in_order_with_closed_stdin_and_rebuilds_only_the_temporary_volume() {
    let mut f = Fixture::new();
    for attempt in 0..2 {
        f.calls.clear();
        let result = f.build().unwrap();
        assert!(result.ready);
        assert_eq!(
            result
                .seconds
                .keys()
                .map(String::as_str)
                .collect::<Vec<_>>(),
            vec!["copy", "template", "volume"]
        );
        let calls: Vec<_> = f.calls.iter().map(|c| c[2..].join(" ")).collect();
        let volume = "orbit-topology-seed-abababababab-new";
        let offset = if attempt == 1 {
            assert_eq!(
                calls[1],
                format!("storage volume delete orbit-tasks {volume}")
            );
            1
        } else {
            0
        };
        assert_eq!(
            calls[1 + offset],
            format!("storage volume create orbit-tasks {volume} --type=block size=45GiB")
        );
        assert_eq!(calls[2+offset],format!("config device add tvm-image-7-smoke topology-seed disk pool=orbit-tasks source={volume}"));
        assert!(calls[4 + offset].contains("file push"));
        assert!(calls[5 + offset].ends_with(&format!("test -b {DEVICE}")));
        assert!(calls[6 + offset].ends_with(&format!(
            "incus storage create {POOL} btrfs source={DEVICE}"
        )));
        assert!(calls[9 + offset].ends_with(&format!(
            "incus init {} {TEMPLATE} --vm --no-profiles --storage {POOL}",
            input().fingerprint
        )));
        assert!(calls[calls.len() - 2].ends_with(
            "systemctl stop incus.socket incus-user.socket incus.service incus-user.service"
        ));
        assert!(calls.last().unwrap().ends_with("sync"));
        assert_eq!(fs::read_dir(&f.root).unwrap().count(), 0);
    }
}
#[test]
fn invalid_input_refuses_before_any_host_call() {
    for (key, value) in [
        ("project", "--bad"),
        ("pool", "bad pool"),
        ("vm", "-smoke"),
        ("size", "45G"),
        ("fingerprint", "AB"),
        ("volume", "finished-seed"),
        ("device", "custom"),
    ] {
        let mut value_json = json!({"project":"orbit-tasks","pool":"orbit-tasks","vm":"smoke","size":"45GiB","fingerprint":"ab".repeat(32)});
        value_json[key] = json!(value);
        let mut f = Fixture::new();
        if let Ok(input) = serde_json::from_value::<Input>(value_json) {
            assert!(matches!(
                build(&mut f, &input, &std::env::temp_dir(), || {}),
                Err(Error::Invalid {})
            ));
        }
        assert!(f.calls.is_empty());
    }
    assert_eq!(BuildSeed::privilege(Platform::Macos), None);
}
#[test]
fn every_failed_step_refuses_and_cleans_the_host_export() {
    let mut good = Fixture::new();
    good.build().unwrap();
    for call in 1..=good.calls.len() {
        // An absent device is retried separately; it is not a failed mutation.
        if good.calls[call - 1].contains(&"test".to_string()) {
            continue;
        }
        let mut f = Fixture::new();
        f.fail = Some(call);
        assert!(
            matches!(f.build(), Err(Error::Failed { .. })),
            "call {call}"
        );
        assert_eq!(fs::read_dir(&f.root).unwrap().count(), 0, "call {call}");
        assert_eq!(f.calls.len(), call, "ran after failed call {call}");
    }
    let mut f = Fixture::new();
    f.volumes = json!([{"name":"orbit-topology-seed-abababababab-new","type":"custom"}]);
    f.fail = Some(2);
    assert!(f.build().is_err());
    assert_eq!(f.calls.len(), 2);
}
#[test]
fn refuses_missing_and_symlink_archives_before_guest_copy() {
    for symlink in [false, true] {
        let mut f = Fixture::new();
        f.archive = symlink;
        f.symlink = symlink;
        assert!(matches!(f.build(),Err(Error::Failed{reason}) if reason.contains("one archive")));
        assert!(!f.calls.iter().any(|c| c.contains(&"push".into())));
        assert_eq!(fs::read_dir(&f.root).unwrap().count(), 0);
    }
}
#[test]
fn bounds_device_wait_and_never_formats_a_missing_disk() {
    let mut f = Fixture::new();
    f.device = false;
    assert!(
        matches!(f.build(),Err(Error::Failed{reason}) if reason.contains("disk did not appear"))
    );
    assert_eq!(
        f.calls
            .iter()
            .filter(|c| c.contains(&"test".into()))
            .count(),
        61
    );
    assert!(!f.calls.iter().any(|c| c.contains(&"btrfs".into())));
}
#[test]
fn refuses_incomplete_inventory_before_creating_any_volume() {
    let mut f = Fixture::new();
    f.volumes = json!([{"name":"unknown"}]);
    assert!(f.build().is_err());
    assert_eq!(f.calls.len(), 1);
}
#[test]
fn verifies_the_single_stopped_template_before_unmounting() {
    for change in [
        json!([]),
        json!([{}]),
        json!([{}, {}]),
        json!({"unexpected":true}),
    ] {
        let mut f = Fixture::new();
        f.instances = change;
        assert!(f.build().is_err());
        assert!(!f.calls.iter().any(|c| c.contains(&"systemctl".into())));
    }
    for (key, value) in [
        ("status", json!("Running")),
        ("profiles", json!(["default"])),
        ("type", json!("container")),
        ("devices", json!({"root":{"pool":"default"}})),
    ] {
        let mut f = Fixture::new();
        f.instances[0][key] = value;
        assert!(f.build().is_err());
        assert!(!f.calls.iter().any(|c| c.contains(&"sync".into())));
    }
}
