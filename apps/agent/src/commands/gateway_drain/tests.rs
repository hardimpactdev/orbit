use super::*;
use crate::commands::recipe::{Failure, Output, WorkDir};
use serde_json::{json, Value};
use std::os::unix::fs::{symlink, PermissionsExt};

pub(super) struct Fixture {
    pub work: WorkDir,
    pub files: Files,
    pub checkout: String,
}
impl Fixture {
    pub fn new() -> Self {
        let work = WorkDir::new().unwrap();
        fs::create_dir_all(work.0.join("releases/aaaaaaaaaaaa")).unwrap();
        fs::write(
            work.0.join("releases/aaaaaaaaaaaa/REVISION"),
            "a".repeat(40),
        )
        .unwrap();
        symlink("releases/aaaaaaaaaaaa", work.0.join("orbit")).unwrap();
        let files = Files {
            root: work.0.join("machine"),
            uid: rustix::process::geteuid().as_raw(),
            gid: rustix::process::getegid().as_raw(),
        };
        let checkout = work.0.join("orbit/apps/gateway").display().to_string();
        let f = Self {
            work,
            files,
            checkout,
        };
        f.file(
            "/proc/mounts",
            "proc /proc proc rw,nosuid,nodev,noexec,relatime 0 0\n",
        );
        f.manifest(f.default_manifest());
        f
    }
    pub fn file(&self, path: &str, value: impl AsRef<[u8]>) {
        let path = self.files.path(path);
        fs::create_dir_all(path.parent().unwrap()).unwrap();
        fs::write(path, value).unwrap();
    }
    pub fn default_manifest(&self) -> Value {
        json!({"target_sha": "b".repeat(40), "source_sha": "a".repeat(40), "units": Fake::default().units})
    }
    pub fn manifest(&self, value: Value) {
        self.file(
            "/run/orbit-gateway-drain/hold.json",
            serde_json::to_vec(&value).unwrap(),
        );
    }
    pub fn input(&self) -> HoldInput {
        HoldInput {
            checkout: self.checkout.clone(),
            target_sha: "b".repeat(40),
            caller_pid: 900,
        }
    }
    pub fn declaration(&self) -> Value {
        json!({"process_id": 7, "node_id": 1, "unit": "orbit-process-7-ordinary.service", "source_sha":"a".repeat(40), "target_sha":"b".repeat(40), "spec_sha256":"c".repeat(64), "unit_sha256":"d".repeat(64), "runtime_sha256":"e".repeat(64)})
    }
}
pub(super) struct Fake {
    pub calls: Vec<Vec<String>>,
    pub units: Vec<String>,
    pub properties: BTreeMap<String, String>,
    pub default_properties: String,
    pub process_list: String,
    pub fault: String,
    pub after_first_show: Option<String>,
    shows: usize,
}
impl Default for Fake {
    fn default() -> Self {
        Self {
            calls: vec![],
            units: [
                "orbit-gateway-release-run@.service",
                "orbit-gateway-release.timer",
                "orbit-process-108-schedule-work.service",
                "php8.5-fpm.service",
            ]
            .map(str::to_owned)
            .into(),
            properties: BTreeMap::new(),
            default_properties:
                "LoadState=masked\nActiveState=inactive\nMainPID=0\nControlGroup=\n".into(),
            process_list: "900 php8.5\n1 systemd\n".into(),
            fault: String::new(),
            after_first_show: None,
            shows: 0,
        }
    }
}
impl recipe::Host for Fake {
    fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
        self.calls.push(call.argv.clone());
        let verb = call.argv[1].as_str();
        let stdout = match verb {
            "list-unit-files" | "list-units" => {
                assert!(call.argv.contains(&"--type=service,timer".into()));
                let mut rows = self
                    .units
                    .iter()
                    .map(|u| format!("{u} masked disabled\n"))
                    .collect::<String>();
                if self.fault == "template" {
                    rows = rows.replace("@.service masked", "@.service static");
                }
                if self.fault == verb {
                    rows += "orbit-process-999-unknown.service loaded active\n";
                }
                rows
            }
            "show" => {
                assert!(!call.argv[2].contains("@."));
                self.shows += 1;
                if self.shows > 1 && self.after_first_show.is_some() {
                    self.after_first_show.clone().unwrap()
                } else {
                    self.properties
                        .get(&call.argv[2])
                        .cloned()
                        .unwrap_or_else(|| self.default_properties.clone())
                }
            }
            "-eo" => self.process_list.clone(),
            other => panic!("unexpected mutation or executable: {other}"),
        };
        Ok(Output {
            code: if self.fault == "failed" { 1 } else { 0 },
            stdout: if self.fault == "oversized" {
                "x".repeat(65537)
            } else {
                stdout
            },
            stderr: "password=never-return-this".into(),
            signal: None,
            truncated: self.fault == "truncated",
        })
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("read-only observation must not wait for a change")
    }
}
pub(super) fn stage(error: Error) -> String {
    let Error::NotDrained { stage } = error;
    stage
}

