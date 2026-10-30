use super::*;

const NAME: &str = "orbit-process-7-web";
const UNIT: &str = "orbit-process-7-web.service";

#[derive(Clone, Debug)]
struct FakeContainer {
    managed: String,
    kind: String,
    owner: String,
    spec: String,
    running: bool,
}

fn owned(spec: &str, running: bool) -> FakeContainer {
    FakeContainer {
        managed: "true".into(),
        kind: "process".into(),
        owner: "7".into(),
        spec: spec.into(),
        running,
    }
}

/// Docker and systemd as far as the commands use them. A call listed in `failing` fails once.
#[derive(Default)]
struct Fake {
    containers: BTreeMap<String, FakeContainer>,
    calls: Vec<String>,
    failing: Vec<String>,
    health_answers: Vec<bool>,
    active: String,
    environment: Vec<(String, u32)>,
}

fn ok(stdout: &str) -> Output {
    Output {
        exit_code: 0,
        stdout: stdout.into(),
        stderr: String::new(),
    }
}

fn error(stderr: &str) -> Output {
    Output {
        exit_code: 1,
        stdout: String::new(),
        stderr: stderr.into(),
    }
}

impl Fake {
    fn exists(&self, name: &str) -> Output {
        if self.containers.contains_key(name) {
            ok("")
        } else {
            error(&format!(
                "Error response from daemon: No such container: {name}"
            ))
        }
    }
}

impl Programs for Fake {
    fn run(&mut self, program: &str, arguments: &[&str]) -> Output {
        let call = format!("{program} {}", arguments.join(" "));
        self.calls.push(call.clone());
        if let Some(index) = self.failing.iter().position(|failing| *failing == call) {
            self.failing.remove(index);
            return error("injected failure");
        }
        match (program, arguments) {
            ("/usr/bin/curl", _) if !self.health_answers.is_empty() => {
                if self.health_answers.remove(0) {
                    ok("")
                } else {
                    error("not ready")
                }
            }
            ("docker", ["container", "inspect", "--format", format, name]) => {
                match self.containers.get(*name) {
                    None => error(&format!("Error: No such object: {name}")),
                    Some(c) if *format == INSPECT_FORMAT => ok(&format!(
                        "{}\n{}\n{}\n{}\n{}\n",
                        c.managed, c.kind, c.owner, c.spec, c.running
                    )),
                    Some(c) => ok(if c.running { "running\n" } else { "exited\n" }),
                }
            }
            ("docker", ["container", "create", rest @ ..]) => {
                let (mut name, mut spec, mut owner) = (String::new(), String::new(), String::new());
                for pair in rest.windows(2) {
                    match pair {
                        ["--env-file", file] => {
                            let mode = fs::metadata(file).unwrap().mode() & 0o777;
                            self.environment
                                .push((fs::read_to_string(file).unwrap(), mode));
                        }
                        ["--name", value] => name = (*value).into(),
                        ["--label", label] => {
                            if let Some(value) = label.strip_prefix("orbit.process.spec=") {
                                spec = value.into();
                            }
                            if let Some(value) = label.strip_prefix("orbit.process.id=") {
                                owner = value.into();
                            }
                        }
                        _ => {}
                    }
                }
                if self.containers.contains_key(&name) {
                    return error("Conflict. The container name is already in use");
                }
                let mut container = owned(&spec, false);
                container.owner = owner;
                self.containers.insert(name, container);
                ok("")
            }
            ("docker", ["container", "start" | "restart", name]) => {
                let output = self.exists(name);
                if let Some(c) = self.containers.get_mut(*name) {
                    c.running = true;
                }
                output
            }
            ("docker", ["container", "stop", name]) => {
                let output = self.exists(name);
                if let Some(c) = self.containers.get_mut(*name) {
                    c.running = false;
                }
                output
            }
            ("docker", ["container", "rename", from, to]) => {
                if self.containers.contains_key(*to) {
                    return error("Conflict");
                }
                match self.containers.remove(*from) {
                    Some(c) => {
                        self.containers.insert((*to).into(), c);
                        ok("")
                    }
                    None => self.exists(from),
                }
            }
            ("docker", ["container", "rm", "--force", name]) => {
                let output = self.exists(name);
                self.containers.remove(*name);
                output
            }
            ("systemctl", ["is-active", _]) => Output {
                exit_code: if self.active == "active" { 0 } else { 3 },
                stdout: format!("{}\n", self.active),
                stderr: String::new(),
            },
            _ => ok(""),
        }
    }
}

