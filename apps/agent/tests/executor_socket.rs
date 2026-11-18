#![cfg(target_os = "linux")]
//! End to end over a real socket: the caller check, the relay binary, and the order checks behind them.

use std::fs;
use std::io::{Read as _, Write as _};
use std::os::unix::net::UnixStream;
use std::path::{Path, PathBuf};
use std::process::{Command, Stdio};
use std::sync::Arc;
use std::thread;

use base64::engine::general_purpose::STANDARD;
use base64::Engine as _;
use ed25519_dalek::{Signer as _, SigningKey};
use orbit_executor_core::action::Enabled;
use orbit_executor_core::caller::Policy;
use orbit_executor_core::executor::{Executor, Run};
use orbit_executor_core::journal::Outcome;
use orbit_executor_core::order::{PinnedKeys, VerifiedOrder};
use orbit_executor_core::server::{self, Settings};

#[derive(Debug)]
struct Noop;

impl Run for Noop {
    fn run(&self, _order: &VerifiedOrder) -> Outcome {
        Outcome::Done
    }
}

fn key() -> SigningKey {
    SigningKey::from_bytes(&[3; 32])
}

fn order(nonce: &str) -> String {
    let payload = serde_json::json!({
        "action": "proof.noop",
        "node": "7",
        "target": "7",
        "operation": "op-socket",
        "nonce": nonce,
        "expires_ms": server::now_ms() + 5_000,
    });
    let bytes = serde_json::to_vec(&payload).expect("json");
    format!(
        "{}.{}",
        STANDARD.encode(&bytes),
        STANDARD.encode(key().sign(&bytes).to_bytes())
    )
}

fn own_uid() -> u32 {
    rustix::process::getuid().as_raw()
}

/// Starts an executor on a socket in `dir` with `policy` and returns the socket path.
fn start(dir: &Path, policy: Policy) -> PathBuf {
    let socket = dir.join("executor.sock");
    let listener = server::bind(&socket, None).expect("bind");
    let settings = Arc::new(Settings {
        policy,
        keys: PinnedKeys::parse(&STANDARD.encode(key().verifying_key().as_bytes())).expect("key"),
        enabled: Enabled::parse("proof").expect("enabled"),
        node: "7".parse().expect("node"),
    });
    let (executor, _) =
        Executor::start(&dir.join("journal"), Noop, server::now_ms, |_| {}).expect("start");
    let executor = Arc::new(executor);
    thread::spawn(move || server::serve(&listener, &settings, &executor, |_| {}));
    socket
}

/// The relay is this test process: the core's relay entry point runs here, so the executable the executor checks is the test
/// binary. The shipped binary has only `orbit-agent execute relay`, with the fixed socket.
fn relay_policy() -> Policy {
    policy_with_relay(std::env::current_exe().expect("own executable"))
}

/// A policy whose relay executable is another program than this test process.
fn other_program_policy() -> Policy {
    policy_with_relay(PathBuf::from(env!("CARGO_BIN_EXE_orbit-agent")))
}

fn policy_with_relay(relay_exe: PathBuf) -> Policy {
    Policy {
        observer_uid: u32::MAX,
        observer_cgroup: "0::/system.slice/orbit-agent.service".to_owned(),
        relay_uid: Some(own_uid()),
        relay_exe,
    }
}

fn relay(socket: &Path, order: &str) -> Vec<serde_json::Value> {
    let mut reply = Vec::new();
    orbit_executor_core::relay::relay(socket, order.as_bytes(), &mut reply)
        .expect("relay hands the order over");
    String::from_utf8(reply)
        .expect("utf-8")
        .lines()
        .map(|line| serde_json::from_str(line).expect("json line"))
        .collect()
}