#[test]
fn complete_hold_repeats_without_mutation_and_checks_concrete_instances_as_well_as_templates() {
    let f = Fixture::new();
    let mut host = Fake::default();
    host.units
        .push("orbit-gateway-release-run@42.service".into());
    let mut manifest = f.default_manifest();
    manifest["units"] = json!(host.units);
    f.manifest(manifest);
    let before = fs::read(f.files.path("/run/orbit-gateway-drain/hold.json")).unwrap();
    for _ in 0..2 {
        assert!(hold(&f.files, &mut host, &f.input())
            .unwrap()
            .unrelated_processes
            .is_empty());
    }
    assert_eq!(
        fs::read(f.files.path("/run/orbit-gateway-drain/hold.json")).unwrap(),
        before
    );
    assert_eq!(host.calls.iter().filter(|c| c[1] == "show").count(), 8);
}

#[test]
fn manifest_identity_permissions_and_complete_source_refuse_before_host_commands() {
    for fault in [
        "target",
        "source",
        "writable",
        "directory",
        "foreign",
        "link",
        "file-type",
        "duplicate",
        "unsupported",
        "missing-revision",
    ] {
        let mut f = Fixture::new();
        let mut host = Fake::default();
        let mut m = f.default_manifest();
        let path = f.files.path("/run/orbit-gateway-drain/hold.json");
        match fault {
            "target" => {
                m["target_sha"] = json!("c".repeat(40));
                f.manifest(m);
            }
            "source" => {
                m["source_sha"] = json!("c".repeat(40));
                f.manifest(m);
            }
            "writable" => fs::set_permissions(&path, fs::Permissions::from_mode(0o666)).unwrap(),
            "directory" => {
                fs::set_permissions(path.parent().unwrap(), fs::Permissions::from_mode(0o777))
                    .unwrap()
            }
            "foreign" => f.files.uid += 1,
            "link" => {
                fs::rename(&path, path.with_extension("saved")).unwrap();
                symlink(path.with_extension("saved"), &path).unwrap();
            }
            "file-type" => {
                fs::remove_file(&path).unwrap();
                fs::create_dir(&path).unwrap();
            }
            "duplicate" => {
                m["units"]
                    .as_array_mut()
                    .unwrap()
                    .push(json!("php8.5-fpm.service"));
                f.manifest(m);
            }
            "unsupported" => {
                m["units"]
                    .as_array_mut()
                    .unwrap()
                    .push(json!("sshd.service"));
                f.manifest(m);
            }
            "missing-revision" => {
                fs::remove_file(f.work.0.join("releases/aaaaaaaaaaaa/REVISION")).unwrap()
            }
            _ => unreachable!(),
        }
        assert!(hold(&f.files, &mut host, &f.input()).is_err(), "{fault}");
        assert!(host.calls.is_empty(), "{fault}");
    }
}

#[test]
fn changed_failed_or_capped_inventories_and_writer_state_never_prove_a_hold() {
    let f = Fixture::new();
    for fault in [
        "list-units",
        "list-unit-files",
        "failed",
        "truncated",
        "oversized",
        "template",
    ] {
        let mut host = Fake {
            fault: fault.into(),
            ..Fake::default()
        };
        assert!(hold(&f.files, &mut host, &f.input()).is_err(), "{fault}");
    }
    for (from, to) in [
        ("ActiveState=inactive", "ActiveState=active"),
        ("LoadState=masked", "LoadState=loaded"),
        ("MainPID=0", "MainPID=1"),
        ("ControlGroup=", "Absent="),
    ] {
        let mut host = Fake::default();
        host.default_properties = host.default_properties.replace(from, to);
        assert!(hold(&f.files, &mut host, &f.input()).is_err(), "{to}");
    }
    let mut host = Fake::default();
    host.units.retain(|u| u != "php8.5-fpm.service");
    let mut m = f.default_manifest();
    m["units"] = json!(host.units);
    f.manifest(m);
    assert_eq!(
        stage(hold(&f.files, &mut host, &f.input()).unwrap_err()),
        "inventory"
    );
}