struct Sandbox {
    root: PathBuf,
    node: Node<Fake>,
}

impl Drop for Sandbox {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.root);
    }
}

fn sandbox() -> Sandbox {
    let root = std::env::temp_dir().join(format!("orbit-processes-{:016x}", rand::random::<u64>()));
    fs::create_dir_all(root.join("units")).unwrap();
    Sandbox {
        node: Node {
            units: root.join("units"),
            candidates: root.join("candidates"),
            scratch: root.join("scratch"),
            programs: Fake::default(),
        },
        root,
    }
}

fn spec(letter: char) -> String {
    letter.to_string().repeat(64)
}

fn container_input(spec: &str, desired: Desired) -> ContainerInput {
    ContainerInput {
        process_id: 7,
        container: NAME.into(),
        desired,
        spec: spec.into(),
        image: "nginx:1.27".into(),
        command: vec!["nginx".into(), "-g".into(), "daemon off;".into()],
        restart: "unless-stopped".into(),
        working_directory: Some("/app".into()),
        ports: vec!["127.0.0.1:8080:80".into()],
        mounts: vec![Mount {
            source: "/srv/data".into(),
            target: "/data".into(),
            read_only: true,
        }],
        health: None,
        expected_spec: None,
        expected_running: None,
        environment: BTreeMap::from([("SECRET".into(), "s3cret".into())]),
    }
}

fn containers(node: &Node<Fake>) -> Vec<(String, String, bool)> {
    node.programs
        .containers
        .iter()
        .map(|(name, c)| (name.clone(), c.spec.clone(), c.running))
        .collect()
}

fn position(node: &Node<Fake>, call: &str) -> usize {
    node.programs
        .calls
        .iter()
        .position(|made| made == call)
        .unwrap_or_else(|| panic!("no call [{call}] in {:?}", node.programs.calls))
}

#[test]
fn a_new_container_is_created_with_its_labels_and_a_private_environment_file() {
    let mut sandbox = sandbox();
    converge_container(
        &mut sandbox.node,
        &container_input(&spec('a'), Desired::Running),
    )
    .unwrap();
    assert_eq!(
        containers(&sandbox.node),
        [(NAME.to_string(), spec('a'), true)]
    );
    let create = sandbox
        .node
        .programs
        .calls
        .iter()
        .find(|call| call.starts_with("docker container create"))
        .unwrap();
    for part in [
        "--name orbit-process-7-web",
        "--label orbit.managed=true --label orbit.container.kind=process --label orbit.process.id=7",
        "--restart unless-stopped --workdir /app --publish 127.0.0.1:8080:80",
        "--mount type=bind,source=/srv/data,target=/data,readonly nginx:1.27 nginx -g daemon off;",
    ] {
        assert!(create.contains(part), "{create}");
    }
    assert!(!create.contains("s3cret"));
    assert_eq!(
        sandbox.node.programs.environment,
        [("SECRET=s3cret\n".to_string(), 0o600)]
    );
    assert_eq!(fs::read_dir(&sandbox.node.scratch).unwrap().count(), 0);
}

#[test]
fn a_replacement_removes_the_previous_container_only_after_it_starts() {
    let mut sandbox = sandbox();
    sandbox
        .node
        .programs
        .containers
        .insert(NAME.into(), owned(&spec('a'), true));
    converge_container(
        &mut sandbox.node,
        &container_input(&spec('b'), Desired::Running),
    )
    .unwrap();
    assert_eq!(
        containers(&sandbox.node),
        [(NAME.to_string(), spec('b'), true)]
    );
    let node = &sandbox.node;
    assert!(
        position(node, "docker container start orbit-process-7-web")
            < position(
                node,
                "docker container rm --force orbit-process-7-web-rollback-running"
            )
    );
}

