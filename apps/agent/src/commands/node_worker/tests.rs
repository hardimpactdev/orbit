use super::{issuer::Pair, lifecycle::converge, *};
use recipe::{Failure, Output as HostOutput};
use std::path::PathBuf;

pub(super) struct Sandbox(pub(super) Files);
impl Sandbox {
    pub(super) fn new() -> Self {
        let root =
            std::env::temp_dir().join(format!("orbit-node-worker-{:016x}", rand::random::<u64>()));
        for dir in [
            "etc/orbit/agent",
            "etc/systemd/system",
            "usr/local/share",
            "usr/local/bin",
            "var/lib",
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
    fn start(&self) {
        self.configured();
        converge(
            &self.0,
            &mut Fake::new("inactive"),
            Some(self.pair(87400)),
            1000,
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
    state_after_stop: Option<String>,
    restarts: u64,
    calls: Vec<Vec<String>>,
}
impl Fake {
    fn new(state: &str) -> Self {
        Self {
            state: state.into(),
            state_after_stop: None,
            restarts: 0,
            calls: vec![],
        }
    }
}
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> Result<HostOutput, Failure> {
        self.calls.push(call.argv.clone());
        if call.argv.iter().any(|arg| arg == "--no-block") {
            if let Some(state) = self.state_after_stop.take() {
                self.state = state;
            }
        }
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
        Ok(())
    }
}
#[test]
fn configure_keeps_private_custody_and_installs_only_the_non_root_worker() {
    let s = Sandbox::new();
    let mut host = Fake::new("inactive");
    assert!(
        configure(&s.0, &mut host, &s.account(), false)
            .unwrap()
            .changed
    );
    assert!(
        !configure(&s.0, &mut host, &s.account(), false)
            .unwrap()
            .changed
    );
    for dir in ["home", "cache", "work", "credentials"] {
        assert_eq!(
            fs::metadata(s.0.path(&format!("{ROOT}/{dir}")))
                .unwrap()
                .mode()
                & 0o777,
            0o700
        );
    }
    let unit = fs::read_to_string(s.0.path(&format!("/etc/systemd/system/{UNIT}"))).unwrap();
    for required in [
        "User=orbit-swamp-worker",
        "ExitType=cgroup",
        "--concurrency=1",
        "--ca-cert=/usr/local/share/ca-certificates/orbit-root.crt",
        "LoadCredential=enrollment:",
        "SendSIGKILL=no",
        "wss://swamp.orbit",
    ] {
        assert!(unit.contains(required));
    }
    assert_eq!(fs::read(s.0.path(CA)).unwrap(), b"public-ca");
    assert!(!s
        .0
        .path("/etc/systemd/system/orbit-node-worker-renew.timer")
        .exists());
}
#[test]
fn configure_refuses_root_linked_custody_and_unsupported_architecture() {
    let s = Sandbox::new();
    let mut host = Fake::new("inactive");
    let mut account = s.account();
    account.uid = 0;
    assert!(configure(&s.0, &mut host, &account, false).is_err());
    assert!(host.calls.is_empty());
    fs::create_dir(s.0.path(ROOT)).unwrap();
    std::os::unix::fs::symlink(s.0.path("/tmp"), s.0.path(&format!("{ROOT}/credentials"))).unwrap();
    assert!(configure(&s.0, &mut host, &s.account(), false).is_err());
    let mut host = Fake::new("aarch64");
    assert!(install_binary(&s.0, &mut host).is_err());
    assert_eq!(host.calls, vec![vec!["uname", "-m"]]);
}
#[test]
fn due_worker_disables_boot_and_drains_before_accepting_credentials() {
    let s = Sandbox::new();
    s.start();
    let mut host = Fake::new("active");
    let state = converge(&s.0, &mut host, None, 73000).unwrap();
    assert!(!state.ready && !state.drained);
    assert!(host
        .calls
        .iter()
        .any(|call| call == &["systemctl", "disable", "--now", "--no-block", UNIT]));
    assert!(converge(&s.0, &mut host, Some(s.pair(87400)), 1000).is_err());
    let state = converge(&s.0, &mut Fake::new("deactivating"), None, 73000).unwrap();
    assert!(!state.ready && !state.drained);
    let mut host = Fake::new("inactive");
    let state = converge(&s.0, &mut host, None, 73000).unwrap();
    assert!(!state.ready && state.drained);
    assert_eq!(host.calls.last().unwrap(), &["systemctl", "disable", UNIT]);
}
#[test]
fn idle_due_worker_can_rotate_in_the_same_gateway_pass() {
    let s = Sandbox::new();
    s.start();
    let mut host = Fake::new("active");
    host.state_after_stop = Some("inactive".into());
    let state = converge(&s.0, &mut host, None, 73000).unwrap();
    assert!(!state.ready && state.drained);
    assert!(!s.0.path(&format!("{ROOT}/credentials/expiry")).exists());
    assert!(
        converge(&s.0, &mut host, Some(s.pair(159400)), 73000)
            .unwrap()
            .ready
    );
}
#[test]
fn fresh_worker_reuses_credentials_and_persistent_identity_after_restart() {
    let s = Sandbox::new();
    s.start();
    let cache = s.0.path(&format!("{ROOT}/cache/machine-id"));
    fs::write(&cache, "same-machine").unwrap();
    let mut host = Fake::new("active");
    let state = converge(&s.0, &mut host, None, 1000).unwrap();
    assert!(state.ready && !state.drained);
    assert_eq!(host.calls.len(), 2);
    let mut host = Fake::new("inactive");
    assert!(converge(&s.0, &mut host, None, 1000).unwrap().ready);
    assert_eq!(
        host.calls.last().unwrap(),
        &["systemctl", "enable", "--now", UNIT]
    );
    assert_eq!(fs::read_to_string(cache).unwrap(), "same-machine");
    for name in ["enrollment", "server", "expiry"] {
        assert_eq!(
            fs::metadata(s.0.path(&format!("{ROOT}/credentials/{name}")))
                .unwrap()
                .mode()
                & 0o777,
            0o600
        );
    }
}
#[test]
fn failed_or_repeatedly_restarting_worker_requests_a_new_pair() {
    let s = Sandbox::new();
    s.start();
    assert!(
        converge(&s.0, &mut Fake::new("failed"), None, 1000)
            .unwrap()
            .drained
    );
    s.start();
    let mut host = Fake::new("activating");
    host.restarts = 3;
    assert!(!converge(&s.0, &mut host, None, 1000).unwrap().ready);
    assert!(!s.0.path(&format!("{ROOT}/credentials/expiry")).exists());
    assert!(
        converge(&s.0, &mut Fake::new("inactive"), None, 1000)
            .unwrap()
            .drained
    );
}
#[test]
fn invalid_pairs_or_custody_never_start_and_partial_publication_cannot_reuse_old_expiry() {
    let s = Sandbox::new();
    s.start();
    let mut pair = s.pair(87400);
    pair.server_token = "orbit-node-72-access.abcdefghijklmnop".into();
    let mut host = Fake::new("inactive");
    assert!(converge(&s.0, &mut host, Some(pair), 1000).is_err());
    assert_eq!(host.calls.len(), 1);
    assert!(s.pair(1000).validate(1000).is_err());
    assert!(s.pair(100000).validate(1000).is_err());
    fs::remove_file(s.0.path(&format!("{ROOT}/credentials/server"))).unwrap();
    std::os::unix::fs::symlink(
        s.0.path("/tmp"),
        s.0.path(&format!("{ROOT}/credentials/server")),
    )
    .unwrap();
    assert!(converge(&s.0, &mut host, Some(s.pair(87400)), 1000).is_err());
    assert!(!s.0.path(&format!("{ROOT}/credentials/expiry")).exists());
}
#[test]
fn removal_disables_and_drains_before_erasing_credentials_but_preserves_cache() {
    let s = Sandbox::new();
    s.start();
    let mut host = Fake::new("inactive");
    remove(&s.0, &mut host).unwrap();
    assert_eq!(host.calls[0], &["systemctl", "disable", "--now", UNIT]);
    assert!(!s.0.path(&format!("{ROOT}/credentials/expiry")).exists());
    remove(&s.0, &mut host).unwrap();
    assert!(s.0.path(&format!("{ROOT}/cache")).is_dir());
}
