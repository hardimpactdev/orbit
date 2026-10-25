use super::*;
use crate::commands::recipe::WorkDir;
use std::os::unix::fs::MetadataExt;

fn scratch() -> PathBuf {
    let path = fs::canonicalize(std::env::temp_dir())
        .unwrap()
        .join(random_name("orbit-websocket-test"));
    fs::create_dir_all(&path).unwrap();
    path
}

/// A host whose Git, Composer, PHP, and systemd act on the sandbox as the real ones would.
struct Fake {
    account: Option<(u32, u32)>,
    /// The program, or `account` for a failed user switch, that fails.
    fail: Option<&'static str>,
    /// Whether systemd knows the unit.
    loaded: bool,
    stops: bool,
    starts: bool,
    listens: bool,
    calls: Vec<String>,
    systemctl: Vec<String>,
    verified_units: Vec<(PathBuf, String)>,
}

impl Default for Fake {
    fn default() -> Self {
        Self {
            account: Some((
                rustix::process::getuid().as_raw(),
                rustix::process::getgid().as_raw(),
            )),
            fail: None,
            loaded: false,
            stops: true,
            starts: true,
            listens: true,
            calls: vec![],
            systemctl: vec![],
            verified_units: vec![],
        }
    }
}

impl Host for Fake {
    fn account(&self, _: &str, _: &str) -> Option<(u32, u32)> {
        self.account
    }

    fn run(
        &mut self,
        user: Option<&str>,
        program: &str,
        arguments: &[&OsStr],
        environment: &[(&str, &str)],
    ) -> Result<(), Refused> {
        let arguments: Vec<&str> = arguments.iter().map(|a| a.to_str().unwrap()).collect();
        let mut call = format!(
            "{}{program}",
            user.map_or(String::new(), |u| format!("{u}: "))
        );
        for (key, value) in environment {
            call = format!("{key}={value} {call}");
        }
        self.calls.push(format!("{call} {}", arguments.join(" ")));
        if user.is_some() && self.fail == Some("account") {
            return Err(Refused::Account(
                "only root can run a command as orbit".into(),
            ));
        }
        let name = match (program, arguments.as_slice()) {
            ("git", ["clone", .., release]) => {
                fs::write(Path::new(release).join("artisan"), "<?php\n").unwrap();
                "clone"
            }
            ("git", ["-C", _, "fetch", ..]) => "fetch",
            ("git", ["-C", _, "checkout", ..]) => "checkout",
            ("composer", [.., directory]) => {
                let release = directory.strip_prefix("--working-dir=").unwrap();
                fs::create_dir(Path::new(release).join("vendor")).unwrap();
                "composer"
            }
            ("/usr/bin/php", [artisan, "orbit:reverb:start", "--help"]) => {
                assert!(Path::new(artisan).is_file());
                "artisan"
            }
            ("systemd-analyze", ["verify", unit]) => {
                let content = fs::read_to_string(unit).unwrap();
                self.verified_units.push((unit.into(), content));
                "systemd-analyze"
            }
            _ => panic!("unexpected program {program} {arguments:?}"),
        };
        if self.fail == Some(name) {
            return Err(Refused::Failed(format!("{name} failed")));
        }
        Ok(())
    }

    fn systemctl(&mut self, arguments: &[&str]) -> bool {
        self.systemctl.push(arguments.join(" "));
        match arguments {
            ["cat", _] | ["list-unit-files", ..] => self.loaded,
            ["stop", _] | ["disable", ..] => self.stops,
            ["daemon-reload"] | ["enable", ..] | ["reset-failed", ..] => true,
            ["restart", _] => {
                self.loaded = true;
                self.starts
            }
            _ => panic!("unexpected systemctl {arguments:?}"),
        }
    }

    fn listening(&mut self, _: u16) -> bool {
        self.listens
    }
}

struct Sandbox {
    _work: WorkDir,
    files: Files,
    host: Fake,
}

impl Sandbox {
    fn new() -> Self {
        let work = WorkDir::new().unwrap();
        let root = fs::canonicalize(&work.0).unwrap();
        fs::create_dir_all(root.join("etc/systemd/system")).unwrap();
        Self {
            files: Files {
                root,
                uid: rustix::process::getuid().as_raw(),
                gid: rustix::process::getgid().as_raw(),
            },
            host: Fake::default(),
            _work: work,
        }
    }

    fn path(&self, path: &str) -> PathBuf {
        self.files.path(path)
    }

    fn converge(&mut self, input: &ConvergeInput) -> Result<Converged, WebSocketError> {
        converge(&self.files, &mut self.host, input)
    }

