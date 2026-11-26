use std::cell::RefCell;
use std::collections::VecDeque;

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
        self.calls().into_iter().filter(|call| !call.contains(" show ") && !call.contains(" inspect ")).collect()
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
    assert_eq!(fake.changed(), [format!("/usr/bin/systemctl disable -- {UNIT}"), format!("/usr/bin/systemctl stop -- {UNIT}"),
        format!("/usr/bin/systemctl stop -- {UNIT}")]);

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
        json!({"process_id": 417, "runtime": "systemd", "name": UNIT, "state": "running", "boot": "enabled",
            "container": {"project": "orbit-instances", "name": "instance-12"}}),
    ] {
        assert_eq!(ensure_state(&fake, &input).map_err(|failure| failure.code), Err("process.input_invalid"), "{input}");
    }
    assert!(fake.calls().is_empty(), "nothing runs");
}

fn docker(state: &str) -> serde_json::Value {
    json!({"process_id": 417, "runtime": "docker", "name": "orbit-process-417-web", "image": "redis:7", "state": state})
}

#[test]
fn a_docker_start_acts_only_on_the_inspected_container_and_reads_running() {
    let fake = Fake::default().on("/usr/bin/docker container inspect", &[Ok("/orbit-process-417-web exited redis:7\n"), Ok("/orbit-process-417-web running redis:7\n")]);
    assert_eq!(ensure_state(&fake, &docker("running")), Ok(1));
    assert_eq!(fake.changed(), ["/usr/bin/docker container start orbit-process-417-web"]);
    let running = Fake::default().on("/usr/bin/docker container inspect", &[Ok("/orbit-process-417-web running redis:7\n")]);
    assert_eq!(ensure_state(&running, &docker("running")), Ok(0));
    let other = Fake::default().on("/usr/bin/docker container inspect", &[Ok("/orbit-process-417-web running redis:6\n")]);
    assert_eq!(ensure_state(&other, &docker("running")).map_err(|failure| failure.code), Err("process.state_mismatch"));
}

#[test]
fn a_docker_stop_requires_exited_and_refuses_created() {
    let fake = Fake::default().on("/usr/bin/docker container inspect", &[Ok("/orbit-process-417-web running redis:7\n"), Ok("/orbit-process-417-web created redis:7\n")]);
    assert_eq!(ensure_state(&fake, &docker("stopped")).map_err(|failure| failure.code), Err("process.state_mismatch"));
    // `created` never ran: a stop does not count it as stopped, so it acts, then the read-back still refuses it.
    let created = Fake::default().on("/usr/bin/docker container inspect", &[Ok("/orbit-process-417-web created redis:7\n")]);
    assert_eq!(ensure_state(&created, &docker("stopped")).map_err(|failure| failure.code), Err("process.state_mismatch"));
}

#[test]
fn a_docker_answer_for_another_container_is_refused() {
    let fake = Fake::default().on("/usr/bin/docker container inspect", &[Ok("/orbit-process-417-web-2 running redis:7\n")]);
    assert_eq!(ensure_state(&fake, &docker("stopped")).map_err(|failure| failure.code), Err("process.container_missing"));
    assert!(fake.changed().is_empty(), "a sibling is never acted on");
}

const SCHEDULE: &str = "7f3c0a5e-1b2c-4d3e-8f90-0123456789ab";

fn timer() -> serde_json::Value {
    json!({"schedule_id": SCHEDULE, "unit": format!("orbit-schedule-{SCHEDULE}.timer")})
}

#[test]
fn a_timer_is_enabled_and_started_and_reads_back_its_next_elapse() {
    let fake = Fake::default().on("/usr/bin/systemctl show", &[
        Ok("LoadState=loaded\nSubState=waiting\nActiveState=inactive\nUnitFileState=disabled\nNextElapseUSecRealtime=\n"),
        Ok("LoadState=loaded\nSubState=waiting\nActiveState=active\nUnitFileState=enabled\nNextElapseUSecRealtime=Thu 2026-11-26 14:00:00 UTC\n"),
    ]);
    let read = ensure_timer(&fake, &timer()).expect("enabled");
    assert_eq!((read.changes, read.unit_file_state.as_str(), read.active_state.as_str(), read.next_elapse.as_str()), (2, "enabled", "active", "Thu 2026-11-26 14:00:00 UTC"));
    let unit = format!("orbit-schedule-{SCHEDULE}.timer");
    assert_eq!(fake.changed(), [format!("/usr/bin/systemctl enable -- {unit}"), format!("/usr/bin/systemctl start -- {unit}")]);
}

