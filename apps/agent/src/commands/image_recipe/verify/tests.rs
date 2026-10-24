use super::*;
use crate::commands::recipe::Output;
fn fixture() -> (WorkDir, Files, Account, Input) {
    let work = WorkDir::new().unwrap();
    let uid = rustix::process::getuid().as_raw();
    let gid = rustix::process::getgid().as_raw();
    let files = Files {
        root: work.0.clone(),
        uid,
        gid,
    };
    let account = Account {
        name: "orbit".into(),
        uid,
        gid,
        home: files.path("/home/orbit"),
    };
    for path in [
        "/etc",
        "/usr/local/bin",
        "/var/tmp",
        "/home/orbit",
        "/opt/orbit",
    ] {
        fs::create_dir_all(files.path(path)).unwrap();
    }
    fs::write(
        files.path("/etc/os-release"),
        "ID=ubuntu\nVERSION_CODENAME=resolute\n",
    )
    .unwrap();
    fs::write(files.path("/usr/local/bin/orbit-agent"), "pinned agent").unwrap();
    let identity = format!("sha256:{}", "a".repeat(64));
    fs::write(
        files.path("/etc/orbit-machine-recipe.json"),
        json!({"identity":identity,"profile":"base"}).to_string(),
    )
    .unwrap();
    let input = serde_json::from_value(json!({"identity":identity,"agent_sha256":crate::command::run::file_sha256(&files.path("/usr/local/bin/orbit-agent")).unwrap(),"pi_sha256":null,"php":null,"rust":{},
        "recipe":{"user":"orbit","snapshot":"20261022T000000Z","architecture":"amd64","tools":{}}})).unwrap();
    (work, files, account, input)
}
struct Fake {
    calls: Vec<Invocation>,
    readable: bool,
}
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
        self.calls.push(call.clone());
        let stdout = if call.argv == ["dpkg", "--print-architecture"] {
            "amd64"
        } else {
            ""
        };
        assert!(
            !["apt-get", "curl", "systemctl"].contains(&call.argv[0].as_str()),
            "Verification must not install or refresh: {:?}",
            call.argv
        );
        if call.environment.iter().any(|(k, _)| k == "APT_CONFIG") {
            let path = &call
                .environment
                .iter()
                .find(|(k, _)| k == "APT_CONFIG")
                .unwrap()
                .1;
            let config = fs::read_to_string(path).unwrap();
            assert!(config.contains("Dir::Etc::parts \"\""));
            assert!(config.contains("APT::Get::Always-Include-Phased-Updates \"true\""));
        }
        Ok(Output {
            code: if !self.readable && call.argv.iter().any(|v| v == "test") {
                1
            } else {
                0
            },
            stdout: stdout.into(),
            ..Output::default()
        })
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("No retry")
    }
}
#[test]
fn verifies_repeatedly_without_refresh_installation_or_retained_configuration() {
    let (_work, files, account, input) = fixture();
    let mut host = Fake {
        calls: vec![],
        readable: true,
    };
    for _ in 0..2 {
        verify(
            &mut host,
            &files,
            &input,
            &account,
            Instant::now() + Duration::from_secs(1),
        )
        .unwrap();
    }
    assert!(host
        .calls
        .iter()
        .any(|call| call.argv.iter().any(|v| v == "-x")));
    assert!(host
        .calls
        .iter()
        .any(|call| call.argv.iter().any(|v| v == "ls")));
    assert_eq!(fs::read_dir(files.path("/var/tmp")).unwrap().count(), 0);
}
#[test]
fn wrong_marker_binary_and_guest_access_fail_without_repair() {
    for defect in ["marker", "binary", "guest", "workspace"] {
        let (_work, files, account, input) = fixture();
        let mut host = Fake {
            calls: vec![],
            readable: defect != "guest",
        };
        match defect {
            "marker" => fs::write(files.path("/etc/orbit-machine-recipe.json"), "{}").unwrap(),
            "binary" => fs::write(files.path("/usr/local/bin/orbit-agent"), "wrong pin").unwrap(),
            "workspace" => fs::create_dir(account.home.join("orbit")).unwrap(),
            _ => {}
        }
        assert!(
            verify(
                &mut host,
                &files,
                &input,
                &account,
                Instant::now() + Duration::from_secs(1)
            )
            .is_err(),
            "{defect}"
        );
        assert_eq!(fs::read_dir(files.path("/var/tmp")).unwrap().count(), 0);
        if defect == "binary" {
            assert_eq!(
                fs::read(files.path("/usr/local/bin/orbit-agent")).unwrap(),
                b"wrong pin"
            );
        }
    }
}
#[test]
fn final_pin_check_observes_a_delivery_change_after_the_initial_read() {
    let (_work, files, account, input) = fixture();
    struct Change<'a> {
        files: &'a Files,
        base: Fake,
    }
    impl Host for Change<'_> {
        fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
            if call.argv.iter().any(|v| v == "ls") {
                fs::write(
                    self.files.path("/usr/local/bin/orbit-agent"),
                    "new delivery",
                )
                .unwrap();
            }
            self.base.execute(call)
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            panic!("No retry")
        }
    }
    let mut host = Change {
        files: &files,
        base: Fake {
            calls: vec![],
            readable: true,
        },
    };
    let error = verify(
        &mut host,
        &files,
        &input,
        &account,
        Instant::now() + Duration::from_secs(1),
    )
    .unwrap_err();
    assert_eq!(error.stage, "agent");
}
#[test]
fn image_origin_checks_never_fetch_a_historical_snapshot() {
    let mut host = Fake {
        calls: vec![],
        readable: true,
    };
    let mut snapshots = apt::Snapshots::retained_only();
    assert!(snapshots.options(&mut host, "20261022T000000Z").is_err());
    assert!(host.calls.is_empty());
}

