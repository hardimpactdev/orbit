use super::*;
use crate::commands::recipe::{store, Output};
use sha2::{Digest, Sha256};
struct Fake {
    installed: BTreeMap<String, String>,
    calls: Vec<Invocation>,
    files: Files,
    fail_install: bool,
    foreign: bool,
    audit: bool,
}
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
        self.calls.push(call.clone());
        let args = &call.argv;
        let mut out = Output::default();
        match args[0].as_str() {
            "dpkg" if args[1] == "--print-architecture" => out.stdout = "amd64".into(),
            "dpkg" if args[1] == "--compare-versions" => {
                out.code = if (args[3] == "gt" && args[2] > args[4])
                    || (args[3] == "lt" && args[2] < args[4])
                {
                    0
                } else {
                    1
                }
            }
            "dpkg" if args[1] == "--audit" => {
                if self.audit {
                    out.stdout = "half-configured package".into();
                }
            }
            "dpkg-query" if args[1] == "-W" => {
                out.stdout = self
                    .installed
                    .iter()
                    .map(|(n, v)| format!("{n}\tamd64\t{v}\tinstall ok installed\n"))
                    .collect()
            }
            "dpkg-query" if args[1] == "-L" => {}
            "apt-cache" => {
                let index = if self.foreign {
                    "https://ppa.test/ubuntu resolute/main amd64 Packages"
                } else {
                    "http://archive.ubuntu.com/ubuntu resolute/main amd64 Packages"
                };
                out.stdout = if args.get(2).is_none_or(|s| s.starts_with('-')) {
                    format!(" 500 {index}\n     release o=Ubuntu,a=resolute,n=resolute\n")
                } else {
                    format!(
                        "curl:\n *** {} 500\n        500 {index}\n",
                        self.installed["curl"]
                    )
                };
            }
            "apt-get" if args[1] == "install" => {
                assert!(self.files.path("/usr/sbin/policy-rc.d").exists());
                assert!(args.contains(&"--no-remove".into()));
                if args.contains(&"--simulate".into()) {
                    out.stdout = "Inst caddy [3.0] (2.0 local-deb [amd64])\n".into();
                } else if self.fail_install {
                    out.code = 100;
                    out.stderr = "installation failed API_TOKEN=secret-sentinel".into();
                } else {
                    for argument in args {
                        if argument.ends_with("/caddy.deb") {
                            self.installed.insert("caddy".into(), "2.0".into());
                        } else if argument == "curl=8.0" {
                            self.installed.insert("curl".into(), "8.0".into());
                        }
                    }
                }
            }
            "apt-get" => {}
            "curl" => {
                let index = args.iter().position(|a| a == "--output").unwrap();
                fs::write(&args[index + 1], b"pinned-deb").unwrap();
            }
            "dpkg-deb" => out.stdout = "Package: caddy\nVersion: 2.0\nArchitecture: amd64\n".into(),
            "systemctl" if args[1] == "list-units" => {}
            _ => panic!("Unexpected invocation {args:?}"),
        }
        Ok(out)
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("no retries")
    }
}
fn fixture() -> (WorkDir, Files, Account, Input, Fake) {
    let work = WorkDir::new().unwrap();
    let uid = rustix::process::getuid().as_raw();
    let gid = rustix::process::getgid().as_raw();
    let files = Files {
        root: work.0.clone(),
        uid,
        gid,
    };
    for path in [
        "/etc/apt/sources.list.d",
        "/etc/apt/preferences.d",
        "/usr/sbin",
    ] {
        fs::create_dir_all(files.path(path)).unwrap();
    }
    let account = store::Account {
        name: crate::command::identity::user_name(uid).unwrap(),
        uid,
        gid,
        home: work.0.join("home"),
    };
    let digest = format!("{:x}", Sha256::digest(b"pinned-deb"));
    let input=serde_json::from_value(json!({"user":account.name,"snapshot":"20261022T000000Z","architecture":"amd64","tools":{"curl":{"source":"ubuntu","package":"curl","version":"8.0"},"caddy":{"source":"artifact","version":"2.0","artifacts":{"amd64":{"url":"https://example.test/caddy.deb","sha256":digest}}}}})).unwrap();
    let host = Fake {
        installed: [
            ("curl".into(), "9.0".into()),
            ("caddy".into(), "3.0".into()),
        ]
        .into(),
        calls: vec![],
        files: Files {
            root: files.root.clone(),
            uid,
            gid,
        },
        fail_install: false,
        foreign: false,
        audit: false,
    };
    (work, files, account, input, host)
}
#[test]
fn incus_instance_started_during_download_refuses_before_package_installation() {
    struct StartedDuringDownload {
        inner: Fake,
        probes: usize,
    }
    impl Host for StartedDuringDownload {
        fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
            if call.argv[0] == "incus" {
                self.probes += 1;
                let active = self.inner.calls.iter().any(|call| call.argv[0] == "curl");
                return Ok(Output { stdout: if active {
                    r#"[{"project":"orbit-ci","name":"late-runner","type":"container","status":"Running"}]"#
                } else { "[]" }.into(), ..Output::default() });
            }
            self.inner.execute(call)
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            panic!("No retries")
        }
    }
    let (_work, files, account, mut input, mut fake) = fixture();
    for name in ["incus", "incus-base", "incus-client"] {
        let mut pin = input.tools["caddy"].clone();
        pin.version = "1.0".into();
        input.tools.insert(name.into(), pin);
        fake.installed.insert(name.into(), "1.0".into());
    }
    let mut host = StartedDuringDownload {
        inner: fake,
        probes: 0,
    };
    let failure = apply(
        &mut host,
        &files,
        &input,
        &account,
        Instant::now() + Duration::from_secs(10),
    )
    .unwrap_err();
    assert_eq!(failure.stage, "incus:running");
    assert!(failure.reason.contains("orbit-ci/late-runner"));
    assert_eq!(host.probes, 2);
    assert!(!host
        .inner
        .calls
        .iter()
        .any(|call| call.argv[..2] == ["apt-get", "install"]));
    assert!(!files.path("/usr/sbin/policy-rc.d").exists());
}

