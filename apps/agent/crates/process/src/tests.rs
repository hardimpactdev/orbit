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
    attempts: RefCell<std::collections::BTreeSet<String>>,
}

impl Fake {
    fn on(self, prefix: &str, answers: &[Result<&str, &str>]) -> Self {
        self.answers.borrow_mut().push((
            prefix.to_owned(),
            answers
                .iter()
                .map(|answer| answer.map(str::to_owned).map_err(str::to_owned))
                .collect(),
        ));
        self
    }

    fn calls(&self) -> Vec<String> {
        self.calls.borrow().clone()
    }

    fn changed(&self) -> Vec<String> {
        self.calls()
            .into_iter()
            .filter(|call| !call.contains(" show ") && !call.contains(" inspect "))
            .collect()
    }
}

impl System for Fake {
    fn run(&self, _seconds: u32, argv: &[&str]) -> Result<String, String> {
        let line = argv.join(" ");
        self.calls.borrow_mut().push(line.clone());
        let mut answers = self.answers.borrow_mut();
        let Some((_, queue)) = answers
            .iter_mut()
            .find(|(prefix, _)| line.starts_with(prefix.as_str()))
        else {
            return Ok(String::new());
        };
        if queue.len() > 1 {
            queue.pop_front().expect("an answer")
        } else {
            queue.front().cloned().expect("an answer")
        }
    }

    fn begin_attempt(&self, key: &str) -> Result<(), String> {
        self.calls.borrow_mut().push(format!("attempt {key}"));
        self.attempts.borrow_mut().insert(key.to_owned());
        Ok(())
    }

    fn attempted(&self, key: &str) -> bool {
        self.attempts.borrow().contains(key)
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
    let fake = Fake::default().on(
        "/usr/bin/systemctl show",
        &[
            Ok(&show("loaded", "inactive", "disabled")),
            Ok(&show("loaded", "active", "enabled")),
        ],
    );
    assert_eq!(ensure_state(&fake, &host("running", "enabled")), Ok(2));
    assert_eq!(
        fake.changed(),
        [
            format!("/usr/bin/systemctl enable -- {UNIT}"),
            format!("/usr/bin/systemctl start -- {UNIT}")
        ]
    );
}

#[test]
fn an_on_demand_start_keeps_the_unit_disabled_at_boot() {
    let fake = Fake::default().on(
        "/usr/bin/systemctl show",
        &[
            Ok(&show("loaded", "inactive", "disabled")),
            Ok(&show("loaded", "active", "disabled")),
        ],
    );
    assert_eq!(ensure_state(&fake, &host("running", "disabled")), Ok(1));
    assert_eq!(
        fake.changed(),
        [format!("/usr/bin/systemctl start -- {UNIT}")]
    );
}

#[test]
fn a_running_unit_changes_nothing_and_a_repeat_reports_zero() {
    let fake = Fake::default().on(
        "/usr/bin/systemctl show",
        &[Ok(&show("loaded", "active", "enabled"))],
    );
    assert_eq!(ensure_state(&fake, &host("running", "enabled")), Ok(0));
    assert!(fake.changed().is_empty(), "a converged unit is only read");
}

#[test]
fn a_host_stop_requires_inactive_and_disabled_exactly() {
    let fake = Fake::default().on(
        "/usr/bin/systemctl show",
        &[
            Ok(&show("loaded", "active", "enabled")),
            Ok(&show("loaded", "inactive", "disabled")),
        ],
    );
    assert_eq!(ensure_state(&fake, &host("stopped", "disabled")), Ok(2));
    assert_eq!(
        fake.changed(),
        [
            format!("/usr/bin/systemctl disable -- {UNIT}"),
            format!("/usr/bin/systemctl stop -- {UNIT}"),
            format!("/usr/bin/systemctl stop -- {UNIT}")
        ]
    );

    let failed = Fake::default().on(
        "/usr/bin/systemctl show",
        &[
            Ok(&show("loaded", "active", "disabled")),
            Ok(&show("loaded", "failed", "disabled")),
        ],
    );
    assert_eq!(
        ensure_state(&failed, &host("stopped", "disabled")).map_err(|failure| failure.code),
        Err("process.state_mismatch")
    );
}

#[test]
fn a_missing_unit_is_refused_before_any_change() {
    let fake = Fake::default().on(
        "/usr/bin/systemctl show",
        &[Ok(&show("not-found", "inactive", ""))],
    );
    assert_eq!(
        ensure_state(&fake, &host("running", "enabled")).map_err(|failure| failure.code),
        Err("process.unit_missing")
    );
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
        assert_eq!(
            ensure_state(&fake, &input).map_err(|failure| failure.code),
            Err("process.input_invalid"),
            "{input}"
        );
    }
    assert!(fake.calls().is_empty(), "nothing runs");
}