#[test]
fn a_replacement_that_does_not_start_restores_the_previous_container() {
    for running in [true, false] {
        let mut sandbox = sandbox();
        sandbox
            .node
            .programs
            .containers
            .insert(NAME.into(), owned(&spec('a'), running));
        sandbox
            .node
            .programs
            .failing
            .push("docker container start orbit-process-7-web".into());
        let result = converge_container(
            &mut sandbox.node,
            &container_input(&spec('b'), Desired::Running),
        );
        assert!(
            matches!(result, Err(ProcessError::StartFailed { restored: true, ref step, .. }) if step == "start"),
            "{result:?}"
        );
        assert_eq!(
            containers(&sandbox.node),
            [(NAME.to_string(), spec('a'), running)]
        );
    }
}

#[test]
fn an_interrupted_replacement_is_undone_and_done_again_on_the_next_call() {
    let mut sandbox = sandbox();
    // The connection dropped after the candidate took the name and before it started.
    let programs = &mut sandbox.node.programs;
    programs
        .containers
        .insert(NAME.into(), owned(&spec('b'), false));
    programs
        .containers
        .insert(format!("{NAME}-rollback-running"), owned(&spec('a'), false));
    converge_container(
        &mut sandbox.node,
        &container_input(&spec('b'), Desired::Running),
    )
    .unwrap();
    assert_eq!(
        containers(&sandbox.node),
        [(NAME.to_string(), spec('b'), true)]
    );
}

#[test]
fn a_rollback_without_a_canonical_container_is_put_back() {
    let mut sandbox = sandbox();
    sandbox
        .node
        .programs
        .containers
        .insert(format!("{NAME}-rollback-running"), owned(&spec('a'), false));
    converge_container(
        &mut sandbox.node,
        &container_input(&spec('a'), Desired::Running),
    )
    .unwrap();
    assert_eq!(
        containers(&sandbox.node),
        [(NAME.to_string(), spec('a'), true)]
    );
}

#[test]
fn two_rollback_markers_fail_closed_without_a_change() {
    let mut sandbox = sandbox();
    let programs = &mut sandbox.node.programs;
    for suffix in ["-rollback-running", "-rollback-stopped"] {
        programs
            .containers
            .insert(format!("{NAME}{suffix}"), owned(&spec('a'), false));
    }
    let result = converge_container(
        &mut sandbox.node,
        &container_input(&spec('a'), Desired::Running),
    );
    assert!(
        matches!(result, Err(ProcessError::RecoveryRequired { ref reason, .. }) if reason == "ambiguous_rollback"),
        "{result:?}"
    );
    assert!(sandbox
        .node
        .programs
        .calls
        .iter()
        .all(|call| call.starts_with("docker container inspect")));
}

#[test]
fn a_container_without_every_owner_label_is_a_name_collision() {
    for broken in ["managed", "kind", "owner"] {
        let mut sandbox = sandbox();
        let mut foreign = owned(&spec('a'), true);
        match broken {
            "managed" => foreign.managed = String::new(),
            "kind" => foreign.kind = "database".into(),
            _ => foreign.owner = "8".into(),
        }
        sandbox
            .node
            .programs
            .containers
            .insert(NAME.into(), foreign);
        let result = converge_container(
            &mut sandbox.node,
            &container_input(&spec('b'), Desired::Running),
        );
        assert!(
            matches!(result, Err(ProcessError::NameCollision { ref artifact, .. }) if artifact == NAME),
            "{result:?}"
        );
        let control = control(
            &mut sandbox.node,
            &ControlInput {
                operation: Operation::Stop,
                process_id: 7,
                runtime: Runtime::Docker,
                name: NAME.into(),
                on_demand: false,
            },
        );
        assert!(matches!(control, Err(ProcessError::NameCollision { .. })));
        assert_eq!(sandbox.node.programs.containers.len(), 1);
    }
}

