use super::*;
use crate::commands::recipe::{Failure, WorkDir};
use std::os::unix::fs::{symlink, PermissionsExt};
struct Fake {
    path: PathBuf,
    active: bool,
    enabled: bool,
    fail: Option<&'static str>,
    recovery_fails: bool,
    changes: Vec<String>,
    validations: usize,
}
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> Result<recipe::Output, Failure> {
        let mut code = 0;
        if call.argv[0] == "/usr/sbin/php-fpm8.5" {
            self.validations += 1;
            let main = fs::read_to_string(&call.argv[3]).unwrap();
            let include = main
                .lines()
                .find_map(|s| s.strip_prefix("include = "))
                .unwrap();
            let pools = Path::new(include).parent().unwrap();
            assert_eq!(
                fs::read_to_string(pools.join("www.conf")).unwrap(),
                "unrelated\n"
            );
            assert!(pools.join("orbit-gateway.conf").exists());
            if self.fail == Some("validate") {
                code = 1;
            }
        } else {
            let action = call.argv[1].as_str();
            match action {
                "is-active" => {
                    if !self.active {
                        code = 3;
                    }
                }
                "is-enabled" => {
                    if !self.enabled {
                        code = 1;
                    }
                }
                _ => {
                    self.changes.push(format!(
                        "{action}:{}",
                        fs::read_to_string(&self.path).unwrap_or_default()
                    ));
                    if self.fail == Some(action) {
                        code = 1;
                        if !self.recovery_fails {
                            self.fail = None;
                        }
                    } else {
                        match action {
                            "enable" => self.enabled = true,
                            "disable" => self.enabled = false,
                            "reload-or-restart" => self.active = true,
                            "stop" => self.active = false,
                            _ => panic!("{action}"),
                        }
                    }
                }
            }
        }
        Ok(recipe::Output {
            code,
            ..Default::default()
        })
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("unexpected wait")
    }
}
fn fixture() -> (WorkDir, Files, Fake, Input) {
    let work = WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: rustix::process::getuid().as_raw(),
        gid: rustix::process::getgid().as_raw(),
    };
    fs::create_dir_all(files.path(POOLS)).unwrap();
    fs::write(
        files.path("/etc/php/8.5/fpm/php-fpm.conf"),
        "[global]\ninclude = /etc/php/8.5/fpm/pool.d/*.conf\n",
    )
    .unwrap();
    fs::write(files.path(POOLS).join("www.conf"), "unrelated\n").unwrap();
    let fake = Fake {
        path: files.path(POOL),
        active: true,
        enabled: true,
        fail: None,
        recovery_fails: false,
        changes: vec![],
        validations: 0,
    };
    (
        work,
        files,
        fake,
        Input {
            pool: "[orbit-gateway]\nlisten = /run/php/gateway.sock\n".into(),
            hooks: vec![],
        },
    )
}
#[test]
fn publishes_validated_pool_and_preserves_active_pool_on_repeat() {
    let (_w, files, mut host, input) = fixture();
    let mut runs = vec![];
    assert!(
        converge(&files, &mut host, &input, |run| {
            runs.push(run);
            Ok(false)
        })
        .unwrap()
        .activated
    );
    let meta = fs::metadata(files.path(POOL)).unwrap();
    assert_eq!(meta.mode() & 0o7777, 0o644);
    host.changes.clear();
    let result = converge(&files, &mut host, &input, |run| {
        runs.push(run);
        Ok(false)
    })
    .unwrap();
    assert!(!result.changed && !result.activated);
    assert!(host.changes.is_empty());
    assert_eq!(host.validations, 1);
    assert_eq!(runs, [true, false]);
    assert_eq!(fs::metadata(files.path(POOL)).unwrap().ino(), meta.ino());
}
#[test]
fn starts_an_inactive_matching_pool_but_only_repairs_metadata_for_an_active_pool() {
    let (_w, files, mut host, input) = fixture();
    fs::write(files.path(POOL), &input.pool).unwrap();
    host.active = false;
    host.enabled = false;
    assert!(
        converge(&files, &mut host, &input, |run| {
            assert!(run);
            Ok(false)
        })
        .unwrap()
        .activated
    );
    assert!(host.enabled && host.active);
    fs::set_permissions(files.path(POOL), fs::Permissions::from_mode(0o600)).unwrap();
    host.changes.clear();
    let result = converge(&files, &mut host, &input, |run| {
        assert!(!run);
        Ok(false)
    })
    .unwrap();
    assert!(result.changed && !result.activated);
    assert!(host.changes.is_empty());
}
#[test]
fn validation_and_hook_refusals_leave_live_pool_and_service_unchanged() {
    for stage in ["validate", "hooks"] {
        let (_w, files, mut host, input) = fixture();
        fs::write(files.path(POOL), "prior").unwrap();
        let ino = fs::metadata(files.path(POOL)).unwrap().ino();
        if stage == "validate" {
            host.fail = Some("validate");
        }
        let error = converge(&files, &mut host, &input, |_| {
            assert_eq!(stage, "hooks");
            Err(Error::HookFailed {
                cause: "documents.cleanup_state_unsafe".into(),
            })
        })
        .unwrap_err();
        if stage == "validate" {
            assert_eq!(error, failed("validate"));
        }
        assert_eq!(fs::read_to_string(files.path(POOL)).unwrap(), "prior");
        assert_eq!(fs::metadata(files.path(POOL)).unwrap().ino(), ino);
        assert!(host.changes.is_empty());
    }
}
#[test]
fn activation_failure_restores_exact_prior_bytes_mode_and_service_or_reports_recovery_failure() {
    for existed in [true, false] {
        for active in [true, false] {
            let (_w, files, mut host, input) = fixture();
            host.active = active;
            host.enabled = false;
            if existed {
                fs::write(files.path(POOL), "; exact prior\n[orbit-gateway]\n").unwrap();
                fs::set_permissions(files.path(POOL), fs::Permissions::from_mode(0o600)).unwrap();
            }
            host.fail = Some("reload-or-restart");
            assert_eq!(
                converge(&files, &mut host, &input, |_| Ok(false)).unwrap_err(),
                failed("reload-or-restart")
            );
            assert_eq!(host.active, active);
            assert!(!host.enabled);
            if existed {
                assert_eq!(
                    fs::read_to_string(files.path(POOL)).unwrap(),
                    "; exact prior\n[orbit-gateway]\n"
                );
                assert_eq!(
                    fs::metadata(files.path(POOL)).unwrap().mode() & 0o7777,
                    0o600
                );
            } else {
                assert!(!files.path(POOL).exists());
            }
        }
    }
    let (_w, files, mut host, input) = fixture();
    host.fail = Some("reload-or-restart");
    host.recovery_fails = true;
    assert_eq!(
        converge(&files, &mut host, &input, |_| Ok(false)).unwrap_err(),
        Error::RecoveryFailed {}
    );
}
#[test]
fn enable_failure_restores_pool_without_reloading_the_running_master() {
    let (_w, files, mut host, input) = fixture();
    host.enabled = false;
    host.fail = Some("enable");
    assert_eq!(
        converge(&files, &mut host, &input, |_| Ok(false)).unwrap_err(),
        failed("enable")
    );
    assert!(!files.path(POOL).exists());
    assert!(host.active);
    assert!(host.changes.iter().all(|s| !s.starts_with("reload")));
}
#[test]
fn refuses_invalid_pool_include_and_unsafe_paths_before_any_live_change() {
    for text in [
        "",
        "[other]\n",
        "[orbit-gateway]\n[orbit-gateway]\n",
        "include = /tmp/other\n[orbit-gateway]\n",
    ] {
        let (_w, files, mut host, mut input) = fixture();
        input.pool = text.into();
        assert_eq!(
            converge(&files, &mut host, &input, |_| panic!(
                "hook on invalid input"
            ))
            .unwrap_err(),
            Error::Invalid {}
        );
        assert!(!files.path(POOL).exists());
    }
    for main in [
        "[global]\n",
        "include=/etc/php/8.5/fpm/pool.d/*.conf\ninclude=/etc/php/8.5/fpm/pool.d/*.conf\n",
    ] {
        let (_w, files, mut host, input) = fixture();
        fs::write(files.path("/etc/php/8.5/fpm/php-fpm.conf"), main).unwrap();
        assert_eq!(
            converge(&files, &mut host, &input, |_| panic!(
                "hook on invalid main"
            ))
            .unwrap_err(),
            Error::Invalid {}
        );
        assert!(!files.path(POOL).exists());
    }
    let (_w, files, mut host, input) = fixture();
    let outside = files.path("/outside");
    fs::write(&outside, "keep").unwrap();
    symlink(&outside, files.path(POOL)).unwrap();
    assert_eq!(
        converge(&files, &mut host, &input, |_| panic!("unsafe hook")).unwrap_err(),
        Error::Unsafe {}
    );
    assert_eq!(fs::read_to_string(outside).unwrap(), "keep");
}
fn net(files: &Files) {
    fs::create_dir_all(files.path("/proc/net")).unwrap();
    for table in ["unix", "tcp", "tcp6"] {
        fs::write(files.path(&format!("/proc/net/{table}")), "header\n").unwrap();
    }
}
#[test]
fn counts_connected_unix_and_established_tcp_in_every_enabled_pool() {
    let (_w, files, _host, input) = fixture();
    net(&files);
    fs::write(files.path(POOL), input.pool).unwrap();
    fs::write(
        files.path(POOLS).join("www.conf"),
        "[www]\nlisten=127.0.0.1:9000\n",
    )
    .unwrap();
    fs::write(
        files.path(POOLS).join("disabled.conf.off"),
        "listen=/disabled\n",
    )
    .unwrap();
    fs::write(files.path("/proc/net/unix"),"header\n0: 2 0 0 1 01 100 /run/php/gateway.sock\n0: 2 0 0 1 03 101 /run/php/gateway.sock\n0: 2 0 0 1 03 102 /run/php/gateway.sock\n0: 2 0 0 1 03 103 /disabled\n0: 2 0 0 1 03 104\n").unwrap();
    fs::write(
        files.path("/proc/net/tcp"),
        "header\n0: 0100007F:2328 0:0 0A\n1: 0100007F:2328 0:0 01\n2: 0100007F:1F90 0:0 01\n",
    )
    .unwrap();
    assert_eq!(connections(&files).unwrap().connections, 3);
    assert_eq!(connections(&files).unwrap().connections, 3);
}
#[test]
fn unreadable_or_malformed_observation_never_claims_idle() {
    let (_w, files, _host, input) = fixture();
    fs::write(files.path(POOL), input.pool).unwrap();
    assert_eq!(
        connections(&files).unwrap_err(),
        Error::ObservationFailed {}
    );
    net(&files);
    assert_eq!(connections(&files).unwrap().connections, 0);
    fs::write(files.path("/proc/net/tcp"), "header\nbroken\n").unwrap();
    assert_eq!(
        connections(&files).unwrap_err(),
        Error::ObservationFailed {}
    );
    net(&files);
    fs::write(files.path(POOL), "[orbit-gateway]\nlisten=$unknown\n").unwrap();
    assert_eq!(
        connections(&files).unwrap_err(),
        Error::ObservationFailed {}
    );
}
