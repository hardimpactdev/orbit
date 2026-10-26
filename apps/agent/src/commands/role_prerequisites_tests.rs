use super::*;
use crate::commands::recipe::WorkDir;

fn me() -> (u32, u32) {
    (
        rustix::process::getuid().as_raw(),
        rustix::process::getgid().as_raw(),
    )
}

/// A host whose programs act on the sandbox the way the real ones act on the Node.
struct Fake {
    account: Option<(u32, u32)>,
    docker_ce: bool,
    /// Where the Vite+ installer puts `vp` when it runs without `VP_HOME`.
    /// Whether `vp install -g pnpm` leaves a `pnpm`.
    /// A command whose joined arguments contain this text fails.
    fail: Option<String>,
    calls: Vec<String>,
    environments: Vec<Vec<(String, String)>>,
}

impl Fake {
    fn act(&self, _arguments: &[&str]) -> bool {
        true
    }
}

impl Host for Fake {
    fn account(&self, _: &str, _: &str) -> Option<(u32, u32)> {
        self.account
    }

    fn run(
        &mut self,
        program: &str,
        arguments: &[&str],
        environment: &[(&str, &str)],
    ) -> io::Result<Ran> {
        let call = format!("{program} {}", arguments.join(" "));
        self.calls.push(call.clone());
        self.environments.push(
            environment
                .iter()
                .map(|(k, v)| ((*k).into(), (*v).into()))
                .collect(),
        );
        let fail = |stderr: &str| {
            Ok(Ran {
                success: false,
                stdout: String::new(),
                stderr: stderr.into(),
            })
        };
        if self.fail.as_ref().is_some_and(|text| call.contains(text)) {
            return fail("injected failure");
        }
        let success = match (program, arguments) {
            ("dpkg-query", _) => {
                return Ok(Ran {
                    success: self.docker_ce,
                    stdout: if self.docker_ce {
                        "install ok installed".into()
                    } else {
                        String::new()
                    },
                    stderr: String::new(),
                })
            }
            ("systemctl", ["is-active", "--quiet", "docker"]) => self.docker_ce,
            ("sudo", ["-u", "orbit", "-H", rest @ ..]) => self.act(rest),
            ("apt-get" | "chown" | "chmod", _) => true,
            _ => panic!("unexpected program {call}"),
        };
        if success {
            Ok(Ran {
                success,
                stdout: String::new(),
                stderr: String::new(),
            })
        } else {
            fail("not installed")
        }
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
        let (uid, gid) = me();
        for directory in ["etc", "opt", "usr/bin", "usr/local/bin", "home/orbit"] {
            fs::create_dir_all(root.join(directory)).unwrap();
        }
        fs::write(
            root.join("etc/os-release"),
            "ID=ubuntu\nVERSION_CODENAME=noble\n",
        )
        .unwrap();
        Self {
            files: Files {
                root: root.clone(),
                uid,
                gid,
            },
            host: Fake {
                account: Some((uid, gid)),
                docker_ce: false,
                fail: None,
                calls: vec![],
                environments: vec![],
            },
            _work: work,
        }
    }

    fn path(&self, path: &str) -> PathBuf {
        self.files.path(path)
    }

    fn install(&mut self, input: &PrerequisiteInput) -> Result<Prerequisites, PrerequisiteError> {
        install(&self.files, &mut self.host, input)
    }

    fn read(&self, path: &str) -> String {
        fs::read_to_string(self.path(path)).unwrap()
    }

    fn mode(&self, path: &str) -> u32 {
        fs::symlink_metadata(self.path(path)).unwrap().mode() & 0o7777
    }
}

fn input(application: bool) -> PrerequisiteInput {
    PrerequisiteInput {
        user: "orbit".into(),
        group: "orbit".into(),
        home: "/home/orbit".into(),
        releases: vec!["noble".into(), "resolute".into()],
        packages: vec!["docker.io".into(), "git".into()],
        apps_directory: application,
        composer_home: application,
    }
}

#[test]
fn installs_packages_with_the_dpkg_lock_timeout_and_changes_nothing_else() {
    let mut sandbox = Sandbox::new();
    assert_eq!(sandbox.install(&input(false)), Ok(Prerequisites {}));
    let apt: Vec<&String> = sandbox
        .host
        .calls
        .iter()
        .filter(|call| call.starts_with("apt-get"))
        .collect();
    assert_eq!(
        apt,
        [
            "apt-get -o DPkg::Lock::Timeout=300 update",
            "apt-get -o DPkg::Lock::Timeout=300 install --yes --no-install-recommends --no-remove -- docker.io git",
        ]
    );
    let index = sandbox
        .host
        .calls
        .iter()
        .position(|call| call.starts_with("apt-get"))
        .unwrap();
    for environment in &sandbox.host.environments[index..] {
        assert_eq!(
            environment,
            &[("DEBIAN_FRONTEND".to_string(), "noninteractive".to_string())]
        );
    }
    assert!(!sandbox.path("/opt/orbit").exists());
    assert!(!sandbox.path("/home/orbit/apps").exists());
}

