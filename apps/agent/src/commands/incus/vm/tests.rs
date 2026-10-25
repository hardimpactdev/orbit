use super::super::Output;
use super::*;
use std::collections::VecDeque;

#[derive(Default)]
struct Fake {
    answers: VecDeque<Output>,
    calls: Vec<(Vec<String>, Option<String>)>,
}
impl Host for Fake {
    fn run(&mut self, args: &[&str], input: Option<&str>) -> Output {
        self.calls.push((
            args.iter().map(|v| (*v).into()).collect(),
            input.map(str::to_owned),
        ));
        self.answers.pop_front().expect("unexpected host call")
    }
}
fn output(code: i32, value: impl Into<String>) -> Output {
    Output {
        code,
        stdout: value.into(),
        stderr: "host failed".into(),
        truncated: false,
    }
}
fn good(value: impl Into<String>) -> Output {
    output(0, value)
}
fn fake(answers: Vec<Output>) -> Fake {
    Fake {
        answers: answers.into(),
        ..Fake::default()
    }
}
fn location() -> Location {
    Location {
        project: "orbit-tasks".into(),
        name: "tvm-1".into(),
        cidr: "10.251.77.0/24".into(),
    }
}
fn instance(status: &str) -> Value {
    json!({"name":"tvm-1","type":"virtual-machine","status":status,"config":{"volatile.base_image":"a".repeat(64)},"state":{"network":{"enp5s0":{"addresses":[{"family":"inet","address":"10.251.77.20"}]},"lo":{"addresses":[{"family":"inet","address":"127.0.0.1"}]}}}})
}
fn listed(status: &str) -> Output {
    good(json!([instance(status)]).to_string())
}
fn request(operation: Operation) -> Input {
    Input {
        vm: location(),
        operation,
    }
}
fn create(topology: bool) -> Input {
    request(Operation::Create {
        image: "a".repeat(64),
        expected_fingerprint: Some("a".repeat(64)),
        base_alias: "orbit-task-base".into(),
        gateway_public_key: "ssh-ed25519 AAAA gateway".into(),
        cpus: 4,
        memory: "8GiB".into(),
        disk: "40GiB".into(),
        network: "orbittask0".into(),
        pool: "tank".into(),
        topology,
    })
}
fn images() -> Output {
    good(json!([{"fingerprint":"a".repeat(64),"aliases":[{"name":"orbit-task-base"}]}]).to_string())
}

