//! The primitives against a fake Incus: a unix socket in a scratch folder that answers scripted replies in order and
//! records each request.

use std::io::{BufRead as _, BufReader, Read as _, Write as _};
use std::os::unix::net::UnixListener;
use std::path::PathBuf;
use std::sync::{Arc, Mutex};
use std::thread;
use std::time::Duration;

use serde_json::{json, Value};

use super::*;

/// One request the fake saw: method, path, `If-Match` and JSON body.
#[derive(Debug, Clone)]
struct Seen {
    method: String,
    path: String,
    if_match: Option<String>,
    body: Value,
}

/// One scripted answer: status and JSON body, or a silence that never answers.
enum Reply {
    Json(u16, Value),
    Chunked(u16, Value),
    Silent,
    /// A reply after a delay, as Incus answers a wait only when its timeout ends.
    After(Duration, Box<Reply>),
}

struct Fake {
    _dir: tempfile::TempDir,
    socket: PathBuf,
    seen: Arc<Mutex<Vec<Seen>>>,
}

impl Fake {
    fn new(replies: Vec<Reply>) -> Self {
        let dir = tempfile::tempdir().expect("a scratch folder");
        let socket = dir.path().join("unix.socket");
        let listener = UnixListener::bind(&socket).expect("a socket");
        let seen = Arc::new(Mutex::new(Vec::new()));
        let log = Arc::clone(&seen);
        thread::spawn(move || {
            for reply in replies {
                let Ok((stream, _)) = listener.accept() else {
                    return;
                };
                let mut reader = BufReader::new(stream.try_clone().expect("a stream"));
                let mut line = String::new();
                reader.read_line(&mut line).expect("a request line");
                let mut parts = line.split_whitespace();
                let (method, path) = (
                    parts.next().unwrap_or("").to_owned(),
                    parts.next().unwrap_or("").to_owned(),
                );
                let (mut length, mut if_match) = (0, None);
                loop {
                    let mut header = String::new();
                    reader.read_line(&mut header).expect("a header");
                    if header == "\r\n" {
                        break;
                    }
                    let (name, value) = header.split_once(':').expect("a header");
                    match name.to_ascii_lowercase().as_str() {
                        "content-length" => length = value.trim().parse().expect("a length"),
                        "if-match" => if_match = Some(value.trim().to_owned()),
                        _ => {}
                    }
                }
                let mut body = vec![0; length];
                reader.read_exact(&mut body).expect("a body");
                let body = if body.is_empty() {
                    Value::Null
                } else {
                    serde_json::from_slice(&body).expect("JSON")
                };
                log.lock().expect("the log").push(Seen {
                    method,
                    path,
                    if_match,
                    body,
                });
                let mut stream = stream;
                let reply = match reply {
                    Reply::After(delay, reply) => {
                        thread::sleep(delay);
                        *reply
                    }
                    reply => reply,
                };
                match reply {
                    Reply::Json(status, value) => {
                        let text = value.to_string();
                        let _ = write!(stream, "HTTP/1.1 {status} X\r\nContent-Type: application/json\r\nETag: \"e1\"\r\nContent-Length: {}\r\n\r\n{text}", text.len());
                    }
                    Reply::Chunked(status, value) => {
                        let text = value.to_string();
                        let (a, b) = text.split_at(text.len() / 2);
                        let _ = write!(stream, "HTTP/1.1 {status} X\r\nTransfer-Encoding: chunked\r\n\r\n{:x}\r\n{a}\r\n{:x}\r\n{b}\r\n0\r\n\r\n", a.len(), b.len());
                    }
                    Reply::Silent | Reply::After(..) => thread::sleep(Duration::from_secs(5)),
                }
            }
        });
        Self {
            _dir: dir,
            socket,
            seen,
        }
    }

    fn socket(&self) -> String {
        self.socket.display().to_string()
    }

    fn seen(&self) -> Vec<Seen> {
        self.seen.lock().expect("the log").clone()
    }
}

fn ok(metadata: Value) -> Reply {
    let mut envelope = json!({"type": "sync", "status_code": 200});
    envelope["metadata"] = metadata;
    Reply::Json(200, envelope)
}

fn missing() -> Reply {
    Reply::Json(
        404,
        json!({"type": "error", "error_code": 404, "error": "not found"}),
    )
}

