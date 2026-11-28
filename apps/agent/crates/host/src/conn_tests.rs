//! The Conn Server primitives against a scripted server: each answer is what the server's CLI or local API would answer.

use std::cell::RefCell;
use std::collections::{BTreeMap, VecDeque};
use std::path::Path;

use base64::engine::general_purpose::STANDARD;
use base64::Engine as _;
use crypto_box::SecretKey;
use serde_json::{json, Value};

use crate::{pairing_issue, project_ensure, session_issue, Exit, System};

const TOKEN: &str = "tok-SECRET-0123456789";
const CREDENTIAL: &str = "PAIRCODE-SECRET";
const HOME: &str = "/srv/conn/home";
const BINARY: &str = "/opt/conn/bin/conn-server";

/// One call the server saw: its argv and its standard input.
type Call = (Vec<String>, Option<Vec<u8>>);

/// A scripted Conn Server: its answers by call, every call it saw, and the projects `project add` writes.
struct Server {
    root: tempfile::TempDir,
    answers: RefCell<BTreeMap<&'static str, VecDeque<Result<Exit, String>>>>,
    calls: RefCell<Vec<Call>>,
    /// Whether `project add` writes the project to the list, as the real server does.
    adds: bool,
}

impl Server {
    fn new() -> Self {
        Self { root: tempfile::tempdir().expect("a scratch root"), answers: RefCell::default(), calls: RefCell::default(), adds: true }
    }

    fn answer(&self, call: &'static str, code: i32, stdout: &str) {
        self.answers.borrow_mut().entry(call).or_default().push_back(Ok(Exit { code: Some(code), stdout: stdout.as_bytes().to_vec(), stderr: Vec::new() }));
    }

    fn fails(&self, call: &'static str, code: i32, stderr: &str) {
        self.answers.borrow_mut().entry(call).or_default().push_back(Ok(Exit { code: Some(code), stdout: Vec::new(), stderr: stderr.as_bytes().to_vec() }));
    }

    fn called(&self, call: &str) -> usize {
        self.calls.borrow().iter().filter(|(argv, _)| name(argv) == call).count()
    }

    fn projects(&self) -> std::path::PathBuf {
        self.path(&format!("{HOME}/userdata/projects.json"))
    }
}

/// The call an argv makes: `curl`, or the server's first words such as `auth session issue`.
fn name(argv: &[String]) -> String {
    if argv.first().map(String::as_str) == Some("/usr/bin/curl") {
        return "curl".to_owned();
    }
    let words = if argv.get(8).map(String::as_str) == Some("project") { 2 } else { 3 };
    argv.iter().skip(8).take(words).cloned().collect::<Vec<_>>().join(" ")
}

impl System for Server {
    fn root(&self) -> &Path {
        self.root.path()
    }

    fn run(&self, _seconds: u32, argv: &[String]) -> Result<String, String> {
        Err(format!("unexpected {argv:?}"))
    }

    fn status(&self, _seconds: u32, argv: &[String]) -> Result<(Option<i32>, Vec<u8>), String> {
        Err(format!("unexpected {argv:?}"))
    }

    fn user(&self, name: &str) -> Result<u32, String> {
        (name == "agent").then_some(2750).ok_or_else(|| format!("no user {name}"))
    }

    fn group(&self, name: &str) -> Result<u32, String> {
        (name == "agent").then_some(2750).ok_or_else(|| format!("no group {name}"))
    }

    fn blob(&self, sha256: &str) -> Result<Vec<u8>, String> {
        Err(format!("no blob {sha256}"))
    }

    fn exec(&self, _seconds: u32, argv: &[String], stdin: Option<&[u8]>) -> Result<Exit, String> {
        self.calls.borrow_mut().push((argv.to_vec(), stdin.map(<[u8]>::to_vec)));
        let call = name(argv);
        if call == "project add" {
            if self.adds {
                let entry = json!([{"title": argv[12], "workspaceRoot": argv[10]}]);
                std::fs::create_dir_all(self.projects().parent().expect("a parent")).expect("userdata");
                std::fs::write(self.projects(), entry.to_string()).expect("projects.json");
            }
            return Ok(Exit { code: Some(0), stdout: b"Added project".to_vec(), stderr: Vec::new() });
        }
        self.answers.borrow_mut().get_mut(call.as_str()).and_then(VecDeque::pop_front).unwrap_or_else(|| Err(format!("no answer for {call}")))
    }
}

