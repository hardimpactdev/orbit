use super::*;
use orbit_agent_contract::CommandError;
use std::collections::BTreeMap;

#[test]
fn acl_rules_compare_without_the_empty_fields_incus_adds() {
    let answered = json!([
        {"action": "reject", "destination": "0.0.0.0/8,10.0.0.0/8,100.64.0.0/10,127.0.0.0/8,169.254.0.0/16,172.16.0.0/12,192.0.0.0/24,192.168.0.0/16,198.18.0.0/15,224.0.0.0/3", "state": "enabled", "source": "", "description": ""},
        {"action": "reject", "destination": "::/0", "state": "enabled", "protocol": ""},
        {"action": "allow", "destination": "0.0.0.0/0", "state": "enabled"},
    ]);
    assert_eq!(rules(&answered), acl_rules());
    assert_ne!(rules(&json!([])), acl_rules());
}

#[test]
fn config_matches_only_when_every_reserved_key_has_its_value() {
    let mut config = Map::new();
    for (key, value) in NETWORK_CONFIG {
        config.insert(key.into(), Value::String(value.into()));
    }
    config.insert("ipv4.address".into(), json!("10.9.0.1/24"));
    assert!(includes(&Value::Object(config.clone()), &NETWORK_CONFIG));
    config.insert("security.acls".into(), json!("other"));
    assert!(!includes(&Value::Object(config), &NETWORK_CONFIG));
}

#[test]
fn default_scope_applies_to_networks_and_acls_only() {
    assert_eq!(
        default_path("networks", Some(NETWORK)),
        "/1.0/networks/orbitci0?project=default"
    );
    assert_eq!(default_path("projects", None), "/1.0/projects");
}

const PROJECT_PATH: &str = "/1.0/projects/orbit-ci";
const NETWORK_PATH: &str = "/1.0/networks/orbitci0?project=default";
const ACL_PATH: &str = "/1.0/network-acls/orbit-ci-egress?project=default";
const POOL_PATH: &str = "/1.0/storage-pools/default";
const PROFILE_PATH: &str = "/1.0/profiles/default?project=orbit-ci";
const INSTANCES_PATH: &str = "/1.0/instances?project=orbit-ci";

/// An Incus daemon held as its API objects. Each command changes the objects as Incus would.
#[derive(Default)]
struct Incus {
    objects: BTreeMap<String, Value>,
    calls: Vec<Vec<String>>,
    broken: bool,
}

fn answer(code: i32, stdout: String) -> Output {
    Output {
        code,
        stdout,
        stderr: if code == 0 {
            String::new()
        } else {
            "Error: daemon unavailable".into()
        },
        truncated: false,
    }
}

fn settings(items: &[&str]) -> Map<String, Value> {
    items
        .iter()
        .map(|item| {
            let (key, value) = item.split_once('=').unwrap();
            (key.to_string(), Value::String(value.into()))
        })
        .collect()
}

impl Host for Incus {
    fn incus(&mut self, argv: &[&str], input: Option<&str>) -> Output {
        self.calls.push(argv.iter().map(|s| (*s).into()).collect());
        if self.broken {
            return answer(1, String::new());
        }
        if argv[0] == "query" {
            let path = argv[1];
            let collection = path.split('?').next().unwrap();
            if [
                "/1.0/projects",
                "/1.0/networks",
                "/1.0/network-acls",
                "/1.0/storage-pools",
            ]
            .contains(&collection)
            {
                if matches!(collection, "/1.0/networks" | "/1.0/network-acls") {
                    assert!(path.ends_with("?project=default"), "{path}");
                }
                let prefix = format!("{collection}/");
                let names: Vec<&String> = self
                    .objects
                    .keys()
                    .filter(|key| key.starts_with(&prefix))
                    .collect();
                return answer(0, json!(names).to_string());
            }
            return match self.objects.get(path) {
                Some(object) => answer(0, object.to_string()),
                None => answer(1, String::new()),
            };
        }
        assert_eq!(argv[..2], ["--project", "default"], "{argv:?}");
        let document = || serde_json::from_str::<Value>(input.unwrap()).unwrap();
        match &argv[2..] {
            ["storage", "create", _, driver] => {
                self.objects
                    .insert(POOL_PATH.into(), json!({ "driver": driver }));
            }
            ["project", "create", _, rest @ ..] => {
                let items: Vec<&str> = rest.iter().copied().filter(|s| *s != "-c").collect();
                self.objects
                    .insert(PROJECT_PATH.into(), json!({ "config": settings(&items) }));
                self.objects.insert(INSTANCES_PATH.into(), json!([]));
            }
            ["project", "set", _, rest @ ..] => {
                let project = self.objects.get_mut(PROJECT_PATH).unwrap();
                project["config"]
                    .as_object_mut()
                    .unwrap()
                    .extend(settings(rest));
            }
            ["network", "acl", "create", _] => {
                self.objects.insert(ACL_PATH.into(), json!({}));
            }
            ["network", "acl", "edit", _] => {
                self.objects.insert(ACL_PATH.into(), document());
            }
            ["network", "create", ..] => {
                self.objects.insert(
                    NETWORK_PATH.into(),
                    json!({"type": "bridge", "managed": true, "config": {"ipv4.address": "10.253.3.1/24"}}),
                );
            }
            ["network", "set", _, rest @ ..] => {
                let network = self.objects.get_mut(NETWORK_PATH).unwrap();
                network["config"]
                    .as_object_mut()
                    .unwrap()
                    .extend(settings(rest));
            }
            ["profile", "edit", "default", "--project", "orbit-ci"] => {
                self.objects.insert(PROFILE_PATH.into(), document());
            }
            ["project", "delete", _] => {
                self.objects.remove(PROJECT_PATH);
            }
            ["network", "delete", _] => {
                self.objects.remove(NETWORK_PATH);
            }
            ["network", "acl", "delete", _] => {
                self.objects.remove(ACL_PATH);
            }
            _ => panic!("unexpected incus call {argv:?}"),
        }
        answer(0, String::new())
    }
}

