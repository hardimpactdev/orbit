use super::*;
use crate::commands::recipe::{Failure, Output as ProcessOutput};
use std::process::Command as Process;

#[derive(Default)]
struct TestHost {
    calls: Vec<Vec<String>>,
    inactive: bool,
    fail: Vec<String>,
    obstruct_restore: Option<PathBuf>,
}
impl Host for TestHost {
    fn execute(&mut self, invocation: &Invocation) -> Result<ProcessOutput, Failure> {
        self.calls.push(invocation.argv.clone());
        if invocation.argv[0] == "openssl" {
            let output = Process::new("openssl")
                .args(&invocation.argv[1..])
                .output()
                .unwrap();
            return Ok(ProcessOutput {
                code: output.status.code().unwrap(),
                stdout: String::from_utf8(output.stdout).unwrap(),
                stderr: String::from_utf8(output.stderr).unwrap(),
                ..Default::default()
            });
        }
        assert_eq!(invocation.argv[0], "systemctl");
        if invocation.argv[1] == "reload-or-restart" {
            if let Some(current) = self.obstruct_restore.take() {
                fs::remove_file(&current).unwrap();
                fs::create_dir(&current).unwrap();
            }
        }
        Ok(ProcessOutput {
            code: if self.fail.contains(&invocation.argv[1]) {
                1
            } else if self.inactive && invocation.argv[1] == "is-active" {
                3
            } else {
                0
            },
            ..Default::default()
        })
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("unexpected wait")
    }
}
fn fixture() -> (WorkDir, Files, TestHost) {
    let work = WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: rustix::process::getuid().as_raw(),
        gid: rustix::process::getgid().as_raw(),
    };
    fs::create_dir_all(files.path("/etc/caddy")).unwrap();
    (work, files, TestHost::default())
}
fn certificate() -> Input {
    let work = WorkDir::new().unwrap();
    let run = |args: &[&str]| {
        let output = Process::new("openssl")
            .args(args)
            .current_dir(&work.0)
            .output()
            .unwrap();
        assert!(
            output.status.success(),
            "{}",
            String::from_utf8_lossy(&output.stderr)
        );
    };
    run(&[
        "req",
        "-x509",
        "-newkey",
        "ed25519",
        "-nodes",
        "-keyout",
        "root.key",
        "-out",
        "root.pem",
        "-subj",
        "/CN=Test Root",
        "-days",
        "1",
    ]);
    run(&[
        "req",
        "-new",
        "-newkey",
        "ed25519",
        "-nodes",
        "-keyout",
        "leaf.key",
        "-out",
        "leaf.csr",
        "-subj",
        "/CN=gateway.orbit",
    ]);
    run(&[
        "x509",
        "-req",
        "-in",
        "leaf.csr",
        "-CA",
        "root.pem",
        "-CAkey",
        "root.key",
        "-set_serial",
        "1",
        "-out",
        "leaf.pem",
        "-days",
        "1",
    ]);
    Input {
        certificate: fs::read_to_string(work.0.join("leaf.pem")).unwrap(),
        key: fs::read_to_string(work.0.join("leaf.key")).unwrap(),
        root_certificate: fs::read_to_string(work.0.join("root.pem")).unwrap(),
    }
}
fn service_calls(host: &TestHost) -> Vec<&str> {
    host.calls
        .iter()
        .filter(|a| a[0] == "systemctl")
        .map(|a| a[1].as_str())
        .collect()
}
#[test]
fn publishes_valid_pair_with_permissions_repeats_without_reload_and_retains_old_versions() {
    let (_work, files, mut host) = fixture();
    let input = certificate();
    assert!(
        publish(&files, files.gid, &input, &mut host)
            .unwrap()
            .changed
    );
    let (prior, bytes) = previous(&files, files.gid).unwrap().unwrap();
    assert_eq!(bytes, contents(&input).map(|s| s.as_bytes().to_vec()));
    assert_eq!(service_calls(&host), ["is-active", "reload-or-restart"]);
    host.calls.clear();
    assert!(
        !publish(&files, files.gid, &input, &mut host)
            .unwrap()
            .changed
    );
    assert!(service_calls(&host).is_empty());
    assert_eq!(fs::read_link(files.path(CURRENT)).unwrap(), prior);
    assert!(
        publish(&files, files.gid, &certificate(), &mut host)
            .unwrap()
            .changed
    );
    assert_ne!(fs::read_link(files.path(CURRENT)).unwrap(), prior);
    assert!(files.path(prior.to_str().unwrap()).is_dir());
    assert_eq!(fs::read_dir(files.path(VERSIONS)).unwrap().count(), 2);
}
#[test]
fn leaves_an_inactive_caddy_inactive() {
    let (_work, files, mut host) = fixture();
    host.inactive = true;
    publish(&files, files.gid, &certificate(), &mut host).unwrap();
    assert_eq!(service_calls(&host), ["is-active"]);
}
#[test]
fn refuses_invalid_ca_key_pair_and_input_before_managed_writes() {
    for case in 0..6 {
        let (_work, files, mut host) = fixture();
        let mut input = certificate();
        match case {
            0 => input.key = certificate().key,
            1 => input.root_certificate = certificate().root_certificate,
            2 => input.certificate = "not a certificate".into(),
            3 => input.key.clear(),
            4 => input.certificate.push('\0'),
            _ => input.root_certificate = "x".repeat(1024 * 1024 + 1),
        }
        assert_eq!(
            publish(&files, files.gid, &input, &mut host).unwrap_err(),
            Error::Invalid {}
        );
        assert!(service_calls(&host).is_empty());
        assert!(!files.path(VERSIONS).exists());
        assert!(fs::symlink_metadata(files.path(CURRENT)).is_err());
    }
}
#[test]
fn refuses_unsafe_ancestors_current_link_and_version_contents_without_execution() {
    let input = certificate();
    for name in ["/etc", "/etc/caddy", VERSIONS, CURRENT] {
        let (_work, files, mut host) = fixture();
        let path = files.path(name);
        if path.exists() {
            fs::remove_dir_all(&path).unwrap();
        }
        fs::write(&path, "keep").unwrap();
        assert_eq!(
            publish(&files, files.gid, &input, &mut host).unwrap_err(),
            Error::Unsafe {}
        );
        assert!(host.calls.is_empty());
        assert_eq!(fs::read_to_string(path).unwrap(), "keep");
    }
    for case in 0..7 {
        let (_work, files, mut host) = fixture();
        publish(&files, files.gid, &input, &mut host).unwrap();
        let target = fs::read_link(files.path(CURRENT)).unwrap();
        let version = files.path(target.to_str().unwrap());
        match case {
            0 => fs::set_permissions(files.path(VERSIONS), fs::Permissions::from_mode(0o755))
                .unwrap(),
            1 => fs::set_permissions(&version, fs::Permissions::from_mode(0o755)).unwrap(),
            2 => fs::set_permissions(
                version.join("gateway.key"),
                fs::Permissions::from_mode(0o644),
            )
            .unwrap(),
            3 => {
                fs::remove_file(version.join("gateway.key")).unwrap();
                symlink("/outside", version.join("gateway.key")).unwrap();
            }
            4 => {
                fs::remove_file(files.path(CURRENT)).unwrap();
                symlink("/outside", files.path(CURRENT)).unwrap();
            }
            5 => {
                fs::remove_file(files.path(CURRENT)).unwrap();
                symlink(format!("{VERSIONS}/../outside"), files.path(CURRENT)).unwrap();
            }
            _ => {
                fs::remove_dir_all(&version).unwrap();
                symlink("/outside", &version).unwrap();
            }
        }
        host.calls.clear();
        assert_eq!(
            publish(&files, files.gid, &input, &mut host).unwrap_err(),
            Error::Unsafe {}
        );
        assert!(host.calls.is_empty());
    }
}
#[test]
fn failed_activation_restores_previous_link_or_absence_and_reports_failed_recovery() {
    let input = certificate();
    let replacement = certificate();
    for existing in [false, true] {
        for recovery_fails in [false, true] {
            let (_work, files, mut host) = fixture();
            let prior = if existing {
                publish(&files, files.gid, &input, &mut host).unwrap();
                previous(&files, files.gid).unwrap()
            } else {
                None
            };
            host.calls.clear();
            host.fail.push("reload-or-restart".into());
            if recovery_fails {
                host.fail.push("restart".into());
            }
            assert_eq!(
                publish(&files, files.gid, &replacement, &mut host).unwrap_err(),
                if recovery_fails {
                    Error::RecoveryFailed {}
                } else {
                    failed("reload")
                }
            );
            assert_eq!(previous(&files, files.gid).unwrap(), prior);
            assert_eq!(
                service_calls(&host),
                ["is-active", "reload-or-restart", "restart"]
            );
            if existing {
                assert_eq!(fs::read_dir(files.path(VERSIONS)).unwrap().count(), 1);
            } else {
                assert!(!files.path(VERSIONS).exists());
            }
        }
    }
}
#[test]
fn state_and_staging_failures_preserve_existing_publication() {
    let (_work, files, mut host) = fixture();
    let input = certificate();
    host.fail.push("is-active".into());
    assert_eq!(
        publish(&files, files.gid, &input, &mut host).unwrap_err(),
        failed("state")
    );
    assert!(!files.path(VERSIONS).exists());
    host.fail.clear();
    fs::set_permissions(files.path("/etc/caddy"), fs::Permissions::from_mode(0o550)).unwrap();
    assert_eq!(
        publish(&files, files.gid, &input, &mut host).unwrap_err(),
        failed("stage")
    );
    fs::set_permissions(files.path("/etc/caddy"), fs::Permissions::from_mode(0o750)).unwrap();
    assert!(previous(&files, files.gid).unwrap().is_none());
}

#[test]
fn retains_candidate_if_restoring_the_live_link_fails() {
    let (_work, files, mut host) = fixture();
    publish(&files, files.gid, &certificate(), &mut host).unwrap();
    host.obstruct_restore = Some(files.path(CURRENT));
    host.fail.push("reload-or-restart".into());
    assert_eq!(
        publish(&files, files.gid, &certificate(), &mut host).unwrap_err(),
        Error::RecoveryFailed {}
    );
    assert_eq!(fs::read_dir(files.path(VERSIONS)).unwrap().count(), 2);
    assert!(!service_calls(&host).contains(&"restart"));
}
