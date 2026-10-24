use super::*;
use recipe::{Failure, Output as ProcessOutput};
use std::{cell::RefCell, os::unix::fs::symlink, rc::Rc};

#[derive(Default)]
struct State {
    events: Vec<Vec<String>>,
    home: PathBuf,
    uid: u32,
    gid: u32,
    exists: bool,
    sudo: bool,
    healthy: bool,
    dns: String,
    domain: String,
    uris: String,
    fail: String,
}
#[derive(Clone)]
struct Fake(Rc<RefCell<State>>);
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> Result<ProcessOutput, Failure> {
        let mut s = self.0.borrow_mut();
        s.events.push(call.argv.clone());
        let a: Vec<_> = call.argv.iter().map(String::as_str).collect();
        let mut code = 0;
        let mut stdout = String::new();
        match a[0] {
            "/usr/bin/getent" if a[1] == "passwd" => {
                if s.exists {
                    stdout = format!(
                        "{}:x:{}:{}::{}:/bin/bash\n",
                        a[2],
                        s.uid,
                        s.gid,
                        s.home.display()
                    );
                } else {
                    code = 2;
                }
            }
            "/usr/bin/getent" => {
                code = i32::from(!s.healthy && !s.dns.is_empty() || s.fail == "underlay");
            }
            "/usr/bin/apt-get" if a[1] == "--print-uris" => {
                stdout = s.uris.clone();
                if s.fail == "sources" {
                    code = 1;
                }
            }
            "/usr/bin/apt-get" if a.last() == Some(&"update") => {
                code = i32::from(!s.healthy && !s.dns.is_empty() || s.fail == "update");
            }
            "/usr/bin/apt-get" => {
                if s.fail == "install" {
                    code = 1;
                }
                if s.fail == "timeout" {
                    code = 124;
                }
            }
            "/usr/bin/resolvectl" => {
                let property = a[1];
                if a.len() == 3 {
                    stdout = format!(
                        "Link 5 (orbit): {}\n",
                        if property == "dns" { &s.dns } else { &s.domain }
                    );
                } else {
                    let action = if a[3].is_empty() { "clear" } else { "restore" };
                    if s.fail == format!("{action}-{property}") {
                        code = 1;
                    } else if property == "dns" {
                        s.dns = a[3].into();
                    } else {
                        s.domain = a[3].into();
                    }
                }
            }
            "/usr/sbin/useradd" => {
                if s.fail == "useradd" {
                    code = 1;
                } else {
                    s.exists = true;
                    fs::create_dir_all(&s.home).unwrap();
                }
            }
            "/usr/bin/sudo" => {
                code = i32::from(!s.sudo);
            }
            "/usr/sbin/visudo" => {
                assert!(fs::read_to_string(a[2])
                    .unwrap()
                    .ends_with(" ALL=(ALL) NOPASSWD:ALL\n"));
                if s.fail == "visudo" {
                    code = 1;
                }
            }
            _ => panic!("unexpected {a:?}"),
        }
        Ok(ProcessOutput {
            code,
            stdout,
            ..Default::default()
        })
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("no wait");
    }
}
fn fixture() -> (WorkDir, Files, Fake, Input) {
    let work = WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: rustix::process::getuid().as_raw(),
        gid: rustix::process::getgid().as_raw(),
    };
    for path in ["/etc/wireguard", "/etc/sudoers.d", "/run/lock"] {
        fs::create_dir_all(files.path(path)).unwrap();
    }
    fs::write(
        files.path("/etc/os-release"),
        "ID=ubuntu\nVERSION_CODENAME=resolute\n",
    )
    .unwrap();
    files
        .publish(
            &files.path("/etc/wireguard/orbit.dns-link"),
            b"orbit\n10.44.0.1\n.\n",
            0o600,
            files.uid,
            files.gid,
            false,
        )
        .unwrap();
    let host = Fake(Rc::new(RefCell::new(State {
        home: files.path("/home/nckrtl"),
        uid: files.uid,
        gid: files.gid,
        dns: "10.44.0.1".into(),
        domain: "~.".into(),
        uris: "'https://archive.example.test/path' metadata 0\n".into(),
        ..Default::default()
    })));
    let input = Input {
        user: "nckrtl".into(),
        public_key: "ssh-ed25519 GATEWAY".into(),
        releases: vec!["resolute".into()],
        packages: vec!["sudo".into(), "wireguard".into()],
        dns_policy: DnsPolicy::Managed,
    };
    (work, files, host, input)
}
fn apply(files: &Files, host: &mut Fake, input: &Input) -> Result<Output, Error> {
    bootstrap(
        files,
        host,
        &mut host.clone(),
        input,
        Instant::now() + Duration::from_secs(5),
    )
}
fn mutations(s: &State) -> Vec<&Vec<String>> {
    s.events
        .iter()
        .filter(|a| a[0] != "/usr/bin/getent" && !(a[0] == "/usr/bin/resolvectl" && a.len() == 3))
        .collect()
}
#[test]
fn restores_owned_dns_before_account_creation_and_repeats_without_duplicate_keys() {
    let (_w, files, mut host, input) = fixture();
    apply(&files, &mut host, &input).unwrap();
    let home = host.0.borrow().home.clone();
    for dir in [".ssh", ".orbit"] {
        assert_eq!(fs::metadata(home.join(dir)).unwrap().mode() & 0o777, 0o700);
    }
    let keys = home.join(".ssh/authorized_keys");
    assert_eq!(fs::read_to_string(&keys).unwrap(), "ssh-ed25519 GATEWAY\n");
    assert_eq!(fs::metadata(&keys).unwrap().mode() & 0o777, 0o600);
    let sudoers = files.path("/etc/sudoers.d/nckrtl");
    assert_eq!(
        fs::read_to_string(&sudoers).unwrap(),
        "nckrtl ALL=(ALL) NOPASSWD:ALL\n"
    );
    assert_eq!(fs::metadata(&sudoers).unwrap().mode() & 0o777, 0o440);
    {
        let s = host.0.borrow();
        let create = s
            .events
            .iter()
            .position(|a| a[0] == "/usr/sbin/useradd")
            .unwrap();
        assert_eq!(
            s.events[create - 1],
            ["/usr/bin/resolvectl", "domain", "orbit", "~."]
        );
        assert_eq!((&*s.dns, &*s.domain), ("10.44.0.1", "~."));
    }
    host.0.borrow_mut().sudo = true;
    host.0.borrow_mut().events.clear();
    apply(&files, &mut host, &input).unwrap();
    assert_eq!(fs::read_to_string(keys).unwrap(), "ssh-ed25519 GATEWAY\n");
    assert!(!host
        .0
        .borrow()
        .events
        .iter()
        .any(|a| ["/usr/sbin/useradd", "/usr/sbin/visudo"].contains(&a[0].as_str())));
}
#[test]
fn healthy_apt_bypasses_local_dns_and_source_transport_diagnosis() {
    let (_w, files, mut host, mut input) = fixture();
    host.0.borrow_mut().healthy = true;
    host.0.borrow_mut().uris = "'mirror+https://example/path' 0".into();
    input.dns_policy = DnsPolicy::Preserve;
    fs::remove_file(files.path("/etc/wireguard/orbit.dns-link")).unwrap();
    apply(&files, &mut host, &input).unwrap();
    assert!(!host
        .0
        .borrow()
        .events
        .iter()
        .any(|a| a[0] == "/usr/bin/resolvectl" || a[1] == "ahosts" || a[1] == "--print-uris"));
}
#[test]
fn validates_request_and_os_without_remote_effects_or_executing_release_content() {
    for release in [
        "ID=debian\nVERSION_CODENAME=resolute",
        "ID=ubuntu\nVERSION_CODENAME=unsupported",
        "ID=ubuntu\nID=ubuntu\nVERSION_CODENAME=resolute",
        "ID=ubuntu\nVERSION_CODENAME=$(touch injected)",
        "VERSION_CODENAME=resolute",
        "ID=ubuntu\nVERSION_CODENAME='resolute",
        "",
    ] {
        let (_w, files, mut host, input) = fixture();
        fs::write(files.path("/etc/os-release"), release).unwrap();
        assert!(matches!(
            apply(&files, &mut host, &input),
            Err(Error::Unsupported { .. })
        ));
        assert!(host.0.borrow().events.is_empty());
    }
    let (_w, files, mut host, mut input) = fixture();
    input.public_key.push('\n');
    assert_eq!(
        apply(&files, &mut host, &input).unwrap_err(),
        Error::Invalid {}
    );
    assert!(host.0.borrow().events.is_empty());
    for user in ["root", "-x", "user;id", "a/b", "", "USER"] {
        input.public_key = "key".into();
        input.user = user.into();
        assert_eq!(
            apply(&files, &mut host, &input).unwrap_err(),
            Error::Invalid {}
        );
    }
    input.user = "orbit".into();
    input.packages = vec!["--allow-unauthenticated".into()];
    assert_eq!(
        apply(&files, &mut host, &input).unwrap_err(),
        Error::Invalid {}
    );
}
#[test]
fn refuses_unowned_or_changed_dns_before_resolver_writes() {
    for case in [
        "missing",
        "link",
        "dir-link",
        "mode",
        "owner",
        "other-link",
        "split",
        "injection",
        "extra",
        "server",
        "servers",
        "domains",
        "preserve",
    ] {
        let (_w, files, mut host, mut input) = fixture();
        let marker = files.path("/etc/wireguard/orbit.dns-link");
        match case {
            "missing" => fs::remove_file(&marker).unwrap(),
            "link" => {
                fs::rename(&marker, marker.with_extension("save")).unwrap();
                symlink(marker.with_extension("save"), &marker).unwrap();
            }
            "dir-link" => {
                fs::rename(files.path("/etc/wireguard"), files.path("/etc/real")).unwrap();
                symlink("real", files.path("/etc/wireguard")).unwrap();
            }
            "mode" => fs::set_permissions(&marker, fs::Permissions::from_mode(0o666)).unwrap(),
            "owner" => {
                let different = Files {
                    root: files.root.clone(),
                    uid: files.uid + 1,
                    gid: files.gid,
                };
                assert!(matches!(
                    apply(&different, &mut host, &input),
                    Err(Error::Dns { .. })
                ));
                continue;
            }
            "other-link" => fs::write(&marker, "eth0\n10.44.0.1\n.\n").unwrap(),
            "split" => fs::write(&marker, "orbit\n10.44.0.1\nexample.test\n").unwrap(),
            "injection" => fs::write(&marker, "orbit\n$(touch injected)\n.\n").unwrap(),
            "extra" => fs::write(&marker, "orbit\n10.44.0.1\n.\nextra\n").unwrap(),
            "server" => host.0.borrow_mut().dns = "192.0.2.1".into(),
            "servers" => host.0.borrow_mut().dns = "10.44.0.1 192.0.2.1".into(),
            "domains" => host.0.borrow_mut().domain = "~. extra".into(),
            "preserve" => input.dns_policy = DnsPolicy::Preserve,
            _ => unreachable!(),
        }
        assert!(
            matches!(apply(&files, &mut host, &input), Err(Error::Dns { .. })),
            "{case}"
        );
        let s = host.0.borrow();
        assert!(!s.exists);
        assert!(
            !s.events
                .iter()
                .any(|a| a[0] == "/usr/bin/resolvectl" && a.len() == 4),
            "{case}"
        );
    }
}
#[test]
fn restores_both_properties_on_each_failure_and_never_creates_the_account() {
    for failure in [
        "clear-dns",
        "clear-domain",
        "underlay",
        "update",
        "install",
        "timeout",
        "restore-dns",
        "restore-domain",
    ] {
        let (_w, files, mut host, input) = fixture();
        host.0.borrow_mut().fail = failure.into();
        let error = apply(&files, &mut host, &input).unwrap_err();
        if failure.starts_with("restore") {
            assert_eq!(error, Error::Recovery {});
        }
        let s = host.0.borrow();
        assert!(!s.exists, "{failure}");
        let tail = &s.events[s.events.len() - 2..];
        assert_eq!(
            tail[0],
            ["/usr/bin/resolvectl", "dns", "orbit", "10.44.0.1"]
        );
        assert_eq!(tail[1], ["/usr/bin/resolvectl", "domain", "orbit", "~."]);
        if !failure.starts_with("restore") {
            assert_eq!((&*s.dns, &*s.domain), ("10.44.0.1", "~."));
        }
    }
}
#[test]
fn parses_source_hosts_and_rejects_unknown_transports_and_injection() {
    assert_eq!(source_hosts("'https://user:secret@archive.example.test:443/p' 0\n'http://[2001:db8::1]/p' 0\n'file:/local' 0\n'copy:/local' 0\n'cdrom:test' 0\n'http://archive.example.test/other' 0").unwrap(), ["archive.example.test", "2001:db8::1"]);
    for source in [
        "'mirror+https://archive.example.test/p' 0",
        "'https://$(touch injected)/p' 0",
    ] {
        let (_w, files, mut host, input) = fixture();
        host.0.borrow_mut().uris = source.into();
        assert!(matches!(
            apply(&files, &mut host, &input),
            Err(Error::Dns { .. })
        ));
        assert!(!host
            .0
            .borrow()
            .events
            .iter()
            .any(|a| a[0] == "/usr/bin/resolvectl"));
    }
    let (_w, files, mut host, input) = fixture();
    host.0.borrow_mut().fail = "sources".into();
    assert_eq!(
        apply(&files, &mut host, &input).unwrap_err(),
        failed("package-sources")
    );
}
#[test]
fn preserves_existing_directory_modes_and_keys_and_refuses_linked_paths_before_packages() {
    for bad in ["", ".ssh", ".orbit", ".ssh/authorized_keys"] {
        let (_w, files, mut host, input) = fixture();
        let home = host.0.borrow().home.clone();
        fs::create_dir_all(home.join(".ssh")).unwrap();
        fs::create_dir_all(home.join(".orbit")).unwrap();
        host.0.borrow_mut().exists = true;
        let path = if bad.is_empty() {
            home.clone()
        } else {
            home.join(bad)
        };
        if path.is_dir() {
            fs::remove_dir_all(&path).unwrap();
        }
        symlink(files.path("/etc"), path).unwrap();
        assert!(matches!(
            apply(&files, &mut host, &input),
            Err(Error::Unsafe { .. })
        ));
        assert!(mutations(&host.0.borrow()).is_empty());
    }
    let (_w, files, mut host, input) = fixture();
    let home = host.0.borrow().home.clone();
    fs::create_dir_all(home.join(".ssh")).unwrap();
    fs::create_dir_all(home.join(".orbit")).unwrap();
    for p in [&home, &home.join(".ssh"), &home.join(".orbit")] {
        fs::set_permissions(p, fs::Permissions::from_mode(0o751)).unwrap();
    }
    fs::write(home.join(".ssh/authorized_keys"), "unrelated key").unwrap();
    host.0.borrow_mut().exists = true;
    apply(&files, &mut host, &input).unwrap();
    for p in [&home, &home.join(".ssh"), &home.join(".orbit")] {
        assert_eq!(fs::metadata(p).unwrap().mode() & 0o777, 0o751);
    }
    assert_eq!(
        fs::read_to_string(home.join(".ssh/authorized_keys")).unwrap(),
        "unrelated key\nssh-ed25519 GATEWAY\n"
    );
}
#[test]
fn validates_sudo_before_publishing_access_and_reports_account_creation_failure() {
    for failure in ["useradd", "visudo"] {
        let (_w, files, mut host, input) = fixture();
        host.0.borrow_mut().fail = failure.into();
        assert!(matches!(
            apply(&files, &mut host, &input),
            Err(Error::Failed { .. })
        ));
        assert!(!host.0.borrow().home.join(".ssh/authorized_keys").exists());
        assert!(!files.path("/etc/sudoers.d/nckrtl").exists());
    }
}
#[test]
fn peer_lock_blocks_before_package_changes() {
    let (_w, files, mut host, input) = fixture();
    let deadline = Instant::now();
    let _held =
        lock::acquire(&files.path("/run/lock/orbit-wireguard-peer.lock"), deadline).unwrap();
    let mut recovery = host.clone();
    assert_eq!(
        bootstrap(&files, &mut host, &mut recovery, &input, deadline).unwrap_err(),
        failed("peer-lock")
    );
    assert!(mutations(&host.0.borrow()).is_empty());
}