fn docker(state: &str) -> serde_json::Value {
    json!({"process_id": 417, "runtime": "docker", "name": "orbit-process-417-web", "image": "redis:7", "state": state})
}

#[test]
fn a_docker_start_acts_only_on_the_inspected_container_and_reads_running() {
    let fake = Fake::default().on(
        "/usr/bin/docker container inspect",
        &[
            Ok("/orbit-process-417-web exited redis:7\n"),
            Ok("/orbit-process-417-web running redis:7\n"),
        ],
    );
    assert_eq!(ensure_state(&fake, &docker("running")), Ok(1));
    assert_eq!(
        fake.changed(),
        ["/usr/bin/docker container start orbit-process-417-web"]
    );
    let running = Fake::default().on(
        "/usr/bin/docker container inspect",
        &[Ok("/orbit-process-417-web running redis:7\n")],
    );
    assert_eq!(ensure_state(&running, &docker("running")), Ok(0));
    let other = Fake::default().on(
        "/usr/bin/docker container inspect",
        &[Ok("/orbit-process-417-web running redis:6\n")],
    );
    assert_eq!(
        ensure_state(&other, &docker("running")).map_err(|failure| failure.code),
        Err("process.state_mismatch")
    );
}

#[test]
fn a_docker_stop_requires_exited_and_refuses_created() {
    let fake = Fake::default().on(
        "/usr/bin/docker container inspect",
        &[
            Ok("/orbit-process-417-web running redis:7\n"),
            Ok("/orbit-process-417-web created redis:7\n"),
        ],
    );
    assert_eq!(
        ensure_state(&fake, &docker("stopped")).map_err(|failure| failure.code),
        Err("process.state_mismatch")
    );
    // `created` never ran: a stop does not count it as stopped, so it acts, then the read-back still refuses it.
    let created = Fake::default().on(
        "/usr/bin/docker container inspect",
        &[Ok("/orbit-process-417-web created redis:7\n")],
    );
    assert_eq!(
        ensure_state(&created, &docker("stopped")).map_err(|failure| failure.code),
        Err("process.state_mismatch")
    );
}

#[test]
fn a_docker_answer_for_another_container_is_refused() {
    let fake = Fake::default().on(
        "/usr/bin/docker container inspect",
        &[Ok("/orbit-process-417-web-2 running redis:7\n")],
    );
    assert_eq!(
        ensure_state(&fake, &docker("stopped")).map_err(|failure| failure.code),
        Err("process.container_missing")
    );
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
    assert_eq!(
        (
            read.changes,
            read.unit_file_state.as_str(),
            read.active_state.as_str(),
            read.next_elapse.as_str()
        ),
        (2, "enabled", "active", "Thu 2026-11-26 14:00:00 UTC")
    );
    let unit = format!("orbit-schedule-{SCHEDULE}.timer");
    assert_eq!(
        fake.changed(),
        [
            format!("/usr/bin/systemctl enable -- {unit}"),
            format!("/usr/bin/systemctl start -- {unit}")
        ]
    );
}

#[test]
fn an_enabled_timer_is_only_checked() {
    let fake = Fake::default().on("/usr/bin/systemctl show", &[Ok("LoadState=loaded\nSubState=waiting\nActiveState=active\nUnitFileState=enabled\nNextElapseUSecRealtime=x\n")]);
    assert_eq!(
        ensure_timer(&fake, &timer()).map(|read| read.changes),
        Ok(0)
    );
    assert!(fake.changed().is_empty(), "nothing changes");
}

#[test]
fn a_timer_that_is_not_the_schedules_own_is_refused() {
    let fake = Fake::default();
    for unit in [
        "ssh.service".to_owned(),
        "orbit-schedule-7f3c0a5e-1b2c-4d3e-8f90-0123456789ac.timer".to_owned(),
        format!("orbit-schedule-{SCHEDULE}.service"),
    ] {
        assert_eq!(
            ensure_timer(&fake, &json!({"schedule_id": SCHEDULE, "unit": unit}))
                .map_err(|failure| failure.code),
            Err("schedule.input_invalid")
        );
    }
    assert!(fake.calls().is_empty(), "nothing runs");
}