#[test]
fn application_keeps_newer_ubuntu_packages_installs_exact_artifacts_and_repeats_without_downloads()
{
    let (_work, files, account, input, mut host) = fixture();
    let plan = plan(&mut host, &files, &input, Some(&account)).unwrap();
    assert!(!plan.tools["curl"].changed);
    assert!(plan.tools["caddy"].changed);
    assert!(!host.calls.iter().any(|c| c.argv[0] == "apt-get"));
    host.calls.clear();
    let result = apply(
        &mut host,
        &files,
        &input,
        &account,
        Instant::now() + Duration::from_secs(60),
    )
    .unwrap();
    assert_eq!(result.changed_packages, ["caddy"]);
    assert_eq!(result.changed_tools, ["caddy"]);
    assert_eq!(result.newer_than_recipe["curl"]["installed"], "9.0");
    assert!(result.healthy);
    let installs: Vec<_> = host
        .calls
        .iter()
        .filter(|c| c.argv[..2] == ["apt-get", "install"])
        .collect();
    assert_eq!(installs.len(), 2);
    assert!(installs.iter().all(|c| c.argv.contains(&"curl=9.0".into())));
    assert!(!files.path("/usr/sbin/policy-rc.d").exists());
    host.calls.clear();
    let result = apply(
        &mut host,
        &files,
        &input,
        &account,
        Instant::now() + Duration::from_secs(60),
    )
    .unwrap();
    assert!(result.changed_packages.is_empty());
    assert!(result.changed_tools.is_empty());
    assert!(!host
        .calls
        .iter()
        .any(|c| c.argv[0] == "curl" || c.argv[..2] == ["apt-get", "install"]));
    let updates: Vec<_> = host
        .calls
        .iter()
        .filter(|c| c.argv[..2] == ["apt-get", "update"])
        .collect();
    assert_eq!(
        updates.len(),
        1,
        "current pins refresh rolling Ubuntu evidence only"
    );
}
#[test]
fn applying_refuses_foreign_newer_packages_before_download_and_existing_restart_policies_before_any_command(
) {
    let (_work, files, account, input, mut host) = fixture();
    host.foreign = true;
    assert_eq!(
        apply(
            &mut host,
            &files,
            &input,
            &account,
            Instant::now() + Duration::from_secs(60)
        )
        .unwrap_err()
        .stage,
        "origin:curl"
    );
    assert!(!host
        .calls
        .iter()
        .any(|c| c.argv[0] == "curl" || c.argv[..2] == ["apt-get", "install"]));
    assert!(!files.path("/usr/sbin/policy-rc.d").exists());
    fs::write(files.path("/usr/sbin/policy-rc.d"), "operator policy").unwrap();
    host.calls.clear();
    assert_eq!(
        apply(
            &mut host,
            &files,
            &input,
            &account,
            Instant::now() + Duration::from_secs(60)
        )
        .unwrap_err()
        .stage,
        "preflight"
    );
    assert!(host.calls.is_empty());
    assert_eq!(
        fs::read_to_string(files.path("/usr/sbin/policy-rc.d")).unwrap(),
        "operator policy"
    );
}
#[test]
fn install_and_audit_failures_remove_restart_suppression_and_expose_bounded_diagnostics() {
    for audit in [false, true] {
        let (_work, files, account, input, mut host) = fixture();
        host.fail_install = !audit;
        host.audit = audit;
        let error = apply(
            &mut host,
            &files,
            &input,
            &account,
            Instant::now() + Duration::from_secs(60),
        )
        .unwrap_err();
        assert_eq!(error.stage, "packages");
        assert!(!files.path("/usr/sbin/policy-rc.d").exists());
        assert!(!error.details().to_string().contains("secret-sentinel"));
        if !audit {
            assert_eq!(error.packages, ["caddy"]);
        }
    }
}

