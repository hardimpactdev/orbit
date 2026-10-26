use super::*;
use std::collections::BTreeMap;

struct Fake {
    resources: BTreeMap<&'static str, Vec<Value>>,
    devices: Value,
    calls: Vec<(Tool, Vec<String>, Option<Value>)>,
    changes: Vec<String>,
    missing: Option<Tool>,
    dataset_exists: bool,
    dataset_unreadable: bool,
    fail_change: Option<usize>,
    image: bool,
}
impl Default for Fake {
    fn default() -> Self {
        Self {
            resources: BTreeMap::from([
                ("storage-pools", vec![]),
                ("projects", vec![]),
                ("networks", vec![]),
                ("network-acls", vec![]),
            ]),
            devices: json!({}),
            calls: vec![],
            changes: vec![],
            missing: None,
            dataset_exists: false,
            dataset_unreadable: false,
            fail_change: None,
            image: false,
        }
    }
}
fn answer(value: Value) -> Output {
    Output {
        code: 0,
        stdout: value.to_string(),
        stderr: String::new(),
        truncated: false,
    }
}
impl Host for Fake {
    fn available(&self, tool: Tool) -> bool {
        self.missing != Some(tool)
    }
    fn run(&mut self, tool: Tool, args: &[&str], input: Option<&str>) -> Output {
        self.calls.push((
            tool,
            args.iter().map(|s| (*s).into()).collect(),
            input.map(|s| serde_json::from_str(s).unwrap()),
        ));
        if tool == Tool::Zfs {
            return Output {
                code: if self.dataset_unreadable { 1 } else { 0 },
                stdout: if self.dataset_exists {
                    "data\ndata/tasks\n".into()
                } else {
                    "data\n".into()
                },
                ..answer(Value::Null)
            };
        }
        if args[0] == "query" {
            if args[2].starts_with("/1.0/profiles/default?") {
                return answer(json!({"devices":self.devices}));
            }
            let collection = args[2]
                .strip_prefix("/1.0/")
                .unwrap()
                .split('?')
                .next()
                .unwrap();
            return answer(json!(self
                .resources
                .get(collection)
                .expect("unexpected collection")));
        }
        if args.starts_with(&["--project", "orbit-tasks", "image", "list"]) {
            return answer(if self.image {
                json!([{"fingerprint":"a".repeat(64),"aliases":[{"name":"ubuntu-26.04-vm"}]}])
            } else {
                json!([])
            });
        }
        self.changes.push(args.join(" "));
        if self.fail_change == Some(self.changes.len()) {
            return Output {
                code: 1,
                stderr: "operation refused".into(),
                ..answer(Value::Null)
            };
        }
        match &args[..2] {
            ["storage", "create"] => {
                self.resources.insert("storage-pools", vec![json!({"name":args[2],"driver":"zfs","config":{"source":args[4].strip_prefix("source=").unwrap()}})]);
            }
            ["project", "create"] => {
                self.resources.insert(
                    "projects",
                    vec![json!({"name":args[2],"config":{"features.networks":"false"}})],
                );
            }
            ["network", "create"] => {
                self.resources.insert("networks", vec![json!({"name":args[2],"managed":true,"type":"bridge","config":{"ipv4.address":"10.252.0.1/24"}})]);
            }
            ["network", "acl"] if args[2] == "create" => {
                self.resources
                    .insert("network-acls", vec![json!({"name":args[3]})]);
            }
            ["profile", "device"] => {
                self.devices[args[4]] = if args[4] == "root" {
                    json!({"pool":"orbit-tasks"})
                } else {
                    json!({"network":"orbittask0"})
                };
            }
            ["image", "copy"] => {
                self.image = true;
            }
            ["network", "acl"] | ["network", "set"] | ["project", "set"] | ["route", "allow"] => {}
            _ => panic!("unexpected mutation: {args:?}"),
        }
        answer(Value::Null)
    }
}
fn input() -> Input {
    Input {
        project: "orbit-tasks".into(),
        network: "orbittask0".into(),
        cidr: "10.252.0.0/24".into(),
        pool: "orbit-tasks".into(),
        zfs_dataset: Some("data/tasks".into()),
    }
}

