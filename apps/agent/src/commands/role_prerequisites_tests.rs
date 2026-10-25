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
    root: PathBuf,
    account: Option<(u32, u32)>,
    docker_ce: bool,
    /// Where the Vite+ installer puts `vp` when it runs without `VP_HOME`.
    vp_install_home: PathBuf,
    /// Whether `vp install -g pnpm` leaves a `pnpm`.
    installs_pnpm: bool,
    /// A command whose joined arguments contain this text fails.
    fail: Option<String>,
    calls: Vec<String>,
    environments: Vec<Vec<(String, String)>>,
}

impl Fake {
    fn physical(&self, path: &str) -> PathBuf {
        self.root.join(path.trim_start_matches('/'))
    }

    fn create_executable(&self, path: PathBuf) {
        fs::create_dir_all(path.parent().unwrap()).unwrap();
        fs::write(&path, "#!/bin/sh\n").unwrap();
        fs::set_permissions(&path, fs::Permissions::from_mode(0o755)).unwrap();
    }

    fn act(&self, arguments: &[&str]) -> bool {
        let joined = arguments.join(" ");
        if joined.contains("https://vite.plus") {
            let home = self.physical(self.vp_install_home.to_str().unwrap());
            self.create_executable(home.join("bin/vp"));
            return true;
        }
        if joined.contains("https://bun.com/install") {
            self.create_executable(self.physical("/opt/orbit/bun/bin/bun"));
            return true;
        }
        if let ["env", environment, vp, rest @ ..] = arguments {
            if let Some(home) = environment.strip_prefix("VP_HOME=") {
                assert_eq!(*vp, format!("{home}/bin/vp"));
                let bin = self.physical(home).join("bin");
                match rest {
                    ["env", "setup"] => {
                        for binary in ["node", "npm", "npx"] {
                            self.create_executable(bin.join(binary));
                        }
                    }
                    ["install", "-g", "--node", "lts", "pnpm"] if self.installs_pnpm => {
                        self.create_executable(bin.join("pnpm"));
                    }
                    _ => {}
                }
                return true;
            }
        }
        if let [launcher, "--version"] = arguments {
            return self.physical(launcher).is_file();
        }
        if let ["env", "BUN_INSTALL=/opt/orbit/bun", "/usr/local/bin/bun", "--version"] = arguments
        {
            return fs::symlink_metadata(self.physical("/usr/local/bin/bun")).is_ok();
        }
        true
    }

    fn installs(&self) -> usize {
        self.calls
            .iter()
            .filter(|call| call.contains("https://vite.plus"))
            .count()
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
                root,
                account: Some((uid, gid)),
                docker_ce: false,
                vp_install_home: PathBuf::from("/home/orbit/.local/share/vite-plus"),
                installs_pnpm: true,
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
        application_runtime: application,
    }
}

const VP_HOME: &str = "/home/orbit/.local/share/vite-plus";

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
    sandbox
        .host
        .create_executable(sandbox.path("/usr/bin/docker"));
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
fn an_application_role_gets_its_directories_runtime_and_launchers_and_repeats_without_change() {
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
    for binary in LAUNCHERS {
        let path = format!("/usr/local/bin/{binary}");
        assert_eq!(
            sandbox.read(&path),
            launcher(Path::new(VP_HOME), binary),
            "{binary}"
        );
        assert_eq!(sandbox.mode(&path), 0o755);
    }
    assert_eq!(
        fs::read_link(sandbox.path("/usr/local/bin/bun")).unwrap(),
        Path::new("/opt/orbit/bun/bin/bun")
    );
    assert_eq!(sandbox.host.installs(), 1);
    assert!(sandbox.host.calls.iter().any(|call| call
        == "sudo -u orbit -H env COMPOSER_HOME=/opt/orbit/composer /usr/bin/composer --version --no-ansi"));
    assert!(sandbox.host.calls.contains(&format!(
        "chown -R --no-dereference {0}:{1} /opt/orbit/bun",
        me().0,
        me().1
    )));

    let before: Vec<_> = LAUNCHERS
        .iter()
        .map(|binary| {
            fs::metadata(sandbox.path(&format!("/usr/local/bin/{binary}")))
                .unwrap()
                .ino()
        })
        .collect();
    sandbox.host.calls.clear();
    assert_eq!(sandbox.install(&input(true)), Ok(Prerequisites {}));
    assert_eq!(sandbox.host.installs(), 0, "Vite+ installs only once");
    let after: Vec<_> = LAUNCHERS
        .iter()
        .map(|binary| {
            fs::metadata(sandbox.path(&format!("/usr/local/bin/{binary}")))
                .unwrap()
                .ino()
        })
        .collect();
    assert_eq!(before, after, "an unchanged launcher is not rewritten");
}