#[test]
fn the_relay_hands_over_an_order_and_a_replay_refuses() {
    let dir = tempfile::tempdir().expect("tempdir");
    let socket = start(dir.path(), relay_policy());
    let mode = fs::metadata(&socket)
        .map(|meta| std::os::unix::fs::PermissionsExt::mode(&meta.permissions()) & 0o777);
    assert_eq!(mode.ok(), Some(0o660));

    let wire = order("000000000000000000000000000000a1");
    let lines = relay(&socket, &wire);
    assert_eq!(lines[0]["status"], "accepted");
    assert_eq!(lines[1]["status"], "done");
    assert_eq!(lines[1]["repeat"], false);

    let replay = relay(&socket, &wire);
    assert_eq!(replay[1]["status"], "refused");
    assert_eq!(replay[1]["reason"], "replayed_nonce");
    assert_eq!(replay[1]["first"]["status"], "done");

    let repeat = relay(&socket, &order("000000000000000000000000000000a2"));
    assert_eq!(repeat[1]["repeat"], true);

    let tampered = relay(&socket, &wire.replacen('e', "f", 1));
    assert_eq!(tampered[1]["status"], "refused");
}

#[test]
fn the_relay_user_running_another_program_gets_nothing() {
    let dir = tempfile::tempdir().expect("tempdir");
    let socket = start(dir.path(), other_program_policy());
    let mut stream = UnixStream::connect(&socket).expect("connect");
    let _ = stream.write_all(order("000000000000000000000000000000b1").as_bytes());
    let _ = stream.shutdown(std::net::Shutdown::Write);
    let mut reply = String::new();
    let _ = stream.read_to_string(&mut reply);
    assert_eq!(reply, "", "a refused caller gets no reply at all");
}

#[test]
fn the_observer_unit_is_admitted_by_its_exact_cgroup() {
    let dir = tempfile::tempdir().expect("tempdir");
    let cgroup = fs::read_to_string("/proc/self/cgroup")
        .expect("cgroup")
        .trim()
        .to_owned();
    let socket = start(
        dir.path(),
        Policy {
            observer_uid: own_uid(),
            observer_cgroup: cgroup,
            relay_uid: None,
            relay_exe: PathBuf::from("/nonexistent"),
        },
    );
    let mut stream = UnixStream::connect(&socket).expect("connect");
    stream
        .write_all(order("000000000000000000000000000000c1").as_bytes())
        .expect("write");
    stream
        .shutdown(std::net::Shutdown::Write)
        .expect("shutdown");
    let mut reply = String::new();
    stream.read_to_string(&mut reply).expect("read");
    assert!(
        reply.contains("\"accepted\"") && reply.contains("\"done\""),
        "observer reply: {reply}"
    );
}

#[test]
fn an_order_of_exactly_four_kilobytes_passes_the_size_check() {
    let dir = tempfile::tempdir().expect("tempdir");
    let socket = start(dir.path(), relay_policy());
    let wire = "a".repeat(4096);
    let lines = relay(&socket, &wire);
    assert_eq!(
        lines[1]["reason"], "order_malformed",
        "4096 bytes reach the form check, not the size check"
    );
    let mut reply = Vec::new();
    let over = orbit_executor_core::relay::relay(&socket, "a".repeat(4097).as_bytes(), &mut reply);
    assert!(
        matches!(
            over,
            Err(orbit_executor_core::relay::RelayError::OrderTooLarge)
        ) && reply.is_empty(),
        "the relay itself refuses an order over 4 KB"
    );
}

#[test]
fn the_shipped_binary_takes_no_socket_path() {
    let dir = tempfile::tempdir().expect("tempdir");
    let socket = start(dir.path(), relay_policy());
    let output = Command::new(env!("CARGO_BIN_EXE_orbit-agent"))
        .args(["execute", "relay", "--socket"])
        .arg(&socket)
        .stdin(Stdio::null())
        .output()
        .expect("binary runs");
    assert!(
        !output.status.success() && output.stdout.is_empty(),
        "only `relay`, with the fixed socket, is accepted"
    );
}
