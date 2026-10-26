//! Exercise the real process boundary: a stopped reader must not leave a blocked final result.
#![cfg(target_os = "linux")]
use orbit_agent::{command::run::file_sha256, commands::recipe::WorkDir};
use std::{
    fs,
    io::{BufRead, BufReader, Write},
    path::Path,
    process::{Command, Stdio},
    time::{Duration, Instant},
};

fn git(path: &Path, args: &[&str]) -> String {
    let result = Command::new("/usr/bin/git")
        .args([
            "-c",
            "user.name=Orbit test",
            "-c",
            "user.email=orbit@example.test",
            "-C",
        ])
        .arg(path)
        .args(args)
        .output()
        .unwrap();
    assert!(
        result.status.success(),
        "{}",
        String::from_utf8_lossy(&result.stderr)
    );
    String::from_utf8(result.stdout).unwrap().trim().to_owned()
}

#[test]
fn stopped_binary_reader_exits_after_deadline_instead_of_blocking_on_final_json() {
    let work = WorkDir::new().unwrap();
    git(&work.0, &["init", "--quiet"]);
    // Reproducible, poorly compressible bytes fill the output pipe before the deadline.
    let mut state = 0x12345678u32;
    let bytes: Vec<_> = (0..2 * 1024 * 1024)
        .map(|_| {
            state ^= state << 13;
            state ^= state >> 17;
            state ^= state << 5;
            state as u8
        })
        .collect();
    fs::write(work.0.join("large.bin"), bytes).unwrap();
    git(&work.0, &["add", "large.bin"]);
    git(&work.0, &["commit", "--quiet", "-m", "fixture"]);
    let commit = git(&work.0, &["rev-parse", "HEAD"]);
    let binary = Path::new(env!("CARGO_BIN_EXE_orbit-agent"));
    let envelope = serde_json::json!({
        "agent_sha256": file_sha256(binary).unwrap(), "deadline_ms": 500,
        "input": {"checkout": work.0, "commit": commit, "haves": []}
    });
    let mut child = Command::new(binary)
        .args(["command", "task-vms.export-git-pack"])
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::null())
        .spawn()
        .unwrap();
    child
        .stdin
        .take()
        .unwrap()
        .write_all(envelope.to_string().as_bytes())
        .unwrap();
    let mut output = BufReader::new(child.stdout.take().unwrap());
    let mut header = String::new();
    output.read_line(&mut header).unwrap();
    assert!(
        serde_json::from_str::<serde_json::Value>(&header).unwrap()["stream_bytes"]
            .as_u64()
            .is_some()
    );
    let stop = Instant::now() + Duration::from_secs(4);
    loop {
        if let Some(status) = child.try_wait().unwrap() {
            assert_eq!(status.code(), Some(74));
            break;
        }
        if Instant::now() >= stop {
            child.kill().unwrap();
            child.wait().unwrap();
            panic!("the binary producer blocked after its deadline");
        }
        std::thread::sleep(Duration::from_millis(10));
    }
    // Retain the unread pipe until the child exits; dropping it earlier tests a broken pipe instead.
    drop(output);
}