#[test]
fn an_existing_vite_plus_home_is_kept_and_not_reinstalled() {
    let mut sandbox = Sandbox::new();
    for binary in LAUNCHERS {
        sandbox
            .host
            .create_executable(sandbox.path(&format!("/opt/orbit/vite-plus/bin/{binary}")));
    }
    sandbox.install(&input(true)).unwrap();
    assert_eq!(sandbox.host.installs(), 0);
    assert_eq!(
        sandbox.read("/usr/local/bin/node"),
        launcher(Path::new("/opt/orbit/vite-plus"), "node")
    );
}

#[test]
fn a_legacy_launcher_is_upgraded() {
    let mut sandbox = Sandbox::new();
    let path = sandbox.path("/usr/local/bin/node");
    fs::write(&path, legacy_launcher(Path::new(VP_HOME), "node")).unwrap();
    fs::set_permissions(&path, fs::Permissions::from_mode(0o755)).unwrap();
    sandbox.install(&input(true)).unwrap();
    assert_eq!(
        sandbox.read("/usr/local/bin/node"),
        launcher(Path::new(VP_HOME), "node")
    );
}

#[test]
fn a_launcher_orbit_did_not_write_refuses_before_any_launcher_changes() {
    for (content, mode) in [
        ("#!/bin/sh\necho mine\n".to_string(), 0o755),
        (launcher(Path::new(VP_HOME), "node"), 0o775),
    ] {
        let mut sandbox = Sandbox::new();
        let path = sandbox.path("/usr/local/bin/node");
        fs::write(&path, &content).unwrap();
        fs::set_permissions(&path, fs::Permissions::from_mode(mode)).unwrap();
        assert_eq!(
            sandbox.install(&input(true)),
            Err(PrerequisiteError::Conflict {
                path: "/usr/local/bin/node".into()
            })
        );
        assert_eq!(fs::read_to_string(&path).unwrap(), content);
        assert_eq!(
            fs::read_dir(sandbox.path("/usr/local/bin"))
                .unwrap()
                .count(),
            1,
            "no other launcher or the bun link was published"
        );
        assert!(!sandbox
            .host
            .calls
            .iter()
            .any(|call| call.ends_with("--version") && call.contains("/usr/local/bin/")));
    }
}

#[test]
fn a_foreign_bun_link_refuses_before_any_launcher_changes() {
    let mut sandbox = Sandbox::new();
    symlink("/tmp/elsewhere", sandbox.path("/usr/local/bin/bun")).unwrap();
    assert_eq!(
        sandbox.install(&input(true)),
        Err(PrerequisiteError::Conflict {
            path: "/usr/local/bin/bun".into()
        })
    );
    assert!(!sandbox.path("/usr/local/bin/vp").exists());
}

#[test]
fn a_failed_version_check_restores_the_launchers_it_replaced_and_removes_new_ones() {
    let mut sandbox = Sandbox::new();
    let vp = sandbox.path("/usr/local/bin/vp");
    let legacy = legacy_launcher(Path::new(VP_HOME), "vp");
    fs::write(&vp, &legacy).unwrap();
    fs::set_permissions(&vp, fs::Permissions::from_mode(0o755)).unwrap();
    sandbox.host.fail = Some("/usr/local/bin/npm --version".into());

    assert_eq!(
        sandbox.install(&input(true)),
        Err(failed("javascript-runtime", "injected failure"))
    );
    assert_eq!(fs::read_to_string(&vp).unwrap(), legacy);
    let left: Vec<String> = fs::read_dir(sandbox.path("/usr/local/bin"))
        .unwrap()
        .map(|entry| entry.unwrap().file_name().into_string().unwrap())
        .collect();
    assert_eq!(left, ["vp"]);
}

