use super::*;
use crate::commands::recipe::{Failure, Output as ProcessOutput, WorkDir};
#[derive(Default)]
struct FakeHost {
    calls: Vec<Vec<String>>,
    active: bool,
    failures: Vec<usize>,
}
impl Host for FakeHost {
    fn execute(&mut self, invocation: &Invocation) -> Result<ProcessOutput, Failure> {
        self.calls.push(invocation.argv.clone());
        let code = if self.failures.contains(&self.calls.len()) {
            1
        } else if invocation.argv[1] == "is-active" && !self.active {
            3
        } else {
            0
        };
        Ok(ProcessOutput {
            code,
            ..Default::default()
        })
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("unexpected wait")
    }
}
fn fixture() -> (WorkDir, Files, FakeHost) {
    let work = WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: rustix::process::getuid().as_raw(),
        gid: rustix::process::getgid().as_raw(),
    };
    fs::create_dir_all(files.path("/etc/systemd/system")).unwrap();
    (
        work,
        files,
        FakeHost {
            active: true,
            ..Default::default()
        },
    )
}
fn path(files: &Files, install: bool) -> std::path::PathBuf {
    files.path(if install {
        "/etc/systemd/system/caddy.service.d/orbit-vpn.conf"
    } else {
        "/etc/systemd/system/dnsmasq.service.d/orbit-vpn.conf"
    })
}
fn existing(files: &Files, install: bool) -> std::path::PathBuf {
    let path = path(files, install);
    fs::create_dir_all(path.parent().unwrap()).unwrap();
    fs::write(&path, "prior bytes\n").unwrap();
    fs::set_permissions(&path, fs::Permissions::from_mode(0o640)).unwrap();
    path
}
fn actions(host: &FakeHost) -> Vec<&str> {
    host.calls.iter().map(|a| a[1].as_str()).collect()
}
#[test]
fn installs_repeats_and_restarts_only_when_needed() {
    let (_work, files, mut host) = fixture();
    assert!(converge(&files, &mut host, true).unwrap().changed);
    let path = path(&files, true);
    assert_eq!(fs::read(&path).unwrap(), CONTENT);
    assert_eq!(fs::metadata(&path).unwrap().mode() & 0o7777, 0o644);
    assert_eq!(
        fs::metadata(path.parent().unwrap()).unwrap().mode() & 0o7777,
        0o755
    );
    assert_eq!(
        host.calls,
        [
            vec!["systemctl", "is-active", "--quiet", "caddy"],
            vec!["systemctl", "daemon-reload"],
            vec!["systemctl", "restart", "caddy"]
        ]
    );
    host.calls.clear();
    let inode = fs::metadata(&path).unwrap().ino();
    assert!(!converge(&files, &mut host, true).unwrap().changed);
    assert_eq!(actions(&host), ["is-active"]);
    assert_eq!(fs::metadata(&path).unwrap().ino(), inode);
    host.calls.clear();
    host.active = false;
    assert!(converge(&files, &mut host, true).unwrap().changed);
    assert_eq!(actions(&host), ["is-active", "restart"]);
}
#[test]
fn repairs_drift_and_preserves_other_drop_ins() {
    let (_work, files, mut host) = fixture();
    let path = existing(&files, true);
    let unrelated = path.with_file_name("other.conf");
    fs::write(&unrelated, "keep").unwrap();
    assert!(converge(&files, &mut host, true).unwrap().changed);
    assert_eq!(fs::read(&path).unwrap(), CONTENT);
    assert_eq!(fs::read_to_string(unrelated).unwrap(), "keep");
    fs::set_permissions(&path, fs::Permissions::from_mode(0o600)).unwrap();
    assert!(converge(&files, &mut host, true).unwrap().changed);
    fs::set_permissions(path.parent().unwrap(), fs::Permissions::from_mode(0o700)).unwrap();
    host.calls.clear();
    assert!(converge(&files, &mut host, true).unwrap().changed);
    assert_eq!(actions(&host), ["is-active"]);
}
#[test]
fn absent_dns_ordering_runs_nothing_and_removal_preserves_unrelated_files() {
    for other in [true, false] {
        let (_work, files, mut host) = fixture();
        assert!(!converge(&files, &mut host, false).unwrap().changed);
        assert!(host.calls.is_empty());
        let path = existing(&files, false);
        if other {
            fs::write(path.with_file_name("keep.conf"), "keep").unwrap();
        }
        assert!(converge(&files, &mut host, false).unwrap().changed);
        assert!(!path.exists());
        assert_eq!(path.parent().unwrap().exists(), other);
        assert_eq!(
            host.calls.last().unwrap(),
            &["systemctl", "restart", "dnsmasq"]
        );
        host.calls.clear();
        assert!(!converge(&files, &mut host, false).unwrap().changed);
        assert!(host.calls.is_empty());
    }
}
#[test]
fn refuses_unsafe_paths_before_any_service_call_or_write() {
    for install in [true, false] {
        for component in [
            "/etc",
            "/etc/systemd",
            "/etc/systemd/system",
            if install {
                "/etc/systemd/system/caddy.service.d"
            } else {
                "/etc/systemd/system/dnsmasq.service.d"
            },
            if install {
                "/etc/systemd/system/caddy.service.d/orbit-vpn.conf"
            } else {
                "/etc/systemd/system/dnsmasq.service.d/orbit-vpn.conf"
            },
        ] {
            for link in [true, false] {
                let (_work, files, mut host) = fixture();
                let target = files.path(component);
                if target.exists() {
                    fs::remove_dir_all(&target).unwrap();
                }
                fs::create_dir_all(target.parent().unwrap()).unwrap();
                let outside = files.path("/outside");
                fs::write(&outside, "keep").unwrap();
                if link {
                    std::os::unix::fs::symlink(&outside, &target).unwrap();
                } else if component.ends_with(".conf") {
                    fs::create_dir(&target).unwrap();
                } else {
                    fs::write(&target, "keep").unwrap();
                }
                assert!(matches!(
                    converge(&files, &mut host, install),
                    Err(Error::Unsafe { .. })
                ));
                assert!(host.calls.is_empty());
                assert_eq!(fs::read_to_string(&outside).unwrap(), "keep");
            }
        }
        let (_work, mut files, mut host) = fixture();
        files.uid += 1;
        assert!(matches!(
            converge(&files, &mut host, install),
            Err(Error::Unsafe { .. })
        ));
        assert!(host.calls.is_empty());
    }
}
#[test]
fn failed_activation_restores_file_mode_directory_and_active_state() {
    for install in [true, false] {
        for was_active in [true, false] {
            for failure in [2, 3] {
                let (_work, files, mut host) = fixture();
                let path = existing(&files, install);
                let directory = path.parent().unwrap();
                fs::set_permissions(directory, fs::Permissions::from_mode(0o700)).unwrap();
                host.active = was_active;
                host.failures = vec![failure];
                assert!(matches!(
                    converge(&files, &mut host, install),
                    Err(Error::Failed { .. })
                ));
                assert_eq!(fs::read_to_string(&path).unwrap(), "prior bytes\n");
                assert_eq!(fs::metadata(&path).unwrap().mode() & 0o7777, 0o640);
                assert_eq!(fs::metadata(directory).unwrap().mode() & 0o7777, 0o700);
                assert_eq!(
                    host.calls.last().unwrap()[1],
                    if was_active { "restart" } else { "stop" }
                );
            }
        }
    }
}
#[test]
fn failed_first_install_restores_absence_and_reports_failed_recovery() {
    let (_work, files, mut host) = fixture();
    host.failures = vec![3];
    assert_eq!(
        converge(&files, &mut host, true).unwrap_err(),
        failed("restart")
    );
    assert!(!path(&files, true).parent().unwrap().exists());
    host.calls.clear();
    host.failures = vec![3, 5];
    assert_eq!(
        converge(&files, &mut host, true).unwrap_err(),
        Error::RecoveryFailed {}
    );
    assert!(!path(&files, true).exists());
}
#[test]
fn failed_active_probe_and_unwritable_directory_preserve_prior_file() {
    let (_work, files, mut host) = fixture();
    let path = existing(&files, true);
    host.failures = vec![1];
    assert_eq!(
        converge(&files, &mut host, true).unwrap_err(),
        failed("is-active")
    );
    assert_eq!(actions(&host), ["is-active"]);
    assert_eq!(fs::read_to_string(&path).unwrap(), "prior bytes\n");
    host.calls.clear();
    host.failures.clear();
    fs::set_permissions(path.parent().unwrap(), fs::Permissions::from_mode(0o555)).unwrap();
    // Directory repair is part of convergence, so it also restores write access.
    assert!(converge(&files, &mut host, true).unwrap().changed);
}
