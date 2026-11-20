use std::cell::RefCell;

use serde_json::{json, Value};

use super::*;

/// A Node double: answers each `incus` call from a table, and records every call with its standard input.
struct Fake {
    answers: Vec<(String, Result<String, String>)>,
    calls: RefCell<Vec<(String, Option<String>)>>,
}

impl Fake {
    fn new(answers: &[(&str, Result<Value, &str>)]) -> Self {
        Self {
            answers: answers
                .iter()
                .map(|(call, answer)| ((*call).to_owned(), answer.as_ref().map(Value::to_string).map_err(|error| (*error).to_owned())))
                .collect(),
            calls: RefCell::default(),
        }
    }

    fn calls(&self) -> Vec<String> {
        self.calls.borrow().iter().map(|(call, _)| call.clone()).collect()
    }
}

impl System for Fake {
    fn run(&self, _seconds: u32, argv: &[String], stdin: Option<&[u8]>) -> Result<String, String> {
        let call = argv[1..].join(" ");
        self.calls.borrow_mut().push((call.clone(), stdin.map(|bytes| String::from_utf8_lossy(bytes).into_owned())));
        self.answers
            .iter()
            .find(|(prefix, _)| call.starts_with(prefix.as_str()))
            .map_or_else(|| Ok(String::new()), |(_, answer)| answer.clone())
    }
}

fn input() -> Value {
    json!({
        "project": "orbit-instances",
        "name": "instance-12",
        "source": "instance-2",
        "snapshot": "seed-abc123",
        "acl": {"name": "instance-12", "rules": {"description": "Orbit Instance 12", "egress": [], "ingress": [{"action": "allow"}]}},
        "nic_acl": "orbitinst0-proxy",
        "address": "10.253.0.12",
        "config": {"limits.cpu": "4", "security.nesting": "true"},
        "root": {"pool": "orbit-instances", "size": "40GiB"},
        "filters": {"security.ipv4_filtering": "true", "security.mac_filtering": "true"},
        "proxy": {"device": "t3", "listen": "tcp:10.44.0.7:3801", "connect": "tcp:10.253.0.12:3773"},
    })
}

const MISSING: Result<Value, &str> = Err("Error: Not Found");

fn snapshots(names: &[(&str, &str)]) -> Result<Value, &'static str> {
    Ok(Value::Array(names.iter().map(|(name, at)| json!({"name": name, "created_at": at})).collect()))
}

