//! `incus.instance.ensure` against a fake Incus that answers its REST API on a unix socket in a scratch folder.

use std::collections::BTreeMap;
use std::io::{Read as _, Write as _};
use std::os::unix::net::{UnixListener, UnixStream};
use std::sync::{Arc, Mutex};
use std::time::Instant;

use serde_json::{json, Value};

use super::instance::SOURCE_KEY;
use super::instance_ensure;

/// How the fake finishes a background operation.
#[derive(Clone, Copy, PartialEq, Eq)]
enum Ops {
    Succeed,
    Fail,
    /// Still running when the wait ends.
    Hang,
}

struct State {
    /// `(project, name)` to `(status, recorded source)`.
    instances: BTreeMap<(String, String), (String, Option<String>)>,
    ops: Ops,
    /// A method and path prefix the fake answers with an Incus error.
    refuse: Option<String>,
    requests: Vec<String>,
    pending: Option<Pending>,
}

/// What a background operation does to the fake when it finishes.
type Pending = Box<dyn FnOnce(&mut State) + Send>;

struct Fake {
    _dir: tempfile::TempDir,
    socket: String,
    state: Arc<Mutex<State>>,
}

impl Fake {
    fn new() -> Self {
        let dir = tempfile::tempdir().expect("a scratch folder");
        let socket = dir.path().join("unix.socket");
        let listener = UnixListener::bind(&socket).expect("the fake socket");
        let mut instances = BTreeMap::new();
        instances.insert(
            ("seeds".to_owned(), "seed".to_owned()),
            ("Stopped".to_owned(), None),
        );
        let state = Arc::new(Mutex::new(State {
            instances,
            ops: Ops::Succeed,
            refuse: None,
            requests: Vec::new(),
            pending: None,
        }));
        let shared = Arc::clone(&state);
        std::thread::spawn(move || {
            for stream in listener.incoming() {
                let Ok(stream) = stream else { return };
                serve(stream, &shared);
            }
        });
        Self {
            _dir: dir,
            socket: socket.display().to_string(),
            state,
        }
    }

    fn with(&self, project: &str, name: &str, status: &str, source: Option<&str>) {
        self.state.lock().expect("state").instances.insert(
            (project.to_owned(), name.to_owned()),
            (status.to_owned(), source.map(str::to_owned)),
        );
    }

    fn status(&self, project: &str, name: &str) -> Option<String> {
        self.state
            .lock()
            .expect("state")
            .instances
            .get(&(project.to_owned(), name.to_owned()))
            .map(|found| found.0.clone())
    }

    fn changing(&self) -> Vec<String> {
        self.state
            .lock()
            .expect("state")
            .requests
            .iter()
            .filter(|request| !request.starts_with("GET"))
            .cloned()
            .collect()
    }

    fn order(&self, extra: &Value) -> Value {
        let mut order = json!({"socket": self.socket, "project": "throwaway", "name": "copy-1", "timeout_seconds": 5});
        for (key, value) in extra.as_object().expect("an object") {
            order[key] = value.clone();
        }
        order
    }
}

fn copy_order(fake: &Fake, state: &str) -> Value {
    fake.order(&json!({"state": state, "source": {"project": "seeds", "instance": "seed", "snapshot": "base"}, "profiles": ["default"]}))
}

fn serve(mut stream: UnixStream, state: &Mutex<State>) {
    let mut raw = Vec::new();
    let mut buffer = [0_u8; 4096];
    let (head, body) = loop {
        let read = stream.read(&mut buffer).expect("a request");
        raw.extend_from_slice(&buffer[..read]);
        if let Some(split) = raw.windows(4).position(|window| window == b"\r\n\r\n") {
            let head = String::from_utf8(raw[..split].to_vec()).expect("UTF-8 headers");
            let length: usize = head
                .lines()
                .find_map(|line| line.strip_prefix("Content-Length: "))
                .map_or(0, |n| n.parse().expect("a length"));
            while raw.len() < split + 4 + length {
                let read = stream.read(&mut buffer).expect("a body");
                raw.extend_from_slice(&buffer[..read]);
            }
            break (
                head,
                String::from_utf8(raw[split + 4..].to_vec()).expect("a UTF-8 body"),
            );
        }
    };
    let line = head.lines().next().expect("a request line");
    assert!(
        line.ends_with(" HTTP/1.0"),
        "one request per connection: {line}"
    );
    let mut parts = line.split(' ');
    let (method, target) = (
        parts.next().expect("a method"),
        parts.next().expect("a target"),
    );
    let body: Value = if body.is_empty() {
        Value::Null
    } else {
        serde_json::from_str(&body).expect("a JSON body")
    };
    let (code, envelope) = answer(&mut state.lock().expect("state"), method, target, &body);
    let text = envelope.to_string();
    let reply = format!(
        "HTTP/1.0 {code} X\r\nContent-Type: application/json\r\nContent-Length: {}\r\n\r\n{text}",
        text.len()
    );
    let _ = stream.write_all(reply.as_bytes());
}

