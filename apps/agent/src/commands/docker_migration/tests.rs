use super::*;
use recipe::Output;
use std::os::unix::fs::PermissionsExt;
struct Fixture {
    _work: recipe::WorkDir,
    files: Files,
    input: Input,
    host: Fake,
}
struct Fake {
    root: PathBuf,
    containers: Vec<Value>,
    images: Vec<Value>,
    volumes: Vec<Value>,
    networks: Vec<Value>,
    installed: BTreeMap<String, String>,
    calls: Vec<Vec<String>>,
    requests: Vec<(String, String, Option<Value>)>,
    service: bool,
    fresh: bool,
    incompatible: bool,
    changed: bool,
    local: bool,
    available: u64,
    simulation: String,
    fail_install: bool,
    missing_containerd: bool,
    unavailable_api: bool,
    waits: usize,
    fail_command: Option<String>,
    fail_api: Option<String>,
    extra_info: Value,
    dropins: String,
    fragment: String,
    dump: Option<String>,
    size: Value,
}
fn container(id: &str, name: &str, orbit: bool, running: bool, data: &Path) -> Value {
    let labels = if orbit {
        json!({"orbit.managed":"true","orbit.container.kind":"process","orbit.process.id":"7","orbit.process.spec":"spec-hash"})
    } else {
        json!({"app":"fixture"})
    };
    json!({"Id":id,"Name":format!("/{name}"),"Image":format!("sha256:{}","a".repeat(64)),
        "Config":{"Image":"registry.example/app:stable","Env":["PASSWORD=private-sentinel"],"Labels":labels,"Entrypoint":["/app"],"Cmd":["serve"],"WorkingDir":"/work"},
        "HostConfig":{"RestartPolicy":{"Name":"unless-stopped","MaximumRetryCount":0},"Binds":["mysql:/data:rw"],"Runtime":if orbit {"runc"} else {"nvidia"},"NetworkMode":if orbit {"default"} else {"app-network"},"PortBindings":{"80/tcp":[{"HostIp":"127.0.0.1","HostPort":"8080"}]}},
        "Mounts":[{"Type":"volume","Name":"mysql","Source":data,"Destination":"/data","RW":true}],
        "NetworkSettings":{"Networks":if orbit {json!({"bridge":{"IPAMConfig":null}})} else {json!({"app-network":{"Aliases":["app"],"GwPriority":7}})}},
        "State":{"Running":running,"Status":if running {"running"} else {"exited"}}})
}
impl Fixture {
    fn new() -> Self {
        let work = recipe::WorkDir::new().unwrap();
        let files = Files {
            root: work.0.clone(),
            uid: rustix::process::geteuid().as_raw(),
            gid: rustix::process::getegid().as_raw(),
        };
        for path in [
            "/etc/apt/sources.list.d",
            "/etc/docker",
            "/usr/sbin",
            "/docker",
            "/containerd",
            "/volumes/mysql/_data",
        ] {
            fs::create_dir_all(files.path(path)).unwrap();
        }
        fs::write(files.path("/etc/apt/sources.list.d/docker.sources"), "Types: deb\nURIs: https://download.docker.com/linux/ubuntu\nSuites: resolute\nComponents: stable\nSigned-By: /etc/apt/keyrings/docker.asc\n").unwrap();
        fs::write(files.path(DAEMON), "{\"runtimes\":{\"nvidia\":{\"path\":\"nvidia-container-runtime\",\"runtimeArgs\":[]}}}").unwrap();
        let data = files.path("/volumes/mysql/_data");
        fs::write(data.join("database"), "irreplaceable").unwrap();
        let input: Input = serde_json::from_value(json!({"recipe":"sha256:fixture","snapshot":"20261014T000000Z","architecture":"amd64","docker_version":"29.1.3-0ubuntu4.1","containerd_version":"2.2.2-0ubuntu1.1","fallback":true,"process_ids":["7"],"process_states":{"7":"running"},"process_specs":{"7":{"name":"orbit-process-7-mysql","hash":"spec-hash","volumes":[{"source":"mysql","target":"/data"}]}}})).unwrap();
        let host = Fake {
            root: files.root.clone(),
            containers: vec![
                container("one", "mailpit", false, true, &data),
                container("two", "orbit-process-7-mysql", true, true, &data),
                container("three", "stopped", false, false, &data),
            ],
            images: vec![
                json!({"Id":format!("sha256:{}","a".repeat(64)),"RepoDigests":[format!("registry.example/app@sha256:{}","a".repeat(64))],"RepoTags":["registry.example/app:stable"],"Architecture":"amd64","Os":"linux","Size":1024u64.pow(3)}),
            ],
            volumes: vec![
                json!({"Name":"mysql","Driver":"local","Mountpoint":data,"Options":null,"Labels":{}}),
            ],
            networks: vec![
                json!({"Id":"bridge-id","Name":"bridge","Driver":"bridge","Scope":"local"}),
                json!({"Id":"b".repeat(64),"Name":"app-network","Driver":"bridge","Scope":"local","Internal":false,"IPAM":{"Driver":"default","Config":[{"Subnet":"172.30.0.0/24"}]},"Options":{},"Labels":{}}),
            ],
            installed: CE
                .iter()
                .map(|n| (n.to_string(), "ce-version".into()))
                .collect(),
            calls: vec![],
            requests: vec![],
            service: true,
            fresh: false,
            incompatible: false,
            changed: false,
            local: false,
            available: 100 * 1024u64.pow(3),
            fail_install: false,
            missing_containerd: false,
            unavailable_api: false,
            waits: 0,
            simulation: "Remv docker-ce [ce-version]\nRemv containerd.io [ce-version]\n".into(),
            fail_command: None,
            fail_api: None,
            extra_info: json!({}),
            dropins: String::new(),
            fragment: String::new(),
            dump: None,
            size: json!({"SizeRootFs":1024u64.pow(3),"SizeRw":1024u64.pow(2)}),
        };
        Self {
            _work: work,
            files,
            input,
            host,
        }
    }
    fn run(&mut self, mode: Mode) -> Result<Value> {
        private_journal(&self.files, mode == Mode::Prepare || mode == Mode::Restore)?;
        transaction::run(&mut self.host, &self.files, &self.input, mode)
    }
    fn state(&self) -> State {
        serde_json::from_slice(&fs::read(self.files.path(JOURNAL).join("inventory.json")).unwrap())
            .unwrap()
    }
    fn assert_data(&self, daemon: &[u8]) {
        assert_eq!(fs::read(self.files.path(DAEMON)).unwrap(), daemon);
        assert_eq!(
            fs::read_to_string(self.files.path("/volumes/mysql/_data/database")).unwrap(),
            "irreplaceable"
        );
    }
}
fn decode(s: &str) -> String {
    let mut out = vec![];
    let b = s.as_bytes();
    let mut i = 0;
    while i < b.len() {
        if b[i] == b'%' {
            out.push(u8::from_str_radix(&s[i + 1..i + 3], 16).unwrap());
            i += 3;
        } else {
            out.push(b[i]);
            i += 1;
        }
    }
    String::from_utf8(out).unwrap()
}
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> std::result::Result<Output, recipe::Failure> {
        self.calls.push(call.argv.clone());
        let args = &call.argv;
        let mut out = Output::default();
        if self
            .fail_command
            .as_ref()
            .is_some_and(|prefix| args.join(" ").starts_with(prefix))
        {
            self.fail_command = None;
            out.code = 1;
            out.stderr = "PASSWORD=private-sentinel".into();
            if args[0] == "apt-get" && args.contains(&"--yes".into()) {
                self.installed.clear();
            }
            return Ok(out);
        }
        match args[0].as_str() {
            "dpkg-query" => {
                out.stdout = self
                    .installed
                    .iter()
                    .map(|(n, v)| format!("{n}\tamd64\t{v}\tinstall ok installed\n"))
                    .collect()
            }
            "dpkg" if args[1] == "--compare-versions" => {
                out.code = if args[2] < args[4] { 0 } else { 1 }
            }
            "dpkg" if args[1] == "--print-architecture" => out.stdout = "amd64\n".into(),
            "dpkg" => {}
            "containerd" => {
                out.stdout = self.dump.clone().unwrap_or_else(|| {
                    format!("root = '{}'\n", self.root.join("containerd").display())
                })
            }
            "systemctl" if args[1] == "show" => {
                out.stdout = match args[2].as_str() {
                    "--property=DropInPaths" => self.dropins.clone(),
                    "--property=FragmentPath" => self.fragment.clone(),
                    "--property=LoadState" => {
                        if self.missing_containerd
                            && args.last().is_some_and(|s| s == "containerd.service")
                        {
                            "not-found".into()
                        } else {
                            "loaded".into()
                        }
                    }
                    _ => panic!("{args:?}"),
                }
            }
            "systemctl" if args[1] == "stop" => self.service = false,
            "systemctl" if args[1] == "start" => {
                self.service = true;
                if self.root.join(DROPIN.trim_start_matches('/')).exists() && !self.fresh {
                    self.fresh = true;
                    self.containers.clear();
                    self.volumes.clear();
                    self.networks.retain(|n| n["Name"] == "bridge");
                    fs::create_dir_all(
                        self.root
                            .join(JOURNAL.trim_start_matches('/'))
                            .join("engine"),
                    )
                    .unwrap();
                }
            }
            "systemctl" => {}
            "apt-get" if args[1] == "install" && args.contains(&"--simulate".into()) => {
                out.stdout = self.simulation.clone()
            }
            "apt-get" if args[1] == "install" && args.contains(&"--yes".into()) => {
                assert!(self.root.join("usr/sbin/policy-rc.d").exists());
                assert!(!args.iter().any(|a| a.starts_with("Dir::State=")
                    || a.starts_with("Dir::Log=")
                    || a.starts_with("Dir=")));
                if self.fail_install {
                    self.fail_install = false;
                    self.installed.clear();
                    out.code = 100;
                    out.stderr = "private-sentinel".into();
                    return Ok(out);
                }
                self.installed = [
                    ("docker.io".into(), "29.1.3-0ubuntu4.1".into()),
                    ("containerd".into(), "2.2.2-0ubuntu1.1".into()),
                    ("docker-buildx".into(), "fixture".into()),
                    ("docker-compose-v2".into(), "fixture".into()),
                ]
                .into();
            }
            "apt-get" => {}
            "docker" if args[1] == "manifest" => out.code = if self.local { 1 } else { 0 },
            "docker" if args[1] == "save" => {
                fs::write(&args[3], "archive").unwrap();
            }
            "docker" if args[1] == "commit" => {
                out.stdout = format!(
                    "sha256:{}",
                    if args[2] == "one" { "c" } else { "d" }.repeat(64)
                )
            }
            "docker" if ["start", "stop"].contains(&args[1].as_str()) => {
                let id = args.last().unwrap();
                let c = self
                    .containers
                    .iter_mut()
                    .find(|c| c["Id"] == *id || text(&c["Name"]).trim_start_matches('/') == id)
                    .unwrap();
                c["State"] = json!({"Running":args[1]=="start","Status":if args[1]=="start" {"running"} else {"exited"}});
            }
            "docker" if ["pull", "tag", "load"].contains(&args[1].as_str()) => {}
            _ => panic!("Unexpected command {args:?}"),
        }
        Ok(out)
    }
    fn wait(&mut self, _: Duration) -> std::result::Result<(), recipe::Failure> {
        self.waits += 1;
        Ok(())
    }
}
impl DockerHost for Fake {
    fn free_space(&mut self, _: &Path) -> Result<u64> {
        Ok(self.available)
    }
    fn api(
        &mut self,
        method: &str,
        path: &str,
        body: Option<&Value>,
        missing: bool,
    ) -> Result<Value> {
        self.requests
            .push((method.into(), path.into(), body.cloned()));
        if !self.service || self.unavailable_api {
            return Err(fail("docker-api"));
        }
        if self.fail_api.as_ref().is_some_and(|p| path.starts_with(p)) {
            self.fail_api = None;
            return Err(fail("docker-api"));
        }
        if path == "/info" {
            let mut info = json!({"DockerRootDir":if self.fresh {self.root.join(JOURNAL.trim_start_matches('/')).join("engine")} else {self.root.join("docker")}});
            info.as_object_mut()
                .unwrap()
                .extend(self.extra_info.as_object().unwrap().clone());
            return Ok(info);
        }
        if path == "/containers/json?all=1" {
            return Ok(json!(self
                .containers
                .iter()
                .map(|c| json!({"Id":c["Id"]}))
                .collect::<Vec<_>>()));
        }
        if path == "/images/json?all=1" {
            return Ok(json!(self.images));
        }
        if path == "/volumes" {
            return Ok(json!({"Volumes":self.volumes}));
        }
        if path == "/networks" {
            return Ok(json!(self.networks));
        }
        if path.ends_with("/json?size=1") {
            return Ok(self.size.clone());
        }
        if path.ends_with("/changes") {
            return Ok(if self.changed {
                json!([{"Path":"/changed","Kind":1}])
            } else {
                json!([])
            });
        }
        if path.starts_with("/containers/create?name=") {
            let name = decode(path.split('=').nth(1).unwrap());
            let body = body.unwrap();
            let mut c = container(
                &format!("new-{name}"),
                &name,
                false,
                false,
                &self.root.join("volumes/mysql/_data"),
            );
            c["Config"] = body.clone();
            c["Config"].as_object_mut().unwrap().remove("HostConfig");
            c["Config"]
                .as_object_mut()
                .unwrap()
                .remove("NetworkingConfig");
            c["HostConfig"] = body["HostConfig"].clone();
            c["Image"] = if text(&body["Image"]).starts_with("sha256:") {
                body["Image"].clone()
            } else {
                self.images[0]["Id"].clone()
            };
            self.containers.push(c);
            return Ok(json!({}));
        }
        if path.starts_with("/containers/") {
            let id = decode(path.split('/').nth(2).unwrap());
            let c = self
                .containers
                .iter_mut()
                .find(|c| c["Id"] == id || text(&c["Name"]).trim_start_matches('/') == id);
            if let Some(c) = c {
                if path.ends_with("/update") {
                    c["HostConfig"]["RestartPolicy"] = body.unwrap()["RestartPolicy"].clone();
                    return Ok(json!({}));
                }
                return Ok(c.clone());
            }
            if missing {
                return Ok(Value::Null);
            }
            return Err(fail("docker-api"));
        }
        if path.starts_with("/images/") {
            return Ok(
                if self.incompatible && self.installed.contains_key("docker.io") && !self.fresh {
                    Value::Null
                } else {
                    self.images[0].clone()
                },
            );
        }
        if path == "/volumes/create" {
            let b = body.unwrap();
            let v = json!({"Name":b["Name"],"Options":b["DriverOpts"]});
            self.volumes.push(v.clone());
            return Ok(v);
        }
        if path.starts_with("/volumes/") {
            let name = decode(path.trim_start_matches("/volumes/"));
            return Ok(self
                .volumes
                .iter()
                .find(|v| v["Name"] == name)
                .cloned()
                .unwrap_or_default());
        }
        if path == "/networks/create" {
            let mut n = body.unwrap().clone();
            n["Id"] = json!(format!("new-{}", text(&n["Name"])));
            n["Scope"] = json!("local");
            self.networks.push(n);
            return Ok(json!({}));
        }
        if path.starts_with("/networks/") {
            let id = decode(path.trim_start_matches("/networks/"));
            return Ok(self
                .networks
                .iter()
                .find(|n| n["Id"] == id || n["Name"] == id)
                .cloned()
                .unwrap_or_default());
        }
        panic!("Unexpected API {method} {path}");
    }
}
#[test]
fn plan_is_read_only_and_public_output_has_no_inspect_secrets() {
    let mut f = Fixture::new();
    let daemon = fs::read(f.files.path(DAEMON)).unwrap();
    let plan = f.run(Mode::Plan).unwrap();
    assert_eq!(
        plan["containers"],
        json!(["mailpit", "orbit-process-7-mysql", "stopped"])
    );
    assert_eq!(plan["space"]["sufficient"], true);
    assert!(!plan.to_string().contains("private-sentinel"));
    assert!(!f.files.path(JOURNAL).exists());
    f.assert_data(&daemon);
    assert!(f.host.requests.iter().all(|(m, _, _)| m == "GET"));
    assert!(f.host.calls.iter().all(|a| a[0] == "dpkg-query"
        || a == &["dpkg", "--print-architecture"]
        || a[0] == "containerd"
        || a.get(1).is_some_and(|v| v == "show" || v == "manifest")));
}
#[test]
fn native_success_repeats_without_swapping_and_preserves_config_data_and_backups() {
    let mut f = Fixture::new();
    let daemon = fs::read(f.files.path(DAEMON)).unwrap();
    let result = f.run(Mode::Prepare).unwrap();
    assert_eq!(result["fallback"], false);
    assert_eq!(result["process_ids"], json!(["7"]));
    assert!(!result.to_string().contains("private-sentinel"));
    f.assert_data(&daemon);
    assert!(!f
        .files
        .path("/etc/apt/sources.list.d/docker.sources")
        .exists());
    assert!(f
        .files
        .path("/etc/apt/docker.sources.orbit-docker-backup")
        .exists());
    let count = f.host.calls.len();
    f.run(Mode::Prepare).unwrap();
    assert_eq!(count, f.host.calls.len());
    assert!(!f.files.path("/usr/sbin/policy-rc.d").exists());
    // Gateway reconciliation is outside the migration: the Orbit container remains stopped until it runs.
    assert_eq!(f.run(Mode::Report).unwrap()["healthy"], false);
    f.host.containers[1]["State"] = json!({"Running":true,"Status":"running"});
    assert_eq!(f.run(Mode::Report).unwrap()["healthy"], true);
}
#[test]
fn fallback_is_explicit_preserves_every_setting_and_reuses_original_data() {
    let mut f = Fixture::new();
    f.host.incompatible = true;
    f.input.fallback = false;
    let daemon = fs::read(f.files.path(DAEMON)).unwrap();
    assert_eq!(f.run(Mode::Prepare).unwrap_err().stage, "fallback-required");
    assert!(f.state().swapped);
    assert!(!f.host.fresh);
    f.input.fallback = true;
    f.run(Mode::Prepare).unwrap();
    f.assert_data(&daemon);
    assert!(f.host.fresh);
    assert_eq!(f.host.containers.len(), 2);
    let recreated = &f.host.containers[0];
    assert_eq!(
        recreated["Config"]["Env"],
        json!(["PASSWORD=private-sentinel"])
    );
    assert_eq!(recreated["HostConfig"]["Runtime"], "nvidia");
    assert_eq!(recreated["HostConfig"]["Binds"], json!(["mysql:/data:rw"]));
    assert_eq!(
        f.host.volumes[0]["Options"]["device"],
        json!(f.files.path("/volumes/mysql/_data"))
    );
    let stop = f
        .host
        .calls
        .iter()
        .position(|a| a == &["systemctl", "mask", "containerd.service"])
        .unwrap();
    let reload = f
        .host
        .calls
        .iter()
        .position(|a| a == &["systemctl", "daemon-reload"])
        .unwrap();
    assert!(stop < reload);
    let count = f.host.calls.len();
    f.run(Mode::Prepare).unwrap();
    assert_eq!(count, f.host.calls.len());
}
#[test]
fn unsafe_inventory_refuses_before_stops_sources_or_journal_records() {
    let cases = [
        ("/Config/Future", json!(true), "newer-engine-configuration"),
        (
            "/HostConfig/AutoRemove",
            json!(true),
            "unsupported-container",
        ),
        (
            "/HostConfig/PidMode",
            json!("container:other"),
            "unsupported-container",
        ),
        ("/Config/Image", json!(""), "missing-image"),
        (
            "/Config/Labels/com.docker.swarm.service.id",
            json!("service"),
            "swarm",
        ),
        (
            "/HostConfig/LogConfig",
            json!({"Type":"plugin"}),
            "unsupported-logging-plugin",
        ),
        (
            "/HostConfig/Mounts",
            json!([{"Type":"volume","VolumeOptions":{"Future":true}}]),
            "newer-engine-configuration",
        ),
    ];
    for (pointer, value, expected) in cases {
        let mut f = Fixture::new();
        let (parent, key) = pointer.rsplit_once('/').unwrap();
        f.host.containers[0].pointer_mut(parent).unwrap()[key] = value;
        assert_eq!(
            f.run(Mode::Prepare).unwrap_err().stage,
            expected,
            "{pointer}"
        );
        assert!(!f.files.path(JOURNAL).join("inventory.json").exists());
        assert!(f.host.requests.iter().all(|(m, _, _)| m == "GET"));
        assert!(f
            .files
            .path("/etc/apt/sources.list.d/docker.sources")
            .exists());
    }
}
#[test]
fn orbit_spec_network_mount_and_runtime_drift_refuse_before_mutation() {
    for (kind, expected) in [
        (0, "orbit-spec-changed"),
        (1, "orbit-unrecorded-mount"),
        (2, "orbit-runtime-drift"),
        (3, "orbit-network-drift"),
        (4, "orbit-spec-missing"),
    ] {
        let mut f = Fixture::new();
        match kind {
            0 => f.input.process_specs.get_mut("7").unwrap().hash = "other".into(),
            1 => f.input.process_specs.get_mut("7").unwrap().volumes.clear(),
            2 => f.host.containers[1]["HostConfig"]["Runtime"] = json!("nvidia"),
            3 => f.host.containers[1]["HostConfig"]["NetworkMode"] = json!("host"),
            _ => f.input.process_ids.clear(),
        };
        if kind == 4 {
            f.input.process_specs.clear();
            f.input.process_states.clear();
        }
        assert_eq!(f.run(Mode::Prepare).unwrap_err().stage, expected);
        assert!(f.host.requests.iter().all(|(m, _, _)| m == "GET"));
    }
}
#[test]
fn insufficient_or_unknown_space_refuses_before_policy_change() {
    for kind in 0..3 {
        let mut f = Fixture::new();
        match kind {
            0 => f.host.available = 1,
            1 => {
                f.host.local = true;
                f.host.images[0]["Size"] = Value::Null;
            }
            _ => f.host.size["SizeRw"] = json!(-1),
        };
        assert_eq!(
            f.run(Mode::Prepare).unwrap_err().stage,
            if kind == 0 {
                "insufficient-space"
            } else {
                "image-size"
            }
        );
        assert!(f.host.requests.iter().all(|(m, _, _)| m == "GET"));
    }
}
#[test]
fn local_images_budget_and_shutdown_commits_are_durable_before_swap() {
    let mut f = Fixture::new();
    f.host.local = true;
    f.host.changed = true;
    f.host.incompatible = true;
    let plan = f.run(Mode::Plan).unwrap();
    assert_eq!(plan["space"]["image_bytes"], 1024u64.pow(3));
    assert_eq!(
        plan["space"]["commit_bytes"],
        2 * (1024u64.pow(3) + 2 * 1024u64.pow(2))
    );
    f.run(Mode::Prepare).unwrap();
    let state = f.state();
    assert_eq!(state.archives.len(), 3);
    assert_eq!(state.committed.len(), 2);
    assert!(state.committed.keys().all(|k| k.ends_with(":shutdown")));
    let stop = f
        .host
        .calls
        .iter()
        .position(|a| a[0] == "docker" && a[1] == "stop")
        .unwrap();
    let commit = f
        .host
        .calls
        .iter()
        .position(|a| a[0] == "docker" && a[1] == "commit")
        .unwrap();
    assert!(stop < commit);
    assert!(f.host.calls.iter().any(|a| a[0] == "docker"
        && a[1] == "tag"
        && a.last().unwrap() == "registry.example/app:stable"));
}
#[test]
fn archive_failure_keeps_stopped_state_and_reuses_committed_image_on_resume() {
    let mut f = Fixture::new();
    f.host.changed = true;
    f.host.fail_command = Some("docker save".into());
    assert!(f.run(Mode::Prepare).is_err());
    let state = f.state();
    assert_eq!(state.stage, "preserve-shutdown-layers");
    assert!(state.stopped);
    assert!(!state.swapped);
    assert_eq!(state.committed.len(), 1);
    f.run(Mode::Prepare).unwrap();
    assert_eq!(
        f.host
            .calls
            .iter()
            .filter(|a| a[0] == "docker" && a[1] == "commit" && a[2] == "one")
            .count(),
        1
    );
}
#[test]
fn stop_failure_restores_original_policies_and_never_swaps() {
    let mut f = Fixture::new();
    f.host.changed = true;
    f.host.fail_command = Some("docker stop".into());
    assert!(f.run(Mode::Prepare).is_err());
    assert_eq!(f.state().stage, "post-stop-refusal");
    assert!(f.host.containers[0]["State"]["Running"] == true);
    assert!(!f.host.calls.iter().any(|a| a[0] == "apt-get"));
    assert!(f.state().committed.is_empty());
}
#[test]
fn failed_policy_disable_restores_original_without_running_snapshot() {
    let mut f = Fixture::new();
    f.host.changed = true;
    f.host.fail_api = Some("/containers/one/update".into());
    assert!(f.run(Mode::Prepare).is_err());
    assert_eq!(f.state().stage, "post-stop-refusal");
    assert!(!f
        .host
        .calls
        .iter()
        .any(|a| a[0] == "docker" && a[1] == "commit"));
}
#[test]
fn package_failure_after_removal_repairs_without_a_docker_socket() {
    let mut f = Fixture::new();
    f.host.fail_command=Some("apt-get install --no-install-recommends --allow-downgrades -o Dpkg::Options::=--force-confold".into());
    // First fail the simulated transaction; pre-swap recovery must restart the old engine.
    assert!(f.run(Mode::Prepare).is_err());
    assert!(!f.state().swapping);
    assert!(f.host.service);
    f.host.fail_install = true;
    let error = f.run(Mode::Prepare).unwrap_err();
    assert_eq!(error.stage, "swapping");
    assert!(!error.details().to_string().contains("private-sentinel"));
    assert!(f.state().swapping);
    assert!(f.state().images_preserved);
    assert!(!f.host.service);
    assert!(f.host.installed.is_empty());
    f.run(Mode::Prepare).unwrap();
    assert!(f.state().swapped);
    let configure = f
        .host
        .calls
        .iter()
        .position(|a| a == &["dpkg", "--configure", "-a"])
        .unwrap();
    let repair = f
        .host
        .calls
        .iter()
        .position(|a| a.starts_with(&["apt-get".into(), "-f".into(), "install".into()]))
        .unwrap();
    assert!(configure < repair);
    assert!(!f.files.path("/usr/sbin/policy-rc.d").exists());
}
#[test]
fn package_guard_refuses_unrelated_removal_downgrade_and_malformed_plan() {
    for (simulation, expected) in [
        ("Remv nvidia-toolkit [1]", "unrelated-package-removal"),
        ("Inst libc6 [3] (2 Ubuntu)", "unrelated-package-downgrade"),
        ("Inst malformed", "apt-plan"),
    ] {
        let mut f = Fixture::new();
        f.host.simulation = simulation.into();
        assert_eq!(f.run(Mode::Prepare).unwrap_err().stage, expected);
        assert!(!f.state().swapped);
        assert!(f.host.service);
    }
}
#[test]
fn backup_selects_metadata_without_copying_volume_or_layer_payloads() {
    let mut f = Fixture::new();
    let root = f.files.path("/docker");
    for name in [
        "volumes/metadata.db",
        "volumes/mysql/_data/database",
        "containers/one/config.v2.json",
        "containers/one/hostconfig.json",
        "containers/one/huge.log",
        "image/overlay2/imagedb/content/hash",
        "image/overlay2/layerdb/sha256/hash/cache-id",
        "overlay2/hash/diff/data",
    ] {
        let path = root.join(name);
        fs::create_dir_all(path.parent().unwrap()).unwrap();
        fs::write(path, name).unwrap();
    }
    f.run(Mode::Prepare).unwrap();
    let backup = f.files.path(JOURNAL).join("metadata/docker");
    assert!(backup.join("volumes/metadata.db").exists());
    assert!(backup.join("containers/one/config.v2.json").exists());
    assert!(backup.join("image/overlay2/imagedb/content/hash").exists());
    assert!(!backup.join("volumes/mysql").exists());
    assert!(!backup.join("containers/one/huge.log").exists());
    assert!(!backup.join("overlay2").exists());
}
#[test]
fn invalid_daemon_service_and_storage_roots_refuse_without_recording() {
    for kind in 0..6 {
        let mut f = Fixture::new();
        let expected = match kind {
            0 => {
                fs::write(f.files.path(DAEMON), r#"{"hosts":[]}"#).unwrap();
                "custom-daemon"
            }
            1 => {
                f.host.dropins = "/etc/systemd/foreign.conf".into();
                "custom-daemon"
            }
            2 => {
                f.host.fragment = "/etc/systemd/system/docker.service".into();
                "custom-daemon"
            }
            3 => {
                f.host.extra_info = json!({"DockerRootDir":"/"});
                "storage-root"
            }
            4 => {
                f.host.dump = Some("root='/missing/path'".into());
                "containerd-root"
            }
            _ => {
                f.host.fail_command = Some("containerd config dump".into());
                "containerd-root"
            }
        };
        assert_eq!(f.run(Mode::Prepare).unwrap_err().stage, expected);
        assert!(!f.files.path(JOURNAL).join("inventory.json").exists());
    }
}
#[test]
fn resume_refuses_recipe_daemon_and_spec_changes_without_new_mutation() {
    for kind in 0..3 {
        let mut f = Fixture::new();
        f.run(Mode::Prepare).unwrap();
        let count = f.host.calls.len();
        let expected = match kind {
            0 => {
                f.input.recipe = "sha256:other".into();
                "recipe-mismatch"
            }
            1 => {
                fs::write(f.files.path(DAEMON), "{}").unwrap();
                "daemon-changed"
            }
            _ => {
                f.input.process_specs.get_mut("7").unwrap().hash = "changed".into();
                "orbit-spec-changed"
            }
        };
        assert_eq!(f.run(Mode::Prepare).unwrap_err().stage, expected);
        assert_eq!(f.host.calls.len(), count);
    }
}
#[test]
fn recovery_restores_original_policies_and_running_states_and_can_repeat() {
    let mut f = Fixture::new();
    f.run(Mode::Prepare).unwrap();
    assert_eq!(f.run(Mode::Restore).unwrap_err().stage, "restore-engine");
    f.host
        .installed
        .insert("docker-ce".into(), "ce-version".into());
    f.host.containers[2]["State"] = json!({"Running":true,"Status":"running"});
    f.host.fail_command = Some("docker start one".into());
    assert!(f.run(Mode::Restore).is_err());
    assert_eq!(f.state().stage, "recover-policies");
    f.run(Mode::Restore).unwrap();
    assert_eq!(f.host.containers[0]["State"]["Running"], true);
    assert_eq!(f.host.containers[2]["State"]["Running"], false);
    f.run(Mode::Restore).unwrap();
    assert_eq!(f.run(Mode::Prepare).unwrap_err().stage, "already-restored");
}
#[test]
fn read_report_does_not_create_a_journal_and_unreadable_containers_fail_health() {
    let mut f = Fixture::new();
    assert_eq!(f.run(Mode::Report).unwrap_err().stage, "missing-inventory");
    assert!(!f.files.path(JOURNAL).exists());
    f.run(Mode::Prepare).unwrap();
    f.host.service = false;
    let result = f.run(Mode::Report).unwrap();
    assert_eq!(result["healthy"], false);
    assert!(array(&result["containers"])
        .iter()
        .all(|c| c["state"] == "unreadable"));
}
#[test]
fn journal_permissions_refuse_symlinks_or_public_inventory() {
    let mut f = Fixture::new();
    f.run(Mode::Prepare).unwrap();
    let path = f.files.path(JOURNAL).join("inventory.json");
    fs::set_permissions(&path, fs::Permissions::from_mode(0o644)).unwrap();
    assert_eq!(
        f.run(Mode::Report).unwrap_err().stage,
        "journal-permissions"
    );
    fs::remove_file(&path).unwrap();
    std::os::unix::fs::symlink(f.files.path(DAEMON), &path).unwrap();
    assert_eq!(
        f.run(Mode::Prepare).unwrap_err().stage,
        "journal-permissions"
    );
}
#[test]
fn effective_defaults_and_network_bridge_names_survive_fallback() {
    let info = json!({"DefaultRuntime":"runc","LoggingDriver":"json-file"});
    assert_eq!(
        recovery::effective_host(&json!({}), &info),
        recovery::effective_host(
            &json!({"Runtime":"runc","LogConfig":{"Type":"json-file","Config":{}},"RestartPolicy":{"Name":"no","MaximumRetryCount":0},"NetworkMode":"default"}),
            &info
        )
    );
    let mut f = Fixture::new();
    f.host.incompatible = true;
    f.host.networks[1]["Options"] = json!({"com.docker.network.bridge.name":"app-br"});
    f.run(Mode::Prepare).unwrap();
    let state = f.state();
    recovery::fresh(&mut f.host, &f.files, &state).unwrap();
    assert_eq!(
        f.host
            .networks
            .iter()
            .filter(|n| n["Name"] == "app-network")
            .count(),
        1
    );
    f.host.networks[1]["Options"] = json!({"com.docker.network.bridge.name":"wrong"});
    assert_eq!(
        recovery::fresh(&mut f.host, &f.files, &state)
            .unwrap_err()
            .stage,
        "network-conflict"
    );
}
#[test]
fn partial_fallback_resumes_without_second_swap_or_replacing_inventory() {
    let mut f = Fixture::new();
    f.host.incompatible = true;
    f.host.fail_api = Some("/containers/create?name=stopped".into());
    assert!(f.run(Mode::Prepare).is_err());
    assert_eq!(f.state().stage, "recreate");
    let before = f.state().containers;
    f.run(Mode::Prepare).unwrap();
    assert_eq!(before, f.state().containers);
    assert_eq!(
        f.host
            .calls
            .iter()
            .filter(|a| a[0] == "apt-get" && a[1] == "install" && a.contains(&"--yes".into()))
            .count(),
        1
    );
    assert_eq!(f.host.containers.len(), 2);
}
#[test]
fn anonymous_volumes_and_network_settings_keep_their_original_identity() {
    let mut f = Fixture::new();
    f.host.incompatible = true;
    f.host.containers[0]["HostConfig"]["Binds"] = json!([]);
    f.host.containers[0]["HostConfig"]["Mounts"] =
        json!([{"Type":"volume","Target":"/data","Source":"","VolumeOptions":{"NoCopy":true}}]);
    f.run(Mode::Prepare).unwrap();
    let (_, _, Some(body)) = f
        .host
        .requests
        .iter()
        .find(|(_, p, _)| p == "/containers/create?name=mailpit")
        .unwrap()
    else {
        panic!()
    };
    assert_eq!(body["HostConfig"]["Mounts"][0]["Source"], "mysql");
    assert_eq!(
        body["NetworkingConfig"]["EndpointsConfig"]["app-network"]["GwPriority"],
        7
    );
    assert_eq!(
        body["NetworkingConfig"]["EndpointsConfig"]["app-network"]["Aliases"],
        json!(["app"])
    );
}
#[test]
fn changed_volume_network_container_or_image_refuses_fallback_resume() {
    for kind in 0..3 {
        let mut f = Fixture::new();
        f.host.incompatible = true;
        f.run(Mode::Prepare).unwrap();
        let mut state = f.state();
        state.prepared = false;
        record(&f.files, "inventory.json", &state).unwrap();
        let expected = match kind {
            0 => {
                f.host.volumes[0]["Options"]["device"] = json!("/wrong");
                "volume-conflict"
            }
            1 => {
                f.host.networks[1]["Internal"] = json!(true);
                "network-conflict"
            }
            _ => {
                f.host.containers[0]["HostConfig"]["Runtime"] = json!("runc");
                "container-conflict"
            }
        };
        assert_eq!(f.run(Mode::Prepare).unwrap_err().stage, expected);
    }
    let mut f = Fixture::new();
    let c = f.host.containers.remove(0);
    f.host.images[0]["Id"] = json!("wrong");
    assert_eq!(
        recovery::recreate(&mut f.host, &c, Some("registry.example/app@sha256:digest"))
            .unwrap_err()
            .stage,
        "image-version"
    );
}
#[test]
fn custom_data_root_is_recorded_backed_up_and_kept_out_of_fallback_config() {
    let mut f = Fixture::new();
    let custom = f.files.path("/custom/docker");
    fs::create_dir_all(custom.join("volumes")).unwrap();
    fs::write(custom.join("volumes/metadata.db"), "metadata").unwrap();
    f.host.extra_info = json!({"DockerRootDir":custom});
    let mut config: Value =
        serde_json::from_slice(&fs::read(f.files.path(DAEMON)).unwrap()).unwrap();
    config["data-root"] = json!(custom);
    config["log-driver"] = json!("json-file");
    let raw = serde_json::to_vec(&config).unwrap();
    fs::write(f.files.path(DAEMON), &raw).unwrap();
    f.host.incompatible = true;
    f.run(Mode::Prepare).unwrap();
    assert_eq!(f.state().metadata_roots["docker"], custom);
    assert!(f
        .files
        .path(JOURNAL)
        .join("metadata/docker/volumes/metadata.db")
        .exists());
    f.assert_data(&raw);
    let fallback: Value =
        serde_json::from_slice(&fs::read(f.files.path(JOURNAL).join("daemon.json")).unwrap())
            .unwrap();
    assert!(fallback.get("data-root").is_none());
    assert_eq!(fallback["runtimes"], config["runtimes"]);
    assert_eq!(fallback["log-driver"], "json-file");
}
#[test]
fn recovery_requires_every_original_container_and_effective_root_before_policy_change() {
    for kind in 0..3 {
        let mut f = Fixture::new();
        f.run(Mode::Prepare).unwrap();
        f.host
            .installed
            .insert("docker-ce".into(), "version".into());
        match kind {
            0 => {
                f.host.containers.remove(0);
            }
            1 => f.host.containers[0]["Name"] = json!("/other"),
            _ => f.host.extra_info = json!({"DockerRootDir":f.files.path("/containerd")}),
        };
        let count = f.host.requests.len();
        assert_eq!(
            f.run(Mode::Restore).unwrap_err().stage,
            if kind == 2 {
                "restore-engine"
            } else {
                "restore-container"
            }
        );
        assert!(f.host.requests[count..].iter().all(|(m, _, _)| m == "GET"));
    }
}
#[test]
fn archive_space_counts_only_used_unpullable_images_and_commits_not_repeated() {
    let mut f = Fixture::new();
    f.host.local = true;
    let mut unused = f.host.images[0].clone();
    unused["Id"] = json!("unused");
    unused["Size"] = json!(99 * 1024u64.pow(3));
    // Image lookup uses inspect IDs, so exercise the actual space helper with the complete inventory.
    let mut state = inventory::collect(&mut f.host, &f.files, &f.input).unwrap();
    state.images.push(unused);
    assert_eq!(engine::archive_images(&state).len(), 1);
    assert_eq!(
        engine::space(&mut f.host, &f.files, &state).unwrap()["image_bytes"],
        1024u64.pow(3)
    );
    state
        .committed
        .insert("one:shutdown".into(), "saved".into());
    state
        .archives
        .insert("saved".into(), PathBuf::from("saved.tar"));
    assert_eq!(
        engine::space(&mut f.host, &f.files, &state).unwrap()["commit_bytes"],
        1024u64.pow(3) + 2 * 1024u64.pow(2)
    );
}
#[test]
fn ownership_refusals_cover_mounts_platforms_volumes_networks_and_newer_fields() {
    for (kind, expected) in [
        (0, "unsupported-mount"),
        (1, "missing-mount"),
        (2, "foreign-image-platform"),
        (3, "unsupported-volume"),
        (4, "unsupported-network"),
        (5, "unsupported-container-links"),
        (6, "newer-engine-configuration"),
        (7, "newer-engine-configuration"),
        (8, "newer-engine-configuration"),
    ] {
        let mut f = Fixture::new();
        match kind {
            0 => f.host.containers[0]["Mounts"][0]["Type"] = json!("image"),
            1 => f.host.containers[0]["Mounts"][0]["Source"] = json!("/absent-mount"),
            2 => f.host.images[0]["Architecture"] = json!("arm64"),
            3 => f.host.volumes[0]["Options"] = json!({"type":"nfs"}),
            4 => f.host.networks[1]["Driver"] = json!("overlay"),
            5 => {
                f.host.containers[0]["NetworkSettings"]["Networks"]["app-network"]["Links"] =
                    json!(["other"])
            }
            6 => f.host.containers[0]["Config"]["Healthcheck"] = json!({"Future":true}),
            7 => {
                f.host.containers[0]["NetworkSettings"]["Networks"]["app-network"]["IPAMConfig"] =
                    json!({"Future":"value"})
            }
            _ => {
                f.host.containers[0]["HostConfig"]["Mounts"] =
                    json!([{"Type":"tmpfs","TmpfsOptions":{"Future":true}}])
            }
        };
        assert_eq!(f.run(Mode::Prepare).unwrap_err().stage, expected);
        assert!(f.host.requests.iter().all(|(m, _, _)| m == "GET"));
    }
}
#[test]
fn source_backup_conflict_and_preexisting_restart_policy_do_not_get_overwritten() {
    let mut f = Fixture::new();
    fs::write(
        f.files.path("/etc/apt/docker.sources.orbit-docker-backup"),
        "different",
    )
    .unwrap();
    assert_eq!(f.run(Mode::Prepare).unwrap_err().stage, "apt-source");
    assert!(f.host.requests.iter().all(|(m, _, _)| m == "GET"));
    let mut f = Fixture::new();
    fs::write(f.files.path("/usr/sbin/policy-rc.d"), "operator policy").unwrap();
    assert_eq!(f.run(Mode::Prepare).unwrap_err().stage, "restart-policy");
    assert_eq!(
        fs::read_to_string(f.files.path("/usr/sbin/policy-rc.d")).unwrap(),
        "operator policy"
    );
    assert!(!f.state().swapped);
}
#[test]
fn contract_uses_native_supervision_root_and_shared_package_lock() {
    use crate::command::registry;
    let contract = registry::contract();
    let encoded = serde_json::to_value(contract).unwrap();
    for name in [Plan::NAME, Prepare::NAME, Restore::NAME, Report::NAME] {
        assert!(encoded.to_string().contains(name));
    }
    assert_eq!(Prepare::TIMEOUT, Duration::from_secs(3600));
    assert_eq!(Prepare::RESOURCES, &["packages"]);
    assert_eq!(Prepare::privilege(Platform::Linux), Some(Privilege::Root));
    assert_eq!(Prepare::privilege(Platform::Macos), None);
}
#[test]
fn native_unix_transport_uses_latest_reads_versioned_mutations_and_no_redirects() {
    use std::io::{BufRead, BufReader, Read, Write};
    use std::os::unix::net::UnixListener;
    let work = recipe::WorkDir::new().unwrap();
    let socket = work.0.join("docker.sock");
    let listener = UnixListener::bind(&socket).unwrap();
    let server = std::thread::spawn(move || {
        let mut received = vec![];
        for response in ["200 OK", "200 OK", "404 Not Found", "302 Found"] {
            let (mut stream, _) = listener.accept().unwrap();
            stream
                .set_read_timeout(Some(Duration::from_secs(5)))
                .unwrap();
            let mut reader = BufReader::new(&mut stream);
            let mut first = String::new();
            reader.read_line(&mut first).unwrap();
            let mut length = 0;
            loop {
                let mut line = String::new();
                reader.read_line(&mut line).unwrap();
                if line == "\r\n" {
                    break;
                }
                if let Some(value) = line.to_ascii_lowercase().strip_prefix("content-length:") {
                    length = value.trim().parse().unwrap();
                }
            }
            let mut body = vec![0; length];
            reader.read_exact(&mut body).unwrap();
            received.push((first, body));
            write!(stream,"HTTP/1.1 {response}\r\nContent-Length: 2\r\nContent-Type: application/json\r\nLocation: http://other/secret\r\nConnection: close\r\n\r\n{{}}").unwrap();
        }
        received
    });
    let context = Context::new(
        Platform::Linux,
        std::time::Instant::now() + Duration::from_secs(10),
    );
    let runtime = tokio::runtime::Builder::new_current_thread()
        .enable_all()
        .build()
        .unwrap();
    let client = {
        let _enter = runtime.enter();
        reqwest::Client::builder()
            .unix_socket(socket)
            .redirect(reqwest::redirect::Policy::none())
            .build()
            .unwrap()
    };
    let mut host = Native {
        commands: recipe::Native(&context),
        context: &context,
        runtime,
        client,
    };
    inspect(&mut host, "/a name", false).unwrap();
    host.api(
        "POST",
        "/containers/a/update",
        Some(&json!({"RestartPolicy":{"Name":"no"}})),
        false,
    )
    .unwrap();
    assert!(inspect(&mut host, "missing", true).unwrap().is_null());
    assert_eq!(get(&mut host, "/info").unwrap_err().stage, "docker-api");
    let calls = server.join().unwrap();
    assert_eq!(calls[0].0, "GET /containers/a%20name/json HTTP/1.1\r\n");
    assert_eq!(calls[1].0, "POST /v1.52/containers/a/update HTTP/1.1\r\n");
    assert_eq!(
        serde_json::from_slice::<Value>(&calls[1].1).unwrap(),
        json!({"RestartPolicy":{"Name":"no"}})
    );
}
#[test]
fn daemon_start_is_bounded_and_stop_ignores_unloaded_services() {
    let mut f = Fixture::new();
    f.host.unavailable_api = true;
    assert_eq!(
        engine::start(&mut f.host).unwrap_err().stage,
        "daemon-start"
    );
    assert_eq!(f.host.waits, 30);
    assert_eq!(
        f.host
            .calls
            .iter()
            .filter(|a| a[0] == "systemctl" && a[1] == "start")
            .count(),
        3
    );
    f.host.calls.clear();
    f.host.missing_containerd = true;
    engine::stop_loaded(&mut f.host).unwrap();
    assert!(f
        .host
        .calls
        .iter()
        .any(|a| a == &["systemctl", "stop", "docker.service", "docker.socket"]));
}
#[test]
fn tag_restore_failure_resumes_after_loading_archives_and_before_reconciling_processes() {
    let mut f = Fixture::new();
    f.host.local = true;
    f.host.incompatible = true;
    f.host.images[0]["RepoTags"] = json!(["local/mysql:production", "local/mysql:backup"]);
    f.host.fail_command = Some(format!(
        "docker tag sha256:{} local/mysql:backup",
        "a".repeat(64)
    ));
    assert_eq!(
        f.run(Mode::Prepare).unwrap_err().stage,
        "restore-image-tags"
    );
    assert!(!f.state().prepared);
    f.run(Mode::Prepare).unwrap();
    for tag in ["local/mysql:production", "local/mysql:backup"] {
        assert!(f
            .host
            .calls
            .iter()
            .any(|a| a[0] == "docker" && a[1] == "tag" && a.last().unwrap() == tag));
    }
    assert_eq!(
        f.host
            .calls
            .iter()
            .filter(|a| a[0] == "apt-get" && a[1] == "install" && a.contains(&"--yes".into()))
            .count(),
        1
    );
}
#[test]
fn all_container_layers_are_probed_before_policies_are_restored_and_failure_reports_states() {
    let mut f = Fixture::new();
    f.run(Mode::Prepare).unwrap();
    let changes = f
        .host
        .requests
        .iter()
        .rposition(|(_, p, _)| p == "/containers/two/changes")
        .unwrap();
    let restore = f
        .host
        .requests
        .iter()
        .position(|(m, p, b)| {
            m == "POST"
                && p.ends_with("/update")
                && b.as_ref().unwrap()["RestartPolicy"]["Name"] == "unless-stopped"
        })
        .unwrap();
    assert!(changes < restore);
    let mut f = Fixture::new();
    f.host.simulation.push_str("Remv unrelated [1]\n");
    let error = f.run(Mode::Prepare).unwrap_err();
    assert_eq!(error.stage, "unrelated-package-removal");
    let report = error.details();
    assert_eq!(
        array(&report["containers"])
            .iter()
            .map(|c| text(&c["state"]))
            .collect::<Vec<_>>(),
        vec!["running", "running", "exited"]
    );
    assert!(!report.to_string().contains("private-sentinel"));
}
#[test]
fn unhealthy_and_starting_health_checks_fail_after_bounded_waits() {
    for health in ["starting", "unhealthy"] {
        let mut f = Fixture::new();
        f.run(Mode::Prepare).unwrap();
        f.input.process_states.insert("7".into(), "stopped".into());
        f.host.containers[0]["State"]["Health"] = json!({"Status":health});
        assert_eq!(f.run(Mode::Report).unwrap()["healthy"], false);
        assert_eq!(f.host.waits, if health == "starting" { 60 } else { 0 });
    }
}