#[test]
fn prepares_isolated_resources_and_repeats_without_copying_or_recreating() {
    let mut host = Fake::default();
    assert_eq!(
        prepare(&input(), &mut host).unwrap(),
        Prepared { ready: true }
    );
    let first = host.changes.clone();
    assert_eq!(first[0], "storage create orbit-tasks zfs source=data/tasks");
    let bridge = first
        .iter()
        .find(|c| c.starts_with("network create"))
        .unwrap();
    assert!(bridge.contains("ipv4.address=10.252.0.1/24"));
    assert!(bridge.contains("security.acls=orbittask0-egress"));
    let nic = first
        .iter()
        .position(|c| c.contains("device add default eth0 nic"))
        .unwrap();
    assert!(first[nic].contains("security.port_isolation=true"));
    let restrict = first
        .iter()
        .position(|c| c.starts_with("project set"))
        .unwrap();
    assert!(restrict > nic);
    assert!(first[restrict].contains("restricted.virtual-machines.lowlevel=allow"));
    assert!(first[restrict].contains("restricted.devices.disk.paths=/var/lib/orbit/package-cache"));
    assert_eq!(
        first.last().unwrap(),
        "route allow in on orbittask+ comment orbit-task-vms"
    );
    let rules = host
        .calls
        .iter()
        .find_map(|(_, args, data)| {
            (args.get(2).map(String::as_str) == Some("edit")).then(|| data.as_ref().unwrap())
        })
        .unwrap();
    assert_eq!(rules["ingress"][0]["source"], "10.252.0.1/32");
    assert_eq!(rules["egress"][0]["action"], "drop");
    assert_eq!(rules["egress"][1]["action"], "allow");
    host.changes.clear();
    assert_eq!(
        prepare(&input(), &mut host).unwrap(),
        Prepared { ready: true }
    );
    assert!(host
        .changes
        .iter()
        .all(|c| !c.contains(" create ") && !c.starts_with("image copy")));
    assert!(host
        .changes
        .iter()
        .any(|c| c.starts_with("profile device set default eth0")));
}

#[test]
fn invalid_intent_refuses_before_any_host_access() {
    for (field, value) in [
        ("project", "--bad"),
        ("network", "incusbr0"),
        ("pool", "../pool"),
        ("cidr", "10.252.0.1/24"),
        ("cidr", "10.0.0.0/8"),
        ("cidr", "999.0.0.0/24"),
        ("zfs_dataset", "data"),
        ("zfs_dataset", "-o/x"),
    ] {
        let mut data = json!({"project":"orbit-tasks","network":"orbittask0","pool":"orbit-tasks","cidr":"10.252.0.0/24","zfs_dataset":"data/tasks"});
        data[field] = json!(value);
        let mut host = Fake::default();
        let error = prepare(&serde_json::from_value(data).unwrap(), &mut host).unwrap_err();
        assert!(matches!(error, Error::Failed { stage, .. } if stage == "input"));
        assert!(host.calls.is_empty());
    }
    assert!(serde_json::from_value::<Input>(json!({"project":"p","script":"x"})).is_err());
}

#[test]
fn missing_tools_and_existing_or_unspecified_datasets_never_change_the_host() {
    for missing in [Some(Tool::Incus), Some(Tool::Ufw), Some(Tool::Zfs)] {
        let mut host = Fake {
            missing,
            ..Fake::default()
        };
        assert!(prepare(&input(), &mut host).is_err());
        assert!(host.changes.is_empty());
    }
    let mut host = Fake {
        dataset_exists: true,
        ..Fake::default()
    };
    assert!(prepare(&input(), &mut host).is_err());
    assert!(host.changes.is_empty());
    let mut host = Fake {
        dataset_unreadable: true,
        ..Fake::default()
    };
    assert!(prepare(&input(), &mut host).is_err());
    assert!(host.changes.is_empty());
    let mut request = input();
    request.zfs_dataset = None;
    assert!(prepare(&request, &mut Fake::default()).is_err());
}

#[test]
fn incompatible_or_unreadable_host_state_refuses_before_mutation() {
    for (collection, row) in [
        (
            "storage-pools",
            json!({"name":"orbit-tasks","driver":"dir","config":{}}),
        ),
        (
            "storage-pools",
            json!({"name":"orbit-tasks","driver":"zfs","config":{"source":"other/pool"}}),
        ),
        (
            "projects",
            json!({"name":"orbit-tasks","config":{"features.networks":"true"}}),
        ),
        ("projects", json!({"name":"orbit-tasks","config":null})),
        (
            "networks",
            json!({"name":"orbittask0","managed":true,"type":"bridge","config":{"ipv4.address":"10.9.0.1/24"}}),
        ),
        (
            "networks",
            json!({"name":"orbittask0","managed":false,"type":"bridge","config":{"ipv4.address":"10.252.0.1/24"}}),
        ),
        ("network-acls", json!({"wrong":"name"})),
    ] {
        let mut host = Fake::default();
        host.resources.insert(collection, vec![row]);
        assert!(prepare(&input(), &mut host).is_err());
        assert!(host.changes.is_empty());
    }
    let mut host = Fake::default();
    host.resources
        .insert("projects", vec![json!({"name":"orbit-tasks","config":{}})]);
    host.devices = Value::Null;
    assert!(prepare(&input(), &mut host).is_err());
    assert!(host.changes.is_empty());
}

#[test]
fn every_failed_mutation_stops_and_a_retry_converges_partial_state() {
    let mut completed = Fake::default();
    prepare(&input(), &mut completed).unwrap();
    for fail_change in 1..=completed.changes.len() {
        let mut host = Fake {
            fail_change: Some(fail_change),
            ..Fake::default()
        };
        assert!(prepare(&input(), &mut host).is_err());
        assert_eq!(host.changes.len(), fail_change);
        host.fail_change = None;
        assert_eq!(
            prepare(&input(), &mut host).unwrap(),
            Prepared { ready: true }
        );
    }
}
