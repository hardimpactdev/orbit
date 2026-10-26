use super::*;
use std::collections::VecDeque;

const RUN: &str = "abcdef0123456789";
const VANISHED: &str =
    "LoadState=not-found\nActiveState=inactive\nSubState=dead\nControlGroup=\nJob=\n";
const QUEUED: &str =
    "LoadState=loaded\nActiveState=inactive\nSubState=dead\nControlGroup=\nJob=123\n";
struct Fixture {
    _work: recipe::WorkDir,
    files: Files,
    input: StartInput,
}
impl Fixture {
    fn new() -> Self {
        let work = recipe::WorkDir::new().unwrap();
        let files = Files {
            home: work.0.join("home"),
            uid: identity::effective_uid(),
            gid: rustix::process::getegid().as_raw(),
            boot: work.0.join("boot"),
            cgroups: work.0.join("cgroups"),
        };
        fs::create_dir(&files.home).unwrap();
        fs::write(&files.boot, "current-boot\n").unwrap();
        let checkout = work.0.join("checkout");
        fs::create_dir(&checkout).unwrap();
        Self {
            _work: work,
            files,
            input: StartInput {
                run: RUN.into(),
                checkout,
                issue: "TASK-7".into(),
                nodes: vec!["app-prod+ingress".into()],
                vpn_subnet: "10.44.0.0/24".into(),
            },
        }
    }
}
#[derive(Default)]
struct Fake {
    calls: Vec<Vec<String>>,
    outputs: VecDeque<recipe::Output>,
}
impl Fake {
    fn replies(values: &[&str]) -> Self {
        Self {
            outputs: values
                .iter()
                .map(|v| recipe::Output {
                    stdout: (*v).into(),
                    ..Default::default()
                })
                .collect(),
            ..Default::default()
        }
    }
}
impl Host for Fake {
    fn execute(&mut self, invocation: &Invocation) -> Result<recipe::Output, recipe::Failure> {
        self.calls.push(invocation.argv.clone());
        Ok(self.outputs.pop_front().expect("unexpected systemd call"))
    }
    fn wait(&mut self, _: Duration) -> Result<(), recipe::Failure> {
        panic!("no waits")
    }
}
#[test]
fn starts_as_account_and_repeated_or_unreadable_run_is_left_alone() {
    let fixture = Fixture::new();
    let mut host = Fake::replies(&[VANISHED, "", "", QUEUED, "malformed"]);
    start(&fixture.input, &fixture.files, &mut host).unwrap();
    let launch = &host.calls[2];
    assert_eq!(
        &launch[..5],
        [
            "/usr/bin/sudo",
            "-n",
            "/usr/bin/systemd-run",
            "--quiet",
            "--collect"
        ]
    );
    for expected in [
        format!("--uid={}", fixture.files.uid),
        format!("--gid={}", fixture.files.gid),
        format!("--working-directory={}", fixture.input.checkout.display()),
        "--property=RuntimeMaxSec=4500".into(),
        "--property=KillMode=control-group".into(),
    ] {
        assert!(launch.contains(&expected), "{expected}");
    }
    assert_eq!(
        &launch[launch.len() - 8..],
        [
            "/usr/local/bin/orbit-agent",
            "service",
            "task-topology",
            RUN,
            fixture.input.checkout.to_str().unwrap(),
            "TASK-7",
            "app-prod+ingress",
            "10.44.0.0/24"
        ]
        .as_slice()
    );
    start(&fixture.input, &fixture.files, &mut host).unwrap();
    start(&fixture.input, &fixture.files, &mut host).unwrap();
    assert_eq!(host.calls.len(), 5);
}
#[test]
fn stops_prior_runs_before_dispatch_and_refuses_when_stop_is_uncertain() {
    for stopped in [VANISHED, QUEUED] {
        let fixture = Fixture::new();
        let mut host = Fake::replies(&[
            VANISHED,
            "orbit-task-topology-earlier1.service loaded inactive dead\n",
            QUEUED,
            "",
            stopped,
            "",
        ]);
        let result = start(&fixture.input, &fixture.files, &mut host);
        assert_eq!(
            host.calls[3],
            [
                "/usr/bin/sudo",
                "-n",
                "/usr/bin/systemctl",
                "stop",
                "--",
                "orbit-task-topology-earlier1.service"
            ]
        );
        if stopped == VANISHED {
            assert!(result.is_ok());
            assert_eq!(host.calls.len(), 6);
        } else {
            assert_eq!(result, Err(failed("stop")));
            assert_eq!(host.calls.len(), 5);
            assert!(!fixture.files.directory().exists());
        }
    }
}
#[test]
fn input_refusals_do_not_read_systemd_or_change_state() {
    let fixture = Fixture::new();
    for field in ["run", "checkout", "issue", "nodes", "subnet"] {
        let mut input = fixture.input.clone();
        match field {
            "run" => input.run = "../escape".into(),
            "checkout" => input.checkout = "relative".into(),
            "issue" => input.issue = "TASK-0".into(),
            "nodes" => input.nodes = vec!["--replace".into(), "app-dev;id".into()],
            "subnet" => input.vpn_subnet = "999.44.0.0/24".into(),
            _ => unreachable!(),
        }
        let mut host = Fake::default();
        assert_eq!(
            start(&input, &fixture.files, &mut host),
            Err(Error::Invalid {})
        );
        assert!(host.calls.is_empty());
        assert!(!fixture.files.directory().exists());
    }
    assert_eq!(
        status("../escape", &fixture.files, &mut Fake::default()),
        Err(Error::Invalid {})
    );
}
#[test]
fn uncertain_state_and_populated_cgroups_never_prove_a_lost_run() {
    let fixture = Fixture::new();
    let group = fixture.files.cgroups.join("system.slice/test.service");
    fs::create_dir_all(&group).unwrap();
    let terminal = "LoadState=loaded\nActiveState=failed\nSubState=dead\nControlGroup=/system.slice/test.service\nJob=\n";
    for (text, events, expected) in [
        (VANISHED, "populated 1", false),
        (QUEUED, "populated 0", true),
        ("", "populated 0", true),
        (terminal, "populated 1", true),
        (terminal, "populated 0", false),
        (terminal, "unreadable", true),
        (terminal, "populated 0\npopulated 1", true),
        (
            "LoadState=loaded\nActiveState=inactive\nJob=\n",
            "populated 0",
            true,
        ),
        (
            "LoadState=loaded\nActiveState=inactive\nControlGroup=/../escape\nJob=\n",
            "populated 0",
            true,
        ),
        (
            "LoadState=loaded\nActiveState=active\nSubState=exited\nControlGroup=\nJob=\n",
            "",
            false,
        ),
    ] {
        fs::write(group.join("cgroup.events"), events).unwrap();
        assert_eq!(
            running(&mut Fake::replies(&[text]), &fixture.files, &unit(RUN)),
            expected,
            "{text} / {events}"
        );
    }
    fs::remove_file(group.join("cgroup.events")).unwrap();
    assert!(!running(
        &mut Fake::replies(&[terminal]),
        &fixture.files,
        &unit(RUN)
    ));
    let mut host = Fake::replies(&[VANISHED]);
    host.outputs[0].code = 1;
    assert!(running(&mut host, &fixture.files, &unit(RUN)));
    let mut host = Fake::replies(&[VANISHED]);
    host.outputs[0].truncated = true;
    assert!(running(&mut host, &fixture.files, &unit(RUN)));
}
struct Work {
    results: VecDeque<bool>,
    steps: Vec<String>,
}
impl Harness for Work {
    fn execute(&mut self, _: &StartInput, step: &str, mut log: &fs::File) -> io::Result<bool> {
        self.steps.push(step.into());
        writeln!(log, "{}: quote \" slash \\ and tab\t", step)?;
        Ok(self.results.pop_front().unwrap())
    }
}
#[test]
fn worker_persists_success_failure_and_one_acquire_retry_and_status_detects_boot_change() {
    for (results, steps, state, step) in [
        (
            vec![true, true],
            vec!["host-setup", "acquire"],
            State::Ready,
            "acquire",
        ),
        (vec![false], vec!["host-setup"], State::Failed, "host-setup"),
        (
            vec![true, false, true],
            vec!["host-setup", "acquire", "acquire"],
            State::Ready,
            "acquire",
        ),
        (
            vec![true, false, false],
            vec!["host-setup", "acquire", "acquire"],
            State::Failed,
            "acquire",
        ),
    ] {
        let fixture = Fixture::new();
        let mut harness = Work {
            results: results.into(),
            steps: vec![],
        };
        assert_eq!(
            work(&fixture.input, &fixture.files, &mut harness).unwrap(),
            state == State::Ready
        );
        assert_eq!(harness.steps, steps);
        let result = status(RUN, &fixture.files, &mut Fake::default()).unwrap();
        assert_eq!(result.state, state);
        assert_eq!(result.step.as_deref(), Some(step));
        assert_eq!(result.boot_id.as_deref(), Some("current-boot"));
        assert!(result.log.unwrap().contains("quote \" slash \\ and tab"));
        let meta = fs::metadata(fixture.files.path(RUN, "json")).unwrap();
        assert_eq!(meta.mode() & 0o777, 0o600);
        fs::write(&fixture.files.boot, "new-boot").unwrap();
        let next = status(RUN, &fixture.files, &mut Fake::default()).unwrap();
        assert_eq!(
            next.state,
            if state == State::Ready {
                State::Stale
            } else {
                State::Failed
            }
        );
        assert_eq!(fs::read_dir(fixture.files.directory()).unwrap().count(), 2);
    }
}
#[test]
fn status_handles_missing_results_and_refuses_malformed_or_linked_results() {
    let fixture = Fixture::new();
    assert_eq!(
        status(RUN, &fixture.files, &mut Fake::replies(&[VANISHED]))
            .unwrap()
            .state,
        State::Lost
    );
    assert_eq!(
        status(RUN, &fixture.files, &mut Fake::replies(&[QUEUED]))
            .unwrap()
            .state,
        State::Running
    );
    fixture.files.prepare().unwrap();
    let path = fixture.files.path(RUN, "json");
    fs::write(&path, "broken").unwrap();
    assert_eq!(
        status(RUN, &fixture.files, &mut Fake::default()),
        Err(failed("result"))
    );
    fs::remove_file(&path).unwrap();
    std::os::unix::fs::symlink(&fixture.files.boot, &path).unwrap();
    assert_eq!(
        status(RUN, &fixture.files, &mut Fake::default()),
        Err(failed("result"))
    );
}