fn secret() -> SecretKey {
    SecretKey::from([9; 32])
}

fn recipient() -> String {
    STANDARD.encode(secret().public_key().as_bytes())
}

fn open(sealed: &str) -> Value {
    serde_json::from_slice(&secret().unseal(&STANDARD.decode(sealed).expect("base64")).expect("opens")).expect("JSON")
}

fn server_input() -> Value {
    json!({"binary": BINARY, "home": HOME, "user": "agent", "group": "agent"})
}

fn session_input() -> Value {
    json!({"server": server_input(), "label": "Orbit Gateway", "subject": "order-4f2a", "scopes": ["access:read", "access:write"], "ttl": "3650d",
        "seal_to": recipient()})
}

fn issued() -> String {
    json!({"sessionId": "s-1", "token": TOKEN, "method": "bearer-access-token", "scopes": ["access:read", "access:write"]}).to_string()
}

#[test]
fn a_session_is_issued_once_with_its_token_sealed() {
    let server = Server::new();
    server.answer("auth session list", 0, "[]");
    server.answer("auth session issue", 0, &issued());

    let session = session_issue(&server, &session_input()).expect("issued");

    assert_eq!(session.session_id, "s-1");
    assert_eq!(session.scopes, ["access:read", "access:write"]);
    assert_eq!(open(&session.sealed), json!({"token": TOKEN}));
    assert!(!serde_json::to_string(&session).expect("JSON").contains(TOKEN), "the token travels only sealed");
    let calls = server.calls.borrow();
    let (argv, stdin) = &calls[1];
    let expected = ["/usr/bin/setpriv", "--reuid", "2750", "--regid", "2750", "--clear-groups", "--", BINARY, "auth", "session", "issue", "--label",
        "Orbit Gateway", "--subject", "order-4f2a", "--ttl", "3650d", "--scope", "access:read", "--scope", "access:write", "--json", "--base-dir", HOME];
    assert_eq!(argv, &expected);
    assert!(stdin.is_none());
}

#[test]
fn a_session_whose_subject_the_server_holds_is_refused_and_not_issued_again() {
    let server = Server::new();
    server.answer("auth session list", 0, &json!([{"sessionId": "s-1", "subject": "order-4f2a"}]).to_string());

    let failure = session_issue(&server, &session_input()).expect_err("a replay");

    assert_eq!(failure.code, "conn.session_issued");
    assert_eq!(server.called("auth session issue"), 0);
}

#[test]
fn a_session_input_that_is_not_typed_is_refused_before_anything_runs() {
    let cases = [
        ("unknown field", {
            let mut input = session_input();
            input["argv"] = json!(["rm"]);
            input
        }),
        ("label as an option", {
            let mut input = session_input();
            input["label"] = json!("--json");
            input
        }),
        ("subject as an option", {
            let mut input = session_input();
            input["subject"] = json!("-x");
            input
        }),
        ("bad ttl", {
            let mut input = session_input();
            input["ttl"] = json!("forever");
            input
        }),
        ("bad scope", {
            let mut input = session_input();
            input["scopes"] = json!(["--scope"]);
            input
        }),
        ("relative binary", {
            let mut input = session_input();
            input["server"]["binary"] = json!("conn-server");
            input
        }),
        ("home with ..", {
            let mut input = session_input();
            input["server"]["home"] = json!("/srv/../etc");
            input
        }),
    ];
    for (case, input) in cases {
        let server = Server::new();
        assert_eq!(session_issue(&server, &input).map_err(|failure| failure.code).err(), Some("host.input_invalid"), "{case}");
        assert!(server.calls.borrow().is_empty(), "{case}: nothing ran");
    }
}

#[test]
fn a_server_that_refuses_or_answers_no_token_fails_without_the_answer_in_the_detail() {
    let server = Server::new();
    server.answer("auth session list", 0, "[]");
    server.fails("auth session issue", 1, "auth.lock is held");
    let failure = session_issue(&server, &session_input()).expect_err("refused");
    assert_eq!(failure.code, "conn.session_failed");
    assert!(failure.detail.contains("auth session issue exited Some(1): auth.lock is held"), "{}", failure.detail);

    let server = Server::new();
    server.answer("auth session list", 0, "[]");
    server.answer("auth session issue", 0, &json!({"sessionId": "s-1", "token": ""}).to_string());
    assert_eq!(session_issue(&server, &session_input()).map_err(|failure| failure.code).err(), Some("conn.session_failed"));

    // A command that the time limit ended exits 124.
    let server = Server::new();
    server.fails("auth session list", 124, "");
    assert_eq!(session_issue(&server, &session_input()).map_err(|failure| failure.code).err(), Some("conn.session_failed"));
}