#[test]
fn a_timer_that_does_not_stay_active_fails_the_check() {
    let fake = Fake::default().on("/usr/bin/systemctl show", &[
        Ok("LoadState=loaded\nSubState=waiting\nActiveState=inactive\nUnitFileState=enabled\nNextElapseUSecRealtime=\n"),
    ]);
    assert_eq!(
        ensure_timer(&fake, &timer()).map_err(|failure| failure.code),
        Err("schedule.state_mismatch")
    );
}

#[test]
fn the_gateway_signed_inputs_parse_and_run() {
    let state: serde_json::Value = serde_json::from_str(include_str!(
        "../../../contract/fixtures/executor-process-ensure-state.json"
    ))
    .expect("fixture");
    let fake = Fake::default().on(
        "/usr/bin/systemctl show",
        &[Ok(&show("loaded", "inactive", "disabled"))],
    );
    assert_eq!(ensure_state(&fake, &state["fields"]["input"]), Ok(0));
    let timer: serde_json::Value = serde_json::from_str(include_str!(
        "../../../contract/fixtures/executor-schedule-ensure-timer.json"
    ))
    .expect("fixture");
    let fake = Fake::default().on("/usr/bin/systemctl show", &[Ok("LoadState=loaded\nSubState=waiting\nActiveState=active\nUnitFileState=enabled\nNextElapseUSecRealtime=\n")]);
    assert_eq!(
        ensure_timer(&fake, &timer["fields"]["input"]).map(|read| read.changes),
        Ok(0)
    );
}

#[test]
fn a_property_systemd_left_out_never_counts_as_its_value() {
    // A unit read without SubState: the read-back has no evidence, so it fails rather than succeeding on what is there.
    let unit = Fake::default().on("/usr/bin/systemctl show", &[
        Ok(&show("loaded", "inactive", "disabled")),
        Ok("LoadState=loaded\nActiveState=active\nUnitFileState=enabled\nNextElapseUSecRealtime=\n"),
    ]);
    assert_eq!(
        ensure_state(&unit, &host("running", "enabled")).map_err(|failure| failure.code),
        Err("process.read_failed")
    );
    // A timer read without its next elapse fails; one with an empty next elapse is an answer.
    let absent = Fake::default().on(
        "/usr/bin/systemctl show",
        &[Ok(
            "LoadState=loaded\nSubState=waiting\nActiveState=active\nUnitFileState=enabled\n",
        )],
    );
    assert_eq!(
        ensure_timer(&absent, &timer()).map_err(|failure| failure.code),
        Err("schedule.apply_failed")
    );
    let after = Fake::default().on("/usr/bin/systemctl show", &[
        Ok("LoadState=loaded\nSubState=dead\nActiveState=inactive\nUnitFileState=disabled\nNextElapseUSecRealtime=\n"),
        Ok("LoadState=loaded\nSubState=waiting\nActiveState=active\nUnitFileState=enabled\n"),
    ]);
    assert_eq!(
        ensure_timer(&after, &timer()).map_err(|failure| failure.code),
        Err("schedule.read_failed")
    );
    let empty = Fake::default().on("/usr/bin/systemctl show", &[Ok("LoadState=loaded\nSubState=waiting\nActiveState=active\nUnitFileState=enabled\nNextElapseUSecRealtime=\n")]);
    assert_eq!(
        ensure_timer(&empty, &timer()).map(|read| read.changes),
        Ok(0)
    );
}