#[test]
fn a_new_copy_gets_its_acl_the_seed_copy_and_the_t3_proxy() {
    let fake = Fake::new(&[
        ("query /1.0/instances/instance-12?", MISSING),
        ("query /1.0/network-acls/", MISSING),
        ("query /1.0/instances/instance-2/snapshots", snapshots(&[("seed-abc123", "2026-11-19")])),
        ("query /1.0/storage-pools/", Ok(json!({"driver": "zfs"}))),
    ]);

    let output = clone(&fake, &input()).expect("clone");

    assert_eq!(output, r#"{"snapshot":"seed-abc123"}"#);
    let calls = fake.calls();
    assert_eq!(calls[2], "network acl create instance-12 --project default");
    assert_eq!(calls[3], "network acl edit instance-12 --project default");
    let edit = fake.calls.borrow()[3].1.clone().expect("rules on stdin");
    assert_eq!(serde_json::from_str::<Value>(&edit).expect("json"), input()["acl"]["rules"]);
    assert_eq!(
        calls[6],
        "--project orbit-instances copy instance-2/seed-abc123 instance-12 --config limits.cpu=4 --config security.nesting=true \
         --device root,type=disk --device root,path=/ --device root,pool=orbit-instances --device root,size=40GiB \
         --device eth0,ipv4.address=10.253.0.12 --device eth0,security.acls=orbitinst0-proxy,instance-12 \
         --device eth0,security.acls.default.egress.action=reject --device eth0,security.acls.default.ingress.action=reject \
         --device eth0,security.ipv4_filtering=true --device eth0,security.mac_filtering=true"
    );
    assert_eq!(
        calls[7],
        "--project orbit-instances config device add instance-12 t3 proxy listen=tcp:10.44.0.7:3801 connect=tcp:10.253.0.12:3773 nat=true"
    );
    assert_eq!(calls.len(), 8);
}

#[test]
fn a_btrfs_root_carries_no_size() {
    let fake = Fake::new(&[
        ("query /1.0/instances/instance-12?", MISSING),
        ("query /1.0/network-acls/", Ok(input()["acl"]["rules"].clone())),
        ("query /1.0/instances/instance-2/snapshots", snapshots(&[("seed-abc123", "")])),
        ("query /1.0/storage-pools/", Ok(json!({"driver": "btrfs"}))),
    ]);

    clone(&fake, &input()).expect("clone");

    let copy = fake.calls().into_iter().find(|call| call.contains(" copy ")).expect("copy");
    assert!(!copy.contains("root,size"), "{copy}");
}

#[test]
fn a_repeat_finds_the_copy_and_its_acl_and_changes_nothing() {
    let fake = Fake::new(&[
        ("query /1.0/instances/instance-12?", Ok(json!({"devices": {"t3": {"type": "proxy"}}}))),
        ("query /1.0/network-acls/", Ok(input()["acl"]["rules"].clone())),
    ]);

    assert_eq!(clone(&fake, &input()).expect("clone"), r#"{"snapshot":null}"#);
    assert_eq!(fake.calls().len(), 2);
}

#[test]
fn a_replaced_seed_copies_the_newest_and_a_retake_refuses() {
    let replaced = Fake::new(&[
        ("query /1.0/instances/instance-12?", MISSING),
        ("query /1.0/network-acls/", Ok(input()["acl"]["rules"].clone())),
        ("query /1.0/instances/instance-2/snapshots", snapshots(&[("seed-old", "2026-11-01"), ("seed-new", "2026-11-18")])),
        ("query /1.0/storage-pools/", Ok(json!({"driver": "zfs"}))),
    ]);
    assert_eq!(clone(&replaced, &input()).expect("clone"), r#"{"snapshot":"seed-new"}"#);

    let retake = Fake::new(&[
        ("query /1.0/instances/instance-12?", MISSING),
        ("query /1.0/network-acls/", Ok(input()["acl"]["rules"].clone())),
        ("query /1.0/instances/instance-2/snapshots", snapshots(&[("seed-new", "2026-11-18"), ("next-abc123", "2026-11-19")])),
    ]);
    assert_eq!(clone(&retake, &input()).map_err(|failure| failure.code), Err("instance.default_seed_moved"));

    let none = Fake::new(&[
        ("query /1.0/instances/instance-12?", MISSING),
        ("query /1.0/network-acls/", Ok(input()["acl"]["rules"].clone())),
        ("query /1.0/instances/instance-2/snapshots", snapshots(&[])),
    ]);
    assert_eq!(clone(&none, &input()).map_err(|failure| failure.code), Err("instance.default_seed_missing"));
}

#[test]
fn a_dir_pool_refuses_before_any_copy() {
    let fake = Fake::new(&[
        ("query /1.0/instances/instance-12?", MISSING),
        ("query /1.0/network-acls/", Ok(input()["acl"]["rules"].clone())),
        ("query /1.0/instances/instance-2/snapshots", snapshots(&[("seed-abc123", "")])),
        ("query /1.0/storage-pools/", Ok(json!({"driver": "dir"}))),
    ]);

    assert_eq!(clone(&fake, &input()).map_err(|failure| failure.code), Err("instance.pool_driver_unsupported"));
    assert!(!fake.calls().iter().any(|call| call.contains(" copy ")));
}

#[test]
fn an_incus_failure_other_than_not_found_fails_the_clone() {
    let fake = Fake::new(&[("query /1.0/instances/instance-12?", Err("Error: connection refused"))]);

    assert_eq!(clone(&fake, &input()).map_err(|failure| failure.code), Err("instance.clone_failed"));
}

#[test]
fn values_that_could_carry_another_option_are_refused() {
    let cases: [(&str, Value); 6] = [
        ("/name", json!("instance-12 --force")),
        ("/snapshot", json!("next-abc")),
        ("/address", json!("10.253.0.1,eth1")),
        ("/config", json!({"limits.cpu": "4,root,pool=x"})),
        ("/filters", json!({"raw.lxc": "x"})),
        ("/proxy/listen", json!("tcp:0.0.0.0:22 --x")),
    ];
    for (pointer, value) in cases {
        let mut input = input();
        *input.pointer_mut(pointer).expect("field") = value;
        let fake = Fake::new(&[]);
        assert_eq!(clone(&fake, &input).map_err(|failure| failure.code), Err("instance.clone_input_invalid"), "{pointer}");
        assert!(fake.calls().is_empty());
    }
}