fn act_on(incus: &mut Incus, action: &str) -> Result<&'static str, CiHostError> {
    let input: HostInput = serde_json::from_value(json!({ "action": action })).unwrap();
    act(incus, &input).map(|outcome| outcome.outcome)
}

fn converged() -> Incus {
    let mut incus = Incus::default();
    assert_eq!(act_on(&mut incus, "converge").unwrap(), "converged");
    incus
}

/// Every call except `query`.
fn changes(incus: &Incus) -> Vec<Vec<String>> {
    incus
        .calls
        .iter()
        .filter(|call| call[0] != "query")
        .cloned()
        .collect()
}

fn with_instances(incus: &mut Incus, instances: &[(String, &str)]) {
    incus.objects.insert(
        INSTANCES_PATH.into(),
        json!(instances
            .iter()
            .map(|(name, _)| format!("/1.0/instances/{name}?project=orbit-ci"))
            .collect::<Vec<_>>()),
    );
    for (name, status) in instances {
        incus.objects.insert(
            format!("/1.0/instances/{name}?project=orbit-ci"),
            json!({"name": name, "status": status, "profiles": ["default"],
                   "config": {"volatile.base_image": "cloned-image", "volatile.uuid": name}}),
        );
    }
}

#[test]
fn converges_a_fresh_host_and_repeats_without_change() {
    let mut incus = converged();
    let before = incus.objects.clone();
    assert_eq!(act_on(&mut incus, "converge").unwrap(), "converged");
    assert_eq!(incus.objects, before);
    assert_eq!(act_on(&mut incus, "inspect").unwrap(), "matches");

    assert_eq!(incus.objects[POOL_PATH]["driver"], "dir");
    assert_eq!(incus.objects[INSTANCES_PATH], json!([]));
    let project = &incus.objects[PROJECT_PATH]["config"];
    assert_eq!(project["features.networks"], "false");
    assert_eq!(project["restricted.containers.privilege"], "unprivileged");
    assert_eq!(project["restricted.containers.nesting"], "block");
    assert_eq!(project["limits.virtual-machines"], "0");
    let profile = &incus.objects[PROFILE_PATH];
    assert_eq!(profile["config"]["limits.cpu"], "6");
    assert_eq!(profile["config"]["limits.memory"], "12GiB");
    assert_eq!(profile["devices"]["eth0"]["network"], "orbitci0");
    let network = &incus.objects[NETWORK_PATH]["config"];
    assert_eq!(network["security.acls"], "orbit-ci-egress");
    assert_eq!(network["security.acls.default.egress.action"], "reject");
    assert_eq!(network["security.acls.default.ingress.action"], "reject");
}

#[test]
fn creates_the_project_with_only_its_feature_flags() {
    let incus = converged();
    let create = incus
        .calls
        .iter()
        .find(|call| call.len() > 3 && call[2..4] == ["project", "create"])
        .unwrap();
    assert_eq!(
        create[5..],
        [
            "-c",
            "features.images=true",
            "-c",
            "features.profiles=true",
            "-c",
            "features.storage.volumes=true",
            "-c",
            "features.storage.buckets=true",
            "-c",
            "features.networks=false"
        ]
    );
}