fn queued() -> Reply {
    Reply::Json(
        202,
        json!({"type": "async", "status_code": 100, "operation": "/1.0/operations/op1"}),
    )
}

fn finished(code: u64) -> Reply {
    ok(
        json!({"status_code": code, "status": if code == 200 { "Success" } else { "Failure" }, "err": if code == 200 { "" } else { "copy failed" }}),
    )
}

fn rule() -> Value {
    json!({"action": "allow", "state": "enabled", "destination": "10.0.0.1", "protocol": "tcp", "destination_port": "443"})
}

/// The rule as Incus answers it: every field, empty when unset.
fn stored_rule() -> Value {
    json!({"action": "allow", "state": "enabled", "description": "", "source": "", "destination": "10.0.0.1", "protocol": "tcp",
        "source_port": "", "destination_port": "443", "icmp_type": "", "icmp_code": ""})
}

fn acl(fake: &Fake, state: &str) -> Value {
    let mut input = json!({"socket": fake.socket(), "project": "p1", "name": "a1", "state": state, "timeout_ms": 2000});
    if state == "present" {
        input["egress"] = json!([rule()]);
    }
    input
}

fn stored_acl(used_by: Value) -> Value {
    let mut acl = json!({"name": "a1", "description": "", "config": {}, "ingress": [], "egress": [stored_rule()]});
    acl["used_by"] = used_by;
    acl
}

#[test]
fn acl_present_creates_and_reads_back() {
    let fake = Fake::new(vec![missing(), ok(json!({})), ok(stored_acl(json!([])))]);
    assert_eq!(acl_ensure(&acl(&fake, "present")), Ok(1));
    let seen = fake.seen();
    assert_eq!(
        (seen[0].method.as_str(), seen[0].path.as_str()),
        ("GET", "/1.0/network-acls/a1?project=p1")
    );
    assert_eq!(
        (seen[1].method.as_str(), seen[1].path.as_str()),
        ("POST", "/1.0/network-acls?project=p1")
    );
    assert_eq!(seen[1].body["name"], "a1");
    assert_eq!(seen[1].body["egress"][0]["destination_port"], "443");
    assert_eq!(seen[2].method, "GET");
}

#[test]
fn acl_repeat_answers_zero() {
    let fake = Fake::new(vec![Reply::Chunked(
        200,
        json!({"type": "sync", "metadata": stored_acl(json!([]))}),
    )]);
    assert_eq!(acl_ensure(&acl(&fake, "present")), Ok(0));
    assert_eq!(fake.seen().len(), 1);
}

#[test]
fn acl_that_differs_is_replaced_under_its_etag() {
    let mut old = stored_acl(json!([]));
    old["egress"] = json!([]);
    let fake = Fake::new(vec![ok(old), ok(json!({})), ok(stored_acl(json!([])))]);
    assert_eq!(acl_ensure(&acl(&fake, "present")), Ok(1));
    let seen = fake.seen();
    assert_eq!(
        (seen[1].method.as_str(), seen[1].if_match.as_deref()),
        ("PUT", Some("\"e1\""))
    );
}

#[test]
fn acl_absent_deletes_only_an_unused_acl() {
    let fake = Fake::new(vec![ok(stored_acl(json!([]))), ok(json!({})), missing()]);
    assert_eq!(acl_ensure(&acl(&fake, "absent")), Ok(1));
    assert_eq!(
        (fake.seen()[1].method.as_str(), fake.seen()[1].path.as_str()),
        ("DELETE", "/1.0/network-acls/a1?project=p1")
    );

    let fake = Fake::new(vec![missing()]);
    assert_eq!(acl_ensure(&acl(&fake, "absent")), Ok(0));

    let fake = Fake::new(vec![ok(stored_acl(json!(["/1.0/instances/c1"])))]);
    assert_eq!(
        acl_ensure(&acl(&fake, "absent")).map_err(|failure| failure.code),
        Err("incus.acl_in_use")
    );
    assert_eq!(fake.seen().len(), 1);
}

