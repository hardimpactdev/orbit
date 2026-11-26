use std::cell::RefCell;
use std::collections::VecDeque;
use std::path::PathBuf;
use std::time::Duration;

use serde_json::json;

use super::*;

type Script = Vec<(String, VecDeque<Result<String, String>>)>;

/// A Node double: each command gets the next scripted answer for its first matching prefix, and every command is kept.
#[derive(Default)]
struct Fake {
    answers: RefCell<Script>,
    calls: RefCell<Vec<String>>,
}

impl Fake {
    fn on(self, prefix: &str, answers: &[Result<&str, &str>]) -> Self {
        self.answers.borrow_mut().push((
            prefix.to_owned(),
            answers.iter().map(|answer| answer.map(str::to_owned).map_err(str::to_owned)).collect(),
        ));
        self
    }

    fn calls(&self) -> Vec<String> {
        self.calls.borrow().clone()
    }

    fn changed(&self) -> Vec<String> {
        self.calls().into_iter().filter(|call| !call.contains(" show ") && !call.contains(" inspect ") && !call.contains(" query ") && !call.contains("sha256sum")).collect()
    }
}

impl System for Fake {
    fn run(&self, _seconds: u32, argv: &[&str]) -> Result<String, String> {
        let line = argv.join(" ");
        self.calls.borrow_mut().push(line.clone());
        let mut answers = self.answers.borrow_mut();
        let Some((_, queue)) = answers.iter_mut().find(|(prefix, _)| line.starts_with(prefix.as_str())) else {
            return Ok(String::new());
        };
        if queue.len() > 1 {
            queue.pop_front().expect("an answer")
        } else {
            queue.front().cloned().expect("an answer")
        }
    }

    fn blob_path(&self, sha256: &str) -> PathBuf {
        PathBuf::from("/blobs").join(sha256)
    }

    fn pause(&self, _duration: Duration) {}
}

const UNIT: &str = "orbit-process-417-web.service";

fn show(load: &str, active: &str, file: &str) -> String {
    format!("LoadState={load}\nActiveState={active}\nSubState=running\nUnitFileState={file}\nNextElapseUSecRealtime=\n")
}

fn host(state: &str, boot: &str) -> serde_json::Value {
    json!({"process_id": 417, "runtime": "systemd", "name": UNIT, "state": state, "boot": boot})
}

#[test]
fn a_host_start_enables_and_starts_only_what_differs_and_reads_both_back() {
    let fake = Fake::default().on("/usr/bin/systemctl show", &[Ok(&show("loaded", "inactive", "disabled")), Ok(&show("loaded", "active", "enabled"))]);
    assert_eq!(ensure_state(&fake, &host("running", "enabled")), Ok(2));
    assert_eq!(fake.changed(), [format!("/usr/bin/systemctl enable -- {UNIT}"), format!("/usr/bin/systemctl start -- {UNIT}")]);
}

#[test]
fn an_on_demand_start_keeps_the_unit_disabled_at_boot() {
    let fake = Fake::default().on("/usr/bin/systemctl show", &[Ok(&show("loaded", "inactive", "disabled")), Ok(&show("loaded", "active", "disabled"))]);
    assert_eq!(ensure_state(&fake, &host("running", "disabled")), Ok(1));
    assert_eq!(fake.changed(), [format!("/usr/bin/systemctl start -- {UNIT}")]);
}

#[test]
fn a_running_unit_changes_nothing_and_a_repeat_reports_zero() {
    let fake = Fake::default().on("/usr/bin/systemctl show", &[Ok(&show("loaded", "active", "enabled"))]);
    assert_eq!(ensure_state(&fake, &host("running", "enabled")), Ok(0));
    assert!(fake.changed().is_empty(), "a converged unit is only read");
}

#[test]
fn a_host_stop_requires_inactive_and_disabled_exactly() {
    let fake = Fake::default().on("/usr/bin/systemctl show", &[Ok(&show("loaded", "active", "enabled")), Ok(&show("loaded", "inactive", "disabled"))]);
    assert_eq!(ensure_state(&fake, &host("stopped", "disabled")), Ok(2));
    assert_eq!(fake.changed(), [format!("/usr/bin/systemctl disable -- {UNIT}"), format!("/usr/bin/systemctl stop -- {UNIT}")]);

    let failed = Fake::default().on("/usr/bin/systemctl show", &[Ok(&show("loaded", "active", "disabled")), Ok(&show("loaded", "failed", "disabled"))]);
    assert_eq!(ensure_state(&failed, &host("stopped", "disabled")).map_err(|failure| failure.code), Err("process.state_mismatch"));
}

#[test]
fn a_missing_unit_is_refused_before_any_change() {
    let fake = Fake::default().on("/usr/bin/systemctl show", &[Ok(&show("not-found", "inactive", ""))]);
    assert_eq!(ensure_state(&fake, &host("running", "enabled")).map_err(|failure| failure.code), Err("process.unit_missing"));
    assert!(fake.changed().is_empty(), "nothing is changed");
}

