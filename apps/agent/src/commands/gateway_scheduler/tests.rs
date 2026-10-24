use super::super::recipe::{Failure, Output, WorkDir};
use super::*;
use std::{
    collections::VecDeque,
    os::unix::fs::{symlink, PermissionsExt},
};

struct Fake {
    time: Instant,
    calls: Vec<Vec<String>>,
    pids: VecDeque<String>,
    group: String,
    pcntl: bool,
    active: bool,
    fail: Option<String>,
    truncated: bool,
}
impl Default for Fake {
    fn default() -> Self {
        Self {
            time: Instant::now(),
            calls: vec![],
            pids: ["42", "0"].map(str::to_owned).into(),
            group: "/system.slice/orbit-process-7-schedule-work.service".into(),
            pcntl: true,
            active: true,
            fail: None,
            truncated: false,
        }
    }
}
impl recipe::Host for Fake {
    fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
        self.calls.push(call.argv.clone());
        if self.truncated {
            return Ok(Output {
                truncated: true,
                ..Output::default()
            });
        }
        if self
            .fail
            .as_ref()
            .is_some_and(|word| call.argv.contains(word))
        {
            return Ok(Output {
                code: 1,
                ..Output::default()
            });
        }
        if call.argv.contains(&"pcntl".into()) {
            return Ok(Output {
                code: if self.pcntl { 0 } else { 1 },
                stdout: if self.pcntl {
                    "pcntl\n\npcntl support => enabled\n".into()
                } else {
                    "Extension 'pcntl' not present.".into()
                },
                ..Output::default()
            });
        }
        if call.argv.contains(&"MainPID".into()) {
            let value = if self.pids.len() > 1 {
                self.pids.pop_front().unwrap()
            } else {
                self.pids.front().unwrap().clone()
            };
            return Ok(Output {
                stdout: value,
                ..Output::default()
            });
        }
        if call.argv.contains(&"ControlGroup".into()) {
            return Ok(Output {
                stdout: self.group.clone(),
                ..Output::default()
            });
        }
        if call.argv.contains(&"is-active".into()) && !self.active {
            return Ok(Output {
                code: 3,
                ..Output::default()
            });
        }
        Ok(Output::default())
    }
    fn wait(&mut self, delay: Duration) -> Result<(), Failure> {
        self.time += delay;
        Ok(())
    }
}
impl Host for Fake {
    fn now(&self) -> Instant {
        self.time
    }
}
struct Fixture {
    work: WorkDir,
    files: Files,
}
impl Fixture {
    fn new() -> Self {
        let work = WorkDir::new().unwrap();
        let files = Files {
            root: work.0.clone(),
            uid: rustix::process::geteuid().as_raw(),
            gid: rustix::process::getegid().as_raw(),
        };
        fs::create_dir_all(files.path("/etc/systemd/system")).unwrap();
        fs::write(files.path("/etc/systemd/system/orbit-process-7-schedule-work.service"), "[Unit]\nX-Orbit-Process-ID=7\n[Service]\nExecStart=/usr/bin/php8.5 artisan schedule:work\n").unwrap();
        let f = Self { work, files };
        f.file(
            "/sys/fs/cgroup/system.slice/orbit-process-7-schedule-work.service/cgroup.procs",
            "42\n43\n44\n",
        );
        f.file(
            "/proc/42/cmdline",
            "/usr/bin/php8.5\0artisan\0schedule:work\0",
        );
        f.file("/proc/43/cmdline", "/usr/bin/php8.5\0artisan\0tasks:tick\0");
        f
    }
    fn file(&self, path: &str, value: &str) {
        let path = self.files.path(path);
        fs::create_dir_all(path.parent().unwrap()).unwrap();
        fs::write(path, value).unwrap();
    }
    fn input(&self) -> Input {
        Input {
            process_id: 7,
            name: "orbit-process-7-schedule-work.service".into(),
        }
    }
    fn drain(&self, seconds: u64) -> DrainInput {
        DrainInput {
            process_id: 7,
            name: self.input().name,
            drain_seconds: seconds,
        }
    }
}
fn stage(error: Error) -> String {
    match error {
        Error::Failed { stage } => stage,
        other => panic!("{other:?}"),
    }
}
fn mutations(host: &Fake) -> Vec<String> {
    host.calls
        .iter()
        .filter(|c| {
            c[0] == "/usr/bin/systemctl"
                && ["kill", "start", "stop", "try-restart"].contains(&c[1].as_str())
        })
        .map(|c| c[1].clone())
        .collect()
}