#[test]
fn vanished_units_require_every_piece_of_terminal_evidence() {
    let fixture = Fixture::new();
    for key in ["LoadState", "ActiveState", "ControlGroup", "Job"] {
        let text = VANISHED
            .lines()
            .filter(|line| !line.starts_with(&format!("{key}=")))
            .collect::<Vec<_>>()
            .join("\n");
        assert!(
            running(&mut Fake::replies(&[&text]), &fixture.files, &unit(RUN)),
            "{key}"
        );
    }
    for text in [
        VANISHED.replace("inactive", "active"),
        VANISHED.replace("inactive", "failed"),
        VANISHED.replace("ControlGroup=", "ControlGroup=/live"),
        VANISHED.replace("Job=", "Job=123"),
    ] {
        assert!(running(
            &mut Fake::replies(&[&text]),
            &fixture.files,
            &unit(RUN)
        ));
    }
}

#[test]
fn failed_start_preserves_the_reported_failure_and_does_not_invent_a_result() {
    let fixture = Fixture::new();
    let mut host = Fake::replies(&[VANISHED, "", ""]);
    host.outputs[2].code = 1;
    assert_eq!(
        start(&fixture.input, &fixture.files, &mut host),
        Err(failed("start"))
    );
    assert!(!fixture.files.path(RUN, "json").exists());
    assert_eq!(
        status(RUN, &fixture.files, &mut Fake::replies(&[QUEUED]))
            .unwrap()
            .state,
        State::Running
    );
}
#[test]
fn log_tail_is_bounded_and_contains_only_the_last_forty_lines() {
    let fixture = Fixture::new();
    fixture.files.prepare().unwrap();
    let path = fixture.files.path(RUN, "log");
    fs::write(
        &path,
        (0..100).map(|n| format!("line-{n}\n")).collect::<String>(),
    )
    .unwrap();
    let tail = log_tail(&path).unwrap();
    assert!(tail.starts_with("line-60\n"));
    assert!(tail.ends_with("line-99"));
    fs::write(&path, vec![b'a'; LOG_LIMIT as usize * 3]).unwrap();
    assert!(log_tail(&path).unwrap().len() <= LOG_LIMIT as usize);
}