#[test]
fn observes_exact_names_image_and_only_one_usable_bridge_address_repeatedly() {
    let mut other = instance("Running");
    other["name"] = json!("tvm-12");
    let mut host = fake(vec![
        good(json!([other.clone(), instance("Running")]).to_string()),
        listed("Running"),
        good(json!([other]).to_string()),
    ]);
    let first = observe(&mut host, &location()).unwrap();
    assert_eq!(
        first,
        Some(Observation {
            running: true,
            address: Some("10.251.77.20".parse().unwrap()),
            image_fingerprint: Some("a".repeat(64))
        })
    );
    assert_eq!(observe(&mut host, &location()).unwrap(), first);
    assert_eq!(observe(&mut host, &location()).unwrap(), None);
    let mut stopped = instance("Stopped");
    stopped["state"] = Value::Null;
    stopped["config"] = Value::Null;
    assert_eq!(
        observe(
            &mut fake(vec![good(json!([stopped]).to_string())]),
            &location()
        )
        .unwrap(),
        Some(Observation {
            running: false,
            address: None,
            image_fingerprint: None
        })
    );
    assert_eq!(
        host.calls[0].0,
        [
            "--project",
            "orbit-tasks",
            "list",
            "--format",
            "json",
            "--",
            "tvm-1"
        ]
    );
}
#[test]
fn refuses_invalid_observations_and_host_failures() {
    let mut malformed = vec![json!({}), json!([instance("Running"), instance("Running")])];
    for (key, value) in [
        ("type", json!("container")),
        ("status", json!("Frozen")),
        ("config", json!({"volatile.base_image":42})),
        ("config", json!({"volatile.base_image":"x"})),
    ] {
        let mut row = instance("Running");
        row[key] = value;
        malformed.push(json!([row]));
    }
    for addresses in [
        json!([{"family":"inet","address":"10.251.77.1"}]),
        json!([{"family":"inet","address":"10.251.77.255"}]),
        json!([{"family":"inet","address":"10.251.77.0"}]),
        json!([{"family":"inet","address":"10.251.77.20"},{"family":"inet","address":"10.251.77.21"}]),
    ] {
        let mut row = instance("Running");
        row["state"]["network"]["enp5s0"]["addresses"] = addresses;
        malformed.push(json!([row]));
    }
    for value in malformed {
        assert!(matches!(
            observe(&mut fake(vec![good(value.to_string())]), &location()),
            Err(Error::Output { .. })
        ));
    }
    let mut truncated = good("[]");
    truncated.truncated = true;
    for result in [good("not JSON"), truncated] {
        assert!(matches!(
            observe(&mut fake(vec![result]), &location()),
            Err(Error::Output { .. })
        ));
    }
    assert!(matches!(
        observe(&mut fake(vec![output(1, "")]), &location()),
        Err(Error::Failed { .. })
    ));
}
#[test]
fn creates_with_code_owned_cloud_init_and_topology_settings_and_repeats() {
    for topology in [false, true] {
        let mut answers = vec![good("[]")];
        if topology {
            answers.extend([
                good(
                    json!([{"type":"custom","name":"orbit-topology-seed-aaaaaaaaaaaa"}])
                        .to_string(),
                ),
                good(""),
            ]);
        }
        answers.extend([good(""), listed("Running")]);
        let mut host = fake(answers);
        change(&mut host, &create(topology)).unwrap();
        change(&mut host, &create(topology)).unwrap();
        let launch = host.calls.iter().find(|c| c.0[2] == "launch").unwrap();
        assert_eq!(
            launch.0,
            [
                "--project",
                "orbit-tasks",
                "launch",
                "--vm",
                "--config",
                "limits.cpu=4",
                "--config",
                "limits.memory=8GiB",
                "--device",
                "root,size=40GiB",
                "--device",
                "eth0,network=orbittask0",
                "--device",
                "eth0,security.port_isolation=true",
                "--",
                &"a".repeat(64),
                "tvm-1"
            ]
        );
        let settings: Value = serde_json::from_str(launch.1.as_ref().unwrap()).unwrap();
        let cloud: Value = serde_json::from_str(
            settings["config"]["cloud-init.user-data"]
                .as_str()
                .unwrap()
                .strip_prefix("#cloud-config\n")
                .unwrap(),
        )
        .unwrap();
        assert_eq!(
            cloud,
            json!({"users":[{"name":"orbit","lock_passwd":true,"shell":"/bin/bash","sudo":"ALL=(ALL) NOPASSWD:ALL","ssh_authorized_keys":["ssh-ed25519 AAAA gateway"]}]})
        );
        if topology {
            assert_eq!(
                settings["devices"],
                json!({"topology-seed":{"type":"disk","pool":"tank","source":"tvm-1-topology-seed"}})
            );
            assert_eq!(
                settings["config"]["raw.qemu.conf"],
                "[device \"qemu_balloon\"]\nfree-page-reporting = \"on\"\n"
            );
        } else {
            assert!(settings.get("devices").is_none());
            assert!(settings["config"].get("raw.qemu.conf").is_none());
        }
        assert_eq!(host.calls.iter().filter(|c| c.0[2] == "launch").count(), 1);
    }
}
#[test]
fn create_refuses_a_wrong_claim_or_missing_seed_before_launch() {
    let mut row = instance("Stopped");
    row["config"]["volatile.base_image"] = json!("b".repeat(64));
    let mut host = fake(vec![good(json!([row]).to_string())]);
    assert_eq!(
        change(&mut host, &create(false)),
        Err(Error::ImageMismatch {})
    );
    assert_eq!(host.calls.len(), 1);
    let mut host = fake(vec![good("[]"), good("[]")]);
    assert_eq!(change(&mut host, &create(true)), Err(Error::SeedMissing {}));
    assert_eq!(host.calls.len(), 2);
}
#[test]
fn start_and_create_start_stopped_vms_and_accept_only_observed_running_after_failure() {
    for operation in [create(false), request(Operation::Start {})] {
        for (start_result, observed, passes) in [
            (0, None, true),
            (1, Some("Running"), true),
            (1, Some("Stopped"), false),
        ] {
            let mut answers = vec![listed("Stopped"), output(start_result, "")];
            if let Some(state) = observed {
                answers.push(listed(state));
            }
            let mut host = fake(answers);
            assert_eq!(change(&mut host, &operation).is_ok(), passes);
            assert_eq!(
                host.calls[1].0,
                ["--project", "orbit-tasks", "start", "--", "tvm-1"]
            );
        }
        let mut host = fake(vec![listed("Running"), listed("Running")]);
        change(&mut host, &operation).unwrap();
        change(&mut host, &operation).unwrap();
        assert_eq!(host.calls.len(), 2);
    }
    assert_eq!(
        change(&mut fake(vec![good("[]")]), &request(Operation::Start {})),
        Err(Error::VmMissing {})
    );
}
#[test]
fn a_failed_launch_requires_a_running_vm_else_reports_the_base_or_launch_error() {
    let mut host = fake(vec![good("[]"), output(1, ""), listed("Running")]);
    change(&mut host, &create(false)).unwrap();
    for state in [good("[]"), listed("Stopped")] {
        let mut host = fake(vec![good("[]"), output(1, ""), state, images()]);
        assert!(
            matches!(change(&mut host,&create(false)),Err(Error::Failed {operation,..}) if operation=="launch")
        );
    }
    assert_eq!(
        change(
            &mut fake(vec![good("[]"), output(1, ""), good("[]"), good("[]")]),
            &create(false)
        ),
        Err(Error::BaseMissing {})
    );
}
#[test]
fn stops_cleanly_then_forces_only_if_still_running_and_repeats() {
    let operation = request(Operation::Stop {});
    let mut host = fake(vec![
        listed("Running"),
        good(""),
        listed("Stopped"),
        good("[]"),
    ]);
    for _ in 0..3 {
        change(&mut host, &operation).unwrap();
    }
    assert_eq!(
        host.calls[1].0,
        [
            "--project",
            "orbit-tasks",
            "stop",
            "--timeout",
            "120",
            "--",
            "tvm-1"
        ]
    );
    for last in ["Running", "Stopped"] {
        let mut host = fake(vec![
            listed("Running"),
            output(1, ""),
            listed("Running"),
            output(1, ""),
            listed(last),
        ]);
        assert_eq!(change(&mut host, &operation).is_ok(), last == "Stopped");
        assert_eq!(
            host.calls[3].0,
            ["--project", "orbit-tasks", "stop", "--force", "--", "tvm-1"]
        );
    }
    let mut host = fake(vec![listed("Running"), output(1, ""), listed("Stopped")]);
    change(&mut host, &operation).unwrap();
    assert_eq!(host.calls.len(), 3);
}
#[test]
fn deletion_releases_only_its_clone_after_vm_absence_and_repeats() {
    let operation = request(Operation::Delete {
        pool: "tank".into(),
    });
    let mut host=fake(vec![good(""),good(json!([{"type":"custom","name":"tvm-1-topology-seed"},{"type":"custom","name":"operator-data"}]).to_string()),good(""),output(1,""),good("[]"),good("[]")]);
    change(&mut host, &operation).unwrap();
    change(&mut host, &operation).unwrap();
    assert_eq!(
        host.calls[0].0,
        [
            "--project",
            "orbit-tasks",
            "delete",
            "--force",
            "--",
            "tvm-1"
        ]
    );
    assert_eq!(
        host.calls[2].0,
        [
            "--project",
            "orbit-tasks",
            "storage",
            "volume",
            "delete",
            "--",
            "tank",
            "tvm-1-topology-seed"
        ]
    );
    let mut host = fake(vec![output(1, ""), listed("Running")]);
    assert!(matches!(
        change(&mut host, &operation),
        Err(Error::Failed { .. })
    ));
    assert_eq!(host.calls.len(), 2);
}
#[test]
fn invalid_intent_refuses_before_host_access() {
    for field in [
        "name", "cidr", "key", "image", "memory", "cpus", "pin", "pool",
    ] {
        let mut input = create(false);
        match field {
            "name" => input.vm.name = "--other".into(),
            "cidr" => input.vm.cidr = "10.251.77.1/24".into(),
            _ => {
                if let Operation::Create {
                    gateway_public_key,
                    image,
                    memory,
                    cpus,
                    expected_fingerprint,
                    pool,
                    ..
                } = &mut input.operation
                {
                    match field {
                        "key" => *gateway_public_key = "ssh-ed25519 AAAA\ncommand".into(),
                        "image" => *image = "remote:stock".into(),
                        "memory" => *memory = "4GiB --other".into(),
                        "cpus" => *cpus = 0,
                        "pin" => *expected_fingerprint = Some("x".into()),
                        "pool" => *pool = "../data".into(),
                        _ => unreachable!(),
                    }
                }
            }
        }
        let mut host = Fake::default();
        assert_eq!(change(&mut host, &input), Err(Error::Invalid {}));
        assert!(host.calls.is_empty());
    }
}

