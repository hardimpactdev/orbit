use super::*;
use crate::commands::recipe::{Failure, Output, WorkDir};
use std::{
    collections::BTreeMap,
    os::unix::fs::{symlink, PermissionsExt},
};

#[derive(Default)]
struct Fake {
    calls: Vec<Invocation>,
    enabled: BTreeMap<String, String>,
    active: BTreeMap<String, String>,
    fail: Vec<String>,
    output: Option<Output>,
}
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
        self.calls.push(call.clone());
        if let Some(output) = &self.output {
            return Ok(output.clone());
        }
        let args = &call.argv;
        if self.fail.iter().any(|word| args.contains(word)) {
            return Ok(Output {
                code: 1,
                ..Output::default()
            });
        }
        if args[0] == "/usr/bin/systemctl" {
            let unit = args.last().unwrap().clone();
            match args[1].as_str() {
                "is-enabled" => {
                    let value = self
                        .enabled
                        .get(&unit)
                        .map(String::as_str)
                        .unwrap_or("disabled");
                    return Ok(Output {
                        code: if value == "enabled" { 0 } else { 1 },
                        stdout: format!("{value}\n"),
                        ..Output::default()
                    });
                }
                "is-active" => {
                    let value = self
                        .active
                        .get(&unit)
                        .map(String::as_str)
                        .unwrap_or("inactive");
                    return Ok(Output {
                        code: if value == "active" { 0 } else { 3 },
                        stdout: format!("{value}\n"),
                        ..Output::default()
                    });
                }
                "mask" => {
                    self.enabled.insert(unit, "masked-runtime".into());
                }
                "unmask" => {
                    self.enabled.remove(&unit);
                }
                "enable" => {
                    self.enabled.insert(unit.clone(), "enabled".into());
                    self.active.insert(unit, "active".into());
                }
                "start" => {
                    self.active.insert(unit, "activating".into());
                }
                _ => {}
            }
        }
        Ok(Output::default())
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("unexpected wait")
    }
}
fn input() -> Input {
    Input {
        application: "/home/orbit/orbit/apps/gateway".into(),
        orbit_home: "/home/orbit/.orbit".into(),
        user: "orbit".into(),
        retention: Retention {
            journal_max_mb: 128,
            journal_keep_free_mb: 2048,
            journal_days: 3,
            journal_runtime_max_mb: 16,
            log_rotate_mb: 8,
            log_rotate_keep: 2,
        },
    }
}
fn files(work: &WorkDir, installed: bool) -> Files {
    let files = Files {
        root: work.0.clone(),
        uid: rustix::process::geteuid().as_raw(),
        gid: rustix::process::getegid().as_raw(),
    };
    if installed {
        let path = files.path("/usr/sbin/logrotate");
        fs::create_dir_all(path.parent().unwrap()).unwrap();
        fs::write(&path, "installed").unwrap();
        fs::set_permissions(path, fs::Permissions::from_mode(0o755)).unwrap();
    }
    files
}
fn verbs(host: &Fake, verb: &str) -> Vec<String> {
    host.calls
        .iter()
        .filter(|c| c.argv[1] == verb)
        .map(|c| c.argv.last().unwrap().clone())
        .collect()
}
fn stage(error: Error) -> String {
    match error {
        Error::Install { stage } => stage,
        other => panic!("unexpected {other:?}"),
    }
}