#[test]
fn removal_deletes_every_owned_container_and_nothing_when_one_is_foreign() {
    let docker = RemoveInput {
        process_id: 7,
        runtime: Runtime::Docker,
        name: NAME.into(),
        vite: None,
    };
    let mut sandbox = sandbox();
    for suffix in ["", "-candidate", "-rollback-stopped"] {
        sandbox
            .node
            .programs
            .containers
            .insert(format!("{NAME}{suffix}"), owned(&spec('a'), true));
    }
    remove(&mut sandbox.node, &docker).unwrap();
    assert!(sandbox.node.programs.containers.is_empty());

    let mut sandbox = self::sandbox();
    let programs = &mut sandbox.node.programs;
    programs
        .containers
        .insert(NAME.into(), owned(&spec('a'), true));
    let mut foreign = owned(&spec('a'), false);
    foreign.owner = "8".into();
    programs
        .containers
        .insert(format!("{NAME}-candidate"), foreign);
    assert!(matches!(
        remove(&mut sandbox.node, &docker),
        Err(ProcessError::NameCollision { .. })
    ));
    assert_eq!(sandbox.node.programs.containers.len(), 2);
}

fn unit(content: &str) -> String {
    format!("[Unit]\nDescription={content}\nX-Orbit-Process-ID=7\n")
}

fn stage_input(content: &str) -> StageInput {
    StageInput {
        process_id: 7,
        unit: UNIT.into(),
        content: content.into(),
    }
}

fn activate_input(change: Change, desired: Desired) -> ActivateInput {
    ActivateInput {
        process_id: 7,
        unit: UNIT.into(),
        change,
        desired,
        on_demand: false,
    }
}

#[test]
fn staging_leaves_an_unchanged_unit_and_writes_a_verified_candidate() {
    let mut sandbox = sandbox();
    let path = sandbox.node.units.join(UNIT);
    fs::write(&path, unit("old")).unwrap();
    sandbox.node.programs.active = "active".into();
    assert_eq!(
        stage(&mut sandbox.node, &stage_input(&unit("old"))).unwrap(),
        Staged {
            staged: false,
            previous: true,
            state: Some("active".into())
        }
    );

    let staged = stage(&mut sandbox.node, &stage_input(&unit("new"))).unwrap();
    assert_eq!((staged.staged, staged.previous), (true, true));
    let candidate = sandbox.node.candidates.join(UNIT);
    assert_eq!(fs::read_to_string(&candidate).unwrap(), unit("new"));
    assert_eq!(fs::metadata(&candidate).unwrap().mode() & 0o777, 0o644);
    assert_eq!(fs::read_to_string(&path).unwrap(), unit("old"));

    sandbox
        .node
        .programs
        .failing
        .push(format!("systemd-analyze verify {}", candidate.display()));
    let refused = stage(&mut sandbox.node, &stage_input(&unit("bad")));
    assert!(matches!(
        refused,
        Err(ProcessError::CandidateInvalid { ref step, .. }) if step == "verify-unit"
    ));
    assert!(!candidate.exists());
    assert_eq!(fs::read_to_string(&path).unwrap(), unit("old"));
}

#[test]
fn a_unit_without_the_process_marker_is_never_replaced() {
    let mut sandbox = sandbox();
    let path = sandbox.node.units.join(UNIT);
    fs::write(&path, "[Unit]\nX-Orbit-Process-ID=70\n").unwrap();
    assert!(matches!(
        stage(&mut sandbox.node, &stage_input(&unit("new"))),
        Err(ProcessError::NameCollision { ref step, .. }) if step == "inspect-unit"
    ));
    assert!(!sandbox.node.candidates.join(UNIT).exists());
    // A marker followed by a carriage return is the Process's own.
    fs::write(&path, "[Unit]\r\nX-Orbit-Process-ID=7\r\n").unwrap();
    assert!(stage(&mut sandbox.node, &stage_input(&unit("new"))).is_ok());
}