fn image_request(builder: bool) -> Input {
    request(Operation::CreateImage {
        image: "a".repeat(64),
        gateway_public_key: "ssh-ed25519 AAAA gateway".into(),
        cpus: 2,
        memory: "2GiB".into(),
        disk: "20GiB".into(),
        network: "orbittask0".into(),
        builder,
    })
}

#[test]
fn image_launch_owns_cloud_init_and_isolation_and_repeats_without_replacement() {
    for builder in [false, true] {
        let mut host = fake(vec![good("[]"), good(""), listed("Running")]);
        let input = image_request(builder);
        change(&mut host, &input).unwrap();
        change(&mut host, &input).unwrap();
        assert_eq!(host.calls.len(), 3);
        let (args, data) = &host.calls[1];
        assert_eq!(
            args,
            &[
                "--project",
                "orbit-tasks",
                "launch",
                "--vm",
                "--config",
                "limits.cpu=2",
                "--config",
                "limits.memory=2GiB",
                "--device",
                "root,size=20GiB",
                "--device",
                "eth0,network=orbittask0",
                "--device",
                "eth0,security.port_isolation=true",
                "--",
                &"a".repeat(64),
                "tvm-1"
            ]
        );
        let config: Value = serde_json::from_str(data.as_ref().unwrap()).unwrap();
        let cloud = config["config"]["cloud-init.user-data"].as_str().unwrap();
        let cloud: Value =
            serde_json::from_str(cloud.strip_prefix("#cloud-config\n").unwrap()).unwrap();
        assert_eq!(cloud["users"][0]["name"], "orbit");
        assert_eq!(
            cloud["users"][0]["ssh_authorized_keys"],
            json!(["ssh-ed25519 AAAA gateway"])
        );
        assert_eq!(
            cloud["users"][0]["uid"],
            if builder { json!(1000) } else { Value::Null }
        );
        assert_eq!(
            cloud["users"][0]["no_user_group"],
            if builder { json!(false) } else { Value::Null }
        );
        assert!(config.get("devices").is_none());
    }
    let mut host = fake(vec![listed("Stopped"), good("")]);
    change(&mut host, &image_request(true)).unwrap();
    assert_eq!(
        host.calls[1].0,
        ["--project", "orbit-tasks", "start", "--", "tvm-1"]
    );
}