#[test]
#[ignore = "subprocess fixture reports readiness only after exec"]
#[cfg(target_os = "linux")]
fn interruption_command_helper() {
    use std::os::unix::net::UnixDatagram;
    let socket = UnixDatagram::unbound().unwrap();
    socket
        .connect(std::env::var_os("ORBIT_RECIPE_INTERRUPT_SOCKET").unwrap())
        .unwrap();
    // GNU timeout establishes its process group before executing this fixture.
    let group = rustix::process::getpgrp().as_raw_pid();
    socket.send(&group.to_ne_bytes()).unwrap();
    loop {
        // SAFETY: pause waits for a signal; SIGTERM has its default terminating action.
        unsafe { libc::pause() };
    }
}

#[test]
#[ignore = "subprocess helper installs process-wide signal handlers"]
#[cfg(target_os = "linux")]
fn interruption_helper() {
    let root = std::env::var_os("ORBIT_RECIPE_INTERRUPT_TEST").unwrap();
    let files = Files {
        root: root.into(),
        uid: rustix::process::getuid().as_raw(),
        gid: rustix::process::getgid().as_raw(),
    };
    let _signals = interruption::Guard::install().unwrap();
    let _policy = Policy::install(&files).unwrap();
    let context = Context::new(Platform::Linux, Instant::now() + Duration::from_secs(60));
    let mut host = Native(&context);
    let binary = std::env::current_exe().unwrap();
    let call = Invocation::new(&[
        binary.to_str().unwrap(),
        "--ignored",
        "--exact",
        "commands::recipe::operations::tests::interruption_command_helper",
    ]);
    let error = super::super::run(&mut host, &call, true).unwrap_err();
    assert_eq!(error.stage, "interrupted", "{error:?}");
}

