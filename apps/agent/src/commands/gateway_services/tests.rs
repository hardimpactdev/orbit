use super::*;
use crate::{
    command::CommandError,
    commands::recipe::{Failure, Output, WorkDir},
};
use std::{
    collections::BTreeMap,
    os::unix::fs::{symlink, PermissionsExt},
};
#[derive(Default)]
struct Fake {
    calls: Vec<Invocation>,
    enabled: BTreeMap<String, bool>,
    active: BTreeMap<String, bool>,
    fail: Option<String>,
    malformed: bool,
}
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
        self.calls.push(call.clone());
        let args = &call.argv;
        if self.fail.as_ref().is_some_and(|v| args.contains(v)) {
            return Ok(Output {
                code: 1,
                ..Output::default()
            });
        }
        let unit = args.last().unwrap().clone();
        let stdout = match args.get(1).map(String::as_str) {
            Some("is-enabled") if self.malformed => "not a state",
            Some("is-enabled") => {
                if self.enabled.get(&unit) == Some(&true) {
                    "enabled"
                } else {
                    "disabled"
                }
            }
            Some("is-active") => {
                if self.active.get(&unit) == Some(&true) {
                    "active"
                } else {
                    "inactive"
                }
            }
            Some("enable") => {
                self.enabled.insert(unit.clone(), true);
                self.active.insert(unit, true);
                ""
            }
            _ => "",
        };
        Ok(Output {
            stdout: stdout.into(),
            ..Output::default()
        })
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
    }
}
fn files(work: &WorkDir) -> Files {
    Files {
        root: work.0.clone(),
        uid: rustix::process::geteuid().as_raw(),
        gid: rustix::process::getegid().as_raw(),
    }
}
#[test]
fn fixed_services_keep_the_stable_path_user_and_timer_policy_and_repeat_without_rewriting() {
    for service in [Service::Fleet, Service::View, Service::Hibernator(71)] {
        let work = WorkDir::new().unwrap();
        let files = files(&work);
        let mut host = Fake::default();
        assert!(
            converge(&files, &mut host, &input(), service)
                .unwrap()
                .changed
        );
        let path = files.path(&format!("/etc/systemd/system/{}.service", service.name()));
        let contents = fs::read_to_string(&path).unwrap();
        for part in [
            "User=orbit\n",
            "WorkingDirectory=/home/orbit/orbit/apps/gateway\n",
            "Environment=ORBIT_HOME=/home/orbit/.orbit\n",
            "ExecStart=\"/usr/bin/php8.5\" \"/home/orbit/orbit/apps/gateway/artisan\"",
        ] {
            assert!(contents.contains(part), "{part}");
        }
        assert!(contents.contains(service.artisan()));
        assert_eq!(fs::metadata(&path).unwrap().mode() & 0o7777, 0o644);
        let inode = fs::metadata(&path).unwrap().ino();
        let again = converge(&files, &mut host, &input(), service).unwrap();
        assert_eq!(again.changed, matches!(service, Service::View));
        assert_eq!(fs::metadata(&path).unwrap().ino(), inode);
        match service {
            Service::View => {
                for part in [
                    "Type=simple\n",
                    "Restart=always\n",
                    "RestartSec=2\n",
                    "MemoryMax=256M\n",
                    "WantedBy=multi-user.target\n",
                ] {
                    assert!(contents.contains(part));
                }
                assert_eq!(
                    host.calls.iter().filter(|c| c.argv[1] == "restart").count(),
                    2
                );
            }
            _ => {
                assert!(contents.contains("Type=oneshot\n"));
                let timer = fs::read_to_string(
                    files.path(&format!("/etc/systemd/system/{}.timer", service.name())),
                )
                .unwrap();
                let policy = match service {
                    Service::Fleet => "OnBootSec=5min\nOnUnitInactiveSec=300s",
                    _ => "OnBootSec=2min\nOnUnitActiveSec=71s",
                };
                assert!(timer.contains(policy));
                assert!(timer.contains("Persistent=true\n"));
                assert!(!host
                    .calls
                    .iter()
                    .any(|c| ["start", "restart"].contains(&c.argv[1].as_str())));
            }
        }
    }
}
#[test]
fn escapes_unit_paths_without_resolving_the_stable_link() {
    let mut input = input();
    input.application = "/srv/é space/$value%/apps/gateway".into();
    input.orbit_home = "/srv/a'b\\c".into();
    validate(&input, Service::Fleet).unwrap();
    let files = render(&input, Service::Fleet);
    let service = &files[0].1;
    assert!(service.contains("WorkingDirectory=/srv/é\\x20space/\\x24value%%/apps/gateway\n"));
    assert!(service.contains("Environment=ORBIT_HOME=/srv/a\\x27b\\x5cc\n"));
    assert!(service.contains("\"/srv/é space/$$value%%/apps/gateway/artisan\""));
}
#[test]
fn invalid_input_and_foreign_unit_paths_refuse_before_writes_or_systemd_calls() {
    for fault in [
        "application",
        "application-parent",
        "application-suffix",
        "home",
        "user",
        "root",
        "interval",
    ] {
        let work = WorkDir::new().unwrap();
        let files = files(&work);
        let mut host = Fake::default();
        let mut input = input();
        match fault {
            "application" => input.application = "relative/apps/gateway".into(),
            "application-parent" => input.application = "/srv/../apps/gateway".into(),
            "application-suffix" => input.application = "/tmp/arbitrary".into(),
            "home" => input.orbit_home = "/tmp/line\nbreak".into(),
            "user" => input.user = "user\nRootDirectory=/".into(),
            "root" => input.user = "root".into(),
            _ => {}
        }
        let kind = if fault == "interval" {
            Service::Hibernator(0)
        } else {
            Service::Fleet
        };
        assert_eq!(
            converge(&files, &mut host, &input, kind)
                .unwrap_err()
                .code(),
            "gateway.service_units_invalid"
        );
        assert!(host.calls.is_empty());
        assert_eq!(fs::read_dir(&work.0).unwrap().count(), 0);
    }
    for fault in ["link", "directory", "writable"] {
        let work = WorkDir::new().unwrap();
        let files = files(&work);
        let mut host = Fake::default();
        let timer = files.path("/etc/systemd/system/orbit-fleet-converge.timer");
        fs::create_dir_all(timer.parent().unwrap()).unwrap();
        match fault {
            "link" => symlink(work.0.join("outside"), &timer).unwrap(),
            "directory" => fs::create_dir(&timer).unwrap(),
            _ => {
                fs::write(&timer, "preserved").unwrap();
                fs::set_permissions(&timer, fs::Permissions::from_mode(0o666)).unwrap();
            }
        }
        let error = converge(&files, &mut host, &input(), Service::Fleet).unwrap_err();
        assert_eq!(error.code(), "gateway.fleet_units_install_failed");
        assert_eq!(error.details()["stage"], "gateway-fleet-timer");
        assert!(host.calls.is_empty());
        assert!(!files
            .path("/etc/systemd/system/orbit-fleet-converge.service")
            .exists());
    }
}
#[test]
fn reports_the_failed_family_and_stage_and_stops_before_later_steps() {
    for service in [Service::Fleet, Service::View, Service::Hibernator(600)] {
        for verb in ["daemon-reload", "enable"] {
            let work = WorkDir::new().unwrap();
            let files = files(&work);
            let mut host = Fake {
                fail: Some(verb.into()),
                ..Fake::default()
            };
            let error = converge(&files, &mut host, &input(), service).unwrap_err();
            let action = if verb == "daemon-reload" {
                "reload"
            } else {
                "enable"
            };
            assert_eq!(error.code(), service.failed(action).code());
            assert_eq!(
                error.details()["stage"],
                format!("gateway-{}-{action}", service.prefix())
            );
            assert_eq!(host.calls.last().unwrap().argv[1], verb);
        }
    }
    let work = WorkDir::new().unwrap();
    let files = files(&work);
    let mut host = Fake {
        fail: Some("restart".into()),
        ..Fake::default()
    };
    assert_eq!(
        converge(&files, &mut host, &input(), Service::View)
            .unwrap_err()
            .details()["stage"],
        "gateway-agent-view-restart"
    );
    let mut host = Fake {
        malformed: true,
        ..Fake::default()
    };
    assert_eq!(
        converge(&files, &mut host, &input(), Service::Fleet)
            .unwrap_err()
            .details()["stage"],
        "gateway-fleet-enable"
    );
    assert!(!host.calls.iter().any(|c| c.argv[1] == "enable"));
}
#[test]
fn immediate_and_deferred_fleet_starts_use_fixed_arguments_and_report_failure() {
    let mut host = Fake::default();
    for _ in 0..2 {
        assert!(
            start(&mut host, &StartInput { deferred: false })
                .unwrap()
                .accepted
        );
    }
    assert_eq!(
        host.calls[0].argv,
        [
            "/usr/bin/systemctl",
            "start",
            "--no-block",
            "orbit-fleet-converge.service"
        ]
    );
    for _ in 0..2 {
        assert!(
            start(&mut host, &StartInput { deferred: true })
                .unwrap()
                .accepted
        );
    }
    for call in &host.calls[2..] {
        assert_eq!(
            &call.argv[..4],
            [
                "/usr/bin/systemd-run",
                "--quiet",
                "--collect",
                "--on-active=15"
            ]
        );
        assert!(call.argv[4].starts_with("--unit=orbit-fleet-converge-restart-"));
        assert_eq!(
            &call.argv[5..],
            [
                "/usr/bin/systemctl",
                "start",
                "--no-block",
                "orbit-fleet-converge.service"
            ]
        );
    }
    assert_ne!(host.calls[2].argv[4], host.calls[3].argv[4]);
    for deferred in [false, true] {
        let mut host = Fake {
            fail: Some("orbit-fleet-converge.service".into()),
            ..Fake::default()
        };
        assert_eq!(
            start(&mut host, &StartInput { deferred })
                .unwrap_err()
                .code(),
            "gateway.fleet_start_failed"
        );
    }
    assert_eq!(
        Fleet::target(&input()),
        StartFleet::target(&StartInput { deferred: false })
    );
}