fn sync(metadata: &Value) -> (u16, Value) {
    (
        200,
        json!({"type": "sync", "status": "Success", "status_code": 200, "metadata": metadata}),
    )
}

fn error(code: u16, message: &str) -> (u16, Value) {
    (
        code,
        json!({"type": "error", "error": message, "error_code": code}),
    )
}

fn answer(state: &mut State, method: &str, target: &str, body: &Value) -> (u16, Value) {
    state.requests.push(format!("{method} {target} {body}"));
    if state
        .refuse
        .as_ref()
        .is_some_and(|refused| format!("{method} {target}").starts_with(refused.as_str()))
    {
        return error(500, "refused");
    }
    let (path, query) = target.split_once('?').unwrap_or((target, ""));
    let project = query
        .split('&')
        .find_map(|pair| pair.strip_prefix("project="))
        .unwrap_or("default")
        .to_owned();
    let segments: Vec<&str> = path.trim_start_matches("/1.0/").split('/').collect();
    let accepted = (
        202,
        json!({"type": "async", "status": "Operation created", "status_code": 100, "operation": "/1.0/operations/op-1"}),
    );
    match (method, segments.as_slice()) {
        ("GET", ["operations", _, "wait"]) => {
            let status = match state.ops {
                Ops::Succeed => "Success",
                Ops::Fail => "Failure",
                Ops::Hang => "Running",
            };
            if state.ops == Ops::Succeed {
                if let Some(pending) = state.pending.take() {
                    pending(state);
                }
            }
            sync(
                &json!({"id": "op-1", "status": status, "err": if status == "Failure" { "boom" } else { "" }}),
            )
        }
        ("GET", ["instances", "seed", "snapshots", "base"]) if project == "seeds" => {
            sync(&json!({"name": "base"}))
        }
        ("GET", ["instances", _, "snapshots", _]) => error(404, "Not Found"),
        ("GET", ["instances", name]) => match state.instances.get(&(project, (*name).to_owned())) {
            Some((status, source)) => {
                let config = source
                    .as_ref()
                    .map_or_else(|| json!({}), |source| json!({SOURCE_KEY: source}));
                sync(&json!({"name": name, "type": "container", "status": status, "config": config}))
            }
            None => error(404, "Instance not found"),
        },
        ("POST", ["instances"]) => {
            let name = body["name"].as_str().expect("a name").to_owned();
            let source = body["config"][SOURCE_KEY].as_str().map(str::to_owned);
            state.pending = Some(Box::new(move |state: &mut State| {
                state
                    .instances
                    .insert((project, name), ("Stopped".to_owned(), source));
            }));
            accepted
        }
        ("PUT", ["instances", name, "state"]) => {
            let key = (project, (*name).to_owned());
            let status = if body["action"] == "start" {
                "Running"
            } else {
                "Stopped"
            };
            state.pending = Some(Box::new(move |state: &mut State| {
                if let Some(found) = state.instances.get_mut(&key) {
                    status.clone_into(&mut found.0);
                }
            }));
            accepted
        }
        ("DELETE", ["instances", name]) => {
            let key = (project, (*name).to_owned());
            if state
                .instances
                .get(&key)
                .is_some_and(|found| found.0 != "Stopped")
            {
                return error(400, "Instance is running");
            }
            state.pending = Some(Box::new(move |state: &mut State| {
                state.instances.remove(&key);
            }));
            accepted
        }
        _ => error(404, "not found"),
    }
}

#[test]
fn copies_a_snapshot_and_starts_it_then_a_repeat_changes_nothing() {
    let fake = Fake::new();
    assert_eq!(instance_ensure(&copy_order(&fake, "running")), Ok(2));
    assert_eq!(
        fake.status("throwaway", "copy-1").as_deref(),
        Some("Running")
    );
    let changing = fake.changing();
    assert_eq!(changing.len(), 2, "{changing:?}");
    let copy: Value =
        serde_json::from_str(changing[0].splitn(3, ' ').nth(2).expect("a body")).expect("JSON");
    assert!(
        changing[0].starts_with("POST /1.0/instances?project=throwaway "),
        "{changing:?}"
    );
    assert_eq!(
        copy["source"],
        json!({"type": "copy", "source": "seed/base", "project": "seeds"})
    );
    assert_eq!(copy["profiles"], json!(["default"]));
    assert_eq!(copy["config"][SOURCE_KEY], "seeds/seed/base");
    assert!(
        changing[1].starts_with("PUT /1.0/instances/copy-1/state?project=throwaway "),
        "{changing:?}"
    );

    assert_eq!(instance_ensure(&copy_order(&fake, "running")), Ok(0));
    assert_eq!(fake.changing().len(), 2, "a repeat sends no change");
}

