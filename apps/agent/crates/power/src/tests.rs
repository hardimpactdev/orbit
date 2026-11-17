use super::*;
use std::sync::Mutex;

struct Fake {
    instance: Mutex<Instance>,
    root: PathBuf,
    actions: Mutex<Vec<String>>,
}
impl Api for Fake {
    fn instance(&self, _: &str) -> io::Result<Option<Instance>> {
        Ok(Some(self.instance.lock().expect("instance").clone()))
    }
    fn change(&self, _: &Instance, action: &str) -> io::Result<()> {
        self.actions
            .lock()
            .expect("actions")
            .push(action.to_owned());
        let frozen = action == "freeze";
        fs::write(
            self.root.join("cgroup.freeze"),
            if frozen { "1" } else { "0" },
        )?;
        fs::write(
            self.root.join("cgroup.events"),
            if frozen { "frozen 1" } else { "frozen 0" },
        )?;
        self.instance.lock().expect("instance").status = match action {
            "freeze" => "frozen",
            "stop" => "stopped",
            _ => "running",
        }
        .to_owned();
        Ok(())
    }
}
fn fixture() -> (tempfile::TempDir, Power, Fake) {
    let dir = tempfile::tempdir().expect("tempdir");
    let power = Power {
        state: dir.path().join("state"),
        cgroups: dir.path().join("cgroups"),
        proc: dir.path().join("proc"),
        markers: dir.path().join("markers"),
        incus: Incus(dir.path().join("socket")),
    };
    let root = power.cgroups.join("lxc.payload.orbit-instances_instance-2");
    fs::create_dir_all(root.join("child")).expect("cgroup");
    fs::create_dir_all(power.proc.join("sys/kernel/random")).expect("boot dir");
    fs::write(power.proc.join("sys/kernel/random/boot_id"), "boot-1").expect("boot");
    for path in [&root, &root.join("child")] {
        for (name, value) in [("cgroup.procs", ""), ("memory.swap.max", "0")] {
            fs::write(path.join(name), value).expect("file");
        }
    }
    for (name, value) in [
        ("cgroup.freeze", "0"),
        ("cgroup.events", "frozen 0"),
        ("memory.current", "1024"),
        ("memory.swap.current", "4096"),
        ("memory.max", "max"),
        ("memory.stat", "anon 0\nfile 0\n"),
        ("memory.reclaim", ""),
    ] {
        fs::write(root.join(name), value).expect("file");
    }
    fs::create_dir_all(power.proc.join("1")).expect("pid");
    let stat = format!("1 (init with spaces) S {} 99", vec!["0"; 18].join(" "));
    fs::write(power.proc.join("1/stat"), stat).expect("stat");
    let api = Fake {
        instance: Mutex::new(Instance {
            project: "orbit-instances".to_owned(),
            name: "instance-2".to_owned(),
            status: "running".to_owned(),
            incarnation: "uuid-1".to_owned(),
            pid: 1,
        }),
        root,
        actions: Mutex::new(Vec::new()),
    };
    (dir, power, api)
}
#[test]
fn limits_record_includes_every_non_max_value_before_open_and_restores_exactly() {
    let (_dir, power, api) = fixture();
    fs::write(api.root.join("child/memory.swap.max"), "123456").expect("finite limit");
    let identity = power
        .identity(&api.instance.lock().expect("instance"))
        .expect("identity");
    let base = power.state.join("2");
    private_dir(&base).expect("state");
    power.open_limits(&base, &identity).expect("open");
    let record: Limits = load(&base.join("limits.json")).expect("durable record");
    assert_eq!(record.opened.len(), 2);
    assert_eq!(
        read(&api.root.join("child/memory.swap.max")).expect("read"),
        "max"
    );
    power
        .open_limits(&base, &identity)
        .expect("recovery preserves original record");
    power.close_limits(&base, &identity).expect("close");
    assert_eq!(read(&api.root.join("memory.swap.max")).expect("read"), "0");
    assert_eq!(
        read(&api.root.join("child/memory.swap.max")).expect("read"),
        "123456"
    );
}
#[test]
fn atomic_publish_replaces_abandoned_stage_and_keeps_complete_json() {
    let dir = tempfile::tempdir().expect("temp");
    let path = dir.path().join("plan.json");
    fs::write(path.with_extension("tmp"), "incomplete").expect("crash stage");
    publish(&path, &vec![1, 2, 3]).expect("publish");
    assert_eq!(load::<Vec<u32>>(&path).expect("complete"), vec![1, 2, 3]);
    assert!(!path.with_extension("tmp").exists());
    assert_eq!(
        fs::metadata(&path).expect("meta").permissions().mode() & 0o777,
        0o600
    );
}
#[test]
fn frozen_recovery_keeps_the_existing_plan_and_does_not_freeze_twice() {
    let (_dir, power, api) = fixture();
    let first = power.sleep_with(&api, "2", "op1").expect("sleep");
    assert_eq!(first.plan, "ready");
    let path = power.state.join("2/plan.json");
    let before = fs::read(&path).expect("plan");
    let again = power.sleep_with(&api, "2", "op1").expect("recovery");
    assert_eq!(again.sleep, "frozen");
    assert_eq!(fs::read(&path).expect("kept plan"), before);
    assert_eq!(*api.actions.lock().expect("actions"), vec!["freeze"]);
    assert!(power.markers.join("app-instance-2.asleep").exists());
}
#[test]
fn recovery_after_freeze_before_plan_finishes_the_capture() {
    let (_dir, power, api) = fixture();
    let instance = api.instance.lock().expect("instance").clone();
    let identity = power.identity(&instance).expect("identity");
    let base = power.state.join("2");
    private_dir(&base).expect("state");
    power.open_limits(&base, &identity).expect("limits");
    api.change(&instance, "freeze").expect("freeze");
    assert_eq!(
        power.sleep_with(&api, "2", "op1").expect("recovery").plan,
        "ready"
    );
}
#[test]
fn lost_incus_freeze_is_refused_without_changing_limits_or_plan() {
    let (_dir, power, api) = fixture();
    fs::write(api.root.join("cgroup.freeze"), "1").expect("freeze");
    assert_eq!(
        power
            .sleep_with(&api, "2", "op1")
            .expect_err("refuse")
            .to_string(),
        UNMANAGED
    );
    assert_eq!(read(&api.root.join("memory.swap.max")).expect("limit"), "0");
    assert!(api.actions.lock().expect("actions").is_empty());
}
#[test]
fn exactly_32_mib_anonymous_is_allowed_but_more_stops_without_a_plan() {
    for (anon, expected) in [(RESIDUAL_ANON, "frozen"), (RESIDUAL_ANON + 1, "stopped")] {
        let (_dir, power, api) = fixture();
        fs::write(
            api.root.join("memory.stat"),
            format!("anon {anon}\nfile 0\n"),
        )
        .expect("anon");
        let receipt = power.sleep_with(&api, "2", "op1").expect("sleep");
        assert_eq!(receipt.sleep, expected);
        if expected == "stopped" {
            assert!(!power.state.join("2/plan.json").exists());
            assert!(!power.state.join("2/limits.json").exists());
            assert_eq!(
                *api.actions.lock().expect("actions"),
                vec!["freeze", "unfreeze", "stop"]
            );
        }
    }
}
#[test]
fn discard_is_idempotent_and_does_not_change_live_limits() {
    let (_dir, power, api) = fixture();
    power.sleep_with(&api, "2", "op1").expect("sleep");
    power.discard("2").expect("discard");
    power.discard("2").expect("repeat");
    assert!(!power.state.join("2/plan.json").exists());
    assert!(!power.state.join("2/limits.json").exists());
    assert_eq!(
        read(&api.root.join("memory.swap.max")).expect("limit"),
        "max"
    );
    assert!(power.discard("../2").is_err());
}