#[test]
fn image_launch_refuses_invalid_intent_wrong_images_and_failed_host_operations() {
    let mut input = image_request(true);
    if let Operation::CreateImage { image, .. } = &mut input.operation {
        *image = "b".repeat(64);
    }
    let mut host = fake(vec![listed("Running")]);
    assert_eq!(change(&mut host, &input), Err(Error::ImageMismatch {}));
    assert_eq!(host.calls.len(), 1);
    for result in [output(1, ""), good("malformed")] {
        let mut host = fake(vec![result]);
        assert!(change(&mut host, &image_request(false)).is_err());
        assert_eq!(host.calls.len(), 1);
    }
    let mut host = fake(vec![good("[]"), output(1, "")]);
    assert!(matches!(
        change(&mut host, &image_request(true)),
        Err(Error::Failed { .. })
    ));
    assert_eq!(host.calls.len(), 2);
    for (field, value) in [
        ("image", json!("remote:stock")),
        ("gateway_public_key", json!("ssh-ed25519 AAAA\ncommand")),
        ("cpus", json!(0)),
        ("memory", json!("1GiB --other")),
        ("disk", json!("0GiB")),
        ("network", json!("../other")),
    ] {
        let mut value_json = json!({"vm":{"project":"orbit-tasks","name":"tvm-image-1","cidr":"10.251.77.0/24"},"operation":{"kind":"create_image","image":"stock","gateway_public_key":"ssh-ed25519 AAAA","cpus":2,"memory":"2GiB","disk":"20GiB","network":"orbittask0","builder":true}});
        value_json["operation"][field] = value;
        let input: Input = serde_json::from_value(value_json).unwrap();
        let mut host = Fake::default();
        assert_eq!(change(&mut host, &input), Err(Error::Invalid {}));
        assert!(host.calls.is_empty());
    }
}