#[test]
fn a_vite_plus_install_without_pnpm_fails_before_publishing() {
    let mut sandbox = Sandbox::new();
    sandbox.host.installs_pnpm = false;
    assert_eq!(
        sandbox.install(&input(true)),
        Err(failed("vite-plus", "Vite+ did not install vp and pnpm"))
    );
    assert_eq!(
        fs::read_dir(sandbox.path("/usr/local/bin"))
            .unwrap()
            .count(),
        0
    );
}

#[test]
fn a_composer_manifest_or_bun_home_orbit_did_not_make_is_a_conflict() {
    let mut sandbox = Sandbox::new();
    fs::create_dir_all(sandbox.path("/opt/orbit/composer/composer.json")).unwrap();
    assert_eq!(
        sandbox.install(&input(true)),
        Err(PrerequisiteError::Conflict {
            path: "/opt/orbit/composer/composer.json".into()
        })
    );

    let mut sandbox = Sandbox::new();
    fs::create_dir_all(sandbox.path("/opt/orbit")).unwrap();
    symlink("/tmp", sandbox.path("/opt/orbit/bun")).unwrap();
    assert_eq!(
        sandbox.install(&input(true)),
        Err(PrerequisiteError::Conflict {
            path: "/opt/orbit/bun".into()
        })
    );
    assert_eq!(
        fs::read_link(sandbox.path("/opt/orbit/bun")).unwrap(),
        Path::new("/tmp")
    );
    assert!(!sandbox
        .host
        .calls
        .iter()
        .any(|call| call.starts_with("chown")));
}

#[test]
fn launchers_name_their_vite_plus_home() {
    let home = Path::new("/home/orbit/.local/share/vite-plus");
    assert_eq!(
        launcher(home, "node"),
        "#!/bin/sh\nexport VP_HOME=\"/home/orbit/.local/share/vite-plus\"\nexec \"/home/orbit/.local/share/vite-plus/bin/node\" \"$@\"\n"
    );
    assert_eq!(
        legacy_launcher(home, "node"),
        "#!/bin/sh\nexec \"/home/orbit/.local/share/vite-plus/bin/node\" \"$@\"\n"
    );
    assert!(legacy_launcher(Path::new("/opt/orbit/vite-plus"), "vp")
        .contains("export VP_HOME=/opt/orbit/vite-plus\n"));
}

#[test]
fn the_vite_plus_home_is_the_first_candidate_that_holds_vp() {
    let sandbox = Sandbox::new();
    let files = &sandbox.files;
    let (uid, gid) = me();
    let candidates = [
        PathBuf::from("/a"),
        PathBuf::from("/b"),
        PathBuf::from("/c"),
    ];

    assert_eq!(
        vite_plus_home(files, &candidates, uid, gid),
        Ok(PathBuf::from("/c"))
    );
    fs::create_dir_all(sandbox.path("/a")).unwrap();
    fs::create_dir_all(sandbox.path("/b/bin")).unwrap();
    fs::write(sandbox.path("/b/bin/vp"), "").unwrap();
    assert_eq!(
        vite_plus_home(files, &candidates, uid, gid),
        Ok(PathBuf::from("/b"))
    );
    assert_eq!(
        vite_plus_home(files, &candidates, uid + 1, gid),
        Err(PrerequisiteError::Conflict { path: "/a".into() })
    );
}

#[test]
fn undo_removes_created_files_and_restores_replaced_ones() {
    let sandbox = Sandbox::new();
    let (kept, created) = (sandbox.path("/kept"), sandbox.path("/created"));
    fs::write(&kept, "new").unwrap();
    fs::write(&created, "new").unwrap();

    undo(&[
        Published::Replaced(kept.clone(), b"old".to_vec()),
        Published::Created(created.clone()),
    ]);

    assert_eq!(fs::read_to_string(kept).unwrap(), "old");
    assert!(!created.exists());
}