#[test]
fn an_activated_replacement_deletes_its_backup() {
    let mut sandbox = sandbox();
    let path = sandbox.node.units.join(UNIT);
    fs::write(&path, unit("old")).unwrap();
    stage(&mut sandbox.node, &stage_input(&unit("new"))).unwrap();
    let activated = activate(
        &mut sandbox.node,
        &activate_input(Change::Replace, Desired::Running),
    )
    .unwrap();
    assert_eq!(activated, Activated { backup_left: false });
    assert_eq!(fs::read_to_string(&path).unwrap(), unit("new"));
    assert_eq!(fs::read_dir(&sandbox.node.candidates).unwrap().count(), 0);
    let node = &sandbox.node;
    assert!(
        position(node, "systemctl daemon-reload")
            < position(node, &format!("systemctl enable {UNIT}"))
    );
    assert!(
        position(node, &format!("systemctl enable {UNIT}"))
            < position(node, &format!("systemctl restart {UNIT}"))
    );
}

#[test]
fn a_replacement_that_does_not_start_restores_the_previous_unit_and_state() {
    let mut sandbox = sandbox();
    let path = sandbox.node.units.join(UNIT);
    fs::write(&path, unit("old")).unwrap();
    stage(&mut sandbox.node, &stage_input(&unit("new"))).unwrap();
    sandbox
        .node
        .programs
        .failing
        .push(format!("systemctl restart {UNIT}"));
    let result = activate(
        &mut sandbox.node,
        &activate_input(Change::Replace, Desired::Running),
    );
    assert!(matches!(
        result,
        Err(ProcessError::StartFailed { restored: true, .. })
    ));
    assert_eq!(fs::read_to_string(&path).unwrap(), unit("old"));
    let calls = &sandbox.node.programs.calls;
    assert!(calls.ends_with(&[
        format!("systemctl disable --now {UNIT}"),
        "systemctl daemon-reload".to_string(),
        format!("systemctl enable --now {UNIT}"),
    ]));
}

#[test]
fn a_new_unit_that_does_not_start_is_removed() {
    let mut sandbox = sandbox();
    stage(&mut sandbox.node, &stage_input(&unit("new"))).unwrap();
    sandbox
        .node
        .programs
        .failing
        .push(format!("systemctl restart {UNIT}"));
    let result = activate(
        &mut sandbox.node,
        &activate_input(Change::Create, Desired::Running),
    );
    assert!(matches!(result, Err(ProcessError::StartFailed { .. })));
    assert!(!sandbox.node.units.join(UNIT).exists());
}

#[test]
fn a_failed_reload_after_publication_puts_the_previous_unit_back() {
    let mut sandbox = sandbox();
    let path = sandbox.node.units.join(UNIT);
    fs::write(&path, unit("old")).unwrap();
    stage(&mut sandbox.node, &stage_input(&unit("new"))).unwrap();
    sandbox
        .node
        .programs
        .failing
        .push("systemctl daemon-reload".into());
    let result = activate(
        &mut sandbox.node,
        &activate_input(Change::Replace, Desired::Stopped),
    );
    assert!(matches!(
        result,
        Err(ProcessError::ActivationFailed { ref step, .. }) if step == "daemon-reload"
    ));
    assert_eq!(fs::read_to_string(&path).unwrap(), unit("old"));
    assert!(!sandbox
        .node
        .programs
        .calls
        .iter()
        .any(|call| call.contains("disable")));
}

#[test]
fn control_needs_the_process_unit_and_follows_on_demand_start() {
    let mut sandbox = sandbox();
    let start = |on_demand, operation| ControlInput {
        operation,
        process_id: 7,
        runtime: Runtime::Systemd,
        name: UNIT.into(),
        on_demand,
    };
    assert_eq!(
        control(&mut sandbox.node, &start(false, Operation::Start)),
        Err(ProcessError::NotFound {
            step: "start".into()
        })
    );
    fs::write(sandbox.node.units.join(UNIT), unit("web")).unwrap();
    control(&mut sandbox.node, &start(true, Operation::Start)).unwrap();
    control(&mut sandbox.node, &start(false, Operation::Start)).unwrap();
    control(&mut sandbox.node, &start(false, Operation::Restart)).unwrap();
    control(&mut sandbox.node, &start(true, Operation::Restart)).unwrap();
    assert_eq!(
        sandbox.node.programs.calls,
        [
            format!("systemctl start {UNIT}"),
            format!("systemctl enable --now {UNIT}"),
            format!("systemctl enable {UNIT}"),
            format!("systemctl restart {UNIT}"),
            format!("systemctl restart {UNIT}"),
        ]
    );
    sandbox
        .node
        .programs
        .failing
        .push(format!("systemctl enable {UNIT}"));
    assert!(matches!(
        control(&mut sandbox.node, &start(false, Operation::Restart)),
        Err(ProcessError::RestartFailed { ref step, .. }) if step == "restart-enable"
    ));
}