#[test]
fn drain_signals_only_the_main_process_and_waits_for_that_process_to_exit() {
    let f = Fixture::new();
    let mut host = Fake {
        pids: ["42", "42", "42", "0"].map(str::to_owned).into(),
        ..Fake::default()
    };
    let result = drain(&f.files, &mut host, &f.drain(5)).unwrap();
    assert!(matches!(result.outcome, Outcome::Drained));
    assert_eq!(result.waited_ms, 1000);
    assert_eq!(result.running, ["/usr/bin/php8.5 artisan tasks:tick"]);
    assert!(result.stopped.is_none());
    assert!(result.reason.is_none());
    assert_eq!(mutations(&host), ["kill"]);
    assert!(host.calls.contains(
        &[
            "/usr/bin/systemctl",
            "kill",
            "--kill-whom=main",
            "--signal=SIGTERM",
            "orbit-process-7-schedule-work.service"
        ]
        .map(str::to_owned)
        .into()
    ));
    let probe = &host.calls[0];
    assert_eq!(
        probe,
        &[
            "/usr/bin/env",
            "-i",
            "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
            "LANG=C.UTF-8",
            "/usr/bin/php8.5",
            "--ri",
            "pcntl"
        ]
    );
    assert!(matches!(
        drain(&f.files, &mut host, &f.drain(5)).unwrap().outcome,
        Outcome::NotRunning
    ));
    assert_eq!(mutations(&host), ["kill"]);
    assert!(f.work.0.exists());
}

#[test]
fn deadline_or_missing_pcntl_returns_a_forced_result_without_stopping_anything() {
    let f = Fixture::new();
    for pcntl in [false, true] {
        let mut host = Fake {
            pcntl,
            pids: ["42".into()].into(),
            ..Fake::default()
        };
        let result = drain(&f.files, &mut host, &f.drain(1)).unwrap();
        assert!(matches!(result.outcome, Outcome::Forced));
        assert_eq!(result.stopped.as_ref(), Some(&result.running));
        assert_eq!(result.reason, if pcntl { None } else { Some("no_pcntl") });
        assert_eq!(result.waited_ms, if pcntl { 1000 } else { 0 });
        assert_eq!(mutations(&host), if pcntl { vec!["kill"] } else { vec![] });
    }
}

#[test]
fn control_actions_preserve_enabled_state_and_start_waits_for_activity() {
    let f = Fixture::new();
    let mut host = Fake::default();
    for _ in 0..2 {
        start(&f.files, &mut host, &f.input()).unwrap();
    }
    control(&f.files, &mut host, &f.input(), "stop").unwrap();
    control(&f.files, &mut host, &f.input(), "try-restart").unwrap();
    assert_eq!(mutations(&host), ["start", "start", "stop", "try-restart"]);
    assert!(!host
        .calls
        .iter()
        .flatten()
        .any(|arg| arg == "enable" || arg == "disable"));
    host.active = false;
    let before = host.time;
    assert_eq!(
        stage(start(&f.files, &mut host, &f.input()).unwrap_err()),
        "start"
    );
    assert_eq!(host.time - before, Duration::from_secs(30));
}

