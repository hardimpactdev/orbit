//! The Node owns ACL file publication. Gateway Predis tests own the database protocol.
#![cfg(target_os = "linux")]
use serde_json::{json, Value};
use std::process::{Command, Output};
#[path = "support/privileged_agent.rs"]
mod privileged_agent;
use privileged_agent::Agent;
const IMAGE: &str = "valkey/valkey:8.1";

fn docker(arguments: &[&str]) -> Output {
    Command::new("sudo")
        .args(["-n", "docker"])
        .args(arguments)
        .output()
        .unwrap()
}
fn successful(output: Output) -> String {
    assert!(output.status.success(), "{output:?}");
    String::from_utf8(output.stdout).unwrap()
}
struct Fixture {
    volume: String,
    agent: Agent,
}
impl Fixture {
    fn new() -> Self {
        Self {
            volume: format!("orbit-native-acl-{:016x}", rand::random::<u64>()),
            agent: Agent::new(),
        }
    }
    fn call(&self, input: Value) -> Value {
        let output = self
            .agent
            .start(
                "database.write-valkey-acl",
                input,
                "/usr/local/bin:/usr/bin:/bin",
            )
            .wait_with_output()
            .unwrap();
        assert!(output.status.success(), "{output:?}");
        serde_json::from_slice(&output.stdout).unwrap()
    }
    fn input(&self, acl: &str) -> Value {
        json!({"volume":self.volume, "image":IMAGE, "acl":acl})
    }
    fn inspect(&self, program: &str, arguments: &[&str]) -> String {
        let mount = format!("type=volume,source={},target=/data,readonly", self.volume);
        let mut args = vec![
            "run",
            "--rm",
            "--network",
            "none",
            "--mount",
            &mount,
            "--entrypoint",
            program,
            IMAGE,
        ];
        args.extend(arguments);
        successful(docker(&args))
    }
}
impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = docker(&["volume", "rm", "--force", &self.volume]);
    }
}

#[test]
#[ignore = "requires Docker and sudo on a disposable Linux host"]
fn writes_private_exact_acl_bytes_and_replaces_them_on_repeat() {
    let f = Fixture::new();
    let hash = "a".repeat(64);
    let closed = format!("user default on #{hash} ~* &* +@all\nuser app-1 on #{hash} ~app:* resetchannels -@all +get -@admin -@dangerous -function -script\n");
    for acl in [
        &closed[..],
        &closed[..],
        "user default on nopass ~* &* +@all\n",
    ] {
        let value = f.call(f.input(acl));
        assert_eq!(value["ok"], true, "{value}");
        assert_eq!(f.inspect("cat", &["/data/users.acl"]), acl);
        assert_eq!(
            f.inspect("stat", &["-c", "%a", "/data/users.acl"]).trim(),
            "600"
        );
        assert_eq!(
            f.inspect("find", &["/data", "-maxdepth", "1", "-name", "*.orbit-new"]),
            ""
        );
    }
}

#[test]
#[ignore = "requires Docker and sudo on a disposable Linux host"]
fn invalid_requests_leave_an_existing_acl_unchanged_and_create_no_volume() {
    let f = Fixture::new();
    let acl = "user default on nopass ~* &* +@all\n";
    assert_eq!(f.call(f.input(acl))["ok"], true);
    for (field, value) in [
        ("volume", "../outside"),
        ("image", "redis:7"),
        (
            "acl",
            "user default on nopass ~* &* +@all\nuser injected on nopass ~* +@all\n",
        ),
    ] {
        let mut input = f.input(acl);
        input[field] = value.into();
        let result = f.call(input);
        assert_eq!(
            result["error"]["code"], "database.valkey_acl_invalid",
            "{result}"
        );
        assert_eq!(f.inspect("cat", &["/data/users.acl"]), acl);
    }
    let absent = Fixture::new();
    assert_eq!(
        absent.call(absent.input("malformed\n"))["error"]["code"],
        "database.valkey_acl_invalid"
    );
    assert!(!docker(&["volume", "inspect", &absent.volume])
        .status
        .success());
}

#[test]
#[ignore = "requires Docker and sudo on a disposable Linux host"]
fn a_failed_publication_preserves_the_existing_destination() {
    let f = Fixture::new();
    let original = "user default on nopass ~* &* +@all\n";
    assert_eq!(f.call(f.input(original))["ok"], true);
    let mount = format!("type=volume,source={},target=/data", f.volume);
    successful(docker(&[
        "run",
        "--rm",
        "--network",
        "none",
        "--mount",
        &mount,
        "--entrypoint",
        "mkdir",
        IMAGE,
        "/data/users.acl.orbit-new",
    ]));
    let value = f.call(f.input(&format!(
        "user default on #{} ~* &* +@all\n",
        "b".repeat(64)
    )));
    assert_eq!(
        value["error"]["code"], "database.valkey_acl_failed",
        "{value}"
    );
    assert!(value["error"]["details"]["exit_code"].as_i64().unwrap() > 0);
    assert_eq!(f.inspect("cat", &["/data/users.acl"]), original);
}