    fn remove(&mut self, purge: bool) -> Result<Removed, WebSocketError> {
        remove(
            &self.files,
            &mut self.host,
            &RemoveInput {
                install_path: INSTALL.into(),
                purge,
            },
        )
    }

    fn current(&self) -> PathBuf {
        fs::read_link(self.path(INSTALL)).unwrap()
    }

    fn releases(&self) -> Vec<PathBuf> {
        let mut releases: Vec<PathBuf> = fs::read_dir(self.path(RELEASES))
            .unwrap()
            .map(|entry| entry.unwrap().path())
            .collect();
        releases.sort();
        releases
    }

    fn unit(&self) -> Option<String> {
        fs::read_to_string(self.path(UNIT)).ok()
    }
}

const INSTALL: &str = "/opt/orbit/websocket";
const RELEASES: &str = "/opt/orbit/websocket.releases";

fn input() -> ConvergeInput {
    ConvergeInput {
        user: "orbit".into(),
        group: "orbit".into(),
        install_path: INSTALL.into(),
        repository: "https://example.test/reverb.git".into(),
        reference: "0123456789abcdef".into(),
        port: 8790,
        environment: "REVERB_APP_SECRET=secret\n".into(),
        unit: "[Service]\nExecStart=/usr/bin/php artisan orbit:reverb:start\n".into(),
    }
}

#[test]
fn converges_a_fresh_install_and_starts_the_service() {
    let mut sandbox = Sandbox::new();
    let converged = sandbox.converge(&input()).unwrap();
    let release = PathBuf::from(&converged.release);

    assert_eq!(sandbox.current(), release);
    assert_eq!(release.parent().unwrap(), sandbox.path(RELEASES));
    assert!(release
        .file_name()
        .unwrap()
        .to_str()
        .unwrap()
        .starts_with("release."));
    let environment = fs::metadata(release.join(".env")).unwrap();
    assert_eq!(environment.mode() & 0o777, 0o600);
    assert_eq!(
        fs::read_to_string(release.join(".env")).unwrap(),
        "REVERB_APP_SECRET=secret\n"
    );
    assert!(release.join("vendor").is_dir());
    assert_eq!(sandbox.unit().as_deref(), Some(input().unit.as_str()));
    assert_eq!(
        fs::metadata(sandbox.path(UNIT)).unwrap().mode() & 0o777,
        0o644
    );

    let path = release.display();
    assert_eq!(
        sandbox.host.calls[..5],
        [
            format!("orbit: git clone --quiet --no-checkout -- https://example.test/reverb.git {path}"),
            format!("orbit: git -C {path} fetch --quiet origin 0123456789abcdef"),
            format!("orbit: git -C {path} checkout --quiet --detach FETCH_HEAD"),
            format!("COMPOSER_ALLOW_SUPERUSER=1 orbit: composer install --no-dev --optimize-autoloader --no-interaction --no-progress --working-dir={path}"),
            format!("orbit: /usr/bin/php {path}/artisan orbit:reverb:start --help"),
        ]
    );
    assert_eq!(sandbox.host.verified_units.len(), 1);
    let (unit, content) = &sandbox.host.verified_units[0];
    assert_eq!(content, &input().unit);
    assert!(!unit.exists(), "the verify directory is removed");
    assert_eq!(
        sandbox.host.systemctl,
        [
            "cat orbit-websocket.service",
            "daemon-reload",
            "enable --quiet orbit-websocket",
            "restart orbit-websocket"
        ]
    );
}

#[test]
fn a_repeated_converge_stops_first_keeps_the_previous_release_and_prunes_older_ones() {
    let mut sandbox = Sandbox::new();
    let first = PathBuf::from(sandbox.converge(&input()).unwrap().release);
    sandbox.host.systemctl.clear();
    let second = PathBuf::from(sandbox.converge(&input()).unwrap().release);
    assert_eq!(
        sandbox.host.systemctl[..2],
        ["cat orbit-websocket.service", "stop orbit-websocket"]
    );
    assert_eq!(sandbox.current(), second);
    let mut kept = vec![first.clone(), second.clone()];
    kept.sort();
    assert_eq!(sandbox.releases(), kept);

    let third = PathBuf::from(sandbox.converge(&input()).unwrap().release);
    let mut kept = vec![second, third.clone()];
    kept.sort();
    assert_eq!(sandbox.releases(), kept);
    assert!(!first.exists());
    assert_eq!(sandbox.current(), third);
}