#[test]
fn adopts_existing_resources_and_runners_without_changing_subnet_pool_or_instances() {
    for (host, active, retired) in [("shark", 4, None), ("beast", 1, Some("ci-beast-2"))] {
        let mut incus = converged();
        incus.objects.get_mut(NETWORK_PATH).unwrap()["config"]["ipv4.address"] =
            json!("10.253.2.1/24");
        incus.objects.insert(
            POOL_PATH.into(),
            json!({"driver": "zfs", "config": {"source": "fast/incus"}}),
        );
        let mut instances: Vec<(String, &str)> = (1..=active)
            .map(|n| (format!("ci-{host}-{n}"), "Running"))
            .collect();
        instances.extend(retired.map(|name| (name.to_string(), "Stopped")));
        with_instances(&mut incus, &instances);
        let before = incus.objects.clone();
        incus.calls.clear();

        assert_eq!(act_on(&mut incus, "converge").unwrap(), "converged");
        assert_eq!(act_on(&mut incus, "inspect").unwrap(), "matches");
        assert_eq!(act_on(&mut incus, "converge").unwrap(), "converged");

        assert_eq!(incus.objects, before, "{host}");
        for call in changes(&incus) {
            assert!(
                [
                    ["project", "set"],
                    ["network", "acl"],
                    ["network", "set"],
                    ["profile", "edit"]
                ]
                .iter()
                .any(|prefix| call[2..4] == *prefix)
                    && !call.iter().any(|a| a == "create" || a == "delete"),
                "{call:?}"
            );
        }
    }
}

#[test]
fn inspection_reports_each_boundary_drift_without_change_and_convergence_repairs_it() {
    let mut incus = converged();
    let cases = [
        (
            PROJECT_PATH,
            "config",
            "restricted.containers.nesting",
            json!("allow"),
        ),
        (NETWORK_PATH, "config", "security.acls", json!("")),
        (
            NETWORK_PATH,
            "config",
            "security.acls.default.egress.action",
            json!("allow"),
        ),
        (
            NETWORK_PATH,
            "config",
            "security.acls.default.ingress.action",
            json!("allow"),
        ),
        (PROFILE_PATH, "config", "security.privileged", json!("true")),
        (
            PROFILE_PATH,
            "devices",
            "host",
            json!({"type": "disk", "source": "/"}),
        ),
    ];
    for (path, field, key, value) in cases {
        incus.objects.get_mut(path).unwrap()[field][key] = value;
        let before = incus.objects.clone();
        incus.calls.clear();
        assert_eq!(act_on(&mut incus, "inspect").unwrap(), "drift", "{key}");
        assert_eq!(incus.objects, before);
        assert!(changes(&incus).is_empty());
        assert_eq!(act_on(&mut incus, "converge").unwrap(), "converged");
        assert_eq!(act_on(&mut incus, "inspect").unwrap(), "matches", "{key}");
    }
    incus.objects.get_mut(ACL_PATH).unwrap()["egress"] = json!([]);
    assert_eq!(act_on(&mut incus, "inspect").unwrap(), "drift");
    assert_eq!(act_on(&mut incus, "converge").unwrap(), "converged");
    incus.objects.get_mut(NETWORK_PATH).unwrap()["config"]["ipv4.address"] = json!("none");
    assert_eq!(act_on(&mut incus, "inspect").unwrap(), "drift");
}

#[test]
fn inspection_of_an_unprepared_host_reports_drift_without_creating_resources() {
    let mut incus = Incus::default();
    assert_eq!(act_on(&mut incus, "inspect").unwrap(), "drift");
    assert!(incus.objects.is_empty());
    assert!(changes(&incus).is_empty());
}

#[test]
fn acl_rejects_private_ipv4_and_all_ipv6_before_allowing_public_ipv4() {
    let incus = converged();
    let acl = &incus.objects[ACL_PATH];
    assert_eq!(acl["ingress"], json!([]));
    let egress = acl["egress"].as_array().unwrap();
    assert_eq!(egress[0]["action"], "reject");
    let private: Vec<&str> = egress[0]["destination"]
        .as_str()
        .unwrap()
        .split(',')
        .collect();
    for network in [
        "10.0.0.0/8",
        "100.64.0.0/10",
        "127.0.0.0/8",
        "169.254.0.0/16",
        "172.16.0.0/12",
        "192.168.0.0/16",
        "224.0.0.0/3",
    ] {
        assert!(private.contains(&network), "{network}");
    }
    assert_eq!(
        egress[1],
        json!({"action": "reject", "destination": "::/0", "state": "enabled"})
    );
    assert_eq!(
        egress[2],
        json!({"action": "allow", "destination": "0.0.0.0/0", "state": "enabled"})
    );
}