#[test]
fn a_name_that_is_not_the_processes_own_is_refused() {
    let fake = Fake::default();
    for input in [
        json!({"process_id": 417, "runtime": "systemd", "name": "orbit-process-418-web.service", "state": "running", "boot": "enabled"}),
        json!({"process_id": 417, "runtime": "systemd", "name": "ssh.service", "state": "running", "boot": "enabled"}),
        json!({"process_id": 417, "runtime": "docker", "name": "orbit-process-417-web", "state": "running", "boot": "enabled"}),
        json!({"process_id": 417, "runtime": "systemd", "name": UNIT, "state": "running"}),
        json!({"process_id": 417, "runtime": "systemd", "name": UNIT, "state": "running", "boot": "enabled", "argv": ["sh"]}),
    ] {
        assert_eq!(ensure_state(&fake, &input).map_err(|failure| failure.code), Err("process.input_invalid"), "{input}");
    }
    assert!(fake.calls().is_empty(), "nothing runs");
}

fn docker(state: &str) -> serde_json::Value {
    json!({"process_id": 417, "runtime": "docker", "name": "orbit-process-417-web", "state": state})
}

#[test]
fn a_docker_start_acts_only_on_the_inspected_container_and_reads_running() {
    let fake = Fake::default().on("/usr/bin/docker container inspect", &[Ok("/orbit-process-417-web exited\n"), Ok("/orbit-process-417-web running\n")]);
    assert_eq!(ensure_state(&fake, &docker("running")), Ok(1));
    assert_eq!(fake.changed(), ["/usr/bin/docker container start orbit-process-417-web"]);
    let running = Fake::default().on("/usr/bin/docker container inspect", &[Ok("/orbit-process-417-web running\n")]);
    assert_eq!(ensure_state(&running, &docker("running")), Ok(0));
}

#[test]
fn a_docker_stop_requires_exited_and_refuses_created() {
    let fake = Fake::default().on("/usr/bin/docker container inspect", &[Ok("/orbit-process-417-web running\n"), Ok("/orbit-process-417-web created\n")]);
    assert_eq!(ensure_state(&fake, &docker("stopped")).map_err(|failure| failure.code), Err("process.state_mismatch"));
    // `created` never ran: a stop does not count it as stopped, so it acts, then the read-back still refuses it.
    let created = Fake::default().on("/usr/bin/docker container inspect", &[Ok("/orbit-process-417-web created\n")]);
    assert_eq!(ensure_state(&created, &docker("stopped")).map_err(|failure| failure.code), Err("process.state_mismatch"));
}

#[test]
fn a_docker_answer_for_another_container_is_refused() {
    let fake = Fake::default().on("/usr/bin/docker container inspect", &[Ok("/orbit-process-417-web-2 running\n")]);
    assert_eq!(ensure_state(&fake, &docker("stopped")).map_err(|failure| failure.code), Err("process.container_missing"));
    assert!(fake.changed().is_empty(), "a sibling is never acted on");
}

const SHA: &str = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";

fn guest(state: &str, boot: &str) -> serde_json::Value {
    json!({"process_id": 417, "runtime": "systemd", "name": UNIT, "state": state, "boot": boot,
        "container": {"project": "orbit-instances", "name": "instance-12", "account_path": "/usr/local/libexec/orbit-app-user", "account": {"blob": {"sha256": SHA}}}})
}

fn guest_show(active: &str, file: &str, job: &str) -> String {
    format!("LoadState=loaded\nActiveState={active}\nUnitFileState={file}\nJob={job}\n")
}

const RUNNING: &str = r#"{"name": "instance-12", "status": "Running"}"#;

#[test]
fn a_guest_start_queues_a_job_and_waits_until_no_job_is_queued() {
    let fake = Fake::default()
        .on("/usr/bin/incus query", &[Ok(RUNNING)])
        .on("/usr/bin/incus exec --project orbit-instances instance-12 -- sha256sum", &[Ok(&format!("{SHA}  /usr/local/libexec/orbit-app-user\n"))])
        .on("/usr/bin/incus exec --project orbit-instances instance-12 -- timeout 5 systemctl show", &[
            Ok(&guest_show("inactive", "disabled", "")),
            Ok(&guest_show("activating", "enabled", "88")),
            Ok(&guest_show("active", "enabled", "88")),
            Ok(&guest_show("active", "enabled", "")),
        ]);
    assert_eq!(ensure_state(&fake, &guest("running", "enabled")), Ok(1));
    assert_eq!(fake.changed(), [
        "/usr/bin/incus exec --project orbit-instances instance-12 -- /usr/local/libexec/orbit-app-user".to_owned(),
        format!("/usr/bin/incus exec --project orbit-instances instance-12 -- timeout 5 systemctl enable --now --no-block -- {UNIT}"),
    ]);
}

