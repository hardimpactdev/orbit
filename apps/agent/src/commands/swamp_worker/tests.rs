use super::super::recipe::{Failure, Output as CommandOutput};
use super::*;
use std::os::unix::fs::MetadataExt;
struct Sandbox(std::path::PathBuf);
impl Drop for Sandbox {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}
struct Fake {
    calls: Vec<Invocation>,
    active: bool,
    fail_start: bool,
}
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> Result<CommandOutput, Failure> {
        self.calls.push(call.clone());
        let mut code = 0;
        if call.argv.get(1).is_some_and(|v| v == "is-active") {
            code = if self.active { 0 } else { 3 };
        }
        if call.argv.get(1).is_some_and(|v| v == "start") {
            code = if self.fail_start { 1 } else { 0 };
            self.active = !self.fail_start;
        }
        if call.argv.get(1).is_some_and(|v| v == "stop") {
            self.active = false;
        }
        Ok(CommandOutput {
            code,
            stdout: "sensitive diagnostic".into(),
            stderr: "sensitive diagnostic".into(),
            truncated: false,
        })
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("no waits")
    }
}
fn fixture() -> (Sandbox, Files, Account, Input, Fake) {
    let root = std::env::temp_dir().join(format!("orbit-swamp-{:016x}", rand::random::<u64>()));
    for path in [
        "run/systemd/system",
        "usr/local/share/ca-certificates",
        "usr/local/bin",
        "home/orbit/work",
    ] {
        fs::create_dir_all(root.join(path)).unwrap();
    }
    fs::write(root.join(CA.trim_start_matches('/')), "public-ca").unwrap();
    fs::write(root.join("usr/local/bin/swamp"), "binary").unwrap();
    let uid = rustix::process::geteuid().as_raw();
    let gid = rustix::process::getegid().as_raw();
    let account = Account {
        name: "orbit".into(),
        uid,
        gid,
        home: root.join("home/orbit"),
    };
    let input = Input {
        workspace: root.join("home/orbit/work").display().to_string(),
        orchestrator_url: "wss://swamp.orbit".into(),
        labels: BTreeMap::from([("group".into(), "2074".into())]),
        enrollment_token: Some("group-2074.0123456789abcdef0123456789abcdef".into()),
    };
    (
        Sandbox(root.clone()),
        Files { root, uid, gid },
        account,
        input,
        Fake {
            calls: vec![],
            active: false,
            fail_start: false,
        },
    )
}
#[test]
fn starts_direct_worker_after_loading_credential_and_erases_startup_file() {
    let (_guard, files, account, input, mut host) = fixture();
    let token = input.enrollment_token.as_ref().unwrap();
    assert!(start(&files, &mut host, &account, &input).unwrap().started);
    assert!(!files.path(TOKEN).exists());
    let text = fs::read_to_string(files.path(UNIT_PATH)).unwrap();
    assert!(!text.contains(token));
    assert!(text.contains("--token-file=%d/enrollment"));
    assert!(text.contains("--label \"group=2074\""));
    assert!(text.contains("KillMode=control-group"));
    assert!(text.contains("Restart=no"));
    assert!(text.contains(&format!("--ca-cert={CA}")));
    assert!(!format!("{:?}", host.calls).contains(token));
    assert_eq!(
        fs::metadata(files.path(UNIT_PATH)).unwrap().mode() & 0o777,
        0o644
    );
}
#[test]
fn recovers_started_worker_without_staging_token_again() {
    let (_guard, files, account, mut input, mut host) = fixture();
    start(&files, &mut host, &account, &input).unwrap();
    input.enrollment_token = None;
    assert!(start(&files, &mut host, &account, &input).unwrap().started);
    input.labels.insert("group".into(), "other".into());
    assert!(start(&files, &mut host, &account, &input).is_err());
}
#[test]
fn missing_credential_starts_nothing() {
    let (_guard, files, account, mut input, mut host) = fixture();
    input.enrollment_token = None;
    assert!(!start(&files, &mut host, &account, &input).unwrap().started);
    assert!(!files.path(UNIT_PATH).exists());
}
#[test]
fn failure_stops_service_removes_secret_and_withholds_diagnostics() {
    let (_guard, files, account, input, mut host) = fixture();
    host.fail_start = true;
    let error = start(&files, &mut host, &account, &input).unwrap_err();
    assert!(!files.path(TOKEN).exists());
    assert!(!host.active);
    assert!(host
        .calls
        .iter()
        .any(|c| c.argv == ["systemctl", "stop", UNIT]));
    assert!(!format!("{error:?}").contains("sensitive diagnostic"));
}
#[test]
fn refuses_unsafe_enrollment_before_writing_or_running() {
    for case in 0..5 {
        let (_guard, files, account, mut input, mut host) = fixture();
        match case {
            0 => input.orchestrator_url = "ws://swamp.orbit".into(),
            1 => input.orchestrator_url = "wss://user:secret@swamp.orbit".into(),
            2 => input
                .labels
                .insert("bad\nname".into(), "x".into())
                .map(|_| ())
                .unwrap_or(()),
            3 => input.workspace = "/etc".into(),
            _ => input.enrollment_token = Some("bad".into()),
        };
        assert!(start(&files, &mut host, &account, &input).is_err());
        assert!(host.calls.is_empty());
        assert!(!files.path(TOKEN).exists());
    }
}
#[test]
fn refuses_non_certificate_without_mutating_trust() {
    let (_guard, files, _, _, mut host) = fixture();
    assert!(trust(
        &files,
        &mut host,
        &TrustInput {
            certificate: "not a certificate".into()
        }
    )
    .is_err());
    assert!(host.calls.is_empty());
}