#[test]
fn status_reads_absent_known_states_and_containers() {
    let mut sandbox = sandbox();
    let systemd = ArtifactInput {
        process_id: 7,
        runtime: Runtime::Systemd,
        name: UNIT.into(),
    };
    assert_eq!(status(&mut sandbox.node, &systemd).unwrap().state, "absent");
    fs::write(sandbox.node.units.join(UNIT), unit("web")).unwrap();
    sandbox.node.programs.active = "failed".into();
    assert_eq!(status(&mut sandbox.node, &systemd).unwrap().state, "failed");
    sandbox.node.programs.active = "bogus".into();
    assert!(matches!(
        status(&mut sandbox.node, &systemd),
        Err(ProcessError::StatusFailed { .. })
    ));
    let docker = ArtifactInput {
        process_id: 7,
        runtime: Runtime::Docker,
        name: NAME.into(),
    };
    assert_eq!(status(&mut sandbox.node, &docker).unwrap().state, "absent");
    sandbox
        .node
        .programs
        .containers
        .insert(NAME.into(), owned(&spec('a'), true));
    assert_eq!(status(&mut sandbox.node, &docker).unwrap().state, "running");
}

#[test]
fn a_name_that_is_not_the_process_own_is_refused_before_any_call() {
    let mut sandbox = sandbox();
    for (runtime, name) in [
        (Runtime::Systemd, "orbit-process-8-web.service"),
        (Runtime::Systemd, "../web.service"),
        (Runtime::Docker, "orbit-process-7-web.service"),
        (Runtime::Docker, "postgres"),
    ] {
        let input = ArtifactInput {
            process_id: 7,
            runtime,
            name: name.into(),
        };
        assert!(matches!(
            status(&mut sandbox.node, &input),
            Err(ProcessError::Invalid { .. })
        ));
    }
    let mut input = container_input(&spec('a'), Desired::Running);
    input.environment.insert("BAD".into(), "two\nlines".into());
    assert!(matches!(
        converge_container(&mut sandbox.node, &input),
        Err(ProcessError::Invalid { .. })
    ));
    let mut input = container_input(&spec('a'), Desired::Running);
    input.mounts[0].source = "/srv,type=bind".into();
    assert!(matches!(
        converge_container(&mut sandbox.node, &input),
        Err(ProcessError::Invalid { .. })
    ));
    assert!(sandbox.node.programs.calls.is_empty());
}

#[test]
fn a_vite_environment_goes_only_with_its_instance_marker() {
    let sandbox = sandbox();
    let path = sandbox.root.join("app-instance-4.env");
    let vite = ViteEnvironment {
        path: path.clone(),
        instance_id: 4,
    };
    assert_eq!(remove_vite_environment(&vite), Ok(()));
    fs::write(&path, "# Orbit Instance 40\nPORT=1\n").unwrap();
    assert!(matches!(
        remove_vite_environment(&vite),
        Err(ProcessError::ViteCleanupFailed { .. })
    ));
    fs::write(&path, "# Orbit Instance 4\nPORT=1\n").unwrap();
    fs::write(sandbox.root.join("app-instance-4.env.pending"), "x").unwrap();
    assert_eq!(remove_vite_environment(&vite), Ok(()));
    assert!(!path.exists() && !sandbox.root.join("app-instance-4.env.pending").exists());
    let other = ViteEnvironment {
        path: sandbox.root.join("app-instance-5.env"),
        instance_id: 4,
    };
    assert!(matches!(
        remove_vite_environment(&other),
        Err(ProcessError::Invalid { .. })
    ));
}