#[test]
fn a_writer_cgroup_must_be_safe_readable_and_completely_empty() {
    for (group, events, allowed) in [
        ("/system.slice/writer", "populated 0\nfrozen 0\n", true),
        ("/system.slice/writer", "populated 1\n", false),
        ("/system.slice/writer", "frozen 0\n", false),
        ("/../outside", "populated 0\n", false),
    ] {
        let f = Fixture::new();
        let mut host = Fake::default();
        host.default_properties = host
            .default_properties
            .replace("ControlGroup=", &format!("ControlGroup={group}"));
        f.file("/sys/fs/cgroup/system.slice/writer/cgroup.events", events);
        assert_eq!(hold(&f.files, &mut host, &f.input()).is_ok(), allowed);
    }
}

#[test]
fn hidden_owners_malformed_process_scans_and_extra_php_are_refused() {
    let f = Fixture::new();
    for mount in [
        "proc /proc proc rw,hidepid=2 0 0",
        "proc /proc proc rw,hidepid=invisible 0 0",
        "missing",
    ] {
        f.file("/proc/mounts", mount);
        assert_eq!(
            stage(hold(&f.files, &mut Fake::default(), &f.input()).unwrap_err()),
            "visibility"
        );
    }
    f.file("/proc/mounts", "proc /proc proc rw,hidepid=0 0 0");
    for rows in [
        "999 php8.5",
        "999 php-fpm8.5",
        "999 php",
        "unreadable",
        "+1 systemd",
        "0 systemd",
    ] {
        let mut host = Fake {
            process_list: rows.into(),
            ..Fake::default()
        };
        assert_eq!(
            stage(hold(&f.files, &mut host, &f.input()).unwrap_err()),
            "processes"
        );
    }
    let mut host = Fake {
        process_list: "900 php8.5\n1 systemd\n".repeat(5000),
        ..Fake::default()
    };
    assert_eq!(
        stage(hold(&f.files, &mut host, &f.input()).unwrap_err()),
        "inventory"
    );
}

#[test]
fn declarations_are_exact_bounded_identities_and_do_not_hide_any_other_writer() {
    for included in [false, true] {
        let f = Fixture::new();
        let mut host = Fake::default();
        let mut m = f.default_manifest();
        let declaration = f.declaration();
        host.units.push("orbit-process-7-ordinary.service".into());
        if included {
            m["units"] = json!(host.units);
        }
        m["unrelated_processes"] = json!([declaration]);
        f.manifest(m.clone());
        assert_eq!(
            hold(&f.files, &mut host, &f.input())
                .unwrap()
                .unrelated_processes
                .len(),
            1
        );
        for wrong in [
            Value::Null,
            json!("*"),
            json!({"process_id": 7}),
            json!(["*"]),
            json!([declaration, declaration]),
        ] {
            m["unrelated_processes"] = wrong;
            f.manifest(m.clone());
            assert!(hold(&f.files, &mut host, &f.input()).is_err());
        }
        for field in [
            "source_sha",
            "target_sha",
            "unit",
            "extra",
            "runtime_sha256",
            "process_id",
        ] {
            let mut wrong = declaration.clone();
            wrong[field] = json!("invalid");
            m["unrelated_processes"] = json!([wrong]);
            f.manifest(m.clone());
            assert!(hold(&f.files, &mut host, &f.input()).is_err(), "{field}");
        }
        m["unrelated_processes"] = json!([declaration]);
        f.manifest(m);
        host.fault = "list-units".into();
        assert!(hold(&f.files, &mut host, &f.input()).is_err());
        host.fault.clear();
        host.units
            .retain(|u| u != "orbit-process-7-ordinary.service");
        assert!(hold(&f.files, &mut host, &f.input()).is_err());
    }
}