#[test]
fn a_healthy_docker_ce_replaces_docker_io() {
    let mut sandbox = Sandbox::new();
    sandbox.host.docker_ce = true;
    let docker = sandbox.path("/usr/bin/docker");
    fs::write(&docker, "#!/bin/sh\n").unwrap();
    fs::set_permissions(&docker, fs::Permissions::from_mode(0o755)).unwrap();
    sandbox.install(&input(false)).unwrap();
    assert!(sandbox.host.calls.contains(
        &"apt-get -o DPkg::Lock::Timeout=300 install --yes --no-install-recommends --no-remove -- git"
            .to_string()
    ));

    // Docker CE packages without the docker binary are not a healthy installation.
    let mut sandbox = Sandbox::new();
    sandbox.host.docker_ce = true;
    sandbox.install(&input(false)).unwrap();
    assert!(sandbox
        .host
        .calls
        .last()
        .unwrap()
        .ends_with("-- docker.io git"));
}

#[test]
fn an_unsupported_or_unreadable_release_refuses_before_any_change() {
    for (text, id, codename) in [
        ("ID=ubuntu\nVERSION_CODENAME=jammy\n", "ubuntu", "jammy"),
        ("ID=debian\nVERSION_CODENAME=noble\n", "debian", "noble"),
        ("NAME=nothing\n", "", ""),
    ] {
        let mut sandbox = Sandbox::new();
        fs::write(sandbox.path("/etc/os-release"), text).unwrap();
        assert_eq!(
            sandbox.install(&input(true)),
            Err(PrerequisiteError::Unsupported {
                id: id.into(),
                codename: codename.into()
            })
        );
        assert!(sandbox.host.calls.is_empty());
        assert!(!sandbox.path("/opt/orbit").exists());
    }
    let mut sandbox = Sandbox::new();
    fs::remove_file(sandbox.path("/etc/os-release")).unwrap();
    assert!(matches!(
        sandbox.install(&input(true)),
        Err(PrerequisiteError::Unsupported { .. })
    ));
    assert!(sandbox.host.calls.is_empty());
}

#[test]
fn a_missing_account_refuses_before_any_change() {
    let mut sandbox = Sandbox::new();
    sandbox.host.account = None;
    assert_eq!(
        sandbox.install(&input(true)),
        Err(failed("account", "the managed account does not exist"))
    );
    assert!(sandbox.host.calls.is_empty());
    assert!(!sandbox.path("/home/orbit/apps").exists());
}

#[test]
fn a_package_failure_stops_before_the_account_paths() {
    let mut sandbox = Sandbox::new();
    sandbox.host.fail = Some("apt-get -o DPkg::Lock::Timeout=300 install".into());
    assert_eq!(
        sandbox.install(&input(true)),
        Err(failed("packages", "injected failure"))
    );
    assert!(!sandbox.path("/home/orbit/apps").exists());
    assert!(!sandbox.path("/opt/orbit").exists());
}

#[test]
fn an_application_role_gets_its_directories_and_composer_home_and_repeats_without_change() {
    let mut sandbox = Sandbox::new();
    assert_eq!(sandbox.install(&input(true)), Ok(Prerequisites {}));

    assert!(sandbox.path("/home/orbit/apps").is_dir());
    assert_eq!(sandbox.mode("/home/orbit/apps"), 0o755);
    assert_eq!(sandbox.mode("/opt/orbit"), 0o755);
    assert_eq!(
        sandbox.read("/opt/orbit/composer/composer.json"),
        "{\"require\":{}}\n"
    );
    assert_eq!(sandbox.mode("/opt/orbit/composer/composer.json"), 0o644);
    assert!(sandbox.path("/opt/orbit/composer/vendor/bin").is_dir());
    assert_eq!(
        fs::read_dir(sandbox.path("/opt/orbit"))
            .unwrap()
            .map(|entry| entry.unwrap().file_name().into_string().unwrap())
            .filter(|name| name.starts_with('.'))
            .count(),
        0,
        "no staged manifest remains"
    );
    // The machine recipe owns the JavaScript runtimes; no installer runs here.
    assert!(!sandbox
        .host
        .calls
        .iter()
        .any(|call| call.contains("curl") || call.contains("bun") || call.contains("vite")));
    assert_eq!(
        fs::read_dir(sandbox.path("/usr/local/bin"))
            .unwrap()
            .count(),
        0
    );

    let manifest = fs::metadata(sandbox.path("/opt/orbit/composer/composer.json"))
        .unwrap()
        .ino();
    assert_eq!(sandbox.install(&input(true)), Ok(Prerequisites {}));
    assert_eq!(
        fs::metadata(sandbox.path("/opt/orbit/composer/composer.json"))
            .unwrap()
            .ino(),
        manifest,
        "an existing manifest is kept"
    );
}

#[test]
fn a_composer_manifest_orbit_did_not_make_is_a_conflict() {
    let mut sandbox = Sandbox::new();
    fs::create_dir_all(sandbox.path("/opt/orbit/composer/composer.json")).unwrap();
    assert_eq!(
        sandbox.install(&input(true)),
        Err(PrerequisiteError::Conflict {
            path: "/opt/orbit/composer/composer.json".into()
        })
    );
}