#[test]
fn a_guest_job_still_queued_after_ten_reads_fails_the_start() {
    let fake = Fake::default()
        .on("/usr/bin/incus query", &[Ok(RUNNING)])
        .on("/usr/bin/incus exec --project orbit-instances instance-12 -- timeout 5 systemctl show", &[
            Ok(&guest_show("inactive", "disabled", "")),
            Ok(&guest_show("active", "enabled", "91")),
        ]);
    assert_eq!(ensure_state(&fake, &guest("running", "enabled")).map_err(|failure| failure.code), Err("process.start_failed"));
}

#[test]
fn a_guest_stop_disables_and_requires_inactive_and_places_a_differing_account_file() {
    let fake = Fake::default()
        .on("/usr/bin/incus query", &[Ok(RUNNING)])
        .on("/usr/bin/incus exec --project orbit-instances instance-12 -- sha256sum", &[Err("No such file")])
        .on("/usr/bin/incus exec --project orbit-instances instance-12 -- timeout 5 systemctl show", &[
            Ok(&guest_show("active", "enabled", "")),
            Ok(&guest_show("inactive", "disabled", "")),
        ]);
    assert_eq!(ensure_state(&fake, &guest("stopped", "disabled")), Ok(1));
    assert_eq!(fake.changed(), [
        format!("/usr/bin/incus file push --project orbit-instances --create-dirs --uid 0 --gid 0 --mode 0755 -- /blobs/{SHA} instance-12/usr/local/libexec/orbit-app-user"),
        "/usr/bin/incus exec --project orbit-instances instance-12 -- /usr/local/libexec/orbit-app-user".to_owned(),
        format!("/usr/bin/incus exec --project orbit-instances instance-12 -- timeout 20 systemctl disable --now -- {UNIT}"),
    ]);
}

#[test]
fn a_stopped_container_fails_the_step_before_any_change() {
    let fake = Fake::default().on("/usr/bin/incus query", &[Ok(r#"{"name": "instance-12", "status": "Stopped"}"#)]);
    assert_eq!(ensure_state(&fake, &guest("running", "enabled")).map_err(|failure| failure.code), Err("process.target_inactive"));
    assert_eq!(fake.calls().len(), 1, "only the container is read");
}

const SCHEDULE: &str = "7f3c0a5e-1b2c-4d3e-8f90-0123456789ab";

fn timer() -> serde_json::Value {
    json!({"schedule_id": SCHEDULE, "unit": format!("orbit-schedule-{SCHEDULE}.timer")})
}

#[test]
fn a_timer_is_enabled_and_started_and_reads_back_its_next_elapse() {
    let fake = Fake::default().on("/usr/bin/systemctl show", &[
        Ok("LoadState=loaded\nActiveState=inactive\nUnitFileState=disabled\nNextElapseUSecRealtime=\n"),
        Ok("LoadState=loaded\nActiveState=active\nUnitFileState=enabled\nNextElapseUSecRealtime=Thu 2026-11-26 14:00:00 UTC\n"),
    ]);
    let read = ensure_timer(&fake, &timer()).expect("enabled");
    assert_eq!((read.changes, read.unit_file_state.as_str(), read.active_state.as_str(), read.next_elapse.as_str()), (2, "enabled", "active", "Thu 2026-11-26 14:00:00 UTC"));
    let unit = format!("orbit-schedule-{SCHEDULE}.timer");
    assert_eq!(fake.changed(), [format!("/usr/bin/systemctl enable -- {unit}"), format!("/usr/bin/systemctl start -- {unit}")]);
}

#[test]
fn an_enabled_timer_is_only_checked() {
    let fake = Fake::default().on("/usr/bin/systemctl show", &[Ok("LoadState=loaded\nActiveState=active\nUnitFileState=enabled\nNextElapseUSecRealtime=x\n")]);
    assert_eq!(ensure_timer(&fake, &timer()).map(|read| read.changes), Ok(0));
    assert!(fake.changed().is_empty(), "nothing changes");
}

#[test]
fn a_timer_that_is_not_the_schedules_own_is_refused() {
    let fake = Fake::default();
    for unit in ["ssh.service".to_owned(), "orbit-schedule-7f3c0a5e-1b2c-4d3e-8f90-0123456789ac.timer".to_owned(), format!("orbit-schedule-{SCHEDULE}.service")] {
        assert_eq!(ensure_timer(&fake, &json!({"schedule_id": SCHEDULE, "unit": unit})).map_err(|failure| failure.code), Err("schedule.input_invalid"));
    }
    assert!(fake.calls().is_empty(), "nothing runs");
}

#[test]
fn a_timer_that_does_not_stay_active_fails_the_check() {
    let fake = Fake::default().on("/usr/bin/systemctl show", &[
        Ok("LoadState=loaded\nActiveState=inactive\nUnitFileState=enabled\n"),
    ]);
    assert_eq!(ensure_timer(&fake, &timer()).map_err(|failure| failure.code), Err("schedule.state_mismatch"));
}
