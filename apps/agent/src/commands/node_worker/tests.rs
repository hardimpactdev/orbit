use super::super::recipe::{Failure, Output, WorkDir};
use super::*;
use std::os::unix::fs::symlink;
use std::time::Duration;
#[derive(Default)]
struct Fake {
    calls: Vec<Vec<String>>,
    refuse_sudo: bool,
    needs_reset: bool,
}
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
        self.calls.push(call.argv.clone());
        if call.argv == ["systemctl", "reset-failed", UNIT] {
            self.needs_reset = false;
        }
        if self.needs_reset
            && call.argv.first().is_some_and(|a| a == "systemctl")
            && call
                .argv
                .get(1)
                .is_some_and(|a| a == "start" || a == "restart")
        {
            return Ok(Output {
                code: 1,
                ..Output::default()
            });
        }
        Ok(Output {
            code: if self.refuse_sudo && call.argv[0] == "/usr/sbin/runuser" {
                1
            } else {
                0
            },
            ..Output::default()
        })
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("no polling")
    }
}
fn fixture() -> (WorkDir, Files, NodeWorkerConfig, Account, Fake) {
    let work = WorkDir::new().unwrap();
    let root = fs::canonicalize(&work.0).unwrap();
    for dir in ["etc/systemd/system", "etc/orbit", "var/lib", "proc"] {
        fs::create_dir_all(root.join(dir)).unwrap();
    }
    fs::write(root.join("proc/meminfo"), "MemAvailable: 1048576 kB\n").unwrap();
    let uid = rustix::process::getuid().as_raw();
    let gid = rustix::process::getgid().as_raw();
    fs::create_dir_all(root.join("etc/orbit/swamp-worker")).unwrap();
    fs::set_permissions(
        root.join("etc/orbit/swamp-worker"),
        fs::Permissions::from_mode(0o700),
    )
    .unwrap();
    for name in ["enrollment.token", "server.token"] {
        let path = root.join("etc/orbit/swamp-worker").join(name);
        fs::write(&path, "private-secret").unwrap();
        fs::set_permissions(path, fs::Permissions::from_mode(0o600)).unwrap();
    }
    (
        work,
        Files {
            root: root.clone(),
            uid,
            gid,
        },
        NodeWorkerConfig {
            node_id: 9,
            user: "nckrtl".into(),
        },
        Account {
            name: "nckrtl".into(),
            uid,
            gid,
            home: root.join("home/nckrtl"),
        },
        Fake::default(),
    )
}
#[test]
fn refuses_low_or_unknown_memory_before_worker_files_or_commands() {
    for memory in [
        "MemAvailable: 100000 kB\n",
        "MemFree: 1048576 kB\n",
        "MemAvailable: 1048576 MB\n",
        "MemAvailable: 18446744073709551615 kB\n",
        "MemAvailable: 1048576 kB\nMemAvailable: 1048576 kB\n",
    ] {
        let (_work, files, input, account, mut host) = fixture();
        fs::write(files.path("/proc/meminfo"), memory).unwrap();
        assert!(matches!(
            publish(&files, &mut host, &input, &account, false, None),
            Err(Error::MemoryLow {})
        ));
        assert!(host.calls.is_empty());
        assert!(!files.path(UNIT_PATH).exists());
        assert!(!files.path(ROOT).exists());
        assert_eq!(
            fs::read_to_string(files.path(&format!("{CREDENTIALS}/enrollment.token"))).unwrap(),
            "private-secret"
        );
    }
}
#[test]
fn requires_binary_size_and_headroom_even_without_binary_change() {
    let (_work, files, input, account, mut host) = fixture();
    let minimum_kib = (BINARY_BYTES + MEMORY_HEADROOM).div_ceil(1024);
    fs::write(
        files.path("/proc/meminfo"),
        format!("MemAvailable: {} kB\n", minimum_kib - 1),
    )
    .unwrap();
    assert!(matches!(
        publish(&files, &mut host, &input, &account, false, None),
        Err(Error::MemoryLow {})
    ));
    assert!(host.calls.is_empty());
    fs::write(
        files.path("/proc/meminfo"),
        format!("MemAvailable: {minimum_kib} kB\n"),
    )
    .unwrap();
    publish(&files, &mut host, &input, &account, false, None).unwrap();
    assert!(host
        .calls
        .iter()
        .any(|args| args == &["systemctl", "restart", "--no-block", UNIT]));
}
#[test]
fn converges_a_persistent_node_user_unit_with_sudo_and_stable_identity() {
    let (_work, files, input, account, mut host) = fixture();
    publish(&files, &mut host, &input, &account, false, None).unwrap();
    let text = fs::read_to_string(files.path(UNIT_PATH)).unwrap();
    for required in [
        "User=nckrtl\n",
        "--label node=9",
        "wss://swamp.orbit",
        "--ca-cert=",
        "--cache-dir=/var/lib/orbit-node-worker/cache",
        "LoadCredential=enrollment:/etc/orbit/swamp-worker/enrollment.token",
        "WantedBy=multi-user.target",
        "SendSIGKILL=no",
    ] {
        assert!(text.contains(required), "{required}");
    }
    for forbidden in [
        "NoNewPrivileges",
        "ProtectSystem",
        "ProtectHome",
        "CapabilityBoundingSet",
        "PrivateUsers",
        "--concurrency",
        "orbit-swamp-worker",
        "pool=migration",
    ] {
        assert!(!text.contains(forbidden), "{forbidden}");
    }
    assert_eq!(
        host.calls[0],
        [
            "/usr/sbin/runuser",
            "-u",
            "nckrtl",
            "--",
            "/usr/bin/sudo",
            "-n",
            "/usr/bin/true"
        ]
    );
    assert_eq!(
        fs::metadata(files.path(ROOT)).unwrap().mode() & 0o777,
        0o700
    );
    host.calls.clear();
    publish(&files, &mut host, &input, &account, false, None).unwrap();
    assert_eq!(
        host.calls.last().unwrap(),
        &["systemctl", "start", "--no-block", UNIT]
    );
    assert!(!host.calls.iter().any(|c| c.iter().any(|v| v == "restart")));
    host.calls.clear();
    publish(&files, &mut host, &input, &account, true, None).unwrap();
    assert_eq!(
        host.calls.last().unwrap(),
        &["systemctl", "restart", "--no-block", UNIT]
    );
}
#[test]
fn refuses_unprivileged_or_mismatched_accounts_before_publishing() {
    let (_work, files, mut input, account, mut host) = fixture();
    host.refuse_sudo = true;
    assert!(publish(&files, &mut host, &input, &account, false, None).is_err());
    assert!(!files.path(UNIT_PATH).exists());
    host.calls.clear();
    input.user = "orbit".into();
    assert!(publish(&files, &mut host, &input, &account, false, None).is_err());
    assert!(host.calls.is_empty());
    for user in [
        "root",
        "orbit-swamp-package",
        "x\nUser=root",
        "../orbit",
        "-orbit",
    ] {
        input.user = user.into();
        assert!(!valid(&input));
    }
    input.user = "nckrtl".into();
    input.node_id = 0;
    assert!(!valid(&input));
}
#[test]
fn refuses_linked_or_readable_credential_sources_and_preserves_secrets() {
    for linked in [false, true] {
        let (_work, files, input, account, mut host) = fixture();
        fs::remove_file(files.path(&format!("{CREDENTIALS}/enrollment.token"))).unwrap();
        let token = files.path(&format!("{CREDENTIALS}/enrollment.token"));
        if linked {
            symlink("/missing", &token).unwrap();
        } else {
            fs::write(&token, "private-secret").unwrap();
            fs::set_permissions(&token, fs::Permissions::from_mode(0o644)).unwrap();
        }
        assert!(publish(&files, &mut host, &input, &account, false, None).is_err());
        assert!(!files.path(UNIT_PATH).exists());
        if !linked {
            assert_eq!(fs::read_to_string(token).unwrap(), "private-secret");
        }
    }
}