#[test]
fn temporary_configuration_failure_reports_verification_before_software_checks() {
    let (_work, files, account, input) = fixture();
    fs::remove_dir(files.path("/var/tmp")).unwrap();
    fs::write(files.path("/var/tmp"), "not a directory").unwrap();
    let mut host = Fake {
        calls: vec![],
        readable: true,
    };
    let error = verify(
        &mut host,
        &files,
        &input,
        &account,
        Instant::now() + Duration::from_secs(1),
    )
    .unwrap_err();
    assert_eq!(error.stage, "verify");
    assert!(error.reason.contains("Verification snapshot configuration"));
    assert!(host
        .calls
        .iter()
        .all(|c| ["dpkg", "dpkg-query"].contains(&c.argv[0].as_str())));
    assert!(!files.path(APT).exists());
    assert_eq!(
        fs::read(files.path("/var/tmp")).unwrap(),
        b"not a directory"
    );
}

#[test]
fn guest_cache_read_failure_refuses_without_creating_cache_state() {
    struct Unreadable {
        base: Fake,
    }
    impl Host for Unreadable {
        fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
            let mut out = self.base.execute(call)?;
            if call.argv.iter().any(|v| v == "ls") {
                assert!(call.argv.ends_with(&[
                    "ls".into(),
                    "-A".into(),
                    "--".into(),
                    call.argv.last().unwrap().clone()
                ]));
                assert_eq!(call.argv[0], "sudo");
                out.code = 13;
                out.stderr = "guest cache read failed".into();
            }
            Ok(out)
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            panic!("No retry")
        }
    }
    let (_work, files, mut account, input) = fixture();
    account.uid += 1;
    let mut host = Unreadable {
        base: Fake {
            calls: vec![],
            readable: true,
        },
    };
    let error = verify(
        &mut host,
        &files,
        &input,
        &account,
        Instant::now() + Duration::from_secs(1),
    )
    .unwrap_err();
    assert_eq!(error.diagnostic.unwrap().stderr, "guest cache read failed");
    assert_eq!(fs::read_dir(files.path("/opt/orbit")).unwrap().count(), 0);
    assert_eq!(fs::read_dir(files.path("/var/tmp")).unwrap().count(), 0);
}

