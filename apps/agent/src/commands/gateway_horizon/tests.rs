use super::super::recipe::{Failure, Output, WorkDir};
use super::*;
use std::collections::VecDeque;

struct Fake {
    time: Instant,
    states: VecDeque<String>,
    calls: Vec<Vec<String>>,
    events: std::path::PathBuf,
    clear_after: Option<usize>,
    waits: usize,
    bad_policy: bool,
    fail_stop: bool,
}
impl recipe::Host for Fake {
    fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
        self.calls.push(call.argv.clone());
        let property = call.argv.get(3).map(String::as_str).unwrap_or("");
        let stdout = match property {
            "KillMode" if self.bad_policy => "control-group".into(),
            "KillMode" => "mixed".into(),
            "KillSignal" => "15".into(),
            "SendSIGHUP" | "SendSIGKILL" => "no".into(),
            "TimeoutStopUSec" => "infinity".into(),
            "ControlGroup" => "/system.slice/orbit-process-7-horizon.service".into(),
            "ActiveState" => {
                if self.states.len() > 1 {
                    self.states.pop_front().unwrap()
                } else {
                    self.states.front().unwrap().clone()
                }
            }
            _ => String::new(),
        };
        Ok(Output {
            code: i32::from(self.fail_stop && call.argv.get(1).is_some_and(|v| v == "stop")),
            stdout,
            ..Output::default()
        })
    }
    fn wait(&mut self, delay: Duration) -> Result<(), Failure> {
        self.time += delay;
        self.waits += 1;
        if self.clear_after == Some(self.waits) {
            fs::write(&self.events, "populated 0\n").unwrap();
        }
        Ok(())
    }
}
impl Host for Fake {
    fn now(&self) -> Instant {
        self.time
    }
}
struct Fixture {
    _work: WorkDir,
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
        fs::create_dir_all(files.path("/run")).unwrap();
        fs::write(
            files.path("/etc/systemd/system/orbit-process-7-horizon.service"),
            "[Unit]\nX-Orbit-Process-ID=7\n[Service]\nExecStart=/usr/bin/php8.5 artisan horizon\n",
        )
        .unwrap();
        Self { _work: work, files }
    }
    fn input(&self) -> DrainInput {
        DrainInput {
            process_id: 7,
            name: "orbit-process-7-horizon.service".into(),
            drain_seconds: 1,
        }
    }
    fn policy(&self) -> std::path::PathBuf {
        self.files.path(
            "/run/systemd/system/orbit-process-7-horizon.service.d/zz-orbit-horizon-drain.conf",
        )
    }
    fn host(&self) -> Fake {
        let events = self
            .files
            .path("/sys/fs/cgroup/system.slice/orbit-process-7-horizon.service/cgroup.events");
        fs::create_dir_all(events.parent().unwrap()).unwrap();
        fs::write(&events, "populated 1\n").unwrap();
        fs::write(
            self.files.path("/sys/fs/cgroup/cgroup.controllers"),
            "cpu memory pids\n",
        )
        .unwrap();
        Fake {
            time: Instant::now(),
            states: ["deactivating", "inactive"].map(String::from).into(),
            calls: vec![],
            events,
            clear_after: Some(3),
            waits: 0,
            bad_policy: false,
            fail_stop: false,
        }
    }
}
#[test]
fn release_waits_for_all_children_even_after_the_master_is_gone() {
    let f = Fixture::new();
    let mut host = f.host();
    assert!(drain(&f.files, &mut host, &f.input()).unwrap().drained);
    assert_eq!(host.waits, 3);
    assert!(!f.policy().exists());
    assert_eq!(
        host.calls
            .iter()
            .filter(|c| c.get(1).is_some_and(|v| v == "stop"))
            .count(),
        1
    );
    assert!(host.calls.iter().any(|c| c
        == &[
            "/usr/bin/systemctl",
            "stop",
            "--no-block",
            "orbit-process-7-horizon.service"
        ]));
    assert!(!host.calls.iter().any(|c| c
        .iter()
        .any(|v| ["kill", "restart", "start"].contains(&v.as_str()))));
}
#[test]
fn deadline_keeps_safe_stop_in_place_and_a_later_call_can_finish() {
    let f = Fixture::new();
    let mut host = f.host();
    host.clear_after = None;
    assert!(
        matches!(drain(&f.files, &mut host, &f.input()), Err(Error::NotDrained { stage }) if stage == "deadline")
    );
    assert_eq!(fs::read_to_string(f.policy()).unwrap(), POLICY);
    host.clear_after = Some(host.waits + 1);
    assert!(drain(&f.files, &mut host, &f.input()).unwrap().drained);
    assert!(!f.policy().exists());
}
#[test]
fn an_inactive_unit_with_an_unreadable_cgroup_never_proves_a_drain() {
    let f = Fixture::new();
    let mut host = f.host();
    fs::write(&host.events, "unreadable observation\n").unwrap();
    assert!(
        matches!(drain(&f.files, &mut host, &f.input()), Err(Error::NotDrained { stage }) if stage == "cgroup")
    );
    assert!(f.policy().exists());
}
#[test]
fn overridden_policy_or_failed_stop_never_reports_a_drain() {
    for policy in [false, true] {
        let f = Fixture::new();
        let mut host = f.host();
        host.bad_policy = policy;
        host.fail_stop = !policy;
        assert!(drain(&f.files, &mut host, &f.input()).is_err());
        assert!(f.policy().exists());
        if policy {
            assert!(!host
                .calls
                .iter()
                .any(|c| c.get(1).is_some_and(|v| v == "stop")));
        }
    }
}
#[test]
fn foreign_unit_and_foreign_policy_refuse_without_stopping_anything() {
    let f = Fixture::new();
    let mut host = f.host();
    let mut input = f.input();
    input.process_id = 8;
    assert!(drain(&f.files, &mut host, &input).is_err());
    assert!(host.calls.is_empty());
    fs::create_dir_all(f.policy().parent().unwrap()).unwrap();
    fs::write(f.policy(), "foreign policy\n").unwrap();
    assert!(drain(&f.files, &mut host, &f.input()).is_err());
    assert!(host.calls.is_empty());
}

#[test]
fn a_missing_cgroup_hierarchy_is_not_an_empty_unit() {
    let f = Fixture::new();
    let mut host = f.host();
    fs::remove_file(f.files.path("/sys/fs/cgroup/cgroup.controllers")).unwrap();
    fs::remove_file(&host.events).unwrap();
    assert!(
        matches!(drain(&f.files, &mut host, &f.input()), Err(Error::NotDrained { stage }) if stage == "cgroup")
    );
    assert!(!host
        .calls
        .iter()
        .any(|c| c.get(1).is_some_and(|v| v == "stop")));
}