#[test]
fn a_session_the_gateway_cannot_open_is_not_answered() {
    let server = Server::new();
    server.answer("auth session list", 0, "[]");
    server.answer("auth session issue", 0, &issued());
    let mut input = session_input();
    input["seal_to"] = json!("short");

    let failure = session_issue(&server, &input).expect_err("no recipient");

    assert_eq!(failure.code, "conn.session_failed");
    assert!(!failure.detail.contains(TOKEN));
}

fn project_input() -> Value {
    json!({"server": server_input(), "workspace": "/srv/workspace", "title": "Orbit"})
}

#[test]
fn a_project_is_added_once_and_a_repeat_changes_nothing() {
    let server = Server::new();

    assert_eq!(project_ensure(&server, &project_input()).map_err(|failure| failure.code), Ok(1));
    assert_eq!(project_ensure(&server, &project_input()).map_err(|failure| failure.code), Ok(0));

    assert_eq!(server.called("project add"), 1);
    let calls = server.calls.borrow();
    assert_eq!(&calls[0].0[7..], [BINARY, "project", "add", "/srv/workspace", "--title", "Orbit", "--base-dir", HOME]);
}

#[test]
fn a_project_the_list_lacks_after_the_add_fails() {
    let mut server = Server::new();
    server.adds = false;

    assert_eq!(project_ensure(&server, &project_input()).map_err(|failure| failure.code), Err("conn.project_failed"));
}

#[test]
fn a_project_input_that_is_not_typed_is_refused() {
    let server = Server::new();
    for input in [
        json!({"server": server_input(), "workspace": "relative", "title": "Orbit"}),
        json!({"server": server_input(), "workspace": "/srv/workspace", "title": "--json"}),
        json!({"server": server_input(), "workspace": "/srv/workspace", "title": "Orbit", "id": 1}),
    ] {
        assert_eq!(project_ensure(&server, &input).map_err(|failure| failure.code), Err("host.input_invalid"));
    }
    assert!(server.calls.borrow().is_empty());
}

fn pairing_input() -> Value {
    json!({"server": server_input(), "address": "127.0.0.1:3773", "label": "Pair order-4f2a", "scopes": ["access:read", "access:write"],
        "session_ttl": "until-revoked", "seal_to": recipient()})
}

fn link() -> String {
    format!("{}\n200", json!({"id": "p-1", "credential": CREDENTIAL, "expiresAt": "2026-11-28T10:20:00Z"}))
}

#[test]
fn a_pairing_link_is_minted_with_a_short_session_that_is_revoked_and_its_code_sealed() {
    let server = Server::new();
    server.answer("auth pairing list", 0, "[]");
    server.answer("auth session issue", 0, &issued());
    server.answer("curl", 0, &link());
    server.answer("auth session revoke", 0, "");

    let pairing = pairing_issue(&server, &pairing_input()).expect("issued");

    assert_eq!((pairing.pairing_id.as_str(), pairing.expires_at.as_str()), ("p-1", "2026-11-28T10:20:00Z"));
    assert_eq!(open(&pairing.sealed), json!({"credential": CREDENTIAL}));
    assert!(!serde_json::to_string(&pairing).expect("JSON").contains(CREDENTIAL));
    let calls = server.calls.borrow();
    let names: Vec<String> = calls.iter().map(|(argv, _)| name(argv)).collect();
    assert_eq!(names, ["auth pairing list", "auth session issue", "curl", "auth session revoke"]);
    assert!(calls[1].0.windows(2).any(|pair| pair == ["--ttl", "5m"]));
    let (curl, stdin) = &calls[2];
    assert!(curl.iter().all(|arg| !arg.contains(TOKEN)), "the bearer is never an argument");
    assert_eq!(stdin.as_deref(), Some(format!("Authorization: Bearer {TOKEN}\n").as_bytes()));
    assert_eq!(curl.last().map(String::as_str), Some("http://127.0.0.1:3773/api/auth/pairing-token"));
    let body = &curl[curl.iter().position(|arg| arg == "--data-binary").expect("a body") + 1];
    assert_eq!(serde_json::from_str::<Value>(body).expect("JSON"), json!({"label": "Pair order-4f2a", "scopes": ["access:read", "access:write"],
        "sessionTtl": "until-revoked"}));
    assert_eq!(calls[3].0[8..], ["auth", "session", "revoke", "s-1", "--base-dir", HOME]);
}