#[test]
fn each_failure_names_its_phase() {
    // A stop whose converge passed but whose plain stop fails: Swamp's `Stop the unit`.
    let stop = Fake::default()
        .on(
            "/usr/bin/systemctl show",
            &[Ok(&show("loaded", "inactive", "disabled"))],
        )
        .on("/usr/bin/systemctl stop", &[Err("Job failed")]);
    assert_eq!(
        ensure_state(&stop, &host("stopped", "disabled")).map_err(|failure| failure.code),
        Err("process.unit_stop_failed")
    );
    // A failed change: Swamp's apply step.
    let start = Fake::default()
        .on(
            "/usr/bin/systemctl show",
            &[Ok(&show("loaded", "inactive", "enabled"))],
        )
        .on("/usr/bin/systemctl start", &[Err("Job failed")]);
    assert_eq!(
        ensure_state(&start, &host("running", "enabled")).map_err(|failure| failure.code),
        Err("process.apply_failed")
    );
    // A read-back that fails to read: Swamp's check step, not its assert.
    let read = Fake::default().on(
        "/usr/bin/systemctl show",
        &[
            Ok(&show("loaded", "active", "enabled")),
            Err("Failed to connect to bus"),
        ],
    );
    assert_eq!(
        ensure_state(&read, &host("running", "enabled")).map_err(|failure| failure.code),
        Err("process.read_failed")
    );
    let gone = Fake::default().on(
        "/usr/bin/docker container inspect",
        &[
            Ok("/orbit-process-417-web running redis:7\n"),
            Err("No such container"),
        ],
    );
    assert_eq!(
        ensure_state(&gone, &docker("running")).map_err(|failure| failure.code),
        Err("process.read_failed")
    );
    let missing = Fake::default().on(
        "/usr/bin/docker container inspect",
        &[Err("No such container")],
    );
    assert_eq!(
        ensure_state(&missing, &docker("running")).map_err(|failure| failure.code),
        Err("process.container_missing")
    );
}

const INVOCATION: &str = "/usr/bin/systemctl show --property=InvocationID";

fn restart_input(boot: &str) -> serde_json::Value {
    json!({"process_id": 417, "runtime": "systemd", "name": UNIT, "instance": "aaa", "boot": boot})
}

fn restarts(fake: &Fake) -> usize {
    fake.calls()
        .iter()
        .filter(|call| call.contains(" restart "))
        .count()
}

#[test]
fn a_read_pins_the_units_invocation_and_the_containers_run() {
    let fake = Fake::default()
        .on(
            "/usr/bin/systemctl show --property=LoadState",
            &[Ok(&show("loaded", "active", "enabled"))],
        )
        .on(INVOCATION, &[Ok("aaa\n")]);
    let input = json!({"process_id": 417, "runtime": "systemd", "name": UNIT});
    assert_eq!(
        read_instance(&fake, &input).map(|read| read.instance),
        Ok("aaa".to_owned())
    );
    assert_eq!(fake.changed(), Vec::<String>::new());
    let fake = Fake::default().on(
        "/usr/bin/docker container inspect",
        &[Ok(
            "/orbit-process-417-web c1 2026-11-27T10:00:00Z running nginx:1\n",
        )],
    );
    let input = json!({"process_id": 417, "runtime": "docker", "name": "orbit-process-417-web"});
    assert_eq!(
        read_instance(&fake, &input).map(|read| read.instance),
        Ok("c1@2026-11-27T10:00:00Z".to_owned())
    );
    let missing = Fake::default().on(
        "/usr/bin/systemctl show --property=LoadState",
        &[Ok(&show("not-found", "inactive", ""))],
    );
    let input = json!({"process_id": 417, "runtime": "systemd", "name": UNIT});
    assert_eq!(
        read_instance(&missing, &input).map_err(|failure| failure.code),
        Err("process.unit_missing")
    );
    let foreign =
        json!({"process_id": 417, "runtime": "systemd", "name": "orbit-process-418-web.service"});
    assert_eq!(
        read_instance(&Fake::default(), &foreign).map_err(|failure| failure.code),
        Err("process.input_invalid")
    );
}

#[test]
fn a_unit_restart_sets_its_boot_policy_records_its_attempt_then_restarts_once_and_reads_a_new_run_back(
) {
    let fake = Fake::default()
        .on(
            "/usr/bin/systemctl show --property=LoadState",
            &[
                Ok(&show("loaded", "active", "disabled")),
                Ok(&show("loaded", "active", "enabled")),
            ],
        )
        .on(INVOCATION, &[Ok("aaa"), Ok("aaa"), Ok("bbb")]);
    let restart = restart(&fake, "42-restart", &restart_input("enabled")).expect("restarts");
    assert_eq!(
        (restart.restarted, restart.instance.as_str()),
        ("now", "bbb")
    );
    assert_eq!(
        fake.changed(),
        vec![
            format!("/usr/bin/systemctl enable -- {UNIT}"),
            "attempt 42-restart".to_owned(),
            format!("/usr/bin/systemctl restart -- {UNIT}"),
        ]
    );
    assert!(
        fake.attempted("42-restart"),
        "the order stays spent after its result"
    );
}

