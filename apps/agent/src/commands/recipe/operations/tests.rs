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
#[ignore]
fn interruption_helper() {
    let Some(root) = std::env::var_os("ORBIT_RECIPE_INTERRUPT_TEST") else {
        return;
    };
    let files = Files {
        root: root.into(),
        uid: rustix::process::getuid().as_raw(),
        gid: rustix::process::getgid().as_raw(),
    };
    let _signals = interruption::Guard::install().unwrap();
    let _policy = Policy::install(&files).unwrap();
    fs::write(files.root.join("ready"), "").unwrap();
    let context = Context::new(Platform::Linux, Instant::now() + Duration::from_secs(10));
    let mut host = Native(&context);
    let error =
        super::super::run(&mut host, &Invocation::new(&["/bin/sleep", "5"]), true).unwrap_err();
    assert_eq!(error.stage, "interrupted");
}
#[test]
#[cfg(target_os = "linux")]
fn service_termination_unwinds_the_temporary_package_restart_policy() {
    use std::os::unix::process::CommandExt;
    let work = WorkDir::new().unwrap();
    fs::create_dir_all(work.0.join("usr/sbin")).unwrap();
    let mut child = std::process::Command::new(std::env::current_exe().unwrap())
        .args([
            "--ignored",
            "--exact",
            "commands::recipe::operations::tests::interruption_helper",
        ])
        .env("ORBIT_RECIPE_INTERRUPT_TEST", &work.0)
        .process_group(0)
        .spawn()
        .unwrap();
    let deadline = Instant::now() + Duration::from_secs(5);
    while !work.0.join("ready").exists() && Instant::now() < deadline {
        std::thread::sleep(Duration::from_millis(10));
    }
    assert!(work.0.join("ready").exists());
    // Wait for exec, so the signal exercises termination of both the worker and its child.
    fn children(pid: u32) -> Vec<u32> {
        fs::read_dir(format!("/proc/{pid}/task"))
            .unwrap()
            .filter_map(Result::ok)
            .flat_map(|task| {
                fs::read_to_string(task.path().join("children"))
                    .unwrap_or_default()
                    .split_whitespace()
                    .filter_map(|pid| pid.parse().ok())
                    .collect::<Vec<_>>()
            })
            .collect()
    }
    let mut running = None;
    while Instant::now() < deadline {
        running = children(child.id()).into_iter().find(|pid| {
            children(*pid).into_iter().any(|descendant| {
                fs::read_to_string(format!("/proc/{descendant}/comm"))
                    .is_ok_and(|name| name.trim() == "sleep")
            })
        });
        if running.is_some() {
            break;
        }
        std::thread::sleep(Duration::from_millis(10));
    }
    // GNU timeout owns a separate process group. Systemd kills both groups in the service cgroup.
    // SAFETY: both process groups belong to this test's child processes.
    unsafe {
        libc::kill(-(child.id() as i32), libc::SIGTERM);
        if let Some(group) = running {
            libc::kill(-(group as i32), libc::SIGTERM);
        }
    }
    assert!(child.wait().unwrap().success());
    assert!(running.is_some(), "the supervised child did not start");
    assert!(!work.0.join("usr/sbin/policy-rc.d").exists());
}
