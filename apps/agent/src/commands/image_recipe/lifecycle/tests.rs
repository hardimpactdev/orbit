use super::*;
use crate::commands::recipe::Output;
use serde_json::json;
use std::collections::VecDeque;
const BOOT: &str = "12345678-1234-1234-1234-123456789abc";
const INVOCATION: &str = "0123456789abcdef0123456789abcdef";
fn input() -> Start {
    let identity = format!("sha256:{}", "a".repeat(64));
    serde_json::from_value(json!({
        "key": {"identity":identity, "profile":"base", "phase":"packages", "execution":"23"},
        "boot_id":BOOT,
        "recipe":{"identity":identity, "agent_sha256":"b".repeat(64), "pi_sha256":null, "php":null, "rust":{},
        "recipe":{"user":"orbit", "snapshot":"20261022T000000Z", "architecture":"amd64", "tools":{}}}
    })).unwrap()
}
fn fixture() -> (WorkDir, Files, Start) {
    let work = WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: rustix::process::getuid().as_raw(),
        gid: rustix::process::getgid().as_raw(),
    };
    fs::create_dir_all(files.path("/proc/sys/kernel/random")).unwrap();
    fs::create_dir_all(files.path("/var/log")).unwrap();
    fs::write(files.path("/proc/sys/kernel/random/boot_id"), BOOT).unwrap();
    (work, files, input())
}
fn status(
    active: &str,
    sub: &str,
    outcome: &str,
    code: &str,
    exit: &str,
    invocation: &str,
) -> Output {
    Output { stdout: format!("LoadState=loaded\nActiveState={active}\nSubState={sub}\nResult={outcome}\nExecMainCode={code}\nExecMainStatus={exit}\nInvocationID={invocation}\n"), ..Output::default() }
}
fn missing() -> Output {
    Output {
        code: 4,
        stdout: "LoadState=not-found\n".into(),
        ..Output::default()
    }
}
fn running(invocation: &str) -> Output {
    status("active", "running", "success", "0", "0", invocation)
}
fn failed_status() -> Output {
    status("failed", "failed", "exit-code", "1", "1", INVOCATION)
}
struct Fake {
    replies: VecDeque<Output>,
    calls: Vec<Invocation>,
}
impl Fake {
    fn new(replies: Vec<Output>) -> Self {
        Self {
            replies: replies.into(),
            calls: vec![],
        }
    }
}
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
        self.calls.push(call.clone());
        Ok(self.replies.pop_front().expect("Unexpected call"))
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("No polling inside control")
    }
}
#[test]
fn parses_missing_running_success_failure_timeout_and_signal_without_guessing_success() {
    let key = input().key;
    for (reply, expected, exit, reason) in [
        (missing(), State::Missing, None, None),
        (running(INVOCATION), State::Running, None, None),
        (
            status("activating", "start", "success", "0", "0", INVOCATION),
            State::Running,
            None,
            None,
        ),
        (
            status("active", "exited", "success", "1", "0", INVOCATION),
            State::Complete,
            None,
            None,
        ),
        (
            status("inactive", "dead", "success", "1", "0", INVOCATION),
            State::Failed,
            None,
            Some("failed"),
        ),
        (failed_status(), State::Failed, Some(1), Some("exit 1")),
        (
            status("failed", "failed", "timeout", "2", "15", INVOCATION),
            State::Failed,
            None,
            Some("1440 seconds"),
        ),
        (
            status("failed", "failed", "signal", "2", "9", INVOCATION),
            State::Failed,
            None,
            Some("signal 9"),
        ),
        (
            status("failed", "failed", "exit-code", "1", "75", INVOCATION),
            State::Failed,
            Some(75),
            Some("builder lock"),
        ),
    ] {
        let observed = parse(&reply, key.unit(), BOOT.into()).unwrap();
        assert_eq!(observed.state, expected);
        assert_eq!(observed.exit_code, exit);
        if let Some(reason) = reason {
            assert!(observed.reason.unwrap().contains(reason));
        }
    }
}
#[test]
fn incomplete_malformed_and_truncated_statuses_are_unknown() {
    let key = input().key;
    let valid = running(INVOCATION).stdout;
    for stdout in [
        "".into(),
        "LoadState=loaded\n".into(),
        format!("{valid}LoadState=not-found\n"),
        valid.replace(INVOCATION, "invalid"),
        valid.replace("ExecMainStatus=0", "ExecMainStatus=-1"),
        valid.replace("ExecMainCode=0\n", ""),
        format!("{valid}garbage\n"),
    ] {
        assert!(matches!(
            parse(
                &Output {
                    stdout,
                    ..Output::default()
                },
                key.unit(),
                BOOT.into()
            ),
            Err(Error::Unknown { .. })
        ));
    }
    let mut reply = running(INVOCATION);
    reply.truncated = true;
    assert!(matches!(
        parse(&reply, key.unit(), BOOT.into()),
        Err(Error::Unknown { .. })
    ));
}
#[test]
fn reboot_and_invalid_input_refuse_before_any_control_or_file_change() {
    let (_work, files, mut input) = fixture();
    let mut host = Fake::new(vec![]);
    input.boot_id = "00000000-0000-0000-0000-000000000000".into();
    assert!(matches!(
        start(&mut host, &files, &input),
        Err(Error::Rebooted { .. })
    ));
    input.boot_id = BOOT.into();
    input.key.execution = "../../escape".into();
    assert!(matches!(
        start(&mut host, &files, &input),
        Err(Error::Failed { .. })
    ));
    input.key.execution = "23".into();
    input.recipe.identity = format!("sha256:{}", "c".repeat(64));
    assert!(start(&mut host, &files, &input).is_err());
    assert!(host.calls.is_empty());
    assert!(!files.path(STATE).exists());
}
#[test]
fn start_persists_only_typed_data_and_keeps_loaded_units_on_repeat() {
    let (_work, files, input) = fixture();
    let mut host = Fake::new(vec![
        missing(),
        Output::default(),
        running(INVOCATION),
        running(INVOCATION),
    ]);
    assert_eq!(
        start(&mut host, &files, &input).unwrap().state,
        State::Running
    );
    assert_eq!(
        start(&mut host, &files, &input).unwrap().state,
        State::Running
    );
    assert_eq!(
        host.calls
            .iter()
            .filter(|c| c.argv[0] == "systemd-run")
            .count(),
        1
    );
    let call = &host.calls[1];
    assert!(call
        .argv
        .contains(&"--property=KillMode=control-group".into()));
    assert!(call.argv.contains(&"--property=ExitType=cgroup".into()));
    assert!(call.argv.contains(&"--property=RemainAfterExit=yes".into()));
    assert!(call.argv.contains(&"--property=RuntimeMaxSec=1440".into()));
    assert!(call.argv.ends_with(&[
        "/usr/local/bin/orbit-agent".into(),
        "service".into(),
        "image-phase".into(),
        input.key.unit()
    ]));
    let stored: Start = serde_json::from_slice(&fs::read(input.key.path(&files)).unwrap()).unwrap();
    stored.validate().unwrap();
    assert_eq!(stored.key.unit(), input.key.unit());
    assert_eq!(
        fs::metadata(input.key.path(&files)).unwrap().mode() & 0o777,
        0o600
    );
    assert_eq!(
        fs::metadata(files.path(STATE)).unwrap().mode() & 0o777,
        0o700
    );
}
#[test]
fn failed_start_delivery_is_resolved_by_the_unit_and_missing_unit_is_failure() {
    for loaded in [false, true] {
        let (_work, files, input) = fixture();
        let mut host = Fake::new(vec![
            missing(),
            Output {
                code: 1,
                stderr: "unit already exists".into(),
                ..Output::default()
            },
            if loaded {
                running(INVOCATION)
            } else {
                missing()
            },
        ]);
        let result = start(&mut host, &files, &input);
        if loaded {
            assert_eq!(result.unwrap().state, State::Running);
        } else {
            assert!(matches!(result, Err(Error::Failed { stage, .. }) if stage == "start"));
        }
    }
}
#[test]
fn immutable_input_refuses_conflicts_and_symlinks() {
    let (_work, files, mut input) = fixture();
    stage(&files, &input).unwrap();
    stage(&files, &input).unwrap();
    input.recipe.agent_sha256 = "c".repeat(64);
    assert!(stage(&files, &input).is_err());
    let path = input.key.path(&files);
    fs::remove_file(&path).unwrap();
    let outside = files.path("/outside");
    std::os::unix::fs::symlink(&outside, &path).unwrap();
    assert!(stage(&files, &input).is_err());
    assert!(!outside.exists());
}
#[test]
fn restart_is_tied_to_one_failed_invocation_and_repeats_only_observe() {
    let (_work, files, input) = fixture();
    let next = "abcdef0123456789abcdef0123456789";
    let restart_input = Restart {
        key: input.key,
        boot_id: BOOT.into(),
        invocation_id: INVOCATION.into(),
    };
    let mut host = Fake::new(vec![
        failed_status(),
        Output::default(),
        running(next),
        running(next),
    ]);
    assert_eq!(
        restart(&mut host, &files, &restart_input).unwrap().state,
        State::Running
    );
    assert_eq!(
        restart(&mut host, &files, &restart_input)
            .unwrap()
            .invocation_id
            .as_deref(),
        Some(next)
    );
    assert_eq!(
        host.calls
            .iter()
            .filter(|c| c.argv.get(1).is_some_and(|v| v == "restart"))
            .count(),
        1
    );
    let mut host = Fake::new(vec![failed_status(), Output::default(), failed_status()]);
    assert!(matches!(
        restart(&mut host, &files, &restart_input),
        Err(Error::Unknown { .. })
    ));
    let mut host = Fake::new(vec![running(INVOCATION)]);
    assert_eq!(
        restart(&mut host, &files, &restart_input).unwrap().state,
        State::Running
    );
    assert_eq!(host.calls.len(), 1);
}
#[test]
fn failed_observation_returns_a_bounded_redacted_private_log_tail() {
    let (_work, files, input) = fixture();
    let path = crate::commands::image_build::phase_log(&files.root, "packages", true).unwrap();
    fs::write(
        path,
        format!("{}\nPASSWORD=sentinel\nlast", "old\n".repeat(10000)),
    )
    .unwrap();
    let mut host = Fake::new(vec![failed_status()]);
    let observed = observe(&mut host, &files, &input.key, Some(BOOT)).unwrap();
    assert_eq!(observed.state, State::Failed);
    assert!(!observed.log_tail.contains("sentinel"));
    assert!(observed.log_tail.ends_with("last"));
    assert!(observed.log_tail.len() <= 8000);
    assert!(observed.log_tail.lines().count() <= 40);
}