#[test]
fn an_on_demand_unit_keeps_its_boot_policy_disabled() {
    let fake = Fake::default()
        .on(
            "/usr/bin/systemctl show --property=LoadState",
            &[Ok(&show("loaded", "active", "disabled"))],
        )
        .on(INVOCATION, &[Ok("aaa"), Ok("aaa"), Ok("bbb")]);
    assert!(restart(&fake, "42-restart", &restart_input("disabled")).is_ok());
    assert!(!fake
        .changed()
        .iter()
        .any(|call| call.contains("enable") || call.contains("disable")));
    assert_eq!(restarts(&fake), 1);
}

#[test]
fn a_replay_after_the_restart_ran_never_restarts_again() {
    // The executor died after the restart: its attempt is on disk, the unit runs a new invocation.
    let fake = Fake::default()
        .on(
            "/usr/bin/systemctl show --property=LoadState",
            &[Ok(&show("loaded", "active", "enabled"))],
        )
        .on(INVOCATION, &[Ok("bbb")]);
    fake.begin_attempt("42-restart").expect("attempt");
    let recovered = restart(&fake, "42-restart", &restart_input("enabled")).expect("recovers");
    assert_eq!(recovered.restarted, "recovered");
    assert_eq!(restarts(&fake), 0);
    // No attempt, and the pinned run already ended before the order acted: nothing restarts.
    let fake = Fake::default()
        .on(
            "/usr/bin/systemctl show --property=LoadState",
            &[Ok(&show("loaded", "active", "enabled"))],
        )
        .on(INVOCATION, &[Ok("bbb")]);
    assert_eq!(
        restart(&fake, "42-restart", &restart_input("enabled")).map(|done| done.restarted),
        Ok("already")
    );
    assert_eq!(restarts(&fake), 0);
}

#[test]
fn a_replay_whose_attempt_never_showed_a_new_run_fails_unresolved_without_restarting() {
    let fake = Fake::default()
        .on(
            "/usr/bin/systemctl show --property=LoadState",
            &[Ok(&show("loaded", "active", "enabled"))],
        )
        .on(INVOCATION, &[Ok("aaa")]);
    fake.begin_attempt("42-restart").expect("attempt");
    assert_eq!(
        restart(&fake, "42-restart", &restart_input("enabled")).map_err(|failure| failure.code),
        Err("process.restart_unresolved")
    );
    assert_eq!(restarts(&fake), 0);
}

#[test]
fn a_fresh_order_restarts_again_exactly_once() {
    for key in ["42-restart", "43-restart"] {
        let fake = Fake::default()
            .on(
                "/usr/bin/systemctl show --property=LoadState",
                &[Ok(&show("loaded", "active", "enabled"))],
            )
            .on(INVOCATION, &[Ok("aaa"), Ok("aaa"), Ok("bbb")]);
        assert_eq!(
            restart(&fake, key, &restart_input("enabled")).map(|done| done.restarted),
            Ok("now")
        );
        assert_eq!(restarts(&fake), 1, "{key}");
    }
}

#[test]
fn a_restart_whose_read_back_is_not_a_new_active_run_fails() {
    let same = Fake::default()
        .on(
            "/usr/bin/systemctl show --property=LoadState",
            &[Ok(&show("loaded", "active", "enabled"))],
        )
        .on(INVOCATION, &[Ok("aaa")]);
    assert_eq!(
        restart(&same, "42-restart", &restart_input("enabled")).map_err(|failure| failure.code),
        Err("process.restart_unverified")
    );
    let failed = Fake::default()
        .on(
            "/usr/bin/systemctl show --property=LoadState",
            &[
                Ok(&show("loaded", "active", "enabled")),
                Ok(&show("loaded", "failed", "enabled")),
            ],
        )
        .on(INVOCATION, &[Ok("aaa"), Ok("aaa"), Ok("bbb")]);
    assert_eq!(
        restart(&failed, "42-restart", &restart_input("enabled")).map_err(|failure| failure.code),
        Err("process.state_mismatch")
    );
    let refused = Fake::default()
        .on(
            "/usr/bin/systemctl show --property=LoadState",
            &[Ok(&show("loaded", "active", "enabled"))],
        )
        .on(INVOCATION, &[Ok("aaa")])
        .on("/usr/bin/systemctl restart", &[Err("Job failed")]);
    assert_eq!(
        restart(&refused, "42-restart", &restart_input("enabled")).map_err(|failure| failure.code),
        Err("process.apply_failed")
    );
    assert!(
        refused.attempted("42-restart"),
        "a restart that failed still spends its order"
    );
}