#[test]
fn converges_fixed_units_and_retention_repeats_and_never_starts_a_release() {
    let work = WorkDir::new().unwrap();
    let files = files(&work, true);
    let mut host = Fake::default();
    assert!(
        converge(&files, &mut host, &input(), "/srv/orbit")
            .unwrap()
            .changed
    );
    let template =
        fs::read_to_string(files.path(&format!("/etc/systemd/system/{TEMPLATE}"))).unwrap();
    for text in ["User=orbit\n", "Type=oneshot\n", "Environment=HOME=/srv/orbit\n", "TimeoutStartSec=3600\n", "ExecStart=\"/usr/bin/php8.5\" \"/home/orbit/orbit/apps/gateway/artisan\" \"gateway:release:run\" %i\n", "ExecStopPost=\"/usr/bin/php8.5\" \"/home/orbit/orbit/apps/gateway/artisan\" \"gateway:release:settle\" %i\n"] { assert!(template.contains(text), "{text}"); }
    let service =
        fs::read_to_string(files.path(&format!("/etc/systemd/system/{SERVICE}"))).unwrap();
    assert!(service.contains("\"gateway:release:auto\"\n"));
    assert!(!service.contains("%i"));
    let timer = fs::read_to_string(files.path(&format!("/etc/systemd/system/{TIMER}"))).unwrap();
    assert!(timer.contains(
        "OnCalendar=*-*-* *:*:00\nAccuracySec=1s\nRandomizedDelaySec=10s\nPersistent=false"
    ));
    let journal =
        fs::read_to_string(files.path("/etc/systemd/journald.conf.d/orbit-retention.conf"))
            .unwrap();
    for text in [
        "SystemMaxUse=128M",
        "SystemKeepFree=2048M",
        "RuntimeMaxUse=16M",
        "MaxRetentionSec=3day",
    ] {
        assert!(journal.contains(text));
    }
    let log = fs::read_to_string(files.path("/etc/logrotate.d/orbit-gateway")).unwrap();
    for text in [
        "\"/home/orbit/orbit/apps/gateway/storage/logs/laravel.log\"",
        "su orbit orbit",
        "maxsize 8M",
        "rotate 2",
        "copytruncate",
    ] {
        assert!(log.contains(text));
    }
    for text in ["postrotate", "systemctl", "laravel-*"] {
        assert!(!log.contains(text));
    }
    assert!(
        !converge(&files, &mut host, &input(), "/srv/orbit")
            .unwrap()
            .changed
    );
    assert!(verbs(&host, "start").is_empty());
    assert!(verbs(&host, "restart").is_empty());
    assert!(!host.calls.iter().any(|c| c
        .argv
        .iter()
        .any(|s| s == "/usr/bin/apt-get" || s == SERVICE || s == TEMPLATE)));
    for (path, _, _) in render(&input(), "/srv/orbit") {
        let meta = fs::metadata(files.path(&path)).unwrap();
        assert_eq!(meta.mode() & 0o7777, 0o644);
        assert_eq!(meta.uid(), files.uid);
    }
}

#[test]
fn escaping_preserves_unicode_and_only_expands_the_record_specifier() {
    let mut input = input();
    input.application = "/srv/日本 50%$x\"\\/apps/gateway".into();
    input.orbit_home = "/srv/home $x".into();
    validate(&input).unwrap();
    let value = service(&input, "/srv/é", true);
    assert!(value.contains("WorkingDirectory=/srv/日本\\x2050%%\\x24x\\x22\\x5c/apps/gateway\n"));
    assert!(value.contains("Environment=HOME=/srv/é\n"));
    assert!(value.contains("Environment=ORBIT_HOME=/srv/home\\x20\\x24x\n"));
    assert!(value
        .contains("\"/srv/日本 50%%$$x\\\"\\\\/apps/gateway/artisan\" \"gateway:release:run\" %i"));
}

#[test]
fn invalid_identity_or_unsafe_managed_path_refuses_before_any_mutation() {
    let work = WorkDir::new().unwrap();
    let files = files(&work, false);
    let mut host = Fake::default();
    for bad in ["root", "", "orbit\nUser=root", "../orbit"] {
        let mut input = input();
        input.user = bad.into();
        assert!(matches!(
            converge(&files, &mut host, &input, "/home/orbit"),
            Err(Error::Invalid {})
        ));
    }
    for bad in [
        "relative/apps/gateway",
        "/srv/../apps/gateway",
        "/srv//apps/gateway",
        "/srv/\n/apps/gateway",
        "/srv/not-gateway",
    ] {
        let mut input = input();
        input.application = bad.into();
        assert!(matches!(
            converge(&files, &mut host, &input, "/home/orbit"),
            Err(Error::Invalid {})
        ));
    }
    let mut invalid = input();
    invalid.retention.log_rotate_keep = 0;
    assert!(matches!(
        converge(&files, &mut host, &invalid, "/home/orbit"),
        Err(Error::Invalid {})
    ));
    fs::create_dir_all(files.path("/etc/logrotate.d")).unwrap();
    let outside = work.0.join("keep");
    fs::write(&outside, "keep").unwrap();
    symlink(&outside, files.path("/etc/logrotate.d/orbit-gateway")).unwrap();
    assert_eq!(
        stage(converge(&files, &mut host, &input(), "/home/orbit").unwrap_err()),
        "gateway-retention-config"
    );
    assert!(host.calls.is_empty());
    assert!(!files.path("/etc/systemd").exists());
    assert_eq!(fs::read_to_string(outside).unwrap(), "keep");
    fs::remove_file(files.path("/etc/logrotate.d/orbit-gateway")).unwrap();
    fs::set_permissions(
        files.path("/etc/logrotate.d"),
        fs::Permissions::from_mode(0o777),
    )
    .unwrap();
    assert!(converge(&files, &mut host, &input(), "/home/orbit").is_err());
    assert!(host.calls.is_empty());
}

