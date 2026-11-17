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

#[test]
fn swap_full_resumes_a_nested_fleet_and_never_stops_it() {
    let (_dir, power, mut api) = fixture();
    let nested = power
        .cgroups
        .join("lxc.payload.orbit-instances-nested_instance-2");
    fs::rename(&api.root, &nested).expect("nested cgroup");
    api.root = nested;
    api.instance.lock().expect("instance").project = "orbit-instances-nested".to_owned();
    fs::write(
        api.root.join("memory.stat"),
        format!("anon {}\nfile 0\n", RESIDUAL_ANON + 1),
    )
    .expect("full swap");
    assert_eq!(
        power
            .sleep_with(&api, "2", "op1")
            .expect_err("nested never stops")
            .to_string(),
        "instance.container_freeze_failed"
    );
    assert_eq!(
        *api.actions.lock().expect("actions"),
        vec!["freeze", "unfreeze"]
    );
    assert_eq!(api.instance.lock().expect("instance").status, "running");
    assert!(!power.state.join("2/plan.json").exists());
    assert!(!power.state.join("2/limits.json").exists());
    assert!(!power.markers.join("app-instance-2.asleep").exists());
}

#[test]
fn a_refused_incus_freeze_restores_all_original_swap_limits() {
    struct RefuseFreeze<'a>(&'a Fake);
    impl Api for RefuseFreeze<'_> {
        fn instance(&self, id: &str) -> io::Result<Option<Instance>> {
            self.0.instance(id)
        }
        fn change(&self, _: &Instance, _: &str) -> io::Result<()> {
            Err(invalid("freeze refused"))
        }
    }
    let (_dir, power, api) = fixture();
    fs::write(api.root.join("child/memory.swap.max"), "1234").expect("finite limit");
    assert!(power.sleep_with(&RefuseFreeze(&api), "2", "op1").is_err());
    assert_eq!(
        read(&api.root.join("memory.swap.max")).expect("root limit"),
        "0"
    );
    assert_eq!(
        read(&api.root.join("child/memory.swap.max")).expect("child limit"),
        "1234"
    );
    assert!(!power.state.join("2/limits.json").exists());
}

/// Answers each request on a stand-in Incus socket and returns the request lines and bodies it saw.
fn incus_requests(action: &str) -> Vec<String> {
    use std::io::{BufRead as _, BufReader, Read as _, Write as _};
    use std::os::unix::net::UnixListener;
    let dir = tempfile::tempdir().expect("tempdir");
    let socket = dir.path().join("incus.socket");
    let listener = UnixListener::bind(&socket).expect("bind the stand-in socket");
    let server = std::thread::spawn(move || {
        let mut seen = Vec::new();
        for answer in [
            r#"{"operation":"/1.0/operations/1"}"#,
            r#"{"metadata":{"status_code":200}}"#,
        ] {
            let (stream, _) = listener.accept().expect("accept");
            let mut reader = BufReader::new(stream);
            let mut line = String::new();
            reader.read_line(&mut line).expect("request line");
            seen.push(line.trim_end().to_owned());
            let mut length = 0;
            loop {
                let mut header = String::new();
                reader.read_line(&mut header).expect("header");
                if header == "\r\n" {
                    break;
                }
                if let Some(value) = header.to_ascii_lowercase().strip_prefix("content-length:") {
                    length = value.trim().parse().expect("length");
                }
            }
            let mut body = vec![0; length];
            reader.read_exact(&mut body).expect("body");
            if length > 0 {
                seen.push(String::from_utf8(body).expect("utf-8 body"));
            }
            write!(
                reader.get_mut(),
                "HTTP/1.1 202 Accepted\r\nContent-Length: {}\r\n\r\n{answer}",
                answer.len()
            )
            .expect("answer");
        }
        seen
    });
    let instance = Instance {
        project: "orbit-instances".to_owned(),
        name: "instance-2".to_owned(),
        status: "running".to_owned(),
        incarnation: "u".to_owned(),
        pid: 1,
    };
    let _ = incus::Incus(socket).change(&instance, action);
    server.join().expect("stand-in socket")
}

#[test]
fn a_stop_keeps_the_thirty_second_clean_stop_allowance() {
    assert_eq!(
        incus::bounds("stop"),
        incus::Bounds {
            action: 30,
            wait: 35,
            read: 40
        }
    );
    let seen = incus_requests("stop");
    assert!(seen[1].contains(r#""timeout":30"#), "{seen:?}");
    assert_eq!(seen[2], "GET /1.0/operations/1/wait?timeout=35 HTTP/1.1");
}

#[test]
fn freeze_and_unfreeze_stay_short() {
    for action in ["freeze", "unfreeze"] {
        assert_eq!(
            incus::bounds(action),
            incus::Bounds {
                action: 5,
                wait: 5,
                read: 6
            }
        );
        let seen = incus_requests(action);
        assert!(seen[1].contains(r#""timeout":5"#), "{seen:?}");
        assert_eq!(seen[2], "GET /1.0/operations/1/wait?timeout=5 HTTP/1.1");
    }
}