#[test]
fn acl_refuses_unknown_fields_bad_names_and_rules_before_any_request() {
    let fake = Fake::new(vec![]);
    for input in [
        json!({"socket": fake.socket(), "project": "p1", "name": "a1", "state": "present", "timeout_ms": 2000, "command": "rm"}),
        json!({"socket": fake.socket(), "project": "p1", "name": "../a1", "state": "present", "timeout_ms": 2000}),
        json!({"socket": fake.socket(), "project": "p1", "name": "a1", "state": "present", "timeout_ms": 0}),
        json!({"socket": "relative.socket", "project": "p1", "name": "a1", "state": "present", "timeout_ms": 2000}),
        json!({"socket": fake.socket(), "project": "p1", "name": "a1", "state": "present", "timeout_ms": 2000, "egress": [{"action": "run", "state": "enabled"}]}),
        json!({"socket": fake.socket(), "project": "p1", "name": "a1", "state": "present", "timeout_ms": 2000, "egress": [{"action": "allow", "state": "enabled", "argv": "x"}]}),
        json!({"socket": fake.socket(), "project": "p1", "name": "a1", "state": "absent", "timeout_ms": 2000, "egress": [rule()]}),
    ] {
        assert_eq!(
            acl_ensure(&input).map_err(|failure| failure.code),
            Err("incus.input_invalid"),
            "{input}"
        );
    }
    assert!(fake.seen().is_empty());
}

#[test]
fn an_api_error_and_a_wrong_read_back_fail() {
    let fake = Fake::new(vec![
        missing(),
        Reply::Json(
            400,
            json!({"type": "error", "error_code": 400, "error": "bad rule"}),
        ),
    ]);
    assert_eq!(
        acl_ensure(&acl(&fake, "present")).map_err(|failure| failure.code),
        Err("incus.api_failed")
    );

    let fake = Fake::new(vec![missing(), ok(json!({})), ok(json!({"egress": []}))]);
    assert_eq!(
        acl_ensure(&acl(&fake, "present")).map_err(|failure| failure.code),
        Err("incus.not_converged")
    );

    let fake = Fake::new(vec![Reply::Json(
        500,
        json!({"type": "error", "error": "database"}),
    )]);
    assert_eq!(
        acl_ensure(&acl(&fake, "present")).map_err(|failure| failure.code),
        Err("incus.api_failed")
    );
}

#[test]
fn a_missing_socket_is_unreachable() {
    let input = json!({"socket": "/nonexistent/unix.socket", "project": "p1", "name": "a1", "state": "absent", "timeout_ms": 2000});
    assert_eq!(
        acl_ensure(&input).map_err(|failure| failure.code),
        Err("incus.unreachable")
    );
}

#[test]
fn a_silent_incus_times_out_within_the_input_bound() {
    let fake = Fake::new(vec![Reply::Silent]);
    let mut input = acl(&fake, "present");
    input["timeout_ms"] = json!(1000);
    let started = std::time::Instant::now();
    assert_eq!(
        acl_ensure(&input).map_err(|failure| failure.code),
        Err("incus.timeout")
    );
    assert!(
        started.elapsed() < Duration::from_secs(3),
        "bounded by timeout_ms"
    );
}

fn instance(devices: Value) -> Value {
    let mut instance = json!({"name": "c1", "status": "Stopped", "architecture": "x86_64", "config": {"limits.cpu": "1"},
        "ephemeral": false, "profiles": ["default"], "stateful": false, "description": "", "expanded_devices": {"root": {}}});
    instance["devices"] = devices;
    instance
}

fn device(fake: &Fake, state: &str) -> Value {
    let mut input = json!({"socket": fake.socket(), "project": "p1", "instance": "c1", "device": "data", "state": state, "timeout_ms": 2000});
    if state == "present" {
        input["config"] = json!({"type": "disk", "pool": "pool1", "source": "v1", "path": "/data"});
    }
    input
}

fn data() -> Value {
    json!({"type": "disk", "pool": "pool1", "source": "v1", "path": "/data"})
}

#[test]
fn device_present_writes_only_its_key_and_waits_for_the_operation() {
    let other = json!({"eth0": {"type": "nic", "network": "br0"}});
    let mut both = other.clone();
    both["data"] = data();
    let fake = Fake::new(vec![
        ok(instance(other)),
        queued(),
        finished(200),
        ok(instance(both.clone())),
    ]);
    assert_eq!(device_ensure(&device(&fake, "present")), Ok(1));
    let seen = fake.seen();
    assert_eq!(
        (
            seen[1].method.as_str(),
            seen[1].path.as_str(),
            seen[1].if_match.as_deref()
        ),
        ("PUT", "/1.0/instances/c1?project=p1", Some("\"e1\""))
    );
    assert_eq!(seen[1].body["devices"], both);
    assert_eq!(seen[1].body["config"], json!({"limits.cpu": "1"}));
    assert!(seen[1].body.get("expanded_devices").is_none() && seen[1].body.get("status").is_none());
    assert_eq!(seen[2].path, "/1.0/operations/op1/wait?timeout=1");
}