#[test]
fn installation_masks_hooks_and_cleans_every_owned_mask_preserving_the_first_error() {
    for package_fails in [false, true] {
        for cleanup_fails in [false, true] {
            let work = WorkDir::new().unwrap();
            let files = files(&work, false);
            let mut host = Fake::default();
            if package_fails {
                host.fail.push("install".into());
            }
            if cleanup_fails {
                host.fail.push("unmask".into());
            }
            let result = converge(&files, &mut host, &input(), "/home/orbit");
            if package_fails || cleanup_fails {
                assert_eq!(
                    stage(result.unwrap_err()),
                    if package_fails {
                        "gateway-retention-packages"
                    } else {
                        "gateway-retention-unmask"
                    }
                );
                assert!(!files.path("/etc/systemd").exists());
            } else {
                assert!(result.unwrap().changed);
            }
            assert_eq!(
                verbs(&host, "mask"),
                ["logrotate.service", "logrotate.timer"]
            );
            assert_eq!(
                verbs(&host, "unmask"),
                ["logrotate.service", "logrotate.timer"]
            );
            let apt: Vec<_> = host
                .calls
                .iter()
                .filter(|c| c.argv[0] == "/usr/bin/apt-get")
                .collect();
            assert_eq!(apt.len(), 2);
            assert_eq!(
                apt[1].argv,
                [
                    "/usr/bin/apt-get",
                    "-o",
                    "DPkg::Lock::Timeout=300",
                    "install",
                    "--yes",
                    "--no-install-recommends",
                    "--no-remove",
                    "--",
                    "logrotate"
                ]
            );
            assert_eq!(
                apt[1].environment,
                [
                    ("DEBIAN_FRONTEND".into(), "noninteractive".into()),
                    ("NEEDRESTART_MODE".into(), "l".into())
                ]
            );
        }
    }
}

#[test]
fn preserves_operator_masks_and_does_not_enable_logrotate_when_either_unit_is_masked() {
    for installed in [false, true] {
        for masked_unit in ["logrotate.service", "logrotate.timer"] {
            for value in ["masked", "masked-runtime"] {
                let work = WorkDir::new().unwrap();
                let files = files(&work, installed);
                let mut host = Fake::default();
                host.enabled.insert(masked_unit.into(), value.into());
                converge(&files, &mut host, &input(), "/home/orbit").unwrap();
                assert!(!verbs(&host, "mask").contains(&masked_unit.into()));
                assert!(!verbs(&host, "unmask").contains(&masked_unit.into()));
                assert_eq!(verbs(&host, "enable"), [TIMER]);
                assert_eq!(host.enabled.get(masked_unit).unwrap(), value);
            }
        }
    }
}

#[test]
fn starts_only_the_fixed_record_unit_and_does_not_repeat_an_active_start() {
    let mut host = Fake::default();
    let input = RunInput { record: 7 };
    assert!(start(&mut host, &input).unwrap().changed);
    assert!(!start(&mut host, &input).unwrap().changed);
    assert_eq!(
        verbs(&host, "start"),
        ["orbit-gateway-release-run@7.service"]
    );
    assert_eq!(inspect(&mut host, &input).unwrap().state, "activating");
    for state in ["active", "reloading", "deactivating"] {
        host.active.insert(run_unit(7).unwrap(), state.into());
        assert!(!start(&mut host, &input).unwrap().changed);
    }
    for record in [0, u64::MAX] {
        let before = host.calls.len();
        assert!(matches!(
            start(&mut host, &RunInput { record }),
            Err(Error::Invalid {})
        ));
        assert_eq!(host.calls.len(), before);
    }
    host.active.clear();
    host.fail.push("start".into());
    assert!(matches!(
        start(&mut host, &input),
        Err(Error::Unit { record: 7 })
    ));
}

#[test]
fn observation_rejects_transport_errors_truncation_and_unknown_states() {
    for output in [
        Output {
            code: 5,
            stdout: "active".into(),
            ..Output::default()
        },
        Output {
            stdout: "unexpected".into(),
            ..Output::default()
        },
        Output {
            stdout: "active".into(),
            truncated: true,
            ..Output::default()
        },
    ] {
        let mut host = Fake {
            output: Some(output),
            ..Fake::default()
        };
        assert!(matches!(
            inspect(&mut host, &RunInput { record: 7 }),
            Err(Error::Unit { record: 7 })
        ));
        assert!(matches!(
            start(&mut host, &RunInput { record: 7 }),
            Err(Error::Unit { record: 7 })
        ));
        assert!(verbs(&host, "start").is_empty());
    }
    let mut host = Fake {
        output: Some(Output {
            code: 4,
            ..Output::default()
        }),
        ..Fake::default()
    };
    assert_eq!(
        state(&mut host, "is-enabled", "logrotate.timer", "test").unwrap(),
        "not-found"
    );
}
