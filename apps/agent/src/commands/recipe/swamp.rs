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
    let work = WorkDir::new()?;
    let path = work.0.join("swamp");
    artifacts::download(host, "swamp", pin, input, &path)?;
    let bytes = fs::read(&path)
        .map_err(|_| Failure::new("swamp", "Cannot read verified Swamp artifact"))?;
    files.publish(
        &files.path(BINARY),
        &bytes,
        0o755,
        files.uid,
        files.gid,
        false,
    )
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
    }
    impl Host for Download {
        fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
            assert_eq!(call.argv[0], "curl");
            self.calls += 1;
            let index = call.argv.iter().position(|v| v == "--output").unwrap();
            fs::write(&call.argv[index + 1], &self.bytes).unwrap();
            Ok(Output {
                code: 0,
                stdout: String::new(),
                stderr: String::new(),
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
}