#[test]
fn removing_a_unit_also_removes_the_vite_environment() {
    let mut sandbox = sandbox();
    fs::write(sandbox.node.units.join(UNIT), unit("web")).unwrap();
    let path = sandbox.root.join("app-instance-4.env");
    fs::write(&path, "# Orbit Instance 4\n").unwrap();
    remove(
        &mut sandbox.node,
        &RemoveInput {
            process_id: 7,
            runtime: Runtime::Systemd,
            name: UNIT.into(),
            vite: Some(ViteEnvironment {
                path: path.clone(),
                instance_id: 4,
            }),
        },
    )
    .unwrap();
    assert!(!sandbox.node.units.join(UNIT).exists() && !path.exists());
    assert_eq!(
        sandbox.node.programs.calls,
        [
            format!("systemctl disable --now {UNIT}"),
            "systemctl daemon-reload".to_string(),
            format!("systemctl reset-failed {UNIT}"),
        ]
    );
}

#[test]
fn container_logs_keep_both_streams_in_order_and_their_end() {
    let mut command = Process::new("sh");
    command.args([
        "-c",
        "echo out one; echo err one >&2; echo out two; echo err two >&2",
    ]);
    let output = merged_tail(&mut command, 1024).unwrap();
    assert_eq!(output.stdout, "out one\nerr one\nout two\nerr two\n");
    let mut command = Process::new("sh");
    command.args(["-c", "seq 1 100000"]);
    let output = merged_tail(&mut command, 13).unwrap();
    assert_eq!(output.stdout, "99999\n100000\n");
}

fn readiness_input() -> ContainerInput {
    let mut input = container_input(&spec('b'), Desired::Running);
    input.health = Some(HealthInput {
        url: "http://127.0.0.1:8080/api/health".into(),
        attempts: 1,
        interval_seconds: 0,
    });
    input
}

const PROBE: &str = "/usr/bin/curl -q --fail --silent --show-error --max-time 3 --output /dev/null -- http://127.0.0.1:8080/api/health";

#[test]
fn readiness_keeps_the_old_container_until_the_probe_succeeds() {
    let mut s = sandbox();
    s.node
        .programs
        .containers
        .insert(NAME.into(), owned(&spec('a'), true));
    converge_container(&mut s.node, &readiness_input()).unwrap();
    assert!(
        position(&s.node, &format!("docker container start {NAME}")) < position(&s.node, PROBE)
    );
    assert!(
        position(&s.node, PROBE)
            < position(
                &s.node,
                &format!("docker container rm --force {NAME}-rollback-running")
            )
    );
    assert_eq!(containers(&s.node), [(NAME.into(), spec('b'), true)]);
}

#[test]
fn failed_readiness_restores_the_previous_container_before_a_later_stop() {
    let mut s = sandbox();
    s.node
        .programs
        .containers
        .insert(NAME.into(), owned(&spec('a'), true));
    s.node.programs.failing.push(PROBE.into());
    assert_eq!(
        converge_container(&mut s.node, &readiness_input()),
        Err(ProcessError::HealthFailed { restored: true })
    );
    assert_eq!(containers(&s.node), [(NAME.into(), spec('a'), true)]);
    // A later supported stop sees the restored container; no workflow rollback follows it.
    control(
        &mut s.node,
        &ControlInput {
            process_id: 7,
            runtime: Runtime::Docker,
            name: NAME.into(),
            operation: Operation::Stop,
            on_demand: false,
        },
    )
    .unwrap();
    assert_eq!(containers(&s.node), [(NAME.into(), spec('a'), false)]);
    assert_eq!(
        s.node.programs.calls.last().unwrap(),
        &format!("docker container stop {NAME}")
    );
}

#[test]
fn interrupted_replacement_checks_health_before_discarding_rollback() {
    let mut s = sandbox();
    s.node
        .programs
        .containers
        .insert(NAME.into(), owned(&spec('b'), true));
    s.node
        .programs
        .containers
        .insert(format!("{NAME}-rollback-running"), owned(&spec('a'), false));
    s.node.programs.failing.push(PROBE.into());
    assert_eq!(
        converge_container(&mut s.node, &readiness_input()),
        Err(ProcessError::HealthFailed { restored: true })
    );
    assert_eq!(containers(&s.node), [(NAME.into(), spec('a'), true)]);
}

