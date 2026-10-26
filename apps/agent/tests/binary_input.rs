//! The frozen mismatch reply must not wait for a binary body, including on a new command name.
use std::{
    io::{Read, Write},
    process::{Command, Stdio},
    sync::mpsc,
    time::Duration,
};

#[test]
fn mismatch_returns_before_the_sender_closes_or_sends_binary_input() {
    let mut child = Command::new(env!("CARGO_BIN_EXE_orbit-agent"))
        .args(["command", "future.binary-command"])
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::null())
        .spawn()
        .unwrap();
    let mut stdin = child.stdin.take().unwrap();
    let mut stdout = child.stdout.take().unwrap();
    let (sender, receiver) = mpsc::channel();
    std::thread::spawn(move || {
        let mut output = String::new();
        stdout.read_to_string(&mut output).unwrap();
        let _ = sender.send(output);
    });
    writeln!(stdin, "{}", serde_json::json!({"agent_sha256": "0".repeat(64), "stream_bytes": 2147483648u64, "new_field": true})).unwrap();
    let result = receiver.recv_timeout(Duration::from_secs(5));
    if result.is_err() {
        let _ = child.kill();
    }
    drop(stdin);
    let status = child.wait().unwrap();
    let value: serde_json::Value =
        serde_json::from_str(&result.expect("mismatch waited for the body")).unwrap();
    assert!(status.success());
    assert_eq!(value["error"]["code"], "agent.version_mismatch");
    assert_eq!(value["error"]["details"]["expected"], "0".repeat(64));
}

#[test]
fn mismatch_survives_a_sender_already_writing_more_than_the_pipe_capacity() {
    let mut child = Command::new(env!("CARGO_BIN_EXE_orbit-agent"))
        .args(["command", "future.binary-command"])
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::null())
        .spawn()
        .unwrap();
    let mut stdin = child.stdin.take().unwrap();
    let writer = std::thread::spawn(move || {
        writeln!(
            stdin,
            "{}",
            serde_json::json!({"agent_sha256": "0".repeat(64), "stream_bytes": 8 * 1024 * 1024})
        )
        .unwrap();
        let block = vec![255; 65536];
        for _ in 0..128 {
            if stdin.write_all(&block).is_err() {
                break;
            }
        }
    });
    let mut stdout = child.stdout.take().unwrap();
    let (sender, receiver) = mpsc::channel();
    std::thread::spawn(move || {
        let mut bytes = String::new();
        stdout.read_to_string(&mut bytes).unwrap();
        let _ = sender.send(bytes);
    });
    let result = receiver.recv_timeout(Duration::from_secs(5));
    if result.is_err() {
        let _ = child.kill();
    }
    let status = child.wait().unwrap();
    writer.join().unwrap();
    let value: serde_json::Value =
        serde_json::from_str(&result.expect("mismatch blocked behind upload")).unwrap();
    assert!(status.success());
    assert_eq!(value["error"]["code"], "agent.version_mismatch");
}