#[test]
fn invalid_and_foreign_units_refuse_before_any_command() {
    let f = Fixture::new();
    let mut host = Fake::default();
    for name in [
        "../orbit-process-7-schedule-work.service",
        "orbit-process-8-schedule-work.service",
        "sshd.service",
    ] {
        let mut input = f.input();
        input.name = name.into();
        assert!(matches!(
            start(&f.files, &mut host, &input),
            Err(Error::Invalid {})
        ));
    }
    assert!(matches!(
        drain(&f.files, &mut host, &f.drain(3601)),
        Err(Error::Invalid {})
    ));
    for text in [
        "[Unit]\nX-Orbit-Process-ID=8\n",
        "[Unit]\n# X-Orbit-Process-ID=7\n",
        "[Unit]\nX-Orbit-Process-ID=70\n",
    ] {
        f.file(
            "/etc/systemd/system/orbit-process-7-schedule-work.service",
            text,
        );
        assert_eq!(
            stage(start(&f.files, &mut host, &f.input()).unwrap_err()),
            "unit"
        );
    }
    let path = f
        .files
        .path("/etc/systemd/system/orbit-process-7-schedule-work.service");
    fs::remove_file(&path).unwrap();
    symlink(f.files.path("/proc/43/cmdline"), &path).unwrap();
    assert!(control(&f.files, &mut host, &f.input(), "stop").is_err());
    fs::remove_file(&path).unwrap();
    f.file(
        "/etc/systemd/system/orbit-process-7-schedule-work.service",
        "X-Orbit-Process-ID=7\n",
    );
    fs::set_permissions(path, fs::Permissions::from_mode(0o666)).unwrap();
    assert!(start(&f.files, &mut host, &f.input()).is_err());
    assert!(host.calls.is_empty());
}

#[test]
fn failed_or_malformed_observations_never_signal_and_action_failures_are_typed() {
    let f = Fixture::new();
    for pid in ["", "n/a", "-1", "4294967296"] {
        let mut host = Fake {
            pids: [pid.into()].into(),
            ..Fake::default()
        };
        assert_eq!(
            stage(drain(&f.files, &mut host, &f.drain(5)).unwrap_err()),
            "observe"
        );
        assert!(mutations(&host).is_empty());
    }
    for group in [
        "relative",
        "/../escape",
        "/system.slice//unit",
        "/system.slice/unit\nother",
    ] {
        let mut host = Fake {
            group: group.into(),
            ..Fake::default()
        };
        assert_eq!(
            stage(drain(&f.files, &mut host, &f.drain(5)).unwrap_err()),
            "observe"
        );
        assert!(mutations(&host).is_empty());
    }
    let mut host = Fake {
        truncated: true,
        ..Fake::default()
    };
    assert!(drain(&f.files, &mut host, &f.drain(5)).is_err());
    assert!(mutations(&host).is_empty());
    for verb in ["start", "stop", "try-restart", "kill"] {
        let mut host = Fake {
            fail: Some(verb.into()),
            ..Fake::default()
        };
        let result = match verb {
            "start" => start(&f.files, &mut host, &f.input()).map(|_| ()),
            "kill" => drain(&f.files, &mut host, &f.drain(5)).map(|_| ()),
            _ => control(&f.files, &mut host, &f.input(), verb).map(|_| ()),
        };
        assert_eq!(
            stage(result.unwrap_err()),
            if verb == "kill" { "signal" } else { verb }
        );
    }
}

#[test]
fn member_observation_is_bounded_redacted_and_tolerates_exited_processes() {
    let f = Fixture::new();
    let mut host = Fake::default();
    f.file(
        "/proc/43/cmdline",
        &format!("worker\0PASSWORD=private-sentinel\0{}", "é".repeat(300)),
    );
    let observed = members(&f.files, &mut host, &f.input().name).unwrap();
    assert_eq!(observed.len(), 1);
    assert!(!observed[0].contains("private-sentinel"));
    assert!(observed[0].contains("[REDACTED]"));
    assert_eq!(observed[0].chars().count(), 200);
    let pids: String = (100..130).map(|pid| format!("{pid}\n")).collect();
    f.file(
        "/sys/fs/cgroup/system.slice/orbit-process-7-schedule-work.service/cgroup.procs",
        &pids,
    );
    for pid in 100..130 {
        f.file(&format!("/proc/{pid}/cmdline"), "worker");
    }
    assert_eq!(
        members(&f.files, &mut host, &f.input().name).unwrap().len(),
        20
    );
    f.file(
        "/sys/fs/cgroup/system.slice/orbit-process-7-schedule-work.service/cgroup.procs",
        "invalid\n",
    );
    assert!(members(&f.files, &mut host, &f.input().name).is_err());
}
