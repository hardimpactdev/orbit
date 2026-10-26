use super::super::tests::{stage, Fake, Fixture};
use super::*;
use std::os::unix::fs::{symlink, PermissionsExt};

struct ProcessFixture {
    base: Fixture,
    input: ProcessInput,
    properties: BTreeMap<String, String>,
}
impl ProcessFixture {
    fn new() -> Self {
        let base = Fixture::new();
        let directory = base.work.0.join("ordinary-é");
        fs::create_dir(&directory).unwrap();
        let user = identity::user_name(rustix::process::geteuid().as_raw()).unwrap();
        let input = ProcessInput {
            checkout: base.checkout.clone(),
            orbit_home: base.work.0.join(".orbit"),
            target_sha: "b".repeat(40),
            process_id: 7,
            unit: "orbit-process-7-ordinary.service".into(),
            user,
            directory,
            command: vec!["/usr/bin/sleep".into(), "infinity".into()],
            rendered:
                "[Unit]\nX-Orbit-Process-ID=7\n[Service]\nExecStart=/usr/bin/sleep infinity\n"
                    .into(),
        };
        base.file(
            "/etc/systemd/system/orbit-process-7-ordinary.service",
            &input.rendered,
        );
        let group = "/system.slice/orbit-process-7-ordinary.service";
        base.file(&format!("/sys/fs/cgroup{group}/cgroup.procs"), "3945\n");
        base.file(
            &format!("/sys/fs/cgroup{group}/cgroup.events"),
            "populated 1\nfrozen 0\n",
        );
        base.file(
            &format!("/sys/fs/cgroup{group}/cgroup.stat"),
            "nr_descendants 0\n",
        );
        let mut fields = vec!["0"; 20];
        fields[0] = "S";
        fields[19] = "12345";
        base.file(
            "/proc/3945/stat",
            format!("3945 (sleep) {}", fields.join(" ")),
        );
        base.file("/proc/3945/cmdline", b"/usr/bin/sleep\0infinity\0");
        base.file("/proc/3945/environ", b"PASSWORD=private-sentinel\0");
        symlink("/usr/bin/sleep", base.files.path("/proc/3945/exe")).unwrap();
        symlink(&input.directory, base.files.path("/proc/3945/cwd")).unwrap();
        let properties = [
            ("Id", input.unit.clone()),
            ("LoadState", "loaded".into()),
            ("ActiveState", "active".into()),
            ("SubState", "running".into()),
            ("MainPID", "3945".into()),
            ("ControlGroup", group.into()),
            ("InvocationID", "a".repeat(32)),
            ("User", input.user.clone()),
            ("WorkingDirectory", input.directory.display().to_string()),
            (
                "ExecStart",
                "{ path=/usr/bin/sleep ; argv[]=/usr/bin/sleep infinity ; pid=3945 ; }".into(),
            ),
            (
                "FragmentPath",
                "/etc/systemd/system/orbit-process-7-ordinary.service".into(),
            ),
            ("DropInPaths", "".into()),
            ("NeedDaemonReload", "no".into()),
            ("Transient", "no".into()),
        ]
        .into_iter()
        .map(|(key, value)| (key.into(), value))
        .collect();
        Self {
            base,
            input,
            properties,
        }
    }
    fn host(&self) -> Fake {
        let mut host = Fake::default();
        host.properties.insert(
            self.input.unit.clone(),
            self.properties
                .iter()
                .map(|(k, v)| format!("{k}={v}\n"))
                .collect(),
        );
        host
    }
    fn inspect(&self, host: &mut Fake) -> Result<ProcessIdentity, Error> {
        inspect(&self.base.files, host, &self.input)
    }
}

#[test]
fn exact_process_identity_repeats_without_changes_and_never_returns_secrets() {
    let f = ProcessFixture::new();
    let mut host = f.host();
    let before = fs::read(
        f.base
            .files
            .path("/etc/systemd/system/orbit-process-7-ordinary.service"),
    )
    .unwrap();
    let identity = f.inspect(&mut host).unwrap();
    assert_eq!(identity, f.inspect(&mut host).unwrap());
    assert_eq!(identity.source_sha, "a".repeat(40));
    assert_eq!(identity.unit_sha256, sha256(&f.input.rendered));
    let output = serde_json::to_string(&identity).unwrap();
    assert!(!output.contains("private-sentinel") && !output.contains("infinity"));
    assert_eq!(host.calls.len(), 4);
    assert_eq!(
        fs::read(
            f.base
                .files
                .path("/etc/systemd/system/orbit-process-7-ordinary.service")
        )
        .unwrap(),
        before
    );
    f.base
        .file("/proc/3945/environ", "PASSWORD=changed-private-sentinel\0");
    assert_ne!(
        f.inspect(&mut host).unwrap().runtime_sha256,
        identity.runtime_sha256
    );
}