#[test]
fn images_with_incus_require_exact_admin_membership_without_repair() {
    struct Groups {
        base: Fake,
        groups: &'static str,
    }
    impl Host for Groups {
        fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
            let mut out = self.base.execute(call)?;
            if call.argv[0] == "dpkg-query" {
                out.stdout = "incus\tamd64\t1:7.0.1\tinstall ok installed\n".into();
            } else if call.argv == ["id", "-nG", "orbit"] {
                out.stdout = self.groups.into();
            }
            assert_ne!(call.argv[0], "usermod");
            Ok(out)
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            panic!("No retry")
        }
    }
    for groups in ["orbit incus-admin", "orbit sudo", "orbit incus-admin-other"] {
        let (_work, files, account, mut input) = fixture();
        input.recipe.tools.insert(
            "incus".into(),
            serde_json::from_value(json!({
                "source":"artifact", "version":"1:7.0.1", "artifacts":{"amd64":{
                    "url":"https://example.test/incus.deb", "sha256":"c".repeat(64)}}
            }))
            .unwrap(),
        );
        let mut host = Groups {
            base: Fake {
                calls: vec![],
                readable: true,
            },
            groups,
        };
        for _ in 0..2 {
            let result = verify(
                &mut host,
                &files,
                &input,
                &account,
                Instant::now() + Duration::from_secs(1),
            );
            if groups == "orbit incus-admin" {
                result.unwrap();
            } else {
                assert!(result.unwrap_err().reason.contains("not in incus-admin"));
            }
            assert_eq!(fs::read_dir(files.path("/var/tmp")).unwrap().count(), 0);
        }
    }
}

#[test]
fn system_tools_require_equal_root_and_guest_versions_without_requiring_the_recipe_version() {
    struct Versions {
        root: Output,
        guest: Output,
        calls: Vec<Invocation>,
    }
    impl Host for Versions {
        fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
            self.calls.push(call.clone());
            Ok(if self.calls.len() == 1 {
                self.root.clone()
            } else {
                self.guest.clone()
            })
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            panic!("Version verification does not retry")
        }
    }
    let (_work, files, mut account, _) = fixture();
    account.uid += 1;
    for name in ["git", "composer"] {
        for (root, guest, code, truncated, valid) in [
            ("version 2.55.0", "version 2.55.0", 0, false, true),
            ("version 2.55.0", "version 2.54.0", 0, false, false),
            ("", "", 0, false, false),
            ("version 2.55.0", "version 2.55.0", 1, false, false),
            ("version 2.55.0", "version 2.55.0", 0, true, false),
        ] {
            let mut host = Versions {
                root: Output {
                    stdout: root.into(),
                    ..Output::default()
                },
                guest: Output {
                    stdout: guest.into(),
                    code,
                    truncated,
                    ..Output::default()
                },
                calls: vec![],
            };
            let path = files.path(&format!("/usr/bin/{name}"));
            assert_eq!(
                system_tool(&mut host, &account, &path).is_ok(),
                valid,
                "{name}: {guest}"
            );
            assert_eq!(
                host.calls[0].argv,
                [path.display().to_string(), "--version".into()]
            );
            assert_eq!(
                host.calls[1].argv,
                [
                    "sudo".into(),
                    "-u".into(),
                    "orbit".into(),
                    "-H".into(),
                    "env".into(),
                    format!(
                        "VP_HOME={}",
                        account.home.join(".local/share/vite-plus").display()
                    ),
                    path.display().to_string(),
                    "--version".into()
                ]
            );
        }
    }
}

#[test]
fn verification_refusals_keep_exit_status_and_bounded_redacted_output_as_typed_fields() {
    use crate::command::CommandError;
    let failure = Failure::command(
        &Invocation::new(&["dpkg", "--verify"]),
        &Output {
            code: 17,
            stdout: "package stdout".into(),
            stderr: format!(
                "{}\nPASSWORD=sentinel\ndependency conflict",
                "old\n".repeat(5000)
            ),
            ..Output::default()
        },
    );
    let error = Error::from(failure);
    assert_eq!(error.code(), "task_vm.image_build_failed");
    let detail = error.details();
    assert_eq!(detail["stage"], "verify");
    assert_eq!(detail["exit_code"], 17);
    let stderr = detail["stderr"].as_str().unwrap();
    assert!(stderr.ends_with("dependency conflict"));
    assert!(!stderr.contains("sentinel"));
    assert!(stderr.len() <= 8000);
    let detail = Error::from(bad("missing recipe marker")).details();
    assert!(detail["exit_code"].is_null());
    assert_eq!(detail["reason"], "missing recipe marker");
}

