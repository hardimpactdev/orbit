use super::issuer::Pair;
use super::*;
use recipe::{Failure, Output as HostOutput};
use std::{cell::Cell, path::PathBuf};

pub(super) struct Sandbox(pub(super) Files);
impl Sandbox {
    pub(super) fn new() -> Self {
        let root =
            std::env::temp_dir().join(format!("orbit-node-worker-{:016x}", rand::random::<u64>()));
        for dir in [
            "etc/orbit/agent",
            "etc/systemd/system",
            "usr/local/share",
            "var/lib",
            "usr/local/bin",
        ] {
            fs::create_dir_all(root.join(dir)).unwrap();
        }
        let files = Files {
            root,
            uid: rustix::process::geteuid().as_raw(),
            gid: rustix::process::getegid().as_raw(),
        };
        files
            .publish_root(&files.path(crate::CA_PATH), b"public-ca")
            .unwrap();
        Self(files)
    }
    fn account(&self) -> Account {
        Account {
            name: USER.into(),
            uid: self.0.uid,
            gid: self.0.gid,
            home: PathBuf::from(format!("{ROOT}/home")),
        }
    }
    fn configured(&self) {
        configure(&self.0, &mut Fake::new("inactive"), &self.account(), false).unwrap();
    }
    fn pair(&self, expires: i64) -> Pair {
        Pair {
            enrollment_token: "orbit-node-71.abcdefghijklmnop".into(),
            server_token: "orbit-node-71-access.abcdefghijklmnop".into(),
            expires_at: time::OffsetDateTime::from_unix_timestamp(expires)
                .unwrap()
                .format(&time::format_description::well_known::Rfc3339)
                .unwrap(),
        }
    }
    fn save(&self, pair: &Pair) {
        self.0
            .publish(
                &self.0.path(&format!("{ROOT}/credentials/pair.json")),
                &serde_json::to_vec(pair).unwrap(),
                0o600,
                self.0.uid,
                self.0.gid,
                false,
            )
            .unwrap();
    }
}
impl Drop for Sandbox {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0.root);
    }
}
struct Fake {
    state: String,
    calls: Vec<Vec<String>>,
    restarts: u64,
}
impl Fake {
    fn new(state: &str) -> Self {
        Self {
            state: state.into(),
            calls: vec![],
            restarts: 0,
        }
    }
}
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> Result<HostOutput, Failure> {
        self.calls.push(call.argv.clone());
        Ok(HostOutput {
            stdout: if call.argv.iter().any(|a| a == "--property=NRestarts") {
                self.restarts.to_string()
            } else if call.argv.get(1).is_some_and(|s| s == "show") || call.argv[0] == "uname" {
                self.state.clone()
            } else {
                String::new()
            },
            ..HostOutput::default()
        })
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("no waits");
    }
}
#[test]
fn configure_keeps_private_custody_and_a_non_root_runtime() {
    let sandbox = Sandbox::new();
    let mut host = Fake::new("inactive");
    let first = configure(&sandbox.0, &mut host, &sandbox.account(), false).unwrap();
    assert!(first.installed && first.changed);
    let second = configure(&sandbox.0, &mut host, &sandbox.account(), false).unwrap();
    assert!(!second.changed);
    for path in ["home", "cache", "work", "credentials"] {
        assert_eq!(
            fs::metadata(sandbox.0.path(&format!("{ROOT}/{path}")))
                .unwrap()
                .mode()
                & 0o777,
            0o700
        );
    }
    let unit = fs::read_to_string(sandbox.0.path(&format!("/etc/systemd/system/{UNIT}"))).unwrap();
    for required in [
        "User=orbit-swamp-worker",
        "--concurrency=1",
        "--ca-cert=/usr/local/share/ca-certificates/orbit-root.crt",
        "LoadCredential=enrollment:",
        "SendSIGKILL=no",
        "KillMode=mixed",
        "wss://swamp.orbit",
    ] {
        assert!(unit.contains(required));
    }
    assert!(!unit.contains("User=root"));
    assert_eq!(fs::read(sandbox.0.path(CA)).unwrap(), b"public-ca");
}
#[test]
fn configure_refuses_linked_or_foreign_custody_and_root_accounts() {
    let sandbox = Sandbox::new();
    let mut account = sandbox.account();
    account.uid = 0;
    let mut host = Fake::new("inactive");
    assert!(configure(&sandbox.0, &mut host, &account, false).is_err());
    assert!(host.calls.is_empty());
    fs::create_dir(sandbox.0.path(ROOT)).unwrap();
    std::os::unix::fs::symlink(
        sandbox.0.path("/tmp"),
        sandbox.0.path(&format!("{ROOT}/credentials")),
    )
    .unwrap();
    assert!(configure(&sandbox.0, &mut host, &sandbox.account(), false).is_err());
    assert!(host.calls.is_empty());
}