#[test]
fn a_pairing_link_whose_label_the_server_holds_is_refused_and_not_minted_again() {
    let server = Server::new();
    server.answer("auth pairing list", 0, &json!([{"id": "p-1", "label": "Pair order-4f2a"}]).to_string());

    assert_eq!(pairing_issue(&server, &pairing_input()).map_err(|failure| failure.code).err(), Some("conn.pairing_issued"));
    assert_eq!(server.calls.borrow().len(), 1);
}

#[test]
fn a_refused_or_timed_out_mint_fails_revokes_its_session_and_logs_no_secret() {
    for (curl_code, stdout, expected) in [
        (0, format!("{}\n403", json!({"code": "forbidden", "credential": CREDENTIAL})), "status 403"),
        (28, String::new(), "curl exited Some(28)"),
        (7, String::new(), "curl exited Some(7)"),
    ] {
        let server = Server::new();
        server.answer("auth pairing list", 0, "[]");
        server.answer("auth session issue", 0, &issued());
        server.answer("curl", curl_code, &stdout);
        server.answer("auth session revoke", 0, "");

        let failure = pairing_issue(&server, &pairing_input()).expect_err("not minted");

        assert_eq!(failure.code, "conn.pairing_failed");
        assert!(failure.detail.contains(expected), "{}", failure.detail);
        assert!(!failure.detail.contains(TOKEN) && !failure.detail.contains(CREDENTIAL), "{}", failure.detail);
        assert_eq!(server.called("auth session revoke"), 1, "the minting session is revoked");
    }
}

#[test]
fn a_pairing_link_whose_session_is_not_revoked_fails() {
    let server = Server::new();
    server.answer("auth pairing list", 0, "[]");
    server.answer("auth session issue", 0, &issued());
    server.answer("curl", 0, &link());
    server.fails("auth session revoke", 1, "no such session");

    let failure = pairing_issue(&server, &pairing_input()).expect_err("unrevoked");

    assert_eq!(failure.code, "conn.pairing_failed");
    assert!(!failure.detail.contains(CREDENTIAL));
}

#[test]
fn a_pairing_address_off_loopback_or_an_untyped_input_is_refused() {
    let server = Server::new();
    for (field, value) in [("address", json!("10.0.0.5:3773")), ("address", json!("localhost:3773")), ("address", json!("127.0.0.1:0")),
        ("label", json!("-x")), ("session_ttl", json!("0d")), ("extra", json!(true))] {
        let mut input = pairing_input();
        input[field] = value;
        assert_eq!(pairing_issue(&server, &input).map_err(|failure| failure.code).err(), Some("host.input_invalid"), "{field}");
    }
    assert!(server.calls.borrow().is_empty());
}

#[test]
fn exec_runs_with_an_empty_environment_and_feeds_standard_input() {
    struct Node;
    impl System for Node {
        fn root(&self) -> &Path {
            Path::new("/")
        }
        fn run(&self, _: u32, _: &[String]) -> Result<String, String> {
            unreachable!()
        }
        fn status(&self, _: u32, _: &[String]) -> Result<(Option<i32>, Vec<u8>), String> {
            unreachable!()
        }
        fn blob(&self, _: &str) -> Result<Vec<u8>, String> {
            unreachable!()
        }
    }
    std::env::set_var("CONN_TEST_LEAK", "1");
    let env = Node.exec(5, &["/usr/bin/env".to_owned()], None).expect("runs");
    assert_eq!(env.code, Some(0));
    assert!(env.stdout.is_empty(), "no variable reaches the command: {}", String::from_utf8_lossy(&env.stdout));
    let cat = Node.exec(5, &["/usr/bin/cat".to_owned()], Some(b"header\n")).expect("runs");
    assert_eq!(cat.stdout, b"header\n");
    let slow = Node.exec(1, &["/usr/bin/sleep".to_owned(), "5".to_owned()], None).expect("runs");
    assert_eq!(slow.code, Some(124), "the time limit ends it");
}