#[test]
fn removal_refuses_any_instance_including_a_lone_stopped_one_and_never_deletes_it() {
    for (name, status) in [("ci-beast-1", "Running"), ("ci-beast-2", "Stopped")] {
        let mut incus = converged();
        with_instances(&mut incus, &[(name.to_string(), status)]);
        let before = incus.objects.clone();
        incus.calls.clear();
        assert_eq!(act_on(&mut incus, "remove").unwrap(), "occupied");
        assert_eq!(act_on(&mut incus, "purge").unwrap(), "occupied");
        assert_eq!(incus.objects, before);
        assert!(changes(&incus).is_empty());
    }
}

#[test]
fn empty_removal_retains_resources_and_purge_keeps_the_shared_pool_and_repeats() {
    let mut incus = converged();
    let before = incus.objects.clone();
    assert_eq!(act_on(&mut incus, "remove").unwrap(), "retained");
    assert_eq!(incus.objects, before);
    assert_eq!(act_on(&mut incus, "purge").unwrap(), "removed");
    for path in [PROJECT_PATH, NETWORK_PATH, ACL_PATH] {
        assert!(!incus.objects.contains_key(path), "{path}");
    }
    assert!(incus.objects.contains_key(POOL_PATH));
    incus.calls.clear();
    assert_eq!(act_on(&mut incus, "purge").unwrap(), "removed");
    assert!(changes(&incus).is_empty());
    assert_eq!(act_on(&mut incus, "remove").unwrap(), "retained");
}

#[test]
fn convergence_refuses_a_project_with_its_own_networks_before_changing_it() {
    let mut incus = converged();
    incus.objects.get_mut(PROJECT_PATH).unwrap()["config"]["features.networks"] = json!("true");
    let before = incus.objects.clone();
    incus.calls.clear();
    let error = act_on(&mut incus, "converge").unwrap_err();
    assert_eq!(error.code(), "ci_runner.host_failed");
    assert_eq!(
        error,
        failed("orbit-ci must share the default project networks")
    );
    assert_eq!(incus.objects, before);
    assert!(changes(&incus).is_empty());
}

#[test]
fn convergence_refuses_an_unmanaged_or_non_bridge_network_before_setting_it() {
    for (key, value) in [("type", json!("macvlan")), ("managed", json!(false))] {
        let mut incus = converged();
        incus.objects.get_mut(NETWORK_PATH).unwrap()[key] = value;
        incus.calls.clear();
        let error = act_on(&mut incus, "converge").unwrap_err();
        assert_eq!(error, failed("orbitci0 must be a managed bridge"));
        assert!(!changes(&incus)
            .iter()
            .any(|call| call[2..4] == ["network", "set"] || call[2] == "profile"));
    }
}

#[test]
fn incus_errors_are_not_mistaken_for_an_absent_resource() {
    let mut incus = Incus {
        broken: true,
        ..Incus::default()
    };
    for action in ["converge", "inspect", "remove", "purge"] {
        incus.calls.clear();
        let error = act_on(&mut incus, action).unwrap_err();
        assert_eq!(error.code(), "ci_runner.host_failed");
        assert_eq!(incus.calls.len(), 1, "{action}");
        assert_eq!(incus.calls[0][0], "query");
    }
}

#[test]
fn truncated_output_fails_instead_of_reading_a_partial_answer() {
    struct Truncated;
    impl Host for Truncated {
        fn incus(&mut self, _: &[&str], _: Option<&str>) -> Output {
            Output {
                code: 0,
                stdout: "[]".into(),
                stderr: String::new(),
                truncated: true,
            }
        }
    }
    let error = act(&mut Truncated, &HostInput::Inspect {}).unwrap_err();
    assert_eq!(error.code(), "ci_runner.host_failed");
}

#[test]
fn the_input_refuses_an_unknown_action_or_field() {
    assert!(serde_json::from_value::<HostInput>(json!({"action": "register"})).is_err());
    assert!(
        serde_json::from_value::<HostInput>(json!({"action": "converge", "name": "x"})).is_err()
    );
}
