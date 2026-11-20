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
static POOL: std::sync::OnceLock<pool::Pool> = std::sync::OnceLock::new();

fn fixture() -> (tempfile::TempDir, Power, Fake) {
    let dir = tempfile::tempdir().expect("tempdir");
    let power = Power {
        state: dir.path().join("state"),
        cgroups: dir.path().join("cgroups"),
        proc: dir.path().join("proc"),
        markers: dir.path().join("markers"),
        incus: Incus(dir.path().join("socket")),
        // One pool for every fixture, as on a Node: a pool per test would exhaust the test host's threads.
        pool: POOL
            .get_or_init(|| pool::Pool::new(pool::MIN_THREADS))
            .clone(),
        restore_bound: Duration::from_secs(2),
        web_port: 0,
        ready_bound: Duration::from_millis(1500),
        readers: std::sync::Mutex::default(),
        reader_bound: Duration::from_secs(10),
        reader: wake::restore,
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
            address: None,
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
            orbit_executor_core::action::Action::Wake => {
                self.power
                    .wake_with(self.api.as_ref(), order.target().as_str())
                    .expect("power wake");
            }
            orbit_executor_core::action::Action::ProofNoop
            | orbit_executor_core::action::Action::UfwEnsureRule
            | orbit_executor_core::action::Action::TlsEnsureCsr
            | orbit_executor_core::action::Action::RoutePublishSite
            | orbit_executor_core::action::Action::DnsPublishRecords
            | orbit_executor_core::action::Action::InstanceClone
            | orbit_executor_core::action::Action::InstancePrepare => {
                panic!("power actions only")
            }
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
                expires_ms: 6000,
                power_check: orbit_executor_core::order::PowerCheck::Default,
                input: serde_json::Value::Null,
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
fn a_capture_failure_after_freeze_reports_incomplete_sleep() {
    let (_dir, power, api) = fixture();
    fs::remove_file(api.root.join("memory.stat")).expect("remove stats to interrupt capture");

    let error = power
        .sleep_with(&api, "2", "op1")
        .expect_err("incomplete sleep");
    assert_eq!(error.to_string(), "instance.container_sleep_incomplete");
    assert_eq!(api.instance.lock().expect("instance").status, "frozen");
    assert!(power.markers.join("app-instance-2.asleep").exists());
    assert!(power.state.join("2/limits.json").exists());
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

#[test]
fn caddy_admin_requires_the_full_loopback_authority() {
    use std::io::{BufRead as _, BufReader};
    use std::net::TcpListener;

    let listener = TcpListener::bind("127.0.0.1:0").expect("bind loopback");
    let address = listener.local_addr().expect("listener address");
    let server = std::thread::spawn(move || {
        let (mut stream, _) = listener.accept().expect("admin connection");
        stream
            .set_read_timeout(Some(Duration::from_secs(1)))
            .expect("bound fixture read");
        let mut lines = BufReader::new(&mut stream).lines();
        let request = lines.next().expect("request line").expect("read request");
        let host = lines.next().expect("host header").expect("read authority");
        let _end = lines.next().expect("blank line").expect("read terminator");
        let response: &[u8] = if request == "GET /config/ HTTP/1.0"
            && host == format!("Host: {address}")
        {
            b"HTTP/1.0 200 OK\r\n\r\n{\"match\":[{\"file\":{\"root\":\"/markers\",\"try_files\":[\"/app-instance-2.asleep\"]}}],\"handle\":[{\"handler\":\"reverse_proxy\",\"rewrite\":{\"method\":\"GET\",\"uri\":\"/api/v1/runtime-activations/app-instance/2\"}}]}"
        } else {
            b"HTTP/1.0 403 Forbidden\r\n\r\n"
        };
        stream.write_all(response).expect("write response");
    });

    let response = caddy_config(address).expect("Caddy admin response");
    server.join().expect("fixture server ended");
    assert!(route_checks(
        &response,
        Path::new("/markers/app-instance-2.asleep")
    ));
}

/// Real compiled routes from production server srv2: the activation proxy sits in a `subroute`, behind a `vars` route.
const PRODUCTION_ROUTES: [(&str, &str); 3] = [
    (
        "1830",
        include_str!("fixtures/caddy-production-srv2/route-53.json"),
    ),
    (
        "4",
        include_str!("fixtures/caddy-production-srv2/route-50.json"),
    ),
    (
        "3733",
        include_str!("fixtures/caddy-production-srv2/route-25.json"),
    ),
];

fn production_checks(id: &str, route: &str) -> bool {
    let response = format!("HTTP/1.0 200 OK\r\n\r\n{{\"apps\":{{\"http\":{{\"servers\":{{\"srv2\":{{\"routes\":[{route}]}}}}}}}}}}");
    route_checks(
        response.as_bytes(),
        Path::new(&format!(
            "/data/caddy/orbit/hibernation/app-instance-{id}.asleep"
        )),
    )
}

#[test]
fn caddy_guard_accepts_production_routes_and_refuses_their_broken_activation() {
    for (id, route) in PRODUCTION_ROUTES {
        assert!(
            production_checks(id, route),
            "production route of Instance {id}"
        );
        let uri = format!("/api/v1/runtime-activations/app-instance/{id}");
        let rewrite = format!("\"rewrite\":{{\"method\":\"GET\",\"uri\":\"{uri}\"}},");
        assert!(route.contains(&rewrite));
        for broken in [
            route.replace(&rewrite, ""),
            route.replace(&uri, &format!("{uri}0")),
            route.replace("\"method\":\"GET\"", "\"method\":\"POST\""),
            route.replace(
                "\"handler\":\"reverse_proxy\",\"headers\"",
                "\"handler\":\"static_response\",\"headers\"",
            ),
        ] {
            assert!(
                !production_checks(id, &broken),
                "broken route of Instance {id}: {broken}"
            );
        }
        assert!(!production_checks(&format!("{id}0"), route));
    }
}

#[test]
fn caddy_guard_refuses_a_catch_all_ahead_of_the_activation_route() {
    // Route 50 with its app proxy moved ahead of the activation route: Caddy sends the marked visit to the sleeping app.
    let (id, route) = PRODUCTION_ROUTES[1];
    let mut config: serde_json::Value = serde_json::from_str(route).expect("route 50");
    let routes = config["handle"][0]["routes"]
        .as_array_mut()
        .expect("site routes");
    let app = routes.pop().expect("app route");
    assert!(app.get("match").is_none());
    routes.insert(2, app);
    assert!(!production_checks(id, &config.to_string()));
}

#[test]
fn caddy_must_have_the_exact_markers_matcher_in_its_loaded_config() {
    let instance_marker = Path::new("/markers/app-instance-2.asleep");
    assert!(route_checks(
        b"HTTP/1.0 200 OK\r\n\r\n{\"match\":[{\"file\":{\"root\":\"/markers\",\"try_files\":[\"/app-instance-2.asleep\"]}}],\"handle\":[{\"handler\":\"reverse_proxy\",\"rewrite\":{\"method\":\"GET\",\"uri\":\"/api/v1/runtime-activations/app-instance/2\"}}]}",
        instance_marker,
    ));
    // The exact matcher alone is not enough: its route must forward the visit to activation (ADR 0266).
    assert!(!route_checks(
        b"HTTP/1.0 200 OK\r\n\r\n{\"match\":[{\"file\":{\"root\":\"/markers\",\"try_files\":[\"/app-instance-2.asleep\"]}}]}",
        instance_marker,
    ));
    assert!(!route_checks(
        b"HTTP/1.0 200 OK\r\n\r\n{\"match\":[{\"file\":{\"root\":\"/markers\",\"try_files\":[\"/app-instance-2.asleep\"]}}],\"handle\":[{\"handler\":\"static_response\",\"status_code\":503}]}",
        instance_marker,
    ));
    assert!(!route_checks(
        b"HTTP/1.0 200 OK\r\n\r\n{\"match\":[{\"file\":{\"root\":\"/markers\",\"try_files\":[\"/app-instance-2.asleep\"]}}],\"handle\":[{\"handler\":\"reverse_proxy\",\"rewrite\":{\"uri\":\"/api/v1/runtime-activations/app-instance/20\"}}]}",
        instance_marker,
    ));
    // Caddy's `log_skip` for the same matcher passes the visit on, so the activation proxy after it still runs.
    assert!(route_checks(
        b"HTTP/1.0 200 OK\r\n\r\n{\"routes\":[{\"match\":[{\"file\":{\"root\":\"/markers\",\"try_files\":[\"/app-instance-2.asleep\"]},\"not\":[{\"header\":{\"X-Probe\":[\"1\"]}}]}],\"handle\":[{\"handler\":\"vars\",\"skip_log\":true}]},{\"match\":[{\"file\":{\"root\":\"/markers\",\"try_files\":[\"/app-instance-2.asleep\"]}}],\"handle\":[{\"handler\":\"reverse_proxy\",\"rewrite\":{\"method\":\"GET\",\"uri\":\"/api/v1/runtime-activations/app-instance/2\"}}]}]}",
        instance_marker,
    ));
    // Review 2838 (M10): a terminal 503 before the activation proxy answers the visitor, so the proxy never runs.
    assert!(!route_checks(
        b"HTTP/1.0 200 OK\r\n\r\n{\"match\":[{\"file\":{\"root\":\"/markers\",\"try_files\":[\"/app-instance-2.asleep\"]}}],\"handle\":[{\"handler\":\"static_response\",\"status_code\":503},{\"handler\":\"reverse_proxy\",\"rewrite\":{\"uri\":\"/api/v1/runtime-activations/app-instance/2\"}}]}",
        instance_marker,
    ));
    // Review 2838 (M10): an ordinary proxy without a rewrite is refused, not a panic.
    assert!(!route_checks(
        b"HTTP/1.0 200 OK\r\n\r\n{\"match\":[{\"file\":{\"root\":\"/markers\",\"try_files\":[\"/app-instance-2.asleep\"]}}],\"handle\":[{\"handler\":\"reverse_proxy\",\"upstreams\":[{\"dial\":\"127.0.0.1:5173\"}]}]}",
        instance_marker,
    ));
    assert!(!route_checks(
        b"HTTP/1.0 200 OK\r\n\r\n{\"match\":[{\"file\":{\"root\":\"/markers\",\"try_files\":[\"/app-instance-20.asleep\"]}}]}",
        instance_marker,
    ));
    assert!(!route_checks(
        b"HTTP/1.0 200 OK\r\n\r\n{\"match\":[{\"file\":{\"root\":\"/markers\",\"try_files\":[\"/app-instance-2.asleep.old\"]}}]}",
        instance_marker,
    ));
    assert!(!route_checks(
        b"HTTP/1.0 200 OK\r\n\r\n{\"try_files\":[\"/app-instance-2.asleep\"]}",
        instance_marker,
    ));
    assert!(!route_checks(
        b"HTTP/1.1 503\r\n\r\n/app-instance-2.asleep",
        instance_marker
    ));
}

#[test]
fn lifecycle_resume_does_not_need_the_old_app_and_leaves_the_marker() {
    let (_dir, power, api) = fixture();
    power.sleep_with(&api, "2", "op1").expect("freeze");
    assert!(api.instance.lock().expect("instance").address.is_none());
    power
        .wake_checked(&api, "2", WebProbe::Lifecycle, None)
        .expect("resume without the app");
    assert_eq!(api.instance.lock().expect("instance").status, "running");
    assert!(power.markers.join("app-instance-2.asleep").exists());
    assert_eq!(
        read(&api.root.join("memory.swap.max")).expect("restored swap limit"),
        "0"
    );
}

#[test]
fn signed_discard_cannot_delete_the_state_of_a_frozen_target() {
    let (_dir, power, api) = fixture();
    power.sleep_with(&api, "2", "op1").expect("freeze");
    assert!(power.discard_stopped_with(&api, "2").is_err());
    assert!(power.state.join("2/limits.json").exists());
    api.instance.lock().expect("instance").status = "stopped".to_owned();
    power
        .discard_stopped_with(&api, "2")
        .expect("discard after stop");
    assert!(!power.state.join("2/limits.json").exists());
}

#[test]
fn the_asleep_marker_is_visible_before_incus_freezes_the_container() {
    struct CheckMarker<'a> {
        api: &'a Fake,
        marker: PathBuf,
    }
    impl Api for CheckMarker<'_> {
        fn instance(&self, id: &str) -> io::Result<Option<Instance>> {
            self.api.instance(id)
        }
        fn change(&self, instance: &Instance, action: &str) -> io::Result<()> {
            if action == "freeze" && !self.marker.is_file() {
                return Err(io::Error::other("asleep marker missing before freeze"));
            }
            self.api.change(instance, action)
        }
    }
    let (_dir, power, api) = fixture();
    let checked = CheckMarker {
        api: &api,
        marker: power.marker("2"),
    };
    assert_eq!(
        power.sleep_with(&checked, "2", "op1").expect("sleep").sleep,
        "frozen"
    );
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
        address: None,
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

/// A web port that answers every request with `status`, as the container's web server would.
fn web(status: u16) -> u16 {
    let listener = std::net::TcpListener::bind("127.0.0.1:0").expect("listener");
    let port = listener.local_addr().expect("address").port();
    std::thread::spawn(move || {
        for stream in listener.incoming() {
            let Ok(mut stream) = stream else { continue };
            let mut request = [0; 256];
            // HTTP headers can arrive in several TCP reads. Closing with unread request bytes resets the connection,
            // which can discard our response and make otherwise unrelated wake tests fail under parallel scheduling.
            let mut received = 0;
            while received < request.len() && !request[..received].ends_with(b"\r\n\r\n") {
                match std::io::Read::read(&mut stream, &mut request[received..]) {
                    Ok(0) | Err(_) => break,
                    Ok(bytes) => received += bytes,
                }
            }
            if !request[..received].ends_with(b"\r\n\r\n") {
                continue;
            }
            let _ = write!(stream, "HTTP/1.1 {status} X\r\nContent-Length: 0\r\n\r\n");
        }
    });
    port
}
/// A slept fixture whose readiness check answers `status`.
fn asleep(status: u16) -> (tempfile::TempDir, Power, Fake) {
    let (dir, mut power, api) = fixture();
    power.web_port = web(status);
    api.instance.lock().expect("instance").address = Some([127, 0, 0, 1].into());
    power.sleep_with(&api, "2", "op1").expect("sleep");
    (dir, power, api)
}
fn marker(power: &Power) -> PathBuf {
    power.markers.join("app-instance-2.asleep")
}
fn swap_limits(api: &Fake) -> Vec<String> {
    [api.root.clone(), api.root.join("child")]
        .iter()
        .map(|p| read(&p.join("memory.swap.max")).expect("limit"))
        .collect()
}

#[test]
fn wake_unfreezes_through_incus_closes_exactly_the_recorded_limits_and_removes_the_marker() {
    let (_dir, power, api) = asleep(200);
    assert_eq!(swap_limits(&api), ["max", "max"]);
    fs::write(api.root.join("memory.swap.max"), "max").expect("an unrecorded limit");
    let receipt = power.wake_with(&api, "2").expect("wake");
    assert_eq!(receipt.restore, "complete");
    assert_eq!(
        *api.actions.lock().expect("actions"),
        ["freeze", "unfreeze"]
    );
    assert_eq!(swap_limits(&api), ["0", "0"]);
    for gone in [
        marker(&power),
        power.state.join("2/plan.json"),
        power.state.join("2/limits.json"),
    ] {
        assert!(!gone.exists(), "{gone:?}");
    }
}

/// Plant this test process as the container's only process, with `pages` swapped pages of a real buffer in its plan.
fn plant_self(power: &Power, api: &Fake, buffer: &[u8], pages: u64) -> u64 {
    let page = 4096_u64;
    let start = u64::try_from(buffer.as_ptr().addr())
        .expect("address fits u64")
        .div_ceil(page)
        * page;
    let pid = std::process::id();
    let base = power.proc.join(pid.to_string());
    fs::create_dir_all(&base).expect("proc");
    fs::write(
        base.join("stat"),
        format!("{pid} (self) S {} 77", vec!["0"; 18].join(" ")),
    )
    .expect("stat");
    fs::write(
        base.join("maps"),
        format!(
            "{start:x}-{:x} rw-p 00000000 00:00 0\n",
            start + pages * page
        ),
    )
    .expect("maps");
    let pagemap = File::create(base.join("pagemap")).expect("pagemap");
    for index in 0..pages {
        std::os::unix::fs::FileExt::write_at(
            &pagemap,
            &(1_u64 << 62).to_le_bytes(),
            (start / page + index) * 8,
        )
        .expect("swapped entry");
    }
    fs::write(api.root.join("child/cgroup.procs"), pid.to_string()).expect("procs");
    start
}

#[test]
fn wake_reads_every_recorded_page_that_is_not_resident_through_the_pool() {
    let (_dir, mut power, api) = fixture();
    power.web_port = web(200);
    api.instance.lock().expect("instance").address = Some([127, 0, 0, 1].into());
    let buffer = vec![1_u8; 4096 * 9];
    plant_self(&power, &api, &buffer, 8);
    power.sleep_with(&api, "2", "op1").expect("sleep");
    let receipt = power.wake_with(&api, "2").expect("wake");
    assert_eq!(
        (
            receipt.restore.as_str(),
            receipt.processes,
            receipt.pages_read
        ),
        ("complete", 1, 8)
    );
}

#[test]
fn resident_pages_are_skipped() {
    let (_dir, mut power, api) = fixture();
    power.web_port = web(200);
    api.instance.lock().expect("instance").address = Some([127, 0, 0, 1].into());
    let buffer = vec![1_u8; 4096 * 9];
    let start = plant_self(&power, &api, &buffer, 8);
    power.sleep_with(&api, "2", "op1").expect("sleep");
    // Since the sleep every page came back into RAM.
    let pagemap = OpenOptions::new()
        .write(true)
        .open(
            power
                .proc
                .join(std::process::id().to_string())
                .join("pagemap"),
        )
        .expect("pagemap");
    for index in 0..8 {
        std::os::unix::fs::FileExt::write_at(
            &pagemap,
            &(1_u64 << 63).to_le_bytes(),
            (start / 4096 + index) * 8,
        )
        .expect("present entry");
    }
    let receipt = power.wake_with(&api, "2").expect("wake");
    assert_eq!(
        (receipt.restore.as_str(), receipt.pages_read),
        ("complete", 0)
    );
}

#[test]
fn a_restore_cut_at_the_bound_reports_partial_and_the_wake_goes_on() {
    let (_dir, mut power, api) = fixture();
    power.web_port = web(200);
    api.instance.lock().expect("instance").address = Some([127, 0, 0, 1].into());
    let buffer = vec![1_u8; 4096 * 9];
    plant_self(&power, &api, &buffer, 8);
    power.sleep_with(&api, "2", "op1").expect("sleep");
    power.restore_bound = Duration::ZERO;
    let receipt = power.wake_with(&api, "2").expect("wake");
    assert_eq!(
        (receipt.restore.as_str(), receipt.reason.as_deref()),
        ("partial", Some("deadline"))
    );
    assert!(!marker(&power).exists());
    assert_eq!(swap_limits(&api), ["0", "0"]);
}

#[test]
fn every_identity_mismatch_skips_the_restore_before_any_memory_syscall() {
    type Change = fn(&Power, &Fake);
    let changes: [(&str, Change); 4] = [
        ("boot id", |power, _| {
            fs::write(power.proc.join("sys/kernel/random/boot_id"), "boot-2").expect("boot");
        }),
        ("cgroup inode", |power, _| {
            let path = power.state.join("2/plan.json");
            let mut plan: serde_json::Value = load(&path).expect("plan");
            plan["identity"]["inode"] = 1.into();
            publish(&path, &plan).expect("plan");
        }),
        ("incarnation", |_, api| {
            "uuid-2".clone_into(&mut api.instance.lock().expect("instance").incarnation);
        }),
        ("process start time", |power, _| {
            let pid = std::process::id();
            fs::write(
                power.proc.join(pid.to_string()).join("stat"),
                format!("{pid} (self) S {} 78", vec!["0"; 18].join(" ")),
            )
            .expect("stat");
        }),
    ];
    for (name, change) in changes {
        let (_dir, mut power, api) = fixture();
        power.web_port = web(200);
        api.instance.lock().expect("instance").address = Some([127, 0, 0, 1].into());
        let buffer = vec![1_u8; 4096 * 9];
        plant_self(&power, &api, &buffer, 8);
        power.sleep_with(&api, "2", "op1").expect("sleep");
        change(&power, &api);
        let receipt = power.wake_with(&api, "2").expect(name);
        assert_eq!(
            (
                receipt.restore.as_str(),
                receipt.reason.as_deref(),
                receipt.pages_read
            ),
            ("skipped", Some("plan_stale"), 0),
            "{name}"
        );
        assert!(!power.state.join("2/plan.json").exists(), "{name}");
    }
}

#[test]
fn a_lost_incus_freeze_fails_the_wake_without_any_write() {
    let (_dir, power, api) = asleep(200);
    "running".clone_into(&mut api.instance.lock().expect("instance").status);
    assert_eq!(
        power
            .wake_with(&api, "2")
            .expect_err("lost freeze")
            .to_string(),
        UNMANAGED
    );
    assert_eq!(*api.actions.lock().expect("actions"), ["freeze"]);
    assert!(marker(&power).exists());
    assert_eq!(swap_limits(&api), ["max", "max"]);
}

#[test]
fn an_unready_app_fails_the_wake_and_keeps_the_marker() {
    let (_dir, power, api) = asleep(503);
    assert_eq!(
        power.wake_with(&api, "2").expect_err("unready").to_string(),
        "instance.web_unready"
    );
    assert!(marker(&power).exists());
}

#[test]
fn a_headless_wake_needs_no_web_unit_and_removes_the_marker() {
    let (_dir, power, api) = asleep(503);
    power
        .wake_checked(&api, "2", WebProbe::NotConfigured, None)
        .expect("headless wake");
    assert!(!marker(&power).exists());
    assert_eq!(swap_limits(&api), ["0", "0"]);
}

#[test]
fn a_stopped_container_is_left_to_the_run_and_its_plan_dropped() {
    let (_dir, power, api) = asleep(200);
    "stopped".clone_into(&mut api.instance.lock().expect("instance").status);
    assert_eq!(
        power.wake_with(&api, "2").expect_err("stopped").to_string(),
        "instance.container_stopped"
    );
    assert!(!power.state.join("2/plan.json").exists());
}

#[test]
fn incus_reports_the_first_global_ipv4_address() {
    let addresses = serde_json::json!([
        {"family": "inet6", "address": "fd42::1", "scope": "global"},
        {"family": "inet", "address": "127.0.0.1", "scope": "local"},
        {"family": "inet", "address": "10.1.2.3", "scope": "global"}
    ]);
    assert_eq!(incus::address(&addresses), Some([10, 1, 2, 3].into()));
    assert_eq!(incus::address(&serde_json::Value::Null), None);
}

/// A crash after the unfreeze leaves an open wake, a running container, open limits and the marker. Recovery repeats the wake,
/// which finishes the steps after the unfreeze; a repeat of the Operation returns that result.
#[test]
fn crash_recovery_after_the_unfreeze_finishes_the_wake_and_a_repeat_returns_it() {
    use orbit_executor_core::{
        executor::Executor,
        journal::{DoneKey, Record},
    };
    let (_dir, power, api) = asleep(200);
    let slept = api.instance.lock().expect("instance").clone();
    api.change(&slept, "unfreeze")
        .expect("unfreeze before the crash");
    let power = std::sync::Arc::new(power);
    let api = std::sync::Arc::new(api);
    let path = power.state.join("journal");
    let order = signed_order("00000000000000000000000000000011", "wake");
    fs::write(
        &path,
        format!(
            "{}\n",
            serde_json::to_string(&Record::Open {
                nonce: order.nonce(),
                key: DoneKey {
                    operation: order.operation().clone(),
                    action: order.action(),
                    target: order.target().clone(),
                },
                expires_ms: 6000,
                power_check: orbit_executor_core::order::PowerCheck::Default,
                input: serde_json::Value::Null,
            })
            .expect("record")
        ),
    )
    .expect("open journal");
    let runner = Runner {
        power: std::sync::Arc::clone(&power),
        api: std::sync::Arc::clone(&api),
    };
    let (executor, recovered) = Executor::start(&path, runner, || 1001, |_| {}).expect("recover");
    assert_eq!(recovered.len(), 1);
    assert_eq!(swap_limits(&api), ["0", "0"]);
    assert!(!marker(&power).exists());
    let repeat = executor
        .handle(
            &signed_order("00000000000000000000000000000012", "wake"),
            1001,
        )
        .expect("repeat");
    assert!(repeat.repeat);
    assert_eq!(
        *api.actions.lock().expect("actions"),
        ["freeze", "unfreeze"]
    );
}

// Review 5767 reproductions, kept as regression tests.

#[test]
fn review_failed_pagemap_read_must_not_report_complete() {
    let (_dir, mut power, api) = fixture();
    power.web_port = web(200);
    api.instance.lock().expect("instance").address = Some([127, 0, 0, 1].into());
    let buffer = vec![1_u8; 4096 * 9];
    plant_self(&power, &api, &buffer, 8);
    power.sleep_with(&api, "2", "op1").expect("sleep");
    fs::write(
        power
            .proc
            .join(std::process::id().to_string())
            .join("pagemap"),
        "",
    )
    .expect("short pagemap");
    let receipt = power.wake_with(&api, "2").expect("wake should go on");
    assert_eq!(
        (receipt.restore.as_str(), receipt.reason.as_deref()),
        ("partial", Some("read_failed")),
        "no page was read, so reporting a complete restore is false"
    );
}

#[test]
fn review_failed_memory_read_must_not_report_complete() {
    let (_dir, mut power, api) = fixture();
    power.web_port = web(200);
    api.instance.lock().expect("instance").address = Some([127, 0, 0, 1].into());
    let buffer = vec![1_u8; 4096 * 9];
    plant_self(&power, &api, &buffer, 8);
    power.sleep_with(&api, "2", "op1").expect("sleep");
    let path = power.state.join("2/plan.json");
    let mut plan: serde_json::Value = load(&path).expect("plan");
    plan["processes"][0]["ranges"] = serde_json::json!([{"start": 0, "length": 4096}]);
    publish(&path, &plan).expect("plan");
    let receipt = power.wake_with(&api, "2").expect("wake should go on");
    assert_eq!(
        (receipt.restore.as_str(), receipt.reason.as_deref()),
        ("partial", Some("read_failed")),
        "process_vm_readv failed for an unmapped page"
    );
    assert!(!marker(&power).exists());
}

#[test]
fn capture_excludes_unreadable_mappings_and_guard_markers_but_keeps_their_readable_neighbors() {
    let (_dir, power, api) = fixture();
    let buffer = vec![1_u8; 4096 * 9];
    let start = plant_self(&power, &api, &buffer, 8);
    let base = power.proc.join(std::process::id().to_string());
    fs::write(
        base.join("maps"),
        format!(
            "{start:x}-{:x} ---p 00000000 00:00 0\n{:x}-{:x} --xp 00000000 00:00 0\n{:x}-{:x} rw-p 00000000 00:00 0\n",
            start + 4096, start + 4096, start + 8192, start + 8192, start + 8 * 4096,
        ),
    )
    .expect("mapping permissions");
    let pagemap = File::options()
        .write(true)
        .open(base.join("pagemap"))
        .expect("pagemap");
    // Linux 7.0 sets bit 62 as well as bit 58 on a lightweight guard inside a readable VMA.
    std::os::unix::fs::FileExt::write_at(
        &pagemap,
        &((1_u64 << 62) | (1 << 58)).to_le_bytes(),
        (start / 4096 + 3) * 8,
    )
    .expect("guard marker");
    power.sleep_with(&api, "2", "op1").expect("sleep");
    let plan: plan::Plan = load(&power.state.join("2/plan.json")).expect("plan");
    assert_eq!(
        plan.processes[0].ranges,
        vec![
            plan::Range {
                start: start + 8192,
                length: 4096
            },
            plan::Range {
                start: start + 4 * 4096,
                length: 4 * 4096
            },
        ]
    );
}

#[test]
fn restore_continues_after_each_unreadable_page_in_a_short_or_failed_batch() {
    let (_dir, mut power, api) = fixture();
    power.web_port = web(200);
    api.instance.lock().expect("instance").address = Some([127, 0, 0, 1].into());
    let buffer = vec![1_u8; 4096 * 9];
    let start = plant_self(&power, &api, &buffer, 8);
    power.sleep_with(&api, "2", "op1").expect("sleep");
    let path = power.state.join("2/plan.json");
    let mut plan: serde_json::Value = load(&path).expect("plan");
    // First fail at the batch's start, then after one valid page, then again with a readable tail.
    plan["processes"][0]["ranges"] = serde_json::json!([
        {"start":0,"length":4096}, {"start":start,"length":4096},
        {"start":0,"length":4096}, {"start":start + 4096,"length":4096},
        {"start":0,"length":4096}, {"start":start + 8192,"length":4096}
    ]);
    publish(&path, &plan).expect("plan");
    let receipt = power.wake_with(&api, "2").expect("wake continues");
    assert_eq!(
        (receipt.restore.as_str(), receipt.reason.as_deref()),
        ("partial", Some("read_failed"))
    );
    assert_eq!(
        receipt.pages_read, 3,
        "read every readable page after each failure"
    );
    assert_eq!(receipt.pages_failed, 3, "count only the failed pages");
    assert!(!marker(&power).exists());
}

#[test]
fn review_invalid_range_must_skip_plan_without_panicking() {
    let (_dir, mut power, api) = fixture();
    power.web_port = web(200);
    api.instance.lock().expect("instance").address = Some([127, 0, 0, 1].into());
    let buffer = vec![1_u8; 4096 * 9];
    plant_self(&power, &api, &buffer, 8);
    power.sleep_with(&api, "2", "op1").expect("sleep");
    let path = power.state.join("2/plan.json");
    let mut plan: serde_json::Value = load(&path).expect("plan");
    plan["processes"][0]["ranges"] =
        serde_json::json!([{"start": u64::MAX - 4095, "length": 8192}]);
    publish(&path, &plan).expect("plan");
    let receipt = power
        .wake_with(&api, "2")
        .expect("wake should skip invalid plan");
    assert_eq!(receipt.reason.as_deref(), Some("plan_invalid"));
}

#[test]
fn every_invalid_plan_bound_skips_the_restore() {
    let cases: [(&str, serde_json::Value); 5] = [
        ("zero page size", serde_json::json!({"page_size": 0})),
        ("other page size", serde_json::json!({"page_size": 4097})),
        (
            "unaligned start",
            serde_json::json!({"ranges": [{"start": 4097, "length": 4096}]}),
        ),
        (
            "empty range",
            serde_json::json!({"ranges": [{"start": 4096, "length": 0}]}),
        ),
        (
            "beyond user space",
            serde_json::json!({"ranges": [{"start": 1_u64 << 56, "length": 4096}]}),
        ),
    ];
    for (name, change) in cases {
        let (_dir, mut power, api) = fixture();
        power.web_port = web(200);
        api.instance.lock().expect("instance").address = Some([127, 0, 0, 1].into());
        let buffer = vec![1_u8; 4096 * 9];
        plant_self(&power, &api, &buffer, 8);
        power.sleep_with(&api, "2", "op1").expect("sleep");
        let path = power.state.join("2/plan.json");
        let mut plan: serde_json::Value = load(&path).expect("plan");
        if let Some(page) = change.get("page_size") {
            plan["page_size"] = page.clone();
        }
        if let Some(ranges) = change.get("ranges") {
            plan["processes"][0]["ranges"] = ranges.clone();
        }
        publish(&path, &plan).expect("plan");
        let receipt = power.wake_with(&api, "2").expect(name);
        assert_eq!(
            (
                receipt.restore.as_str(),
                receipt.reason.as_deref(),
                receipt.pages_read
            ),
            ("skipped", Some("plan_invalid"), 0),
            "{name}"
        );
    }
}

#[test]
fn review_readiness_must_obey_one_absolute_deadline() {
    use std::io::Read as _;
    use std::net::TcpListener;
    let listener = TcpListener::bind("127.0.0.1:0").expect("listener");
    let port = listener.local_addr().expect("address").port();
    let server = std::thread::spawn(move || {
        let (mut stream, _) = listener.accept().expect("accept");
        let mut request = [0; 256];
        let _ = stream.read(&mut request).expect("request");
        for byte in b"HTTP/1.1 200 " {
            if stream.write_all(&[*byte]).is_err() {
                break;
            }
            std::thread::sleep(Duration::from_millis(10));
        }
    });
    let deadline = Instant::now() + Duration::from_millis(50);
    let accepted = wake::ready([127, 0, 0, 1].into(), port, Duration::from_millis(50));
    let exceeded = Instant::now() > deadline;
    server.join().expect("server");
    assert!(
        !accepted || !exceeded,
        "readiness accepted a response after its absolute deadline"
    );
    assert!(!accepted);
}

#[test]
fn review_stale_limits_are_dropped_without_writing() {
    let (_dir, power, api) = asleep(200);
    let path = power.state.join("2/limits.json");
    let mut record: serde_json::Value = load(&path).expect("record");
    record["identity"]["incarnation"] = "uuid-other".into();
    publish(&path, &record).expect("record");
    power.wake_with(&api, "2").expect("wake");
    assert_eq!(swap_limits(&api), ["max", "max"]);
    assert!(!path.exists());
}

/// The gate a stalled reader waits on, as a page fault waits on a pager that never answers.
static STALL: (Mutex<bool>, std::sync::Condvar) = (Mutex::new(false), std::sync::Condvar::new());
fn stalled_reader(
    _: &wake::Target,
    _: &[plan::Range],
    _: Instant,
) -> (u64, Option<pool::Shortfall>) {
    let mut open = STALL.0.lock().expect("stall gate");
    while !*open {
        open = STALL.1.wait(open).expect("stall gate");
    }
    (1, None)
}

/// Re-review 5767: a restore item stuck in its syscall ends the wake as partial `stalled` within the deadline and the grace. The
/// wake unfreezes and returns, so the executor releases its target lock; another target restores; and a later sleep of the same
/// target waits for the stalled reader, or refuses after its bound.
#[test]
fn a_stalled_reader_ends_the_wake_partial_and_holds_back_the_next_sleep() {
    let (_dir, mut power, api) = fixture();
    power.web_port = web(200);
    api.instance.lock().expect("instance").address = Some([127, 0, 0, 1].into());
    let buffer = vec![1_u8; 4096 * 9];
    plant_self(&power, &api, &buffer, 8);
    power.sleep_with(&api, "2", "op1").expect("sleep");
    power.reader = stalled_reader;
    power.restore_bound = Duration::from_millis(50);
    let started = Instant::now();
    let receipt = power.wake_with(&api, "2").expect("the wake goes on");
    assert!(
        started.elapsed() < Duration::from_millis(50) + pool::STALL_GRACE + Duration::from_secs(2)
    );
    assert_eq!(
        (receipt.restore.as_str(), receipt.reason.as_deref()),
        ("partial", Some("stalled"))
    );
    assert_eq!(
        *api.actions.lock().expect("actions"),
        ["freeze", "unfreeze"]
    );
    assert!(!marker(&power).exists());
    assert_eq!(power.readers("2").active(), 1);
    // Another target restores while the stalled worker stays with its syscall.
    let other = power.pool.restore(
        vec![Box::new(|_| (1, None))],
        Instant::now() + Duration::from_secs(1),
        &power.pool.readers(),
    );
    assert_eq!((other.pages, other.shortfall), (1, None));
    // A sleep or discard of the same target refuses once its bound passes with the reader still there.
    power.reader_bound = Duration::from_millis(20);
    assert_eq!(
        power
            .sleep_with(&api, "2", "op2")
            .expect_err("busy")
            .to_string(),
        READERS_BUSY
    );
    assert_eq!(
        power.discard("2").expect_err("busy").to_string(),
        READERS_BUSY
    );
    // Within its bound, the sleep waits for the reader and then runs.
    power.reader_bound = Duration::from_secs(10);
    let (slept_tx, slept_rx) = std::sync::mpsc::channel();
    std::thread::scope(|scope| {
        scope.spawn(|| {
            let slept = power.sleep_with(&api, "2", "op3");
            slept_tx.send(slept.map(|r| r.sleep)).expect("slept");
        });
        assert!(
            slept_rx.recv_timeout(Duration::from_millis(100)).is_err(),
            "the sleep must not freeze or scan while a stalled reader remains"
        );
        *STALL.0.lock().expect("stall gate") = true;
        STALL.1.notify_all();
        assert_eq!(
            slept_rx
                .recv_timeout(Duration::from_secs(5))
                .expect("sleep after the reader")
                .expect("sleep"),
            "frozen"
        );
    });
}

/// An app that listens only `start` after the call, then reads each full request and answers 200 after `answer`.
fn late_web(start: Duration, answer: Duration) -> u16 {
    let probe = std::net::TcpListener::bind("127.0.0.1:0").expect("listener");
    let port = probe.local_addr().expect("address").port();
    let listener = if start.is_zero() {
        Some(probe)
    } else {
        drop(probe);
        None
    };
    std::thread::spawn(move || {
        let listener = listener.unwrap_or_else(|| {
            std::thread::sleep(start);
            std::net::TcpListener::bind(("127.0.0.1", port)).expect("late listener")
        });
        for stream in listener.incoming() {
            let Ok(mut stream) = stream else { continue };
            let mut request = [0; 256];
            let mut received = 0;
            while received < request.len() && !request[..received].ends_with(b"\r\n\r\n") {
                match std::io::Read::read(&mut stream, &mut request[received..]) {
                    Ok(0) | Err(_) => break,
                    Ok(bytes) => received += bytes,
                }
            }
            std::thread::sleep(answer);
            let _ = write!(stream, "HTTP/1.1 200 X\r\nContent-Length: 0\r\n\r\n");
        }
    });
    port
}

#[test]
fn a_slow_web_start_wakes_once_the_app_answers() {
    let (_dir, mut power, api) = asleep(200);
    // FrankenPHP comes up about 2 s after the thaw: the port refuses connections until then.
    power.web_port = late_web(Duration::from_secs(2), Duration::ZERO);
    let started = Instant::now();
    power
        .wake_checked(&api, "2", WebProbe::Required, Some(Duration::from_secs(10)))
        .expect("a slow start wakes");
    assert!(started.elapsed() >= Duration::from_secs(2));
    assert!(!marker(&power).exists());
}

#[test]
fn an_app_that_answers_slowly_wakes_within_the_bound() {
    let (_dir, mut power, api) = asleep(200);
    // A healthy app that takes about 3 s per answer: longer than one connect attempt's bound.
    power.web_port = late_web(Duration::ZERO, Duration::from_secs(3));
    let started = Instant::now();
    power
        .wake_checked(&api, "2", WebProbe::Required, Some(Duration::from_secs(10)))
        .expect("a slow answer wakes");
    let waited = started.elapsed();
    assert!(waited >= Duration::from_secs(3) && waited < Duration::from_secs(5), "{waited:?}");
    assert!(!marker(&power).exists());
}

#[test]
fn an_app_that_never_answers_fails_the_wake_at_its_bound() {
    let (_dir, power, api) = asleep(503);
    let started = Instant::now();
    assert_eq!(
        power
            .wake_checked(&api, "2", WebProbe::Required, Some(Duration::from_secs(2)))
            .expect_err("unready")
            .to_string(),
        "instance.web_unready"
    );
    let waited = started.elapsed();
    assert!(
        waited >= Duration::from_secs(2) && waited < Duration::from_secs(4),
        "{waited:?}"
    );
    assert!(marker(&power).exists());
}

#[test]
fn a_missing_address_is_read_again_until_the_bound() {
    let (_dir, power, api) = asleep(200);
    api.instance.lock().expect("instance").address = None;
    std::thread::scope(|scope| {
        scope.spawn(|| {
            std::thread::sleep(Duration::from_millis(500));
            api.instance.lock().expect("instance").address = Some([127, 0, 0, 1].into());
        });
        power
            .wake_checked(&api, "2", WebProbe::Required, Some(Duration::from_secs(5)))
            .expect("the address appears within the bound");
    });
}