#[test]
fn an_enabled_timer_is_only_checked() {
    let fake = Fake::default().on("/usr/bin/systemctl show", &[Ok("LoadState=loaded\nSubState=waiting\nActiveState=active\nUnitFileState=enabled\nNextElapseUSecRealtime=x\n")]);
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
        Ok("LoadState=loaded\nSubState=waiting\nActiveState=inactive\nUnitFileState=enabled\nNextElapseUSecRealtime=\n"),
    ]);
    assert_eq!(ensure_timer(&fake, &timer()).map_err(|failure| failure.code), Err("schedule.state_mismatch"));
}

#[test]
fn the_gateway_signed_inputs_parse_and_run() {
    let state: serde_json::Value = serde_json::from_str(include_str!("../../../contract/fixtures/executor-process-ensure-state.json")).expect("fixture");
    let fake = Fake::default().on("/usr/bin/systemctl show", &[Ok(&show("loaded", "inactive", "disabled"))]);
    assert_eq!(ensure_state(&fake, &state["fields"]["input"]), Ok(0));
    let timer: serde_json::Value = serde_json::from_str(include_str!("../../../contract/fixtures/executor-schedule-ensure-timer.json")).expect("fixture");
    let fake = Fake::default().on("/usr/bin/systemctl show", &[Ok("LoadState=loaded\nSubState=waiting\nActiveState=active\nUnitFileState=enabled\nNextElapseUSecRealtime=\n")]);
    assert_eq!(ensure_timer(&fake, &timer["fields"]["input"]).map(|read| read.changes), Ok(0));
}

#[test]
fn a_property_systemd_left_out_never_counts_as_its_value() {
    // A unit read without SubState: the read-back has no evidence, so it fails rather than succeeding on what is there.
    let unit = Fake::default().on("/usr/bin/systemctl show", &[
        Ok(&show("loaded", "inactive", "disabled")),
        Ok("LoadState=loaded\nActiveState=active\nUnitFileState=enabled\nNextElapseUSecRealtime=\n"),
    ]);
    assert_eq!(ensure_state(&unit, &host("running", "enabled")).map_err(|failure| failure.code), Err("process.read_failed"));
    // A timer read without its next elapse fails; one with an empty next elapse is an answer.
    let absent = Fake::default().on("/usr/bin/systemctl show", &[Ok("LoadState=loaded\nSubState=waiting\nActiveState=active\nUnitFileState=enabled\n")]);
    assert_eq!(ensure_timer(&absent, &timer()).map_err(|failure| failure.code), Err("schedule.apply_failed"));
    let after = Fake::default().on("/usr/bin/systemctl show", &[
        Ok("LoadState=loaded\nSubState=dead\nActiveState=inactive\nUnitFileState=disabled\nNextElapseUSecRealtime=\n"),
        Ok("LoadState=loaded\nSubState=waiting\nActiveState=active\nUnitFileState=enabled\n"),
    ]);
    assert_eq!(ensure_timer(&after, &timer()).map_err(|failure| failure.code), Err("schedule.read_failed"));
    let empty = Fake::default().on("/usr/bin/systemctl show", &[Ok("LoadState=loaded\nSubState=waiting\nActiveState=active\nUnitFileState=enabled\nNextElapseUSecRealtime=\n")]);
    assert_eq!(ensure_timer(&empty, &timer()).map(|read| read.changes), Ok(0));
}

#[test]
fn each_failure_names_its_phase() {
    // A stop whose converge passed but whose plain stop fails: Swamp's `Stop the unit`.
    let stop = Fake::default()
        .on("/usr/bin/systemctl show", &[Ok(&show("loaded", "inactive", "disabled"))])
        .on("/usr/bin/systemctl stop", &[Err("Job failed")]);
    assert_eq!(ensure_state(&stop, &host("stopped", "disabled")).map_err(|failure| failure.code), Err("process.unit_stop_failed"));
    // A failed change: Swamp's apply step.
    let start = Fake::default()
        .on("/usr/bin/systemctl show", &[Ok(&show("loaded", "inactive", "enabled"))])
        .on("/usr/bin/systemctl start", &[Err("Job failed")]);
    assert_eq!(ensure_state(&start, &host("running", "enabled")).map_err(|failure| failure.code), Err("process.apply_failed"));
    // A read-back that fails to read: Swamp's check step, not its assert.
    let read = Fake::default().on("/usr/bin/systemctl show", &[Ok(&show("loaded", "active", "enabled")), Err("Failed to connect to bus")]);
    assert_eq!(ensure_state(&read, &host("running", "enabled")).map_err(|failure| failure.code), Err("process.read_failed"));
    let gone = Fake::default().on("/usr/bin/docker container inspect", &[Ok("/orbit-process-417-web running redis:7\n"), Err("No such container")]);
    assert_eq!(ensure_state(&gone, &docker("running")).map_err(|failure| failure.code), Err("process.read_failed"));
    let missing = Fake::default().on("/usr/bin/docker container inspect", &[Err("No such container")]);
    assert_eq!(ensure_state(&missing, &docker("running")).map_err(|failure| failure.code), Err("process.container_missing"));
}