#[test]
fn exact_instance_removal_repeats_without_touching_volumes_and_propagates_failure() {
    let input = request(Operation::RemoveInstance {});
    let mut host = fake(vec![good(""), output(1, ""), good("[]")]);
    change(&mut host, &input).unwrap();
    change(&mut host, &input).unwrap();
    assert_eq!(host.calls.len(), 3);
    assert_eq!(
        host.calls[0].0,
        [
            "--project",
            "orbit-tasks",
            "delete",
            "--force",
            "--",
            "tvm-1"
        ]
    );
    let mut host = fake(vec![output(1, ""), listed("Running")]);
    assert!(matches!(
        change(&mut host, &input),
        Err(Error::Failed { .. })
    ));
    assert_eq!(host.calls.len(), 2);
    let mut invalid = request(Operation::RemoveInstance {});
    invalid.vm.name = "--all".into();
    let mut host = Fake::default();
    assert_eq!(change(&mut host, &invalid), Err(Error::Invalid {}));
    assert!(host.calls.is_empty());
}

#[test]
fn image_alias_repeats_require_the_same_fingerprint_before_starting() {
    let mut input = image_request(true);
    if let Operation::CreateImage { image, .. } = &mut input.operation {
        *image = "orbit-task-base".into();
    }
    let mut host = fake(vec![listed("Running"), images()]);
    change(&mut host, &input).unwrap();
    assert_eq!(host.calls.len(), 2);
    for image_list in [
        good("[]"),
        good(
            json!([{"fingerprint":"b".repeat(64),"aliases":[{"name":"orbit-task-base"}]}])
                .to_string(),
        ),
    ] {
        let mut host = fake(vec![listed("Stopped"), image_list]);
        assert_eq!(change(&mut host, &input), Err(Error::ImageMismatch {}));
        assert_eq!(host.calls.len(), 2);
    }
}