#[test]
fn device_repeat_answers_zero_and_absent_removes_only_its_key() {
    let fake = Fake::new(vec![ok(instance(json!({"data": data()})))]);
    assert_eq!(device_ensure(&device(&fake, "present")), Ok(0));

    let fake = Fake::new(vec![
        ok(instance(json!({"data": data(), "eth0": {"type": "nic"}}))),
        ok(json!({})),
        ok(instance(json!({"eth0": {"type": "nic"}}))),
    ]);
    assert_eq!(device_ensure(&device(&fake, "absent")), Ok(1));
    assert_eq!(
        fake.seen()[1].body["devices"],
        json!({"eth0": {"type": "nic"}})
    );

    let fake = Fake::new(vec![ok(instance(json!({"eth0": {"type": "nic"}})))]);
    assert_eq!(device_ensure(&device(&fake, "absent")), Ok(0));
}

#[test]
fn device_refusals() {
    let fake = Fake::new(vec![missing()]);
    assert_eq!(
        device_ensure(&device(&fake, "present")).map_err(|failure| failure.code),
        Err("incus.instance_missing")
    );

    let fake = Fake::new(vec![]);
    let mut untyped = device(&fake, "present");
    untyped["config"] = json!({"path": "/data"});
    let mut absent_with_config = device(&fake, "present");
    absent_with_config["state"] = json!("absent");
    for input in [untyped, absent_with_config] {
        assert_eq!(
            device_ensure(&input).map_err(|failure| failure.code),
            Err("incus.input_invalid")
        );
    }
    assert!(fake.seen().is_empty());
}

#[test]
fn a_failed_operation_fails_and_a_running_one_times_out() {
    let fake = Fake::new(vec![ok(instance(json!({}))), queued(), finished(400)]);
    assert_eq!(
        device_ensure(&device(&fake, "present")).map_err(|failure| failure.code),
        Err("incus.api_failed")
    );

    let running = || Reply::After(Duration::from_millis(400), Box::new(finished(103)));
    let fake = Fake::new(vec![
        ok(instance(json!({}))),
        queued(),
        running(),
        running(),
        running(),
        running(),
    ]);
    let mut input = device(&fake, "present");
    input["timeout_ms"] = json!(1000);
    assert_eq!(
        device_ensure(&input).map_err(|failure| failure.code),
        Err("incus.timeout")
    );
}

fn volume(fake: &Fake, state: &str) -> Value {
    let mut input = json!({"socket": fake.socket(), "project": "p1", "pool": "pool1", "name": "v2", "state": state, "timeout_ms": 2000});
    if state == "present" {
        input["source"] = json!({"pool": "pool1", "volume": "v1", "snapshot": "s1"});
        input["config"] = json!({"size": "1GiB"});
    }
    input
}

fn stored_volume(source: &str, used_by: Value) -> Value {
    let mut volume = json!({"name": "v2", "type": "custom", "config": {"size": "1GiB", "user.source": source, "volatile.uuid": "u"}});
    volume["used_by"] = used_by;
    volume
}

#[test]
fn volume_present_copies_from_the_snapshot_and_records_it() {
    let fake = Fake::new(vec![
        missing(),
        ok(json!({"name": "s1"})),
        queued(),
        finished(200),
        ok(stored_volume("p1/pool1/v1/s1", json!([]))),
    ]);
    assert_eq!(volume_ensure(&volume(&fake, "present")), Ok(1));
    let seen = fake.seen();
    assert_eq!(
        seen[0].path,
        "/1.0/storage-pools/pool1/volumes/custom/v2?project=p1"
    );
    assert_eq!(
        seen[1].path,
        "/1.0/storage-pools/pool1/volumes/custom/v1/snapshots/s1?project=p1"
    );
    assert_eq!(
        (seen[2].method.as_str(), seen[2].path.as_str()),
        ("POST", "/1.0/storage-pools/pool1/volumes/custom?project=p1")
    );
    assert_eq!(
        seen[2].body,
        json!({"name": "v2", "type": "custom", "config": {"size": "1GiB", "user.source": "p1/pool1/v1/s1"},
            "source": {"type": "copy", "pool": "pool1", "name": "v1/s1", "project": "p1", "volume_only": true}})
    );
}

