use super::*;
struct Fixture {
    root: PathBuf,
}
impl Fixture {
    fn new() -> Self {
        let root =
            std::env::temp_dir().join(format!("orbit-image-clean-{:016x}", rand::random::<u64>()));
        fs::create_dir_all(&root).unwrap();
        Self { root }
    }
    fn file(&self, path: &str) {
        let path = at(&self.root, path);
        fs::create_dir_all(path.parent().unwrap()).unwrap();
        fs::write(path, b"old identity\n").unwrap();
    }
    fn host(&self) -> Fake {
        Fake {
            root: self.root.clone(),
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
    root: PathBuf,
    calls: Vec<(String, Vec<String>)>,
    cloud: bool,
    keep_identity: bool,
    reintroduce_incus: bool,
    fail: Option<usize>,
}
impl Host for Fake {
    fn cloud_init(&self) -> bool {
        self.cloud
    }
    fn run(&mut self, program: &'static str, args: &[&str]) -> Output {
        self.calls
            .push((program.into(), args.iter().map(|s| (*s).into()).collect()));
        if self.fail == Some(self.calls.len()) {
            return Output {
                code: 1,
                stdout: String::new(),
                stderr: "failure".into(),
                truncated: false,
            };
        }
        let stdout = if args.first() == Some(&"show") {
            "loaded"
        } else {
            ""
        };
        if program == "/usr/bin/cloud-init" && !self.keep_identity {
            fs::write(at(&self.root, "/etc/machine-id"), "uninitialized\n").unwrap();
            let _ = fs::remove_dir_all(at(&self.root, "/var/lib/cloud"));
            if self.reintroduce_incus {
                fs::create_dir_all(at(&self.root, "/var/lib/incus")).unwrap();
                fs::write(at(&self.root, "/var/lib/incus/server.crt"), "new identity").unwrap();
            }
        }
        Output {
            code: 0,
            stdout: stdout.into(),
            stderr: String::new(),
            truncated: false,
        }
    }
}
#[test]
fn removes_identities_but_keeps_package_lists_and_private_service_tmp_on_repeated_runs() {
    let fixture = Fixture::new();
    for path in IDS.iter().chain(
        [
            "/etc/machine-id",
            "/var/lib/dbus/machine-id",
            "/etc/ssh/ssh_host_ed25519_key",
            "/etc/ssh/ssh_host_ed25519_key.pub",
            "/var/lib/incus/database/global/db.bin",
            "/var/lib/systemd/random-seed",
            "/root/.bash_history",
            "/home/orbit/.bash_history",
            "/root/.ssh/authorized_keys",
            "/home/orbit/.ssh/authorized_keys",
            "/var/log/app.log",
            "/var/log/app.log.1",
            "/var/log/app.log.gz",
            "/tmp/scratch/file",
            "/var/tmp/scratch",
            "/tmp/systemd-private-caddy/data",
            "/var/tmp/systemd-private-other/data",
            "/var/lib/apt/lists/packages",
        ]
        .iter(),
    ) {
        fixture.file(path);
    }
    let mut host = fixture.host();
    for _ in 0..2 {
        assert_eq!(
            clean(&fixture.root, &mut host).unwrap(),
            Cleaned { ready: true }
        );
        for id in IDS {
            assert!(!exists(&at(&fixture.root, id)));
        }
        assert!(entries(&at(&fixture.root, "/var/lib/incus"))
            .unwrap()
            .is_empty());
        assert!(!exists(&at(&fixture.root, "/var/lib/dbus/machine-id")));
        assert!(ssh_keys(&fixture.root).unwrap().is_empty());
        for path in [
            "/tmp/scratch",
            "/var/tmp/scratch",
            "/var/log/app.log.1",
            "/var/log/app.log.gz",
            "/home/orbit/.bash_history",
            "/root/.bash_history",
        ] {
            assert!(!exists(&at(&fixture.root, path)), "{path}");
        }
        for path in [
            "/tmp/systemd-private-caddy/data",
            "/var/tmp/systemd-private-other/data",
            "/var/lib/apt/lists/packages",
        ] {
            assert_eq!(
                fs::read(at(&fixture.root, path)).unwrap(),
                b"old identity\n"
            );
        }
        for path in [
            "/var/log/app.log",
            "/root/.ssh/authorized_keys",
            "/home/orbit/.ssh/authorized_keys",
        ] {
            assert_eq!(fs::read(at(&fixture.root, path)).unwrap(), b"");
        }
    }
    assert!(host.calls.contains(&(
        "/usr/bin/systemctl".into(),
        std::iter::once("stop")
            .chain(SERVICES.iter().copied())
            .map(str::to_owned)
            .collect()
    )));
}
#[test]
fn refuses_an_enrolled_machine_before_stopping_services_or_removing_identity() {
    for marker in ["/etc/orbit/agent/secret", "/etc/wireguard/orbit.conf"] {
        let fixture = Fixture::new();
        fixture.file(marker);
        fixture.file("/etc/machine-id");
        let mut host = fixture.host();
        assert_eq!(clean(&fixture.root, &mut host), Err(Error::Enrolled {}));
        assert!(host.calls.is_empty());
        assert_eq!(
            fs::read(at(&fixture.root, "/etc/machine-id")).unwrap(),
            b"old identity\n"
        );
    }
}
#[test]
fn clears_cloud_init_state_and_refuses_incomplete_cleanup() {
    for keeps in [false, true] {
        let fixture = Fixture::new();
        fixture.file("/etc/machine-id");
        fixture.file("/var/lib/cloud/instance/old");
        let mut host = fixture.host();
        host.cloud = true;
        host.keep_identity = keeps;
        let result = clean(&fixture.root, &mut host);
        if keeps {
            assert!(matches!(result,Err(Error::Failed {stage,..}) if stage=="verify"));
        } else {
            assert!(result.is_ok());
            assert!(!exists(&at(&fixture.root, "/var/lib/cloud/instance")));
        }
        assert!(host.calls.contains(&(
            "/usr/bin/cloud-init".into(),
            [
                "clean",
                "--logs",
                "--seed",
                "--machine-id",
                "--configs",
                "all"
            ]
            .iter()
            .map(|s| (*s).into())
            .collect()
        )));
    }
}
#[test]
fn a_failed_service_probe_or_stop_preserves_files_and_later_tool_failures_stop_cleanup() {
    for fail in 1..=13 {
        let fixture = Fixture::new();
        fixture.file("/etc/machine-id");
        fixture.file("/etc/ssh/ssh_host_ed25519_key");
        let mut host = fixture.host();
        host.cloud = true;
        host.fail = Some(fail);
        assert!(
            matches!(clean(&fixture.root, &mut host), Err(Error::Failed { .. })),
            "call {fail}"
        );
        assert_eq!(host.calls.len(), fail);
        if fail <= 9 {
            assert!(exists(&at(&fixture.root, "/etc/ssh/ssh_host_ed25519_key")));
        }
    }
}
#[test]
fn log_walk_does_not_follow_symlinks_outside_the_log_tree() {
    let fixture = Fixture::new();
    fixture.file("/etc/machine-id");
    fixture.file("/keep/private");
    fs::create_dir_all(at(&fixture.root, "/var/log")).unwrap();
    std::os::unix::fs::symlink(
        at(&fixture.root, "/keep"),
        at(&fixture.root, "/var/log/linked"),
    )
    .unwrap();
    std::os::unix::fs::symlink(
        at(&fixture.root, "/keep/private"),
        at(&fixture.root, "/var/log/linked.log"),
    )
    .unwrap();
    clean(&fixture.root, &mut fixture.host()).unwrap();
    assert_eq!(
        fs::read(at(&fixture.root, "/keep/private")).unwrap(),
        b"old identity\n"
    );
}

#[test]
fn refuses_to_confirm_when_incus_identity_reappears_after_services_stop() {
    let fixture = Fixture::new();
    fixture.file("/etc/machine-id");
    let mut host = fixture.host();
    host.cloud = true;
    host.reintroduce_incus = true;
    assert!(
        matches!(clean(&fixture.root, &mut host), Err(Error::Failed { stage, .. }) if stage == "verify")
    );
}

#[test]
fn prepares_cloud_init_order_atomically_and_repeats() {
    use std::os::unix::fs::PermissionsExt;
    let fixture = Fixture::new();
    for _ in 0..2 {
        assert!(prepare_incus(&fixture.root).unwrap().ready);
        let path = at(&fixture.root, INCUS_DROP_IN);
        assert_eq!(fs::read(&path).unwrap(), INCUS_ORDER);
        assert_eq!(
            fs::metadata(&path).unwrap().permissions().mode() & 0o777,
            0o644
        );
        assert_eq!(entries(path.parent().unwrap()).unwrap().len(), 1);
    }
    let blocked = Fixture::new();
    blocked.file("/etc/systemd/system/cloud-init-main.service.d");
    assert!(
        matches!(prepare_incus(&blocked.root),Err(Error::Failed { stage, .. }) if stage=="cloud-init drop-in")
    );
}