#[test]
fn whole_subtree_capture_records_every_process_without_name_selection() {
    let (_dir, power, api) = fixture();
    for (pid, group) in [(1, ""), (2, "child")] {
        let base = power.proc.join(pid.to_string());
        fs::create_dir_all(&base).expect("proc");
        fs::write(
            base.join("stat"),
            format!("{pid} (any process) S {} 99", vec!["0"; 18].join(" ")),
        )
        .expect("stat");
        fs::write(
            base.join("maps"),
            "00000000-00001000 r--p 00000000 00:00 0 arbitrary
",
        )
        .expect("maps");
        fs::write(base.join("pagemap"), (1_u64 << 62).to_le_bytes()).expect("swapped");
        fs::write(api.root.join(group).join("cgroup.procs"), pid.to_string()).expect("cgroup pid");
    }
    let receipt = power.sleep_with(&api, "2", "op1").expect("capture");
    assert_eq!(receipt.processes, 2);
    assert_eq!(receipt.mapping_bytes, 8192);
    let plan: Plan = load(&power.state.join("2/plan.json")).expect("plan");
    assert_eq!(
        plan.processes.iter().map(|p| p.pid).collect::<Vec<_>>(),
        vec![1, 2]
    );
}

struct Runner {
    power: std::sync::Arc<Power>,
    api: std::sync::Arc<Fake>,
}
impl orbit_executor_core::executor::Run for Runner {
    fn run(
        &self,
        order: &orbit_executor_core::order::VerifiedOrder,
    ) -> orbit_executor_core::journal::Outcome {
        match order.action() {
            orbit_executor_core::action::Action::Sleep => {
                self.power
                    .sleep_with(
                        self.api.as_ref(),
                        order.target().as_str(),
                        order.operation().as_str(),
                    )
                    .expect("power sleep");
            }
            orbit_executor_core::action::Action::Discard => self
                .power
                .discard(order.target().as_str())
                .expect("discard"),
            orbit_executor_core::action::Action::ProofNoop => panic!("power actions only"),
        }
        orbit_executor_core::journal::Outcome::Done
    }
}
fn signed_order(nonce: &str, action: &str) -> orbit_executor_core::order::VerifiedOrder {
    use base64::Engine as _;
    use ed25519_dalek::Signer as _;
    use orbit_executor_core::order::{check_with_target, Checks, PinnedKeys};
    let key = ed25519_dalek::SigningKey::from_bytes(&[7; 32]);
    let encoded = base64::engine::general_purpose::STANDARD;
    let payload = serde_json::to_vec(&serde_json::json!({"action":action,"node":"7","target":"2","operation":"op-power", "nonce":nonce,"expires_ms":6000})).expect("payload");
    let wire = format!(
        "{}.{}",
        encoded.encode(&payload),
        encoded.encode(key.sign(&payload).to_bytes())
    );
    let pinned = PinnedKeys::parse(&encoded.encode(key.verifying_key().as_bytes())).expect("key");
    let enabled = orbit_executor_core::action::Enabled::parse("power").expect("family");
    check_with_target(
        wire.as_bytes(),
        &Checks {
            keys: &pinned,
            enabled: &enabled,
            node: &"7".parse().expect("node"),
        },
        1000,
        |_, _| true,
    )
    .expect("signed power order")
}
#[test]
fn operation_idempotency_and_open_journal_recovery_run_real_power_actions() {
    use orbit_executor_core::{
        executor::Executor,
        journal::{DoneKey, Record},
    };
    let (_dir, power, api) = fixture();
    let power = std::sync::Arc::new(power);
    let api = std::sync::Arc::new(api);
    let runner = || Runner {
        power: std::sync::Arc::clone(&power),
        api: std::sync::Arc::clone(&api),
    };
    let path = power.state.join("journal");
    private_dir(&power.state).expect("state");
    let first = signed_order("00000000000000000000000000000001", "sleep");
    // A process crash after plan publication leaves the open record without a done record.
    power
        .sleep_with(api.as_ref(), "2", "op-power")
        .expect("sleep before crash");
    let before = fs::read(power.state.join("2/plan.json")).expect("plan");
    let key = DoneKey {
        operation: first.operation().clone(),
        action: first.action(),
        target: first.target().clone(),
    };
    fs::write(
        &path,
        format!(
            "{}\n",
            serde_json::to_string(&Record::Open {
                nonce: first.nonce(),
                key,
                expires_ms: 6000
            })
            .expect("record")
        ),
    )
    .expect("open journal");
    let (executor, recovered) = Executor::start(&path, runner(), || 1001, |_| {}).expect("recover");
    assert_eq!(recovered.len(), 1);
    let repeat = executor
        .handle(
            &signed_order("00000000000000000000000000000002", "sleep"),
            1001,
        )
        .expect("repeat");
    assert!(repeat.repeat);
    assert_eq!(
        fs::read(power.state.join("2/plan.json")).expect("plan"),
        before
    );
    assert_eq!(*api.actions.lock().expect("actions"), vec!["freeze"]);
    let discard = executor
        .handle(
            &signed_order("00000000000000000000000000000003", "discard"),
            1001,
        )
        .expect("discard");
    assert!(!discard.repeat);
    assert!(
        executor
            .handle(
                &signed_order("00000000000000000000000000000004", "discard"),
                1001
            )
            .expect("repeat discard")
            .repeat
    );
    assert!(!power.state.join("2/plan.json").exists());
}

#[test]
fn recovery_after_the_swap_full_stop_finishes_successfully() {
    let (_dir, power, api) = fixture();
    api.instance.lock().expect("instance").status = "stopped".to_owned();
    let receipt = power
        .sleep_with(&api, "2", "op1")
        .expect("finish stopped sleep");
    assert_eq!(receipt.sleep, "stopped");
    assert_eq!(receipt.ram_bytes, 0);
    assert!(power.markers.join("app-instance-2.asleep").exists());
}

#[test]
fn threaded_subgroups_without_a_memory_controller_do_not_block_sleep() {
    let (_dir, power, api) = fixture();
    fs::remove_file(api.root.join("child/memory.swap.max")).expect("threaded controller");
    assert_eq!(
        power.sleep_with(&api, "2", "op1").expect("sleep").sleep,
        "frozen"
    );
    let limits: Limits = load(&power.state.join("2/limits.json")).expect("record");
    assert_eq!(limits.opened.len(), 1);
}