#[test]
fn unsupported_architecture_refuses_before_download() {
    let sandbox = Sandbox::new();
    let mut host = Fake::new("aarch64");
    assert!(install_binary(&sandbox.0, &mut host).is_err());
    assert_eq!(host.calls, vec![vec!["uname", "-m"]]);
}
#[test]
fn renewal_drains_before_requesting_new_credentials_and_never_kills_steps() {
    let sandbox = Sandbox::new();
    sandbox.configured();
    sandbox.save(&sandbox.pair(8000));
    let mut host = Fake::new("active");
    renewal::rotate(
        &sandbox.0,
        &mut host,
        || 1000,
        || panic!("must drain first"),
    )
    .unwrap();
    assert_eq!(
        host.calls.last().unwrap(),
        &["systemctl", "stop", "--no-block", UNIT]
    );
    let mut draining = Fake::new("deactivating");
    renewal::rotate(
        &sandbox.0,
        &mut draining,
        || 1000,
        || panic!("still draining"),
    )
    .unwrap();
    assert_eq!(draining.calls.len(), 1);
}
#[test]
fn renewal_keeps_fresh_credentials_and_machine_cache_and_recovers_after_restart() {
    let sandbox = Sandbox::new();
    sandbox.configured();
    sandbox.save(&sandbox.pair(15000));
    let cache = sandbox.0.path(&format!("{ROOT}/cache/machine-id"));
    fs::write(&cache, "stable-machine").unwrap();
    let mut host = Fake::new("active");
    renewal::rotate(&sandbox.0, &mut host, || 1000, || panic!("not due")).unwrap();
    assert_eq!(host.calls.len(), 2);
    let mut host = Fake::new("inactive");
    renewal::rotate(
        &sandbox.0,
        &mut host,
        || 1000,
        || panic!("reuse protected pair"),
    )
    .unwrap();
    assert_eq!(host.calls.last().unwrap(), &["systemctl", "start", UNIT]);
    assert_eq!(fs::read_to_string(cache).unwrap(), "stable-machine");
    assert_eq!(
        fs::metadata(sandbox.0.path(&format!("{ROOT}/credentials/server")))
            .unwrap()
            .mode()
            & 0o777,
        0o600
    );
}
#[test]
fn renewal_refuses_wrong_node_or_failed_courier_without_starting() {
    let sandbox = Sandbox::new();
    sandbox.configured();
    let mut host = Fake::new("inactive");
    assert!(renewal::rotate(&sandbox.0, &mut host, || 1000, || Err(failed())).is_err());
    assert_eq!(host.calls.len(), 1);
    let mut wrong = sandbox.pair(15000);
    wrong.server_token = "orbit-node-72-access.abcdefghijklmnop".into();
    assert!(renewal::rotate(&sandbox.0, &mut host, || 1000, || Ok(wrong)).is_err());
    assert_eq!(host.calls.len(), 2);
    let called = Cell::new(false);
    renewal::rotate(
        &sandbox.0,
        &mut host,
        || 1000,
        || {
            called.set(true);
            Ok(sandbox.pair(15000))
        },
    )
    .unwrap();
    assert!(called.get());
}
#[test]
fn repeated_auto_restarts_drain_and_remint_before_the_two_hour_window() {
    let sandbox = Sandbox::new();
    sandbox.configured();
    sandbox.save(&sandbox.pair(15000));
    let mut host = Fake::new("activating");
    host.restarts = 3;
    renewal::rotate(
        &sandbox.0,
        &mut host,
        || 1000,
        || panic!("must drain first"),
    )
    .unwrap();
    assert_eq!(
        host.calls.last().unwrap(),
        &["systemctl", "stop", "--no-block", UNIT]
    );
    assert!(!sandbox
        .0
        .path(&format!("{ROOT}/credentials/pair.json"))
        .exists());
    let mut host = Fake::new("inactive");
    let called = Cell::new(false);
    renewal::rotate(
        &sandbox.0,
        &mut host,
        || 1000,
        || {
            called.set(true);
            Ok(sandbox.pair(15000))
        },
    )
    .unwrap();
    assert!(called.get());
}
#[test]
fn a_failed_worker_remints_even_when_its_pair_is_fresh() {
    let sandbox = Sandbox::new();
    sandbox.configured();
    sandbox.save(&sandbox.pair(15000));
    let called = Cell::new(false);
    let mut host = Fake::new("failed");
    renewal::rotate(
        &sandbox.0,
        &mut host,
        || 1000,
        || {
            called.set(true);
            Ok(sandbox.pair(15000))
        },
    )
    .unwrap();
    assert!(called.get());
    assert_eq!(host.calls.last().unwrap(), &["systemctl", "start", UNIT]);
}
#[test]
fn pair_validation_closes_identity_lifetime_and_secret_shape() {
    let sandbox = Sandbox::new();
    let pair = sandbox.pair(15000);
    assert!(pair.validate(1000).is_ok());
    assert!(pair.validate(15000).is_err());
    assert!(pair.validate(0).is_err());
    let mut pair = sandbox.pair(15000);
    pair.server_token.push('\n');
    assert!(pair.validate(1000).is_err());
}

#[test]
fn renewal_validates_against_the_time_after_a_slow_courier() {
    let sandbox = Sandbox::new();
    sandbox.configured();
    let clock = Cell::new(1000);
    let mut host = Fake::new("inactive");
    renewal::rotate(
        &sandbox.0,
        &mut host,
        || clock.get(),
        || {
            clock.set(1010);
            Ok(sandbox.pair(1010 + 14400))
        },
    )
    .unwrap();
    assert_eq!(host.calls.last().unwrap(), &["systemctl", "start", UNIT]);
}

#[test]
fn removal_stops_the_timer_and_drains_before_erasing_credentials_and_repeats() {
    let sandbox = Sandbox::new();
    sandbox.configured();
    sandbox.save(&sandbox.pair(15000));
    let mut host = Fake::new("inactive");
    remove(&sandbox.0, &mut host).unwrap();
    assert_eq!(
        host.calls[0],
        &[
            "systemctl",
            "disable",
            "--now",
            "orbit-node-worker-renew.timer"
        ]
    );
    assert_eq!(host.calls[2], &["systemctl", "stop", UNIT]);
    assert!(!sandbox
        .0
        .path(&format!("{ROOT}/credentials/pair.json"))
        .exists());
    remove(&sandbox.0, &mut host).unwrap();
    assert!(sandbox.0.path(&format!("{ROOT}/cache")).is_dir());
}