#[test]
fn copies_a_snapshot_stopped() {
    let fake = Fake::new();
    assert_eq!(instance_ensure(&copy_order(&fake, "stopped")), Ok(1));
    assert_eq!(
        fake.status("throwaway", "copy-1").as_deref(),
        Some("Stopped")
    );
    assert_eq!(instance_ensure(&copy_order(&fake, "stopped")), Ok(0));
}

#[test]
fn starts_and_stops_an_existing_instance() {
    let fake = Fake::new();
    fake.with("throwaway", "copy-1", "Stopped", None);
    assert_eq!(
        instance_ensure(&fake.order(&json!({"state": "running"}))),
        Ok(1)
    );
    assert_eq!(
        fake.status("throwaway", "copy-1").as_deref(),
        Some("Running")
    );
    assert_eq!(
        instance_ensure(&fake.order(&json!({"state": "running"}))),
        Ok(0)
    );
    assert_eq!(
        instance_ensure(&fake.order(&json!({"state": "stopped"}))),
        Ok(1)
    );
    assert_eq!(
        fake.status("throwaway", "copy-1").as_deref(),
        Some("Stopped")
    );
    assert_eq!(
        instance_ensure(&fake.order(&json!({"state": "stopped"}))),
        Ok(0)
    );
    let changing = fake.changing();
    assert!(
        changing[1].contains(r#""action":"stop""#)
            && changing[1].contains(r#""force":false"#)
            && changing[1].contains(r#""timeout":"#),
        "{changing:?}"
    );
}

#[test]
fn removes_exactly_the_named_instance() {
    let fake = Fake::new();
    fake.with("throwaway", "copy-1", "Stopped", None);
    fake.with("throwaway", "copy-2", "Stopped", None);
    fake.with("other", "copy-1", "Stopped", None);
    assert_eq!(
        instance_ensure(&fake.order(&json!({"state": "absent"}))),
        Ok(1)
    );
    assert_eq!(fake.status("throwaway", "copy-1"), None);
    assert_eq!(
        fake.status("throwaway", "copy-2").as_deref(),
        Some("Stopped")
    );
    assert_eq!(fake.status("other", "copy-1").as_deref(), Some("Stopped"));
    assert_eq!(
        fake.changing(),
        vec!["DELETE /1.0/instances/copy-1?project=throwaway null".to_owned()]
    );
    assert_eq!(
        instance_ensure(&fake.order(&json!({"state": "absent"}))),
        Ok(0)
    );
}

#[test]
fn removes_a_running_instance_only_with_force() {
    let fake = Fake::new();
    fake.with("throwaway", "copy-1", "Running", None);
    let refused = instance_ensure(&fake.order(&json!({"state": "absent"})))
        .expect_err("a running instance needs force");
    assert_eq!(refused.code, "incus.instance_running");
    assert!(fake.changing().is_empty(), "a refusal changes nothing");
    assert_eq!(
        instance_ensure(&fake.order(&json!({"state": "absent", "force": true}))),
        Ok(2)
    );
    assert_eq!(fake.status("throwaway", "copy-1"), None);
    assert!(
        fake.changing()[0].contains(r#""force":true"#),
        "{:?}",
        fake.changing()
    );
}

#[test]
fn refuses_an_existing_instance_from_another_source() {
    let fake = Fake::new();
    fake.with("throwaway", "copy-1", "Running", Some("seeds/seed/older"));
    let refused = instance_ensure(&copy_order(&fake, "running")).expect_err("another source");
    assert_eq!(refused.code, "incus.source_conflict");
    fake.with("throwaway", "copy-1", "Running", None);
    assert_eq!(
        instance_ensure(&copy_order(&fake, "running"))
            .expect_err("no recorded source")
            .code,
        "incus.source_conflict"
    );
    assert!(fake.changing().is_empty(), "a refusal changes nothing");
}

#[test]
fn refuses_a_missing_instance_or_snapshot() {
    let fake = Fake::new();
    assert_eq!(
        instance_ensure(&fake.order(&json!({"state": "running"})))
            .expect_err("no source")
            .code,
        "incus.instance_missing"
    );
    let order = fake.order(&json!({"state": "running", "source": {"project": "seeds", "instance": "seed", "snapshot": "gone"}, "profiles": ["default"]}));
    assert_eq!(
        instance_ensure(&order).expect_err("no snapshot").code,
        "incus.source_missing"
    );
    assert!(fake.changing().is_empty(), "a refusal changes nothing");
}

#[test]
fn fails_on_api_errors_and_failed_operations() {
    let fake = Fake::new();
    fake.state.lock().expect("state").refuse = Some("POST /1.0/instances".to_owned());
    assert_eq!(
        instance_ensure(&copy_order(&fake, "running"))
            .expect_err("refused")
            .code,
        "incus.api_failed"
    );
    fake.state.lock().expect("state").refuse = Some("GET /1.0/instances/copy-1".to_owned());
    assert_eq!(
        instance_ensure(&copy_order(&fake, "running"))
            .expect_err("refused read")
            .code,
        "incus.api_failed"
    );
    let mut state = fake.state.lock().expect("state");
    state.refuse = None;
    state.ops = Ops::Fail;
    drop(state);
    assert_eq!(
        instance_ensure(&copy_order(&fake, "running"))
            .expect_err("failed")
            .code,
        "incus.operation_failed"
    );
    let missing = instance_ensure(
        &json!({"socket": "/nonexistent/unix.socket", "project": "p", "name": "c", "state": "running", "timeout_seconds": 1}),
    );
    assert_eq!(missing.expect_err("no socket").code, "incus.api_failed");
}

#[test]
fn fails_an_operation_that_outlasts_the_budget() {
    let fake = Fake::new();
    fake.with("throwaway", "copy-1", "Stopped", None);
    fake.state.lock().expect("state").ops = Ops::Hang;
    let started = Instant::now();
    let failed = instance_ensure(&fake.order(&json!({"state": "running", "timeout_seconds": 1})))
        .expect_err("timeout");
    assert_eq!(failed.code, "incus.operation_timeout");
    assert!(started.elapsed().as_secs() < 5, "bounded by the budget");
    let wait = fake
        .state
        .lock()
        .expect("state")
        .requests
        .iter()
        .find(|request| request.contains("/wait?"))
        .cloned();
    assert!(
        wait.is_some_and(|wait| wait.starts_with("GET /1.0/operations/op-1/wait?timeout=1 ")),
        "the wait carries the budget"
    );
}

#[test]
fn fails_a_socket_that_never_answers_within_the_budget() {
    let dir = tempfile::tempdir().expect("a scratch folder");
    let socket = dir.path().join("unix.socket");
    let listener = UnixListener::bind(&socket).expect("a silent socket");
    let started = Instant::now();
    let order = json!({"socket": socket.display().to_string(), "project": "p", "name": "c", "state": "running", "timeout_seconds": 1});
    assert_eq!(
        instance_ensure(&order).expect_err("silent").code,
        "incus.operation_timeout"
    );
    assert!(started.elapsed().as_secs() < 5, "bounded by the budget");
    drop(listener);
}

#[test]
fn refuses_input_outside_its_shape() {
    let fake = Fake::new();
    let base = fake.order(&json!({"state": "running"}));
    let cases = [
        json!({"command": "incus delete --all"}),
        json!({"argv": ["incus", "delete"]}),
        json!({"name": "*"}),
        json!({"name": "copy-*"}),
        json!({"name": "../x"}),
        json!({"project": "a/b"}),
        json!({"project": ""}),
        json!({"state": "frozen"}),
        json!({"timeout_seconds": 0}),
        json!({"timeout_seconds": 601}),
        json!({"socket": "relative.socket"}),
        json!({"socket": "/a/../b"}),
        json!({"source": {"project": "seeds", "instance": "seed", "snapshot": "base"}}),
        json!({"source": {"project": "seeds", "instance": "seed", "snapshot": "base"}, "profiles": []}),
        json!({"source": {"project": "seeds", "instance": "seed", "snapshot": "*"}, "profiles": ["default"]}),
        json!({"source": {"project": "seeds", "instance": "seed", "snapshot": "base", "extra": 1}, "profiles": ["default"]}),
        json!({"state": "absent", "source": {"project": "seeds", "instance": "seed", "snapshot": "base"}, "profiles": ["default"]}),
        json!({"profiles": ["default"]}),
    ];
    for case in cases {
        let mut order = base.clone();
        for (key, value) in case.as_object().expect("an object") {
            order[key] = value.clone();
        }
        assert_eq!(
            instance_ensure(&order).expect_err("refused").code,
            "incus.input_invalid",
            "{case}"
        );
    }
    assert!(
        fake.state.lock().expect("state").requests.is_empty(),
        "a refused input sends nothing"
    );
}
