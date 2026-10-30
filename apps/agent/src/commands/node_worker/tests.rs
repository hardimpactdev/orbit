use super::super::recipe::{Failure, Output, WorkDir};
use super::*;
use std::os::unix::fs::symlink;
use std::time::Duration;
#[derive(Default)]
struct Fake {
    calls: Vec<Vec<String>>,
    refuse_sudo: bool,
}
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
        self.calls.push(call.argv.clone());
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
    for dir in ["etc/systemd/system", "etc/orbit", "var/lib"] {
        fs::create_dir_all(root.join(dir)).unwrap();
    }
    let uid = rustix::process::getuid().as_raw();
    let gid = rustix::process::getgid().as_raw();
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
fn converges_a_persistent_node_user_unit_with_sudo_and_stable_identity() {
    let (_work, files, input, account, mut host) = fixture();
    publish(&files, &mut host, &input, &account, false).unwrap();
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
    publish(&files, &mut host, &input, &account, false).unwrap();
    assert_eq!(host.calls.last().unwrap(), &["systemctl", "start", UNIT]);
    assert!(!host.calls.iter().any(|c| c.iter().any(|v| v == "restart")));
    host.calls.clear();
    publish(&files, &mut host, &input, &account, true).unwrap();
    assert_eq!(host.calls.last().unwrap(), &["systemctl", "restart", UNIT]);
}
#[test]
fn refuses_unprivileged_or_mismatched_accounts_before_publishing() {
    let (_work, files, mut input, account, mut host) = fixture();
    host.refuse_sudo = true;
    assert!(publish(&files, &mut host, &input, &account, false).is_err());
    assert!(!files.path(UNIT_PATH).exists());
    host.calls.clear();
    input.user = "orbit".into();
    assert!(publish(&files, &mut host, &input, &account, false).is_err());
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
        fs::create_dir_all(files.path(CREDENTIALS)).unwrap();
        let token = files.path(&format!("{CREDENTIALS}/enrollment.token"));
        if linked {
            symlink("/missing", &token).unwrap();
        } else {
            fs::write(&token, "private-secret").unwrap();
            fs::set_permissions(&token, fs::Permissions::from_mode(0o644)).unwrap();
        }
        assert!(publish(&files, &mut host, &input, &account, false).is_err());
        assert!(!files.path(UNIT_PATH).exists());
        if !linked {
            assert_eq!(fs::read_to_string(token).unwrap(), "private-secret");
        }
    }
}