#[test]
fn missing_credentials_are_not_ready_and_rotation_restarts_without_waiting() {
    let (_work, files, input, account, mut host) = fixture();
    fs::remove_file(files.path(&format!("{CREDENTIALS}/enrollment.token"))).unwrap();
    assert_eq!(
        publish(&files, &mut host, &input, &account, false, None),
        Err(Error::NotReady {})
    );
    assert!(!files.path(UNIT_PATH).exists());
    let credentials = Credentials {
        enrollment_token: "orbit-node-9.abcdefghijklmnop".into(),
        server_token: "orbit-node-9-access.abcdefghijklmnop".into(),
    };
    publish(
        &files,
        &mut host,
        &input,
        &account,
        false,
        Some(&credentials),
    )
    .unwrap();
    assert_eq!(
        host.calls.last().unwrap(),
        &["systemctl", "restart", "--no-block", UNIT]
    );
    assert!(check_credentials(&files).is_ok());
    fs::set_permissions(
        files.path(&format!("{CREDENTIALS}/server.token")),
        fs::Permissions::from_mode(0o644),
    )
    .unwrap();
    assert!(check_credentials(&files).is_err());
}

#[test]
fn clears_a_failed_start_limit_before_nonblocking_start() {
    let (_work, files, input, account, mut host) = fixture();
    publish(&files, &mut host, &input, &account, false, None).unwrap();
    host.calls.clear();
    host.needs_reset = true;
    publish(&files, &mut host, &input, &account, false, None).unwrap();
    assert_eq!(
        &host.calls[host.calls.len() - 2..],
        &[
            vec![
                "systemctl".to_string(),
                "reset-failed".to_string(),
                UNIT.to_string()
            ],
            vec![
                "systemctl".to_string(),
                "start".to_string(),
                "--no-block".to_string(),
                UNIT.to_string()
            ],
        ]
    );
}