#[test]
fn a_restart_refuses_a_foreign_name_a_bad_key_and_a_mixed_shape_before_any_change() {
    let fake = Fake::default();
    let foreign = json!({"process_id": 417, "runtime": "systemd", "name": "orbit-process-418-web.service", "instance": "aaa", "boot": "enabled"});
    assert_eq!(
        restart(&fake, "42-restart", &foreign).map_err(|failure| failure.code),
        Err("process.input_invalid")
    );
    assert_eq!(
        restart(&fake, "../x", &restart_input("enabled")).map_err(|failure| failure.code),
        Err("process.input_invalid")
    );
    let mixed = json!({"process_id": 417, "runtime": "docker", "name": "orbit-process-417-web", "instance": "aaa", "boot": "enabled"});
    assert_eq!(
        restart(&fake, "42-restart", &mixed).map_err(|failure| failure.code),
        Err("process.input_invalid")
    );
    assert_eq!(fake.calls(), Vec::<String>::new());
}

#[test]
fn a_container_restart_runs_once_and_reads_a_new_start_back() {
    let input = json!({"process_id": 417, "runtime": "docker", "name": "orbit-process-417-web", "instance": "c1@T1", "image": "nginx:1"});
    let fake = Fake::default().on(
        "/usr/bin/docker container inspect",
        &[
            Ok("/orbit-process-417-web c1 T1 running nginx:1"),
            Ok("/orbit-process-417-web c1 T1 running nginx:1"),
            Ok("/orbit-process-417-web c1 T2 running nginx:1"),
        ],
    );
    let restart = restart(&fake, "42-restart", &input).expect("restarts");
    assert_eq!(
        (restart.restarted, restart.instance.as_str()),
        ("now", "c1@T2")
    );
    assert_eq!(
        fake.changed(),
        vec![
            "attempt 42-restart".to_owned(),
            "/usr/bin/docker container restart orbit-process-417-web".to_owned()
        ]
    );
    let replay = Fake::default().on(
        "/usr/bin/docker container inspect",
        &[Ok("/orbit-process-417-web c1 T2 running nginx:1")],
    );
    replay.begin_attempt("42-restart").expect("attempt");
    assert_eq!(
        super::restart(&replay, "42-restart", &input).map(|done| done.restarted),
        Ok("recovered")
    );
    assert_eq!(restarts(&replay), 0);
}

#[test]
fn the_gateway_signed_restart_inputs_parse_and_run() {
    let read: serde_json::Value = serde_json::from_str(include_str!(
        "../../../contract/fixtures/executor-process-read-instance.json"
    ))
    .expect("fixture");
    let fake = Fake::default()
        .on(
            "/usr/bin/systemctl show --property=LoadState",
            &[Ok(&show("loaded", "active", "enabled"))],
        )
        .on(INVOCATION, &[Ok("0f6e2bd1c3a54e7f9b8d2c1a0e9f8d7c")]);
    assert_eq!(
        read_instance(&fake, &read["fields"]["input"]).map(|read| read.instance),
        Ok("0f6e2bd1c3a54e7f9b8d2c1a0e9f8d7c".to_owned())
    );
    let order: serde_json::Value = serde_json::from_str(include_str!(
        "../../../contract/fixtures/executor-process-restart.json"
    ))
    .expect("fixture");
    let fake = Fake::default()
        .on(
            "/usr/bin/systemctl show --property=LoadState",
            &[Ok(&show("loaded", "active", "enabled"))],
        )
        .on(
            INVOCATION,
            &[
                Ok("0f6e2bd1c3a54e7f9b8d2c1a0e9f8d7c"),
                Ok("0f6e2bd1c3a54e7f9b8d2c1a0e9f8d7c"),
                Ok("1a"),
            ],
        );
    assert_eq!(
        restart(&fake, "42-restart", &order["fields"]["input"]).map(|done| done.restarted),
        Ok("now")
    );
}

