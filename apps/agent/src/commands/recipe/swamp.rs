//! The official, digest-pinned standalone Swamp binary in the one base image.
use super::{
    artifacts,
    types::{Input, Tool},
    Failure, Files, Host, WorkDir,
};
use std::{fs, os::unix::fs::PermissionsExt};
pub const BINARY: &str = "/usr/local/bin/swamp";
pub fn installed(files: &Files, pin: &Tool) -> Option<String> {
    let path = files.path(BINARY);
    if !path.is_file() || !fs::metadata(&path).is_ok_and(|m| m.permissions().mode() & 0o111 != 0) {
        return None;
    }
    pin.artifacts
        .values()
        .any(|artifact| artifacts::verify(&path, artifact).is_ok())
        .then(|| pin.version.clone())
}
pub fn install(host: &mut impl Host, files: &Files, input: &Input) -> Result<(), Failure> {
    let Some(pin) = input.tools.get("swamp") else {
        return Ok(());
    };
    if installed(files, pin).as_ref() == Some(&pin.version)
        && artifacts::verify(
            &files.path(BINARY),
            &pin.artifacts[input.architecture.name()],
        )
        .is_ok()
    {
        return Ok(());
    }
    // Command TMPDIR can be memory-backed; keep the large artifact on the target filesystem.
    let destination = files.path(BINARY);
    let work = WorkDir::within(destination.parent().unwrap())?;
    let path = work.0.join("swamp");
    artifacts::download(host, "swamp", pin, input, &path)?;
    files.publish_from(&destination, &path, 0o755, files.uid, files.gid, false)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::commands::recipe::{Invocation, Output};
    use sha2::{Digest, Sha256};
    use std::{path::PathBuf, time::Duration};
    struct Sandbox(PathBuf);
    impl Drop for Sandbox {
        fn drop(&mut self) {
            let _ = fs::remove_dir_all(&self.0);
        }
    }
    struct Download {
        bytes: Vec<u8>,
        calls: usize,
        staging_parent: PathBuf,
    }
    impl Host for Download {
        fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
            assert_eq!(call.argv[0], "curl");
            self.calls += 1;
            let index = call.argv.iter().position(|v| v == "--output").unwrap();
            assert_eq!(
                PathBuf::from(&call.argv[index + 1])
                    .parent()
                    .unwrap()
                    .parent()
                    .unwrap(),
                self.staging_parent
            );
            fs::write(&call.argv[index + 1], &self.bytes).unwrap();
            Ok(Output {
                code: 0,
                stdout: String::new(),
                stderr: String::new(),
                signal: None,
                truncated: false,
            })
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            panic!("no waits")
        }
    }
    #[test]
    fn installs_only_verified_official_bytes_and_detects_a_changed_binary() {
        let root =
            std::env::temp_dir().join(format!("orbit-swamp-pin-{:016x}", rand::random::<u64>()));
        let _guard = Sandbox(root.clone());
        fs::create_dir_all(root.join("usr/local/bin")).unwrap();
        let files = Files {
            root,
            uid: rustix::process::geteuid().as_raw(),
            gid: rustix::process::getegid().as_raw(),
        };
        let bytes = b"official-swamp-test-artifact";
        let digest = format!("{:x}", Sha256::digest(bytes));
        let input:Input=serde_json::from_value(serde_json::json!({"user":"orbit","snapshot":"20261022T000000Z","architecture":"amd64","tools":{"swamp":{"source":"artifact","version":"20261022.021804.0-sha.983e2929","artifacts":{"amd64":{"url":"https://github.com/swamp-club/swamp/releases/download/v20261022.021804.0-sha.983e2929/swamp-linux-x86_64","sha256":digest}}}}})).unwrap();
        let mut host = Download {
            bytes: bytes.to_vec(),
            calls: 0,
            staging_parent: files.path("/usr/local/bin"),
        };
        install(&mut host, &files, &input).unwrap();
        assert_eq!(fs::read(files.path(BINARY)).unwrap(), bytes);
        assert_eq!(
            installed(&files, &input.tools["swamp"]),
            Some(input.tools["swamp"].version.clone())
        );
        install(&mut host, &files, &input).unwrap();
        assert_eq!(host.calls, 1);
        fs::write(files.path(BINARY), "modified").unwrap();
        assert_eq!(installed(&files, &input.tools["swamp"]), None);
        host.bytes = b"wrong-download".to_vec();
        assert!(install(&mut host, &files, &input).is_err());
        assert_eq!(fs::read(files.path(BINARY)).unwrap(), b"modified");
    }

    #[cfg(target_os = "linux")]
    #[test]
    fn large_artifact_installs_with_bounded_address_space() {
        let child = format!(
            "{}::large_artifact_memory_child",
            module_path!().split_once("::").unwrap().1
        );
        let output = std::process::Command::new(std::env::current_exe().unwrap())
            .args(["--exact", &child, "--nocapture"])
            .env("ORBIT_SWAMP_MEMORY_CHILD", "1")
            .env("MALLOC_ARENA_MAX", "1")
            .output()
            .unwrap();
        assert!(
            output.status.success(),
            "large artifact installation failed: {}",
            String::from_utf8_lossy(&output.stderr)
        );
        assert!(
            String::from_utf8_lossy(&output.stdout).contains("bounded Swamp artifact installed")
        );
    }

    #[cfg(target_os = "linux")]
    #[test]
    fn large_artifact_memory_child() {
        if std::env::var_os("ORBIT_SWAMP_MEMORY_CHILD").is_none() {
            return;
        }
        use rustix::process::{getrlimit, setrlimit, Resource, Rlimit};
        const SIZE: u64 = 32 * 1024 * 1024;
        struct SparseDownload;
        impl Host for SparseDownload {
            fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
                assert_eq!(call.argv[0], "curl");
                let index = call.argv.iter().position(|v| v == "--output").unwrap();
                fs::File::create(&call.argv[index + 1])
                    .unwrap()
                    .set_len(SIZE)
                    .unwrap();
                Ok(Output {
                    code: 0,
                    stdout: String::new(),
                    stderr: String::new(),
                    signal: None,
                    truncated: false,
                })
            }
            fn wait(&mut self, _: Duration) -> Result<(), Failure> {
                panic!("no waits")
            }
        }
        let work = WorkDir::new().unwrap();
        let files = Files {
            root: work.0.clone(),
            uid: rustix::process::geteuid().as_raw(),
            gid: rustix::process::getegid().as_raw(),
        };
        fs::create_dir_all(files.path("/usr/local/bin")).unwrap();
        let mut digest = Sha256::new();
        for _ in 0..SIZE / 65536 {
            digest.update([0_u8; 65536]);
        }
        let input: Input = serde_json::from_value(serde_json::json!({
            "user":"orbit", "snapshot":"20261022T000000Z", "architecture":"amd64",
            "tools":{"swamp":{"source":"artifact", "version":"fixture",
                "artifacts":{"amd64":{"url":"https://example.test/swamp",
                    "sha256":format!("{:x}", digest.finalize())}}}}
        }))
        .unwrap();
        let status = fs::read_to_string("/proc/self/status").unwrap();
        let current: u64 = status
            .lines()
            .find_map(|line| {
                line.strip_prefix("VmSize:")?
                    .split_whitespace()
                    .next()?
                    .parse()
                    .ok()
            })
            .unwrap();
        setrlimit(
            Resource::As,
            Rlimit {
                current: Some(current * 1024 + 8 * 1024 * 1024),
                maximum: getrlimit(Resource::As).maximum,
            },
        )
        .unwrap();
        install(&mut SparseDownload, &files, &input).unwrap();
        assert_eq!(fs::metadata(files.path(BINARY)).unwrap().len(), SIZE);
        assert_eq!(
            installed(&files, &input.tools["swamp"]),
            Some("fixture".into())
        );
        println!("bounded Swamp artifact installed");
    }
}