#[test]
fn holds_the_actual_peer_lock_through_both_restoration_calls() {
    struct Recovery {
        fake: Fake,
        path: PathBuf,
        calls: usize,
    }
    impl Host for Recovery {
        fn execute(&mut self, call: &Invocation) -> Result<ProcessOutput, Failure> {
            assert!(matches!(
                lock::acquire(&self.path, Instant::now()),
                Err(lock::LockError::Busy(_))
            ));
            self.calls += 1;
            self.fake.execute(call)
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            unreachable!()
        }
    }
    let (_w, files, mut host, input) = fixture();
    let path = files.path("/run/lock/orbit-wireguard-peer.lock");
    let mut recovery = Recovery {
        fake: host.clone(),
        path: path.clone(),
        calls: 0,
    };
    bootstrap(
        &files,
        &mut host,
        &mut recovery,
        &input,
        Instant::now() + Duration::from_secs(5),
    )
    .unwrap();
    assert_eq!(recovery.calls, 2);
    assert!(lock::acquire(&path, Instant::now()).is_ok());
}

#[test]
#[ignore = "subprocess helper installs process-wide signal handlers"]
fn interrupted_bootstrap_helper() {
    struct Interrupt(Fake);
    impl Host for Interrupt {
        fn execute(&mut self, call: &Invocation) -> Result<ProcessOutput, Failure> {
            let result = self.0.execute(call);
            if call.argv.iter().any(|s| s == "install") {
                let signal = std::env::var("BOOTSTRAP_TEST_SIGNAL")
                    .unwrap()
                    .parse::<i32>()
                    .unwrap();
                // SAFETY: this isolated test process has installed the signal guard.
                assert_eq!(unsafe { libc::raise(signal) }, 0);
            }
            result
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            unreachable!()
        }
    }
    let (_w, files, host, input) = fixture();
    let _guard = recipe::interruption::Guard::install().unwrap();
    let mut recovery = host.clone();
    let mut interrupted = Interrupt(host.clone());
    assert!(bootstrap(
        &files,
        &mut interrupted,
        &mut recovery,
        &input,
        Instant::now() + Duration::from_secs(5)
    )
    .is_err());
    let s = host.0.borrow();
    assert_eq!((&*s.dns, &*s.domain), ("10.44.0.1", "~."));
    assert!(!s.exists);
}

#[test]
fn restores_dns_after_hangup_interrupt_and_termination() {
    for signal in [libc::SIGHUP, libc::SIGINT, libc::SIGTERM] {
        let output = std::process::Command::new(std::env::current_exe().unwrap())
            .args([
                "--exact",
                "commands::node_bootstrap::tests::interrupted_bootstrap_helper",
                "--ignored",
                "--nocapture",
            ])
            .env("BOOTSTRAP_TEST_SIGNAL", signal.to_string())
            .output()
            .unwrap();
        assert!(
            output.status.success(),
            "{}\n{}",
            String::from_utf8_lossy(&output.stdout),
            String::from_utf8_lossy(&output.stderr)
        );
    }
}