#[test]
fn the_previous_release_is_kept_below_a_symlinked_parent() {
    let mut sandbox = Sandbox::new();
    let real = sandbox.path("/fast/opt");
    fs::create_dir_all(&real).unwrap();
    symlink(&real, sandbox.path("/opt")).unwrap();
    let first = PathBuf::from(sandbox.converge(&input()).unwrap().release);
    let second = PathBuf::from(sandbox.converge(&input()).unwrap().release);
    let mut kept = vec![first, second];
    kept.sort();
    assert_eq!(sandbox.releases(), kept);
}

#[test]
fn an_install_directory_from_an_earlier_layout_is_kept_as_the_previous_release() {
    let mut sandbox = Sandbox::new();
    fs::create_dir_all(sandbox.path(INSTALL)).unwrap();
    fs::write(sandbox.path(INSTALL).join("marker"), "old").unwrap();
    let release = PathBuf::from(sandbox.converge(&input()).unwrap().release);
    assert_eq!(sandbox.current(), release);
    let previous: Vec<PathBuf> = sandbox
        .releases()
        .into_iter()
        .filter(|path| path != &release)
        .collect();
    assert_eq!(previous.len(), 1);
    assert!(previous[0]
        .file_name()
        .unwrap()
        .to_str()
        .unwrap()
        .starts_with("previous."));
    assert_eq!(
        fs::read_to_string(previous[0].join("marker")).unwrap(),
        "old"
    );
}

#[test]
fn a_failed_preparation_removes_its_release_and_leaves_the_running_one() {
    for step in [
        "clone",
        "fetch",
        "checkout",
        "composer",
        "artisan",
        "systemd-analyze",
    ] {
        let mut sandbox = Sandbox::new();
        let running = PathBuf::from(sandbox.converge(&input()).unwrap().release);
        let unit = sandbox.unit();
        sandbox.host.fail = Some(step);
        sandbox.host.systemctl.clear();
        let mut changed = input();
        changed.unit = "[Service]\nExecStart=/bin/false\n".into();

        let expected = if step == "systemd-analyze" {
            "unit"
        } else {
            step
        };
        assert_eq!(
            sandbox.converge(&changed),
            Err(failed(expected, format!("{step} failed"))),
            "{step}"
        );
        assert_eq!(sandbox.current(), running, "{step}");
        assert_eq!(sandbox.releases(), [running], "{step}");
        assert_eq!(sandbox.unit(), unit, "{step}");
        assert!(sandbox.host.systemctl.is_empty(), "{step}");
    }
}

#[test]
fn a_failed_user_switch_is_an_account_failure() {
    let mut sandbox = Sandbox::new();
    sandbox.host.fail = Some("account");
    assert_eq!(
        sandbox.converge(&input()),
        Err(failed("account", "only root can run a command as orbit"))
    );
    assert!(sandbox.releases().is_empty());
    assert!(fs::symlink_metadata(sandbox.path(INSTALL)).is_err());
}

#[test]
fn a_service_that_does_not_stop_keeps_the_running_release() {
    let mut sandbox = Sandbox::new();
    let running = PathBuf::from(sandbox.converge(&input()).unwrap().release);
    sandbox.host.stops = false;
    assert_eq!(
        sandbox.converge(&input()),
        Err(failed("stop", "the running service did not stop"))
    );
    assert_eq!(sandbox.current(), running);
    assert_eq!(sandbox.releases(), [running]);
}

#[test]
fn a_failed_restart_or_listener_keeps_every_release_for_forward_recovery() {
    let mut sandbox = Sandbox::new();
    sandbox.converge(&input()).unwrap();
    sandbox.converge(&input()).unwrap();
    sandbox.host.starts = false;
    assert_eq!(
        sandbox.converge(&input()),
        Err(failed("restart", "systemd did not start the service"))
    );
    assert_eq!(sandbox.releases().len(), 3);
    assert!(sandbox.releases().contains(&sandbox.current()));

    sandbox.host.starts = true;
    sandbox.host.listens = false;
    assert_eq!(
        sandbox.converge(&input()),
        Err(WebSocketError::NotListening { port: 8790 })
    );
    assert_eq!(sandbox.releases().len(), 4);
}

