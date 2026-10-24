use super::*;
use crate::commands::recipe::Output;
use serde_json::json;

fn fixture() -> (WorkDir, Files, Input) {
    let work = WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: rustix::process::getuid().as_raw(),
        gid: rustix::process::getgid().as_raw(),
    };
    for directory in [
        "/usr/sbin",
        "/etc/apt/preferences.d",
        "/etc/apt/sources.list.d",
    ] {
        fs::create_dir_all(files.path(directory)).unwrap();
    }
    let input = serde_json::from_value(json!({
        "identity":format!("sha256:{}", "a".repeat(64)), "agent_sha256":"b".repeat(64),
        "pi_sha256":null, "php":null, "rust":{},
        "recipe":{"user":"orbit","snapshot":"20261022T000000Z","architecture":"amd64","tools":{}}
    }))
    .unwrap();
    (work, files, input)
}
#[derive(Default)]
struct Fake {
    calls: Vec<Invocation>,
    fail: Option<&'static str>,
    loaded: bool,
}
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
        self.calls.push(call.clone());
        let operation = call.argv[..2].join(" ");
        let stdout = match operation.as_str() {
            "dpkg --print-architecture" => "amd64",
            "dpkg-query -W"
            | "dpkg --audit"
            | "apt-get full-upgrade"
            | "apt-get check"
            | "systemctl enable" => "",
            "systemctl show" => {
                if self.loaded {
                    "loaded"
                } else {
                    "not-found"
                }
            }
            _ => panic!("Unexpected image command: {:?}", call.argv),
        };
        Ok(Output {
            code: if self.fail == Some(operation.as_str()) {
                17
            } else {
                0
            },
            stdout: stdout.into(),
            stderr: if self.fail == Some(operation.as_str()) {
                "package diagnostic".into()
            } else {
                String::new()
            },
            ..Output::default()
        })
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("No retries")
    }
}
impl php_build::Host for Fake {
    fn ping(&mut self, _: &Path, _: Instant) -> bool {
        panic!("No FPM")
    }
}
#[test]
fn missing_partial_and_different_snapshot_configurations_refuse_before_commands_or_policy() {
    for defect in ["missing", "source", "config", "snapshot"] {
        let (_work, files, input) = fixture();
        let directory = files.path(APT);
        if defect != "missing" {
            apt_setup(&files, &input, &directory).unwrap();
            match defect {
                "source" => fs::remove_file(directory.join("ubuntu.sources")).unwrap(),
                "config" => fs::remove_file(directory.join("apt.conf")).unwrap(),
                "snapshot" => fs::write(
                    directory.join("ubuntu.sources"),
                    sources::snapshot_source("20261021T000000Z"),
                )
                .unwrap(),
                _ => unreachable!(),
            }
        }
        let mut host = Fake::default();
        let error = packages::install(
            &mut host,
            &files,
            &input,
            Instant::now() + Duration::from_secs(1),
        )
        .unwrap_err();
        assert_eq!(error.stage, "apt-source");
        assert!(error.reason.contains("apt-source must run first"));
        assert!(host.calls.is_empty());
        assert!(!files.path("/usr/sbin/policy-rc.d").exists());
    }
}

