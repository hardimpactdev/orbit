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
    state: Option<&'static str>,
    fail_start: bool,
    fail_stop: bool,
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
            code = if self.fail_stop { 1 } else { 0 };
            if !self.fail_stop {
                self.active = false;
            }
        }
        Ok(CommandOutput {
            code,
            stdout: if call.argv.get(1).is_some_and(|v| v == "show") {
                self.state.unwrap_or("inactive").into()
            } else {
                "sensitive diagnostic".into()
            },
            stderr: "sensitive diagnostic".into(),
            signal: None,
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
        enrollment_id: "startup-2074".into(),
        workspace: root.join("home/orbit/work").display().to_string(),
        orchestrator_url: "wss://swamp.orbit".into(),
        labels: BTreeMap::from([("group".into(), "2074".into())]),
        enrollment_token: Some("group-2074.0123456789abcdef0123456789abcdef".into()),
        server_token: None,
        server_token_required: false,
    };
    (
        Sandbox(root.clone()),
        Files { root, uid, gid },
        account,
        input,
        Fake {
            calls: vec![],
            active: false,
            state: None,
            fail_start: false,
            fail_stop: false,
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
    assert_eq!(
        text.lines()
            .find(|line| line.starts_with("WorkingDirectory=")),
        Some(format!("WorkingDirectory={}", input.workspace).as_str())
    );
    assert!(text.contains(&format!("Environment=\"HOME={}\"", account.home.display())));
    assert!(text.contains(&format!(
        "\"--cache-dir={}/.cache/swamp-worker\"",
        account.home.display()
    )));
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
    assert!(!start(&files, &mut host, &account, &input).unwrap().started);
}
#[test]
fn fresh_enrollment_replaces_the_running_worker_and_replay_does_not() {
    let (_guard, files, account, mut input, mut host) = fixture();
    start(&files, &mut host, &account, &input).unwrap();
    host.calls.clear();
    input.enrollment_id = "fresh-enrollment".into();
    input.enrollment_token = Some("fresh.0123456789abcdef0123456789abcdef".into());
    assert!(start(&files, &mut host, &account, &input).unwrap().started);
    let stop_index = host
        .calls
        .iter()
        .position(|c| c.argv == ["systemctl", "stop", UNIT])
        .unwrap();
    let start_index = host
        .calls
        .iter()
        .position(|c| c.argv == ["systemctl", "start", UNIT])
        .unwrap();
    assert!(stop_index < start_index);
    assert!(fs::read_to_string(files.path(UNIT_PATH))
        .unwrap()
        .contains("fresh-enrollment"));
    host.calls.clear();
    input.enrollment_token = None;
    assert!(start(&files, &mut host, &account, &input).unwrap().started);
    assert_eq!(host.calls.len(), 1);
}
#[test]
fn stop_ends_the_service_and_removes_the_unit_and_credential_idempotently() {
    let (_guard, files, account, input, mut host) = fixture();
    start(&files, &mut host, &account, &input).unwrap();
    fs::write(files.path(TOKEN), "leftover").unwrap();
    stop(&files, &mut host).unwrap();
    assert!(!host.active);
    assert!(!files.path(UNIT_PATH).exists());
    assert!(!files.path(TOKEN).exists());
    stop(&files, &mut host).unwrap();
}
#[test]
fn stop_refusal_keeps_the_unit_for_retry() {
    let (_guard, files, account, input, mut host) = fixture();
    start(&files, &mut host, &account, &input).unwrap();
    host.fail_stop = true;
    assert!(stop(&files, &mut host).is_err());
    assert!(host.active);
    assert!(files.path(UNIT_PATH).exists());
}
#[test]
fn working_directory_escapes_only_systemd_specifiers() {
    let (_guard, _files, account, mut input, _host) = fixture();
    input.workspace = format!("{}/work %name $literal", account.home.display());
    let text = unit(&input, &account);
    assert_eq!(
        text.lines()
            .find(|line| line.starts_with("WorkingDirectory=")),
        Some(
            format!(
                "WorkingDirectory={}/work %%name $literal",
                account.home.display()
            )
            .as_str()
        )
    );
}
#[cfg(target_os = "linux")]
#[test]
fn generated_unit_passes_systemd_analyze_verify_when_available() {
    use std::process::Command;
    if Command::new("systemd-analyze")
        .arg("--version")
        .output()
        .is_err()
    {
        assert!(
            std::env::var_os("CI").is_none(),
            "systemd-analyze must be installed in Linux CI"
        );
        eprintln!("systemd-analyze is unavailable; unit verification skipped");
        return;
    }
    let (guard, _files, account, input, _host) = fixture();
    let path = guard.0.join(UNIT);
    // Verify the generated service settings with an executable the Linux test host has installed.
    fs::write(
        &path,
        unit(&input, &account).replace("/usr/local/bin/swamp", "/usr/bin/true"),
    )
    .unwrap();
    let output = Command::new("systemd-analyze")
        .args(["verify", "--man=no", "--generators=no"])
        .arg(path)
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
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

#[test]
fn server_authentication_uses_a_second_runtime_credential_and_survives_replay() {
    let (_guard, files, account, mut input, mut host) = fixture();
    input.server_token_required = true;
    input.server_token = Some("group-access.0123456789abcdef0123456789abcdef".into());
    let secret = input.server_token.clone().unwrap();
    assert!(start(&files, &mut host, &account, &input).unwrap().started);
    let text = fs::read_to_string(files.path(UNIT_PATH)).unwrap();
    assert!(text.contains("LoadCredential=server:"));
    assert!(text.contains("--server-token-file=%d/server"));
    assert!(!text.contains(&secret));
    assert!(!format!("{:?}", host.calls).contains(&secret));
    assert!(!files.path(TOKEN).exists());
    assert!(!files.path(SERVER_TOKEN).exists());
    input.enrollment_token = None;
    input.server_token = None;
    assert!(start(&files, &mut host, &account, &input).unwrap().started);
}

#[test]
fn failed_server_authenticated_start_erases_both_startup_credentials() {
    let (_guard, files, account, mut input, mut host) = fixture();
    input.server_token_required = true;
    input.server_token = Some("group-access.0123456789abcdef0123456789abcdef".into());
    host.fail_start = true;
    assert!(start(&files, &mut host, &account, &input).is_err());
    assert!(!files.path(TOKEN).exists());
    assert!(!files.path(SERVER_TOKEN).exists());
}

#[test]
fn validates_the_server_credential_before_replacing_a_worker() {
    let (_guard, files, account, mut input, mut host) = fixture();
    input.server_token_required = true;
    input.server_token = Some("bad-secret\n".into());
    assert!(start(&files, &mut host, &account, &input).is_err());
    assert!(host.calls.is_empty());
    assert!(!files.path(SERVER_TOKEN).exists());
}