#[test]
fn rehomes_only_the_empty_root_owned_legacy_cache() {
    let (_work, mut files, _input, account, _host) = fixture();
    let path = files.path(ROOT);
    fs::create_dir(&path).unwrap();
    fs::set_permissions(&path, fs::Permissions::from_mode(0o755)).unwrap();
    let status = std::process::Command::new("/usr/bin/sudo")
        .args(["-n", "/usr/bin/chown", "0:0"])
        .arg(&path)
        .status()
        .unwrap();
    if !status.success() {
        eprintln!(
            "root-owned fixture needs passwordless sudo; normal ownership cases remain covered"
        );
        return;
    }
    files.uid = 0;
    files.gid = 0;
    private_dir(&files, ROOT, account.uid, account.gid).unwrap();
    let meta = fs::symlink_metadata(&path).unwrap();
    assert_eq!(meta.uid(), account.uid);
    assert_eq!(meta.mode() & 0o777, 0o700);
}

#[test]
fn refuses_nonempty_legacy_cache_and_linked_cache_without_removing_data() {
    for linked in [false, true] {
        let (_work, files, _input, account, _host) = fixture();
        let path = files.path(ROOT);
        let data = files.path("var/lib/existing");
        fs::create_dir(&data).unwrap();
        fs::write(data.join("preserved"), "existing data").unwrap();
        if linked {
            symlink(&data, &path).unwrap();
        } else {
            fs::rename(&data, &path).unwrap();
            fs::set_permissions(&path, fs::Permissions::from_mode(0o755)).unwrap();
        }
        assert!(private_dir(&files, ROOT, account.uid, account.gid).is_err());
        assert_eq!(
            fs::read_to_string(path.join("preserved")).unwrap(),
            "existing data"
        );
    }
}
