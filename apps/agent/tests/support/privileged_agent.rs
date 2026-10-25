//! A private root command context for tests on a disposable Linux host.
use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use std::{
    fs,
    io::Write,
    process::{Child, Command, Stdio},
    sync::OnceLock,
};

pub struct Agent {
    locks: String,
}
impl Agent {
    pub fn new() -> Self {
        let output = Command::new("sudo")
            .args([
                "-n",
                "mktemp",
                "-d",
                "-p",
                "/var/tmp",
                "orbit-native-locks.XXXXXX",
            ])
            .output()
            .unwrap();
        assert!(output.status.success(), "{output:?}");
        Self {
            locks: String::from_utf8(output.stdout).unwrap().trim().into(),
        }
    }
    pub fn start(&self, name: &str, input: Value, path: &str) -> Child {
        static BINARY: OnceLock<(String, String)> = OnceLock::new();
        let (agent, hash) = BINARY.get_or_init(|| {
            let path = std::env::var("ORBIT_AGENT_TEST_BINARY")
                .unwrap_or_else(|_| env!("CARGO_BIN_EXE_orbit-agent").into());
            let hash = format!("{:x}", Sha256::digest(fs::read(&path).unwrap()));
            (path, hash)
        });
        let mut child = Command::new("sudo")
            .args([
                "-n",
                "env",
                &format!("PATH={path}"),
                "ORBIT_AGENT_SUPERVISED=1",
                &format!("ORBIT_AGENT_LOCK_DIR={}", self.locks),
                agent,
                "command",
                name,
            ])
            .stdin(Stdio::piped())
            .stdout(Stdio::piped())
            .stderr(Stdio::piped())
            .spawn()
            .unwrap();
        writeln!(
            child.stdin.take().unwrap(),
            "{}",
            json!({"agent_sha256":hash, "deadline_ms":60000, "input":input})
        )
        .unwrap();
        child
    }
}
impl Drop for Agent {
    fn drop(&mut self) {
        let _ = Command::new("sudo")
            .args(["-n", "rm", "-rf", "--", &self.locks])
            .status();
    }
}