#[test]
fn an_order_stays_spent_across_a_reboot_before_its_done_record() {
    // A stopped on-demand unit: its pinned run is the empty InvocationID.
    let input = json!({"process_id": 417, "runtime": "systemd", "name": UNIT, "instance": "", "boot": "disabled"});
    let first = Fake::default()
        .on(
            "/usr/bin/systemctl show --property=LoadState",
            &[
                Ok(&show("loaded", "inactive", "disabled")),
                Ok(&show("loaded", "active", "disabled")),
            ],
        )
        .on(INVOCATION, &[Ok(""), Ok("bbb")]);
    assert_eq!(
        restart(&first, "42-restart", &input).map(|done| done.restarted),
        Ok("now")
    );
    assert_eq!(restarts(&first), 1);
    // The Node reboots before the journal's `done` is durable: the attempt is on disk, the disabled unit is inactive with
    // the same empty InvocationID, and the executor replays the same order.
    let rebooted = Fake::default()
        .on(
            "/usr/bin/systemctl show --property=LoadState",
            &[Ok(&show("loaded", "inactive", "disabled"))],
        )
        .on(INVOCATION, &[Ok("")]);
    rebooted
        .attempts
        .borrow_mut()
        .extend(first.attempts.borrow().iter().cloned());
    assert_eq!(
        restart(&rebooted, "42-restart", &input).map_err(|failure| failure.code),
        Err("process.restart_unresolved")
    );
    assert_eq!(restarts(&rebooted), 0, "the replay never restarts again");
    // A new Operation restarts once.
    let fresh = Fake::default()
        .on(
            "/usr/bin/systemctl show --property=LoadState",
            &[
                Ok(&show("loaded", "inactive", "disabled")),
                Ok(&show("loaded", "active", "disabled")),
            ],
        )
        .on(INVOCATION, &[Ok(""), Ok("ccc")]);
    fresh
        .attempts
        .borrow_mut()
        .extend(rebooted.attempts.borrow().iter().cloned());
    assert_eq!(
        restart(&fresh, "43-restart", &input).map(|done| done.restarted),
        Ok("now")
    );
    assert_eq!(restarts(&fresh), 1);
}

#[test]
fn a_new_run_that_is_still_activating_is_waited_for_without_another_restart() {
    let fake = Fake::default()
        .on(
            "/usr/bin/systemctl show --property=LoadState",
            &[
                Ok(&show("loaded", "active", "enabled")),
                Ok(&show("loaded", "activating", "enabled")),
                Ok(&show("loaded", "active", "enabled")),
            ],
        )
        .on(INVOCATION, &[Ok("aaa"), Ok("bbb")]);
    assert_eq!(
        restart(&fake, "42-restart", &restart_input("enabled")).map(|done| done.instance),
        Ok("bbb".to_owned())
    );
    assert_eq!(restarts(&fake), 1);
    let input = json!({"process_id": 417, "runtime": "docker", "name": "orbit-process-417-web", "instance": "c1@T1", "image": "nginx:1"});
    let container = Fake::default().on(
        "/usr/bin/docker container inspect",
        &[
            Ok("/orbit-process-417-web c1 T1 running nginx:1"),
            Ok("/orbit-process-417-web c1 T2 restarting nginx:1"),
            Ok("/orbit-process-417-web c1 T3 running nginx:1"),
        ],
    );
    assert_eq!(
        restart(&container, "42-restart", &input).map(|done| done.instance),
        Ok("c1@T3".to_owned())
    );
    assert_eq!(restarts(&container), 1);
}

#[test]
fn each_failed_phase_carries_its_own_code() {
    let boot = Fake::default()
        .on(
            "/usr/bin/systemctl show --property=LoadState",
            &[Ok(&show("loaded", "active", "disabled"))],
        )
        .on("/usr/bin/systemctl enable", &[Err("Failed to enable")]);
    assert_eq!(
        restart(&boot, "42-restart", &restart_input("enabled")).map_err(|failure| failure.code),
        Err("process.boot_failed")
    );
    assert_eq!(restarts(&boot), 0);
    let before = Fake::default().on(
        "/usr/bin/systemctl show --property=LoadState",
        &[Err("Failed to connect to bus")],
    );
    assert_eq!(
        restart(&before, "42-restart", &restart_input("enabled")).map_err(|failure| failure.code),
        Err("process.apply_failed")
    );
    let after = Fake::default()
        .on(
            "/usr/bin/systemctl show --property=LoadState",
            &[
                Ok(&show("loaded", "active", "enabled")),
                Ok(&show("loaded", "active", "enabled")),
                Err("Failed to connect to bus"),
            ],
        )
        .on(INVOCATION, &[Ok("aaa")]);
    assert_eq!(
        restart(&after, "42-restart", &restart_input("enabled")).map_err(|failure| failure.code),
        Err("process.read_failed")
    );
}

