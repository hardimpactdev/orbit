//! The Conn Server primitives against a scripted server: each answer is what the server's CLI would answer.

use std::cell::RefCell;
use std::collections::{BTreeMap, VecDeque};
use std::os::unix::fs::PermissionsExt as _;
use std::path::{Path, PathBuf};

use base64::engine::general_purpose::STANDARD;
use base64::Engine as _;
use crypto_box::SecretKey;
use serde_json::{json, Value};

use crate::conn::trusted_binary;
use crate::{pairing_issue, project_ensure, session_issue, Exit, System};

const TOKEN: &str = "tok-SECRET-0123456789";
const CREDENTIAL: &str = "PAIRCODE-SECRET";
const HOME: &str = "/srv/conn/home";
const BINARY: &str = "/opt/conn/bin/conn-server";
const WORKSPACE: &str = "/srv/workspace";

/// A change to the scratch root that makes its binary untrusted.
type Case = (&'static str, fn(&Server));

/// One call the server saw: its argv, and the file its `/proc/self/fd` path named while it ran.
type Call = (Vec<String>, PathBuf);

/// A scripted Conn Server below a scratch root: its answers by call, every call it saw, and the projects `project add` writes.
struct Server {
    root: tempfile::TempDir,
    answers: RefCell<BTreeMap<&'static str, VecDeque<Exit>>>,
    calls: RefCell<Vec<Call>>,
    /// Whether `project add` writes the project to the list, as the real server does.
    adds: bool,
    owner: u32,
}

impl Server {
    fn new() -> Self {
        let root = tempfile::tempdir().expect("a scratch root");
        let binary = root.path().join(BINARY.trim_start_matches('/'));
        std::fs::create_dir_all(binary.parent().expect("a folder")).expect("folders");
        for folder in ["", "opt", "opt/conn", "opt/conn/bin"] {
            std::fs::set_permissions(root.path().join(folder), std::fs::Permissions::from_mode(0o755)).expect("mode");
        }
        std::fs::write(&binary, b"#!/bin/false\n").expect("binary");
        std::fs::set_permissions(&binary, std::fs::Permissions::from_mode(0o755)).expect("mode");
        std::fs::create_dir_all(root.path().join(WORKSPACE.trim_start_matches('/'))).expect("workspace");
        Self { root, answers: RefCell::default(), calls: RefCell::default(), adds: true, owner: rustix::process::getuid().as_raw() }
    }

    fn answer(&self, call: &'static str, code: i32, stdout: &str) {
        self.answers.borrow_mut().entry(call).or_default().push_back(Exit { code: Some(code), stdout: stdout.as_bytes().to_vec(), stderr: Vec::new() });
    }

    fn fails(&self, call: &'static str, code: i32, stderr: &str) {
        self.answers.borrow_mut().entry(call).or_default().push_back(Exit { code: Some(code), stdout: TOKEN.as_bytes().to_vec(), stderr: stderr.as_bytes().to_vec() });
    }

    fn called(&self, call: &str) -> usize {
        self.calls.borrow().iter().filter(|(argv, _)| name(argv) == call).count()
    }

    fn projects(&self) -> PathBuf {
        self.path(&format!("{HOME}/userdata/projects.json"))
    }

    fn binary(&self) -> PathBuf {
        self.path(BINARY)
    }
}

/// The call an argv makes: the server's first words, such as `auth session issue`.
fn name(argv: &[String]) -> String {
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

    fn binary_owner(&self) -> u32 {
        self.owner
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

    #[expect(clippy::panic_in_result_fn, reason = "a call the primitives never make fails the test")]
    fn exec(&self, _seconds: u32, argv: &[String], stdin: Option<&[u8]>) -> Result<Exit, String> {
        assert!(stdin.is_none(), "the CLI reads nothing on standard input");
        let ran = std::fs::read_link(&argv[7]).expect("the binary runs through its descriptor");
        self.calls.borrow_mut().push((argv.to_vec(), ran));
        let call = name(argv);
        if call == "project add" {
            if self.adds {
                let entry = json!([{"title": argv[12], "workspaceRoot": argv[10]}]);
                std::fs::create_dir_all(self.projects().parent().expect("a parent")).expect("userdata");
                std::fs::write(self.projects(), entry.to_string()).expect("projects.json");
            }
            return Ok(Exit { code: Some(0), stdout: b"Added project".to_vec(), stderr: Vec::new() });
        }
        self.answers.borrow_mut().get_mut(call.as_str()).and_then(VecDeque::pop_front).ok_or_else(|| format!("no answer for {call}"))
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
fn a_session_is_issued_once_through_the_checked_binary_with_its_token_sealed() {
    let server = Server::new();
    server.answer("auth session list", 0, "[]");
    server.answer("auth session issue", 0, &issued());

    let session = session_issue(&server, &session_input()).expect("issued");

    assert_eq!(session.session_id, "s-1");
    assert_eq!(session.scopes, ["access:read", "access:write"]);
    assert_eq!(open(&session.sealed), json!({"token": TOKEN}));
    assert!(!serde_json::to_string(&session).expect("JSON").contains(TOKEN), "the token travels only sealed");
    let calls = server.calls.borrow();
    let (argv, ran) = &calls[1];
    assert_eq!(argv[..7], ["/usr/bin/setpriv", "--reuid", "2750", "--regid", "2750", "--clear-groups", "--"]);
    assert!(argv[7].starts_with("/proc/self/fd/"));
    assert_eq!(ran, &server.binary(), "the descriptor is the checked file");
    assert_eq!(argv[8..], ["auth", "session", "issue", "--label", "Orbit Gateway", "--subject", "order-4f2a", "--ttl", "3650d", "--scope", "access:read",
        "--scope", "access:write", "--json", "--base-dir", HOME]);
}

#[test]
fn a_session_whose_subject_the_server_holds_is_refused_and_not_issued_again() {
    let server = Server::new();
    server.answer("auth session list", 0, &json!([{"sessionId": "s-1", "subject": "order-4f2a"}]).to_string());

    let failure = session_issue(&server, &session_input()).expect_err("a replay");

    assert_eq!(failure.code, "conn.session_issued");
    assert_eq!(server.called("auth session issue"), 0);
}

fn with(mut input: Value, field: &str, value: Value) -> Value {
    input[field] = value;
    input
}

#[test]
fn a_session_input_that_is_not_typed_is_refused_before_anything_runs() {
    let mut relative = session_input();
    relative["server"]["binary"] = json!("conn-server");
    let mut dotted = session_input();
    dotted["server"]["home"] = json!("/srv/../etc");
    let cases = [
        ("unknown field", with(session_input(), "argv", json!(["rm"]))),
        ("label as an option", with(session_input(), "label", json!("--json"))),
        ("subject as an option", with(session_input(), "subject", json!("-x"))),
        ("unknown unit", with(session_input(), "ttl", json!("1y"))),
        ("fraction", with(session_input(), "ttl", json!("1.5h"))),
        ("leading zero", with(session_input(), "ttl", json!("01h"))),
        ("zero", with(session_input(), "ttl", json!("0m"))),
        ("past the server's cap", with(session_input(), "ttl", json!("3661d"))),
        ("past the cap in hours", with(session_input(), "ttl", json!("87865h"))),
        ("bad scope", with(session_input(), "scopes", json!(["--scope"]))),
        ("no recipient", with(session_input(), "seal_to", json!("short"))),
        ("a recipient that is not a key", with(session_input(), "seal_to", json!(STANDARD.encode([1_u8; 31])))),
        ("relative binary", relative),
        ("home with ..", dotted),
    ];
    for (case, input) in cases {
        let server = Server::new();
        assert_eq!(session_issue(&server, &input).map_err(|failure| failure.code).err(), Some("host.input_invalid"), "{case}");
        assert!(server.calls.borrow().is_empty(), "{case}: nothing ran, so nothing was issued");
    }
}

#[test]
fn a_session_lifetime_up_to_the_servers_cap_is_kept_as_sent() {
    for ttl in ["3660d", "87840h", "until-revoked", "1s", "5m", "2w"] {
        let server = Server::new();
        server.answer("auth session list", 0, "[]");
        server.answer("auth session issue", 0, &issued());
        session_issue(&server, &with(session_input(), "ttl", json!(ttl))).expect(ttl);
    }
}

#[test]
fn a_server_that_refuses_times_out_or_answers_no_token_fails_and_logs_only_the_call_and_its_status() {
    for (exit, expected) in [(1, "auth session issue exited Some(1)"), (124, "auth session issue timed out")] {
        let server = Server::new();
        server.answer("auth session list", 0, "[]");
        server.fails("auth session issue", exit, &format!("bad token {TOKEN}"));
        let failure = session_issue(&server, &session_input()).expect_err("refused");
        assert_eq!((failure.code, failure.detail.as_str()), ("conn.session_failed", expected));
    }

    let server = Server::new();
    server.answer("auth session list", 0, "[]");
    server.answer("auth session issue", 0, &json!({"sessionId": "s-1", "token": ""}).to_string());
    assert_eq!(session_issue(&server, &session_input()).map_err(|failure| failure.detail).err().as_deref(), Some("auth session issue answered no session"));

    let server = Server::new();
    server.answer("auth session list", 0, &format!("not json {TOKEN}"));
    assert_eq!(session_issue(&server, &session_input()).map_err(|failure| failure.detail).err().as_deref(), Some("the server answered no JSON"));
}

#[test]
fn a_binary_that_is_not_root_owned_conn_server_below_safe_folders_is_untrusted_and_never_runs() {
    let cases: [Case; 6] = [
        ("a link", |server| {
            let real = server.binary().with_file_name("real");
            std::fs::rename(server.binary(), &real).expect("move");
            std::os::unix::fs::symlink(&real, server.binary()).expect("link");
        }),
        ("group-writable", |server| std::fs::set_permissions(server.binary(), std::fs::Permissions::from_mode(0o775)).expect("mode")),
        ("other-writable", |server| std::fs::set_permissions(server.binary(), std::fs::Permissions::from_mode(0o757)).expect("mode")),
        ("a writable folder", |server| {
            std::fs::set_permissions(server.path("/opt/conn"), std::fs::Permissions::from_mode(0o777)).expect("mode");
        }),
        ("a linked folder", |server| {
            std::fs::rename(server.path("/opt/conn"), server.path("/opt/real")).expect("move");
            std::os::unix::fs::symlink(server.path("/opt/real"), server.path("/opt/conn")).expect("link");
        }),
        ("a folder", |server| {
            std::fs::remove_file(server.binary()).expect("remove");
            std::fs::create_dir(server.binary()).expect("folder");
            std::fs::set_permissions(server.binary(), std::fs::Permissions::from_mode(0o755)).expect("mode");
        }),
    ];
    for (case, change) in cases {
        let server = Server::new();
        change(&server);
        assert_eq!(session_issue(&server, &session_input()).map_err(|failure| failure.code).err(), Some("conn.binary_untrusted"), "{case}");
        assert!(server.calls.borrow().is_empty(), "{case}");
    }
    let mut stranger = Server::new();
    stranger.owner += 1;
    assert_eq!(project_ensure(&stranger, &project_input()).map_err(|failure| failure.code), Err("conn.binary_untrusted"), "another owner");

    let server = Server::new();
    std::fs::rename(server.binary(), server.binary().with_file_name("t3")).expect("rename");
    assert!(trusted_binary(server.root(), "/opt/conn/bin/t3", server.owner).is_err(), "another name");
    assert!(trusted_binary(server.root(), "/opt/conn/bin", server.owner).is_err(), "no file name");
}

fn project_input() -> Value {
    json!({"server": server_input(), "workspace": WORKSPACE, "title": "Orbit"})
}

#[test]
fn a_project_is_added_once_and_every_accepted_input_repeats_with_no_change() {
    for title in ["Orbit", "a  b", "Örbit ✓"] {
        let server = Server::new();
        let input = with(project_input(), "title", json!(title));
        assert_eq!(project_ensure(&server, &input).map_err(|failure| failure.code), Ok(1), "{title}");
        assert_eq!(project_ensure(&server, &input).map_err(|failure| failure.code), Ok(0), "{title}");
        assert_eq!(server.called("project add"), 1);
        let calls = server.calls.borrow();
        assert_eq!(calls[0].0[8..], ["project", "add", WORKSPACE, "--title", title, "--base-dir", HOME]);
    }
}

#[test]
fn a_new_title_for_a_listed_folder_is_one_change() {
    let server = Server::new();
    assert_eq!(project_ensure(&server, &project_input()).map_err(|failure| failure.code), Ok(1));
    assert_eq!(project_ensure(&server, &with(project_input(), "title", json!("Renamed"))).map_err(|failure| failure.code), Ok(1));
}

#[test]
fn a_project_the_list_lacks_after_the_add_fails() {
    let mut server = Server::new();
    server.adds = false;

    assert_eq!(project_ensure(&server, &project_input()).map_err(|failure| failure.code), Err("conn.project_failed"));
}

#[test]
fn a_project_the_server_would_store_differently_is_refused_before_any_change() {
    let server = Server::new();
    std::fs::create_dir_all(server.path("/srv/real")).expect("folder");
    std::os::unix::fs::symlink(server.path("/srv/real"), server.path("/srv/linked")).expect("link");
    for (case, input) in [
        ("relative", with(project_input(), "workspace", json!("relative"))),
        ("missing", with(project_input(), "workspace", json!("/srv/missing"))),
        ("linked", with(project_input(), "workspace", json!("/srv/linked"))),
        ("below a link", with(project_input(), "workspace", json!("/srv/linked/x"))),
        ("padded", with(project_input(), "title", json!(" Orbit "))),
        ("trailing space", with(project_input(), "title", json!("Orbit "))),
        ("spaces only", with(project_input(), "title", json!("   "))),
        ("no-break padding", with(project_input(), "title", json!("\u{a0}Orbit"))),
        ("byte order mark", with(project_input(), "title", json!("\u{feff}Orbit"))),
        ("option", with(project_input(), "title", json!("--json"))),
        ("unknown field", with(project_input(), "id", json!(1))),
    ] {
        assert_eq!(project_ensure(&server, &input).map_err(|failure| failure.code), Err("host.input_invalid"), "{case}");
    }
    assert!(server.calls.borrow().is_empty());
    assert!(!server.projects().exists());
}

fn pairing_input() -> Value {
    json!({"server": server_input(), "label": "Pair order-4f2a", "scopes": ["access:read", "access:write"], "ttl": "5m", "session_ttl": "30d",
        "seal_to": recipient()})
}

fn link() -> String {
    json!({"id": "p-1", "credential": CREDENTIAL, "expiresAt": "2026-11-28T10:20:00Z", "label": "Pair order-4f2a", "scopes": ["access:read"]}).to_string()
}

#[test]
fn a_pairing_link_is_created_by_the_cli_alone_with_its_code_sealed() {
    let server = Server::new();
    server.answer("auth pairing list", 0, "[]");
    server.answer("auth pairing create", 0, &link());

    let pairing = pairing_issue(&server, &pairing_input()).expect("issued");

    assert_eq!((pairing.pairing_id.as_str(), pairing.expires_at.as_str()), ("p-1", "2026-11-28T10:20:00Z"));
    assert_eq!(open(&pairing.sealed), json!({"credential": CREDENTIAL}));
    assert!(!serde_json::to_string(&pairing).expect("JSON").contains(CREDENTIAL));
    let calls = server.calls.borrow();
    let names: Vec<String> = calls.iter().map(|(argv, _)| name(argv)).collect();
    assert_eq!(names, ["auth pairing list", "auth pairing create"], "no session is made");
    assert_eq!(calls[1].0[8..], ["auth", "pairing", "create", "--label", "Pair order-4f2a", "--scopes", "access:read,access:write", "--ttl", "5m", "--json",
        "--base-dir", HOME]);
}

#[test]
fn a_pairing_link_whose_label_the_server_holds_is_refused_and_not_created_again() {
    let server = Server::new();
    server.answer("auth pairing list", 0, &json!([{"id": "p-1", "label": "Pair order-4f2a"}]).to_string());

    assert_eq!(pairing_issue(&server, &pairing_input()).map_err(|failure| failure.code).err(), Some("conn.pairing_issued"));
    assert_eq!(server.called("auth pairing create"), 0);
}

#[test]
fn a_refused_or_timed_out_pairing_fails_and_logs_only_the_call_and_its_status() {
    for (exit, expected) in [(1, "auth pairing create exited Some(1)"), (124, "auth pairing create timed out")] {
        let server = Server::new();
        server.answer("auth pairing list", 0, "[]");
        server.fails("auth pairing create", exit, &format!("code {CREDENTIAL}"));
        let failure = pairing_issue(&server, &pairing_input()).expect_err("not created");
        assert_eq!((failure.code, failure.detail.as_str()), ("conn.pairing_failed", expected));
    }
    let server = Server::new();
    server.answer("auth pairing list", 0, "[]");
    server.answer("auth pairing create", 0, &json!({"id": "p-1", "expiresAt": "x"}).to_string());
    assert_eq!(pairing_issue(&server, &pairing_input()).map_err(|failure| failure.detail).err().as_deref(), Some("auth pairing create answered no link"));
}

#[test]
fn a_pairing_input_the_server_would_not_keep_as_sent_is_refused_before_anything_runs() {
    let server = Server::new();
    for (field, value) in [
        ("session_ttl", json!("until-revoked")),
        ("session_ttl", json!("3650d")),
        ("ttl", json!("4000d")),
        ("ttl", json!("1y")),
        ("ttl", json!("0m")),
        ("label", json!("-x")),
        ("scopes", json!(["access:read,access:write"])),
        ("seal_to", json!("short")),
        ("address", json!("127.0.0.1:3773")),
    ] {
        assert_eq!(pairing_issue(&server, &with(pairing_input(), field, value)).map_err(|failure| failure.code).err(), Some("host.input_invalid"), "{field}");
    }
    assert!(server.calls.borrow().is_empty(), "nothing was issued");
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
    assert_eq!(Node.binary_owner(), 0, "a Node trusts only root's binary");
}