#[test]
#[ignore = "subprocess helper installs process-wide signal handlers"]
fn interruption_before_execution_helper() {
    for signal in [libc::SIGHUP, libc::SIGINT, libc::SIGTERM] {
        let (_work, files, _account, _input, mut host) = fixture();
        let _signals = interruption::Guard::install().unwrap();
        let policy = Policy::install(&files).unwrap();
        // SAFETY: this isolated process has installed the signal guard.
        assert_eq!(unsafe { libc::raise(signal) }, 0);
        // Fake rejects this invocation if run fails to check interruption first.
        let error =
            super::super::run(&mut host, &Invocation::new(&["must-not-start"]), true).unwrap_err();
        assert_eq!(error.stage, "interrupted", "signal {signal}: {error:?}");
        assert!(host.calls.is_empty());
        drop(policy);
        assert!(!files.path("/usr/sbin/policy-rc.d").exists());
    }
}

#[test]
fn interruption_before_execution_starts_no_command_and_unwinds_the_restart_policy() {
    let output = std::process::Command::new(std::env::current_exe().unwrap())
        .args([
            "--ignored",
            "--exact",
            "commands::recipe::operations::tests::interruption_before_execution_helper",
        ])
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "{}{}",
        String::from_utf8_lossy(&output.stdout),
        String::from_utf8_lossy(&output.stderr)
    );
}

#[test]
#[cfg(target_os = "linux")]
fn service_termination_unwinds_the_temporary_package_restart_policy() {
    use std::os::unix::{net::UnixDatagram, process::CommandExt};

    struct Worker {
        child: std::process::Child,
        supervisor: Option<i32>,
    }
    impl Drop for Worker {
        fn drop(&mut self) {
            // Clean up owned processes even when readiness or a signal assertion fails.
            // SAFETY: these process groups belong to this test's fixtures.
            unsafe {
                if let Some(group) = self.supervisor.take() {
                    libc::kill(-group, libc::SIGKILL);
                }
                if self.child.try_wait().ok().flatten().is_none() {
                    libc::kill(-(self.child.id() as i32), libc::SIGKILL);
                }
            }
            let _ = self.child.wait();
        }
    }

    let work = WorkDir::new().unwrap();
    fs::create_dir_all(work.0.join("usr/sbin")).unwrap();
    let path = work.0.join("ready.sock");
    let ready = UnixDatagram::bind(&path).unwrap();
    ready
        .set_read_timeout(Some(Duration::from_secs(30)))
        .unwrap();
    let child = std::process::Command::new(std::env::current_exe().unwrap())
        .args([
            "--ignored",
            "--exact",
            "commands::recipe::operations::tests::interruption_helper",
        ])
        .env("ORBIT_RECIPE_INTERRUPT_TEST", &work.0)
        .env("ORBIT_RECIPE_INTERRUPT_SOCKET", &path)
        .process_group(0)
        .spawn()
        .unwrap();
    let mut worker = Worker {
        child,
        supervisor: None,
    };
    let mut message = [0; std::mem::size_of::<i32>()];
    let received = ready
        .recv(&mut message)
        .expect("the supervised child did not report readiness after exec");
    assert_eq!(received, message.len(), "invalid readiness message");
    let group = i32::from_ne_bytes(message);
    assert!(group > 0);
    assert_ne!(group, worker.child.id() as i32);
    worker.supervisor = Some(group);
    assert!(work.0.join("usr/sbin/policy-rc.d").exists());
    // GNU timeout owns a separate process group. Systemd kills both groups in the service cgroup.
    // SAFETY: readiness identifies the supervisor group owned by this test.
    unsafe {
        assert_eq!(libc::kill(-(worker.child.id() as i32), libc::SIGTERM), 0);
        assert_eq!(libc::kill(-group, libc::SIGTERM), 0);
    }
    let status = worker.child.wait().unwrap();
    worker.supervisor = None;
    assert!(status.success(), "interruption helper exited with {status}");
    assert!(!work.0.join("usr/sbin/policy-rc.d").exists());
}