#[test]
fn the_receipt_outputs_are_the_contract_fixture() {
    // The executor serializes each answer with `serde_json::json!(…).to_string()`; member order is not part of the contract
    // (`preserve_order` changes it), so the fixture and the answer compare as parsed values.
    let fixture: serde_json::Value = serde_json::from_str(include_str!(
        "../../../contract/fixtures/executor-process-restart-output.json"
    ))
    .expect("fixture");
    let parsed = |text: &serde_json::Value| -> serde_json::Value {
        serde_json::from_str(text.as_str().expect("an output string")).expect("output JSON")
    };
    let serialized = |value: serde_json::Value| -> serde_json::Value {
        serde_json::from_str(&value.to_string()).expect("output JSON")
    };
    assert_eq!(
        parsed(&fixture["read"]),
        serialized(json!(Instance {
            instance: "aaa".to_owned()
        }))
    );
    for restarted in ["now", "already", "recovered"] {
        assert_eq!(
            parsed(&fixture[restarted]),
            serialized(json!(Restart {
                restarted,
                instance: "bbb".to_owned()
            }))
        );
    }
}

#[test]
fn an_enabled_unit_not_yet_started_after_a_reboot_is_waited_for_and_never_restarted_again() {
    // The order's attempt is on disk; the Node rebooted and systemd has not started the enabled unit yet: no run at all.
    let fake = Fake::default()
        .on(
            "/usr/bin/systemctl show --property=LoadState",
            &[
                Ok(&show("loaded", "inactive", "enabled")),
                Ok(&show("loaded", "inactive", "enabled")),
                Ok(&show("loaded", "inactive", "enabled")),
                Ok(&show("loaded", "activating", "enabled")),
                Ok(&show("loaded", "active", "enabled")),
            ],
        )
        .on(INVOCATION, &[Ok(""), Ok(""), Ok("bbb"), Ok("bbb")]);
    fake.begin_attempt("42-restart").expect("attempt");
    assert_eq!(
        restart(&fake, "42-restart", &restart_input("enabled"))
            .map(|done| (done.restarted, done.instance)),
        Ok(("recovered", "bbb".to_owned()))
    );
    assert_eq!(restarts(&fake), 0);
    // It never starts within the bound: unresolved, still without a restart.
    let never = Fake::default()
        .on(
            "/usr/bin/systemctl show --property=LoadState",
            &[Ok(&show("loaded", "inactive", "enabled"))],
        )
        .on(INVOCATION, &[Ok("")]);
    never.begin_attempt("42-restart").expect("attempt");
    assert_eq!(
        restart(&never, "42-restart", &restart_input("enabled")).map_err(|failure| failure.code),
        Err("process.restart_unresolved")
    );
    assert_eq!(restarts(&never), 0);
}

#[test]
fn a_run_that_changes_while_the_boot_policy_applies_is_left_alone() {
    for (file, boot, verb) in [
        ("disabled", "enabled", "enable"),
        ("enabled", "disabled", "disable"),
    ] {
        let fake = Fake::default()
            .on(
                "/usr/bin/systemctl show --property=LoadState",
                &[
                    Ok(&show("loaded", file, file)),
                    Ok(&show("loaded", "active", boot)),
                ],
            )
            // The pinned run was `aaa`; it was replaced by `zzz` while systemctl changed the boot policy.
            .on(INVOCATION, &[Ok("zzz")]);
        assert_eq!(
            restart(&fake, "42-restart", &restart_input(boot)).map(|done| done.restarted),
            Ok("already"),
            "{verb}"
        );
        assert_eq!(restarts(&fake), 0, "{verb}");
        let calls = fake.calls();
        let policy = calls
            .iter()
            .position(|call| call.starts_with(&format!("/usr/bin/systemctl {verb} ")))
            .expect("policy");
        let read = calls
            .iter()
            .position(|call| call.starts_with(INVOCATION))
            .expect("read");
        assert!(
            policy < read,
            "{verb}: the run is read after the boot policy"
        );
    }
}