#[test]
fn volume_repeat_answers_zero_and_a_changed_key_is_patched() {
    let fake = Fake::new(vec![ok(stored_volume("p1/pool1/v1/s1", json!([])))]);
    assert_eq!(volume_ensure(&volume(&fake, "present")), Ok(0));

    let mut input = volume(&Fake::new(vec![]), "present");
    let fake = Fake::new(vec![
        ok(stored_volume("p1/pool1/v1/s1", json!([]))),
        ok(json!({})),
        ok({
            let mut grown = stored_volume("p1/pool1/v1/s1", json!([]));
            grown["config"]["size"] = json!("2GiB");
            grown
        }),
    ]);
    input["socket"] = json!(fake.socket());
    input["config"] = json!({"size": "2GiB"});
    assert_eq!(volume_ensure(&input), Ok(1));
    assert_eq!(
        (fake.seen()[1].method.as_str(), &fake.seen()[1].body),
        ("PATCH", &json!({"config": {"size": "2GiB"}}))
    );
}

#[test]
fn volume_from_another_source_refuses_and_is_never_written() {
    for found in [
        stored_volume("p1/pool1/v9/s1", json!([])),
        json!({"name": "v2", "config": {}, "used_by": []}),
    ] {
        let fake = Fake::new(vec![ok(found)]);
        assert_eq!(
            volume_ensure(&volume(&fake, "present")).map_err(|failure| failure.code),
            Err("incus.volume_source_conflict")
        );
        assert_eq!(fake.seen().len(), 1, "only the read");
    }
}

#[test]
fn volume_with_a_missing_source_refuses() {
    let fake = Fake::new(vec![missing(), missing()]);
    assert_eq!(
        volume_ensure(&volume(&fake, "present")).map_err(|failure| failure.code),
        Err("incus.volume_source_missing")
    );
    assert_eq!(fake.seen().len(), 2);
}

#[test]
fn volume_absent_deletes_exactly_that_unused_volume() {
    let fake = Fake::new(vec![
        ok(stored_volume("p1/pool1/v1/s1", json!([]))),
        queued(),
        finished(200),
        missing(),
    ]);
    assert_eq!(volume_ensure(&volume(&fake, "absent")), Ok(1));
    assert_eq!(
        (fake.seen()[1].method.as_str(), fake.seen()[1].path.as_str()),
        (
            "DELETE",
            "/1.0/storage-pools/pool1/volumes/custom/v2?project=p1"
        )
    );

    let fake = Fake::new(vec![missing()]);
    assert_eq!(volume_ensure(&volume(&fake, "absent")), Ok(0));

    let fake = Fake::new(vec![ok(stored_volume(
        "p1/pool1/v1/s1",
        json!(["/1.0/instances/c1?project=p1"]),
    ))]);
    assert_eq!(
        volume_ensure(&volume(&fake, "absent")).map_err(|failure| failure.code),
        Err("incus.volume_in_use")
    );
    assert_eq!(fake.seen().len(), 1);
}

#[test]
fn volume_refusals_before_any_request() {
    let fake = Fake::new(vec![]);
    let base = volume(&fake, "present");
    let mut cases = Vec::new();
    let mut no_source = base.clone();
    no_source
        .as_object_mut()
        .expect("an object")
        .remove("source");
    cases.push(no_source);
    let mut own = base.clone();
    own["source"] = json!({"pool": "pool1", "volume": "v2"});
    cases.push(own);
    let mut reserved = base.clone();
    reserved["config"] = json!({"user.source": "x"});
    cases.push(reserved);
    let mut unknown = base.clone();
    unknown["source"]["argv"] = json!(["incus"]);
    cases.push(unknown);
    let mut absent = base;
    absent["state"] = json!("absent");
    cases.push(absent);
    for input in cases {
        assert_eq!(
            volume_ensure(&input).map_err(|failure| failure.code),
            Err("incus.input_invalid"),
            "{input}"
        );
    }
    assert!(fake.seen().is_empty());
}