#[test]
fn refuses_a_relative_path_or_unknown_account_before_any_change() {
    let mut sandbox = Sandbox::new();
    for path in ["opt/orbit/websocket", "/"] {
        let mut relative = input();
        relative.install_path = path.into();
        assert_eq!(
            sandbox.converge(&relative),
            Err(WebSocketError::Invalid {
                check: "the install path is absolute".into()
            })
        );
    }
    sandbox.host.account = None;
    assert_eq!(
        sandbox.converge(&input()),
        Err(WebSocketError::Invalid {
            check: "the managed account exists".into()
        })
    );
    assert!(!sandbox.path("/opt").exists());
    assert!(sandbox.host.calls.is_empty());
    assert!(sandbox.host.systemctl.is_empty());
    assert_eq!(
        remove(
            &sandbox.files,
            &mut sandbox.host,
            &RemoveInput {
                install_path: "relative".into(),
                purge: true
            }
        ),
        Err(WebSocketError::Invalid {
            check: "the install path is absolute".into()
        })
    );
    assert!(sandbox.host.systemctl.is_empty());
}

#[test]
fn removal_stops_the_service_and_deletes_the_unit_but_keeps_releases_until_purged() {
    let mut sandbox = Sandbox::new();
    let release = PathBuf::from(sandbox.converge(&input()).unwrap().release);
    sandbox.host.systemctl.clear();

    assert_eq!(sandbox.remove(false), Ok(Removed {}));
    assert_eq!(
        sandbox.host.systemctl,
        [
            "list-unit-files -- orbit-websocket.service",
            "disable --now -- orbit-websocket",
            "daemon-reload",
            "reset-failed -- orbit-websocket.service"
        ]
    );
    assert_eq!(sandbox.unit(), None);
    assert_eq!(sandbox.current(), release);

    sandbox.host.loaded = false;
    sandbox.host.systemctl.clear();
    assert_eq!(sandbox.remove(true), Ok(Removed {}));
    assert!(!sandbox
        .host
        .systemctl
        .iter()
        .any(|call| call.starts_with("disable")));
    assert!(fs::symlink_metadata(sandbox.path(INSTALL)).is_err());
    assert!(!sandbox.path(RELEASES).exists());
    assert_eq!(sandbox.remove(true), Ok(Removed {}));
}

#[test]
fn purge_removes_an_install_directory_from_an_earlier_layout() {
    let mut sandbox = Sandbox::new();
    fs::create_dir_all(sandbox.path(INSTALL).join("vendor")).unwrap();
    assert_eq!(sandbox.remove(true), Ok(Removed {}));
    assert!(!sandbox.path(INSTALL).exists());
}

#[test]
fn removal_refuses_when_the_service_does_not_stop_and_keeps_the_unit() {
    let mut sandbox = Sandbox::new();
    sandbox.converge(&input()).unwrap();
    sandbox.host.stops = false;
    assert_eq!(
        sandbox.remove(true),
        Err(failed("disable", "the service did not stop"))
    );
    assert!(sandbox.unit().is_some());
    assert!(sandbox.path(INSTALL).exists());
}

#[test]
fn prune_keeps_only_the_new_and_previous_releases() {
    let releases = scratch();
    for name in [
        "release.a",
        "release.b",
        "previous.c",
        "release.new",
        "other",
    ] {
        fs::create_dir_all(releases.join(name)).unwrap();
    }

    prune(
        &releases,
        &[&releases.join("release.new"), &releases.join("previous.c")],
    );

    let mut left: Vec<String> = fs::read_dir(&releases)
        .unwrap()
        .flatten()
        .map(|entry| entry.file_name().to_string_lossy().into_owned())
        .collect();
    left.sort();
    assert_eq!(left, ["other", "previous.c", "release.new"]);
    let _ = fs::remove_dir_all(&releases);
}

#[test]
fn waits_for_a_listener_and_gives_up_after_its_wait() {
    // Other tests fork while holding their own fixtures. Keep this listener in a separate process
    // so those children cannot keep its socket open after this test closes it.
    let output = Process::new(std::env::current_exe().unwrap())
        .args([
            "--ignored",
            "--exact",
            "commands::websocket_runtime::tests::listener_wait_helper",
            "--nocapture",
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
#[ignore = "runs in its own process from the listener wait test"]
fn listener_wait_helper() {
    let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
    let port = listener.local_addr().unwrap().port();
    assert!(listening(port, Duration::from_secs(1)));
    drop(listener);
    assert!(!listening(port, Duration::from_millis(200)));
}

#[test]
fn writes_a_file_with_its_mode_through_one_rename() {
    let base = scratch();
    let path = base.join("unit");
    write_file(&path, b"one", 0o600).unwrap();
    write_file(&path, b"two", 0o600).unwrap();
    assert_eq!(fs::read_to_string(&path).unwrap(), "two");
    assert_eq!(
        fs::metadata(&path).unwrap().permissions().mode() & 0o777,
        0o600
    );
    assert_eq!(fs::read_dir(&base).unwrap().count(), 1);
    let _ = fs::remove_dir_all(&base);
}