#[test]
fn readiness_checks_new_and_unchanged_containers() {
    let mut s = sandbox();
    converge_container(&mut s.node, &readiness_input()).unwrap();
    s.node.programs.calls.clear();
    s.node.programs.failing.push(PROBE.into());
    assert_eq!(
        converge_container(&mut s.node, &readiness_input()),
        Err(ProcessError::HealthFailed { restored: false })
    );
    assert!(s.node.programs.calls.iter().any(|call| call == PROBE));
    assert!(!s
        .node
        .programs
        .calls
        .iter()
        .any(|call| call.contains(" create ")));
}

#[test]
fn invalid_readiness_is_refused_before_container_effects() {
    let mut s = sandbox();
    let mut input = readiness_input();
    input.health.as_mut().unwrap().attempts = 91;
    assert!(matches!(
        converge_container(&mut s.node, &input),
        Err(ProcessError::Invalid { .. })
    ));
    assert!(s.node.programs.calls.is_empty());
}

#[test]
fn readiness_and_supported_controls_share_the_process_lock() {
    let update = readiness_input();
    let stop = ControlInput {
        process_id: 7,
        runtime: Runtime::Docker,
        name: NAME.into(),
        operation: Operation::Stop,
        on_demand: false,
    };
    assert_eq!(ConvergeContainer::target(&update), Some("process:7".into()));
    assert_eq!(
        ControlProcess::target(&stop),
        ConvergeContainer::target(&update)
    );
}

#[test]
fn recovered_readiness_is_not_repeated_after_the_backup_is_deleted() {
    let mut s = sandbox();
    s.node
        .programs
        .containers
        .insert(NAME.into(), owned(&spec('b'), true));
    s.node
        .programs
        .containers
        .insert(format!("{NAME}-rollback-running"), owned(&spec('a'), false));
    s.node.programs.health_answers = vec![true, false];
    converge_container(&mut s.node, &readiness_input()).unwrap();
    assert_eq!(s.node.programs.health_answers, [false]);
    assert_eq!(containers(&s.node), [(NAME.into(), spec('b'), true)]);
}

#[test]
fn stale_container_replacement_refuses_before_any_runtime_change() {
    for current in [None, Some(spec('c'))] {
        let mut s = sandbox();
        if let Some(spec) = current {
            s.node
                .programs
                .containers
                .insert(NAME.into(), owned(&spec, true));
        }
        let mut input = readiness_input();
        input.expected_spec = Some(spec('a'));
        assert_eq!(
            converge_container(&mut s.node, &input),
            Err(ProcessError::SpecChanged {})
        );
        assert!(s
            .node
            .programs
            .calls
            .iter()
            .all(|call| call.contains(" inspect ")));
    }
}

#[test]
fn admitted_replacement_accepts_before_and_completed_after_specs() {
    let mut s = sandbox();
    s.node
        .programs
        .containers
        .insert(NAME.into(), owned(&spec('a'), true));
    let mut input = readiness_input();
    input.expected_spec = Some(spec('a'));
    converge_container(&mut s.node, &input).unwrap();
    converge_container(&mut s.node, &input).unwrap();
    assert_eq!(containers(&s.node), [(NAME.into(), spec('b'), true)]);
}

#[test]
fn a_stop_after_admission_is_not_undone_by_a_version_update() {
    let mut s = sandbox();
    s.node
        .programs
        .containers
        .insert(NAME.into(), owned(&spec('a'), false));
    let mut input = readiness_input();
    input.expected_spec = Some(spec('a'));
    input.expected_running = Some(true);
    assert_eq!(
        converge_container(&mut s.node, &input),
        Err(ProcessError::SpecChanged {})
    );
    assert_eq!(containers(&s.node), [(NAME.into(), spec('a'), false)]);
    assert!(s
        .node
        .programs
        .calls
        .iter()
        .all(|call| call.contains(" inspect ")));
}