#[test]
fn incus_membership_follows_successful_packages_and_its_failure_stops_the_image() {
    struct Incus {
        base: Fake,
        fail_membership: bool,
    }
    impl Host for Incus {
        fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
            if call.argv[0] == "usermod" {
                assert_eq!(
                    call.argv,
                    ["usermod", "--append", "--groups", "incus-admin", "orbit"]
                );
                assert!(self
                    .base
                    .calls
                    .iter()
                    .any(|c| c.argv == ["apt-get", "full-upgrade", "--yes"]));
                assert!(self
                    .base
                    .calls
                    .iter()
                    .any(|c| c.argv == ["dpkg", "--audit"]));
                self.base.calls.push(call.clone());
                return Ok(Output {
                    code: if self.fail_membership { 1 } else { 0 },
                    ..Output::default()
                });
            }
            let mut out = self.base.execute(call)?;
            if call.argv[0] == "dpkg-query" {
                out.stdout = ["incus", "incus-base", "incus-client"]
                    .iter()
                    .map(|n| format!("{n}\tamd64\t1:7.0.1\tinstall ok installed\n"))
                    .collect();
            }
            Ok(out)
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            panic!("No retry")
        }
    }
    impl php_build::Host for Incus {
        fn ping(&mut self, _: &Path, _: Instant) -> bool {
            panic!("No FPM")
        }
    }
    for defect in ["none", "packages", "membership"] {
        let (_work, files, mut input) = fixture();
        for name in ["incus", "incus-base", "incus-client"] {
            input.recipe.tools.insert(name.into(), serde_json::from_value(json!({"source":"artifact", "version":"1:7.0.1",
                "artifacts":{"amd64":{"url":format!("https://example.test/{name}.deb"), "sha256":"c".repeat(64)}}})).unwrap());
        }
        apt_setup(&files, &input, &files.path(APT)).unwrap();
        let mut host = Incus {
            base: Fake {
                fail: if defect == "packages" {
                    Some("apt-get full-upgrade")
                } else {
                    None
                },
                ..Fake::default()
            },
            fail_membership: defect == "membership",
        };
        for _ in 0..2 {
            let result = packages::install(
                &mut host,
                &files,
                &input,
                Instant::now() + Duration::from_secs(1),
            );
            assert_eq!(result.is_ok(), defect == "none", "{defect}: {result:?}");
            assert!(!files.path("/usr/sbin/policy-rc.d").exists());
        }
        assert_eq!(
            host.base
                .calls
                .iter()
                .filter(|c| c.argv[0] == "usermod")
                .count(),
            if defect == "packages" { 0 } else { 2 }
        );
    }
}
#[test]
fn package_phase_keeps_snapshot_configuration_and_enables_only_loaded_services_on_repeats() {
    for loaded in [false, true] {
        let (_work, files, input) = fixture();
        apt_setup(&files, &input, &files.path(APT)).unwrap();
        let mut host = Fake {
            loaded,
            ..Fake::default()
        };
        for _ in 0..2 {
            packages::install(
                &mut host,
                &files,
                &input,
                Instant::now() + Duration::from_secs(1),
            )
            .unwrap();
            assert!(!files.path("/usr/sbin/policy-rc.d").exists());
        }
        let enabled: Vec<_> = host
            .calls
            .iter()
            .filter(|c| c.argv.starts_with(&["systemctl".into(), "enable".into()]))
            .map(|c| c.argv[2].as_str())
            .collect();
        assert_eq!(
            enabled,
            if loaded {
                vec!["ssh", "docker", "containerd", "ssh", "docker", "containerd"]
            } else {
                vec![]
            }
        );
        assert!(host
            .calls
            .iter()
            .all(|c| c.environment.iter().any(|(k, v)| k == "APT_CONFIG"
                && v == &files.path(APT).join("apt.conf").display().to_string())));
        assert!(!host.calls.iter().any(|c| c.argv[0] == "usermod"));
    }
}
#[test]
fn package_and_service_failures_stop_the_phase_and_remove_temporary_restart_policy() {
    for fail in [
        "apt-get full-upgrade",
        "apt-get check",
        "dpkg --audit",
        "systemctl show",
        "systemctl enable",
    ] {
        let (_work, files, input) = fixture();
        apt_setup(&files, &input, &files.path(APT)).unwrap();
        let mut host = Fake {
            loaded: true,
            fail: Some(fail),
            ..Fake::default()
        };
        let error = packages::install(
            &mut host,
            &files,
            &input,
            Instant::now() + Duration::from_secs(1),
        )
        .unwrap_err();
        assert!(!files.path("/usr/sbin/policy-rc.d").exists());
        assert_eq!(host.calls.last().unwrap().argv[..2].join(" "), fail);
        assert!(
            error.reason.contains("package diagnostic")
                || error
                    .diagnostic
                    .as_ref()
                    .is_some_and(|d| d.stderr.contains("package diagnostic")),
            "{error:?}"
        );
    }
}