#[test]
fn invalid_identities_and_gateway_consumers_refuse_before_process_observation() {
    for fault in [
        "id",
        "name",
        "target",
        "home",
        "marker",
        "empty",
        "nul",
        "directory",
        "argument",
        "php",
        "schedule",
        "missing",
    ] {
        let mut f = ProcessFixture::new();
        match fault {
            "id" => f.input.process_id = 0,
            "name" => f.input.unit = "sshd.service".into(),
            "target" => f.input.target_sha = "bad".into(),
            "home" => f.input.orbit_home = "relative".into(),
            "marker" => f.input.rendered = "ExecStart=/usr/bin/sleep infinity".into(),
            "empty" => f.input.command.clear(),
            "nul" => f.input.command.push("x\0y".into()),
            "directory" => f.input.directory = f.base.work.0.join("releases/aaaaaaaaaaaa"),
            "argument" => f.input.command.push(
                f.base
                    .work
                    .0
                    .join("releases/aaaaaaaaaaaa/REVISION")
                    .display()
                    .to_string(),
            ),
            "php" => f.input.command[0] = "/usr/bin/php8.5".into(),
            "schedule" => f.input.command.push("schedule:work".into()),
            "missing" => f.input.directory = f.base.work.0.join("absent"),
            _ => unreachable!(),
        }
        let mut host = f.host();
        assert!(f.inspect(&mut host).is_err(), "{fault}");
        assert!(host.calls.is_empty(), "{fault}");
    }
}

#[test]
fn unit_and_runtime_overrides_or_incomplete_observations_refuse() {
    for (key, value) in [
        ("Id", "foreign.service"),
        ("LoadState", "masked"),
        ("ActiveState", "inactive"),
        ("SubState", "exited"),
        ("MainPID", "0"),
        ("MainPID", "+3945"),
        ("InvocationID", "invalid"),
        ("ControlGroup", "/other"),
        ("User", "root"),
        ("WorkingDirectory", "/tmp"),
        ("ExecStart", ""),
        ("DropInPaths", "/override.conf"),
        ("NeedDaemonReload", "yes"),
        ("Transient", "yes"),
        ("FragmentPath", "/tmp/unit"),
    ] {
        let mut f = ProcessFixture::new();
        f.properties.insert(key.into(), value.into());
        assert!(f.inspect(&mut f.host()).is_err(), "{key}={value}");
    }
    let mut f = ProcessFixture::new();
    f.properties.remove("ExecStart");
    assert_eq!(stage(f.inspect(&mut f.host()).unwrap_err()), "properties");
    let f = ProcessFixture::new();
    for fault in ["failed", "truncated", "oversized"] {
        let mut host = f.host();
        host.fault = fault.into();
        assert!(f.inspect(&mut host).is_err());
    }
    let mut host = f.host();
    host.after_first_show =
        Some(host.properties[&f.input.unit].replace(&"a".repeat(32), &"b".repeat(32)));
    assert_eq!(stage(f.inspect(&mut host).unwrap_err()), "changed");
}

#[test]
fn unsafe_unit_files_descendants_and_mismatched_live_process_identity_refuse() {
    for fault in [
        "unit-bytes",
        "unit-mode",
        "unit-link",
        "unit-owner",
        "members",
        "events",
        "descendants",
        "argv",
        "stat",
        "exe",
        "cwd",
        "environment-size",
        "root",
    ] {
        let mut f = ProcessFixture::new();
        let unit = f
            .base
            .files
            .path("/etc/systemd/system/orbit-process-7-ordinary.service");
        match fault {
            "unit-bytes" => {
                fs::write(&unit, "X-Orbit-Process-ID=7\nExecStop=/usr/bin/false").unwrap()
            }
            "unit-mode" => fs::set_permissions(&unit, fs::Permissions::from_mode(0o666)).unwrap(),
            "unit-link" => {
                fs::remove_file(&unit).unwrap();
                symlink(f.base.files.path("/proc/3945/stat"), &unit).unwrap();
            }
            "unit-owner" => f.base.files.uid += 1,
            "members" => f.base.file(
                "/sys/fs/cgroup/system.slice/orbit-process-7-ordinary.service/cgroup.procs",
                "3945\n3946\n",
            ),
            "events" => f.base.file(
                "/sys/fs/cgroup/system.slice/orbit-process-7-ordinary.service/cgroup.events",
                "populated 0\n",
            ),
            "descendants" => f.base.file(
                "/sys/fs/cgroup/system.slice/orbit-process-7-ordinary.service/cgroup.stat",
                "nr_descendants 1\n",
            ),
            "argv" => f.base.file("/proc/3945/cmdline", b"changed-argv\0"),
            "stat" => f.base.file("/proc/3945/stat", "unreadable"),
            "exe" => {
                let path = f.base.files.path("/proc/3945/exe");
                fs::remove_file(&path).unwrap();
                symlink("/usr/bin/true", path).unwrap();
            }
            "cwd" => {
                let path = f.base.files.path("/proc/3945/cwd");
                fs::remove_file(&path).unwrap();
                symlink(&f.base.work.0, path).unwrap();
            }
            "environment-size" => f
                .base
                .file("/proc/3945/environ", vec![b'x'; 1024 * 1024 + 1]),
            "root" => {
                f.input.user = "root".into();
                f.properties.insert("User".into(), "root".into());
            }
            _ => unreachable!(),
        }
        assert!(f.inspect(&mut f.host()).is_err(), "{fault}");
    }
}

#[test]
fn runtime_hash_keeps_the_operator_declaration_json_encoding() {
    let properties = BTreeMap::from([
        ("a".into(), "/tmp/é/😀".into()),
        ("z".into(), "\"\\\n".into()),
    ]);
    // sha256(json_encode([$properties, '123', 'binary', 'environment'], JSON_UNESCAPED_SLASHES)).
    assert_eq!(
        runtime_hash(&properties, "123", "binary", "environment").unwrap(),
        "5bfb23441b31c05e26bc29f1a2ed47bf814b43ce1687e1da183ef413a87bd7a4"
    );
}