#[test]
fn matching_receipts_and_package_metadata_do_not_hide_broken_guest_launchers() {
    struct Executables {
        base: Fake,
        broken: String,
        version: &'static str,
    }
    impl Host for Executables {
        fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
            if call.argv.last().is_some_and(|a| a == "--version") {
                self.base.calls.push(call.clone());
                assert_eq!(call.argv[0], "env", "Guest launchers must not run as root");
                let path = &call.argv[call.argv.len() - 2];
                return Ok(Output {
                    code: if path.ends_with(&self.broken) { 1 } else { 0 },
                    stdout: self.version.into(),
                    ..Output::default()
                });
            }
            self.base.execute(call)
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            panic!("No retry")
        }
    }
    for defect in ["none", "rustc", "cargo", "pnpm", "wrong-version"] {
        let (_work, files, account, mut input) = fixture();
        let pin = json!({"source":"artifact","version":"1.92.0","artifacts":{"amd64":{"url":"https://example.test/tool.tar.xz","sha256":"c".repeat(64)}}});
        input.rust = serde_json::from_value(json!({"rust":pin,"cargo":pin})).unwrap();
        fs::create_dir_all(files.path("/opt/orbit/rust")).unwrap();
        fs::write(
            files.path("/opt/orbit/rust/.recipe-artifacts.json"),
            serde_json::to_vec(&rust::pins(&input)).unwrap(),
        )
        .unwrap();
        input
            .recipe
            .tools
            .insert("pnpm".into(), serde_json::from_value(pin).unwrap());
        let metadata = account
            .home
            .join(".local/share/vite-plus/recipe/pnpm-current/package.json");
        fs::create_dir_all(metadata.parent().unwrap()).unwrap();
        fs::write(metadata, r#"{"version":"1.92.0"}"#).unwrap();
        let mut host = Executables {
            base: Fake {
                calls: vec![],
                readable: true,
            },
            broken: defect.into(),
            version: if defect == "wrong-version" {
                "1.91.0"
            } else {
                "1.92.0"
            },
        };
        let result = verify(
            &mut host,
            &files,
            &input,
            &account,
            Instant::now() + Duration::from_secs(1),
        );
        assert_eq!(result.is_ok(), defect == "none", "{defect}: {result:?}");
        if defect == "none" {
            for binary in [
                "/opt/orbit/rust/bin/rustc",
                "/opt/orbit/rust/bin/cargo",
                "/home/orbit/.cargo/bin/rustc",
                "/home/orbit/.cargo/bin/cargo",
                "/usr/local/bin/pnpm",
            ] {
                assert!(
                    host.base.calls.iter().any(|c| c
                        .argv
                        .iter()
                        .any(|v| v == &files.path(binary).display().to_string())),
                    "{binary}"
                );
            }
        }
        assert_eq!(fs::read_dir(files.path("/var/tmp")).unwrap().count(), 0);
    }
}

#[test]
fn a_matching_javascript_runtime_needs_no_downloads_or_package_changes_on_repeat() {
    struct Current {
        calls: Vec<Invocation>,
    }
    impl Host for Current {
        fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
            self.calls.push(call.clone());
            let stdout = match call.argv[0].as_str() {
                "dpkg" => "amd64",
                "dpkg-query" => "",
                "env" if call.argv.last().unwrap() == "--version" => "1.3.2",
                _ => panic!("A matching runtime must not change: {:?}", call.argv),
            };
            Ok(Output {
                stdout: stdout.into(),
                ..Output::default()
            })
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            panic!("No retry")
        }
    }
    let (_work, files, account, mut input) = fixture();
    input.recipe.tools.insert("bun".into(), serde_json::from_value(json!({"source":"artifact","version":"1.3.2","artifacts":{"amd64":{"url":"https://example.test/bun.zip","sha256":"c".repeat(64)}}})).unwrap());
    let mut host = Current { calls: vec![] };
    for _ in 0..2 {
        super::super::javascript(
            &mut host,
            &files,
            &input,
            &account,
            Instant::now() + Duration::from_secs(1),
        )
        .unwrap();
    }
    assert_eq!(host.calls.iter().filter(|c| c.argv[0] == "env").count(), 2);
}
