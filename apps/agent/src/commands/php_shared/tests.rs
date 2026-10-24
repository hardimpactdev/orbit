use super::*;
use recipe::{Failure, Output as ProcessOutput};
use std::os::unix::fs::{symlink, PermissionsExt};

struct FakeHost {
    root: PathBuf,
    services: BTreeMap<String, (bool, bool)>,
    changes: Vec<(String, String, Option<String>)>,
    fail_validation: bool,
    fail_activation: Option<String>,
    fail_recovery: bool,
    validations: usize,
}
impl Host for FakeHost {
    fn execute(&mut self, call: &Invocation) -> Result<ProcessOutput, Failure> {
        let mut code = 0;
        if call.argv[0].starts_with("/usr/sbin/php-fpm") {
            self.validations += 1;
            let main = fs::read_to_string(&call.argv[3]).unwrap();
            let include = main
                .lines()
                .find_map(|line| line.strip_prefix("include = "))
                .unwrap();
            let pools = Path::new(include).parent().unwrap();
            assert_eq!(
                fs::read_to_string(pools.join("www.conf")).unwrap(),
                "unrelated pool\n"
            );
            assert!(pools.join("orbit-scopes.conf").is_file());
            if self.fail_validation {
                code = 1;
            }
        } else {
            assert_eq!(call.argv[0], "/usr/bin/systemctl");
            let v = call
                .argv
                .last()
                .unwrap()
                .strip_prefix("php")
                .unwrap()
                .strip_suffix("-fpm")
                .unwrap()
                .to_owned();
            let action = call.argv[1].as_str();
            let service = self.services.entry(v.clone()).or_insert((true, true));
            match action {
                "is-active" => {
                    if !service.0 {
                        code = 3;
                    }
                }
                "is-enabled" => {
                    if !service.1 {
                        code = 1;
                    }
                }
                _ => {
                    self.changes.push((
                        action.into(),
                        v.clone(),
                        fs::read_to_string(
                            self.root
                                .join(format!("etc/php/{v}/fpm/pool.d/orbit-scopes.conf")),
                        )
                        .ok(),
                    ));
                    match action {
                        "enable" => service.1 = true,
                        "disable" => service.1 = false,
                        "stop" => service.0 = false,
                        "reload-or-restart" => {
                            if self.fail_activation.as_deref() == Some(&v) {
                                code = 1;
                                if !self.fail_recovery {
                                    self.fail_activation = None;
                                }
                            } else {
                                service.0 = true;
                            }
                        }
                        _ => panic!("unexpected command {action}"),
                    }
                }
            }
        }
        Ok(ProcessOutput {
            code,
            ..Default::default()
        })
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("unexpected wait")
    }
}
fn fixture() -> (WorkDir, Files, FakeHost) {
    let work = WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: rustix::process::getuid().as_raw(),
        gid: rustix::process::getgid().as_raw(),
    };
    for v in ["8.4", "8.5"] {
        let root = files.path(&format!("/etc/php/{v}/fpm"));
        fs::create_dir_all(root.join("pool.d")).unwrap();
        fs::write(
            root.join("php-fpm.conf"),
            format!("[global]\ninclude=/etc/php/{v}/fpm/pool.d/*.conf\n"),
        )
        .unwrap();
        fs::write(root.join("pool.d/www.conf"), "unrelated pool\n").unwrap();
    }
    fs::create_dir_all(files.path("/apps/present")).unwrap();
    let host = FakeHost {
        root: work.0.clone(),
        services: BTreeMap::new(),
        changes: vec![],
        fail_validation: false,
        fail_activation: None,
        fail_recovery: false,
        validations: 0,
    };
    (work, files, host)
}
fn pool(id: u32, directory: &str) -> String {
    format!("[orbit-app-instance-{id}]\nchdir = {directory}\n")
}
fn input(items: &[(&str, String)]) -> Input {
    Input {
        configurations: items
            .iter()
            .map(|(v, c)| ((*v).into(), c.clone()))
            .collect(),
    }
}
fn write(files: &Files, v: &str, text: &str) {
    fs::write(pool_path(files, v), text).unwrap();
}
#[test]
fn publishes_multiple_versions_preserves_other_pools_and_repeats_without_activation() {
    let (_work, files, mut host) = fixture();
    let input = input(&[
        ("8.4", pool(1, "/apps/present")),
        ("8.5", pool(2, "/apps/present")),
    ]);
    assert!(converge(&files, &mut host, &input).unwrap().changed);
    assert_eq!(host.validations, 2);
    for v in ["8.4", "8.5"] {
        assert_eq!(
            fs::read_to_string(pool_path(&files, v)).unwrap(),
            input.configurations[v]
        );
        assert_eq!(
            fs::metadata(pool_path(&files, v)).unwrap().mode() & 0o7777,
            0o644
        );
        assert_eq!(
            fs::read_to_string(pool_path(&files, v).with_file_name("www.conf")).unwrap(),
            "unrelated pool\n"
        );
    }
    host.changes.clear();
    assert!(!converge(&files, &mut host, &input).unwrap().changed);
    assert!(host.changes.is_empty());
}
#[test]
fn read_reports_installed_and_desired_missing_pools_without_writing() {
    let (_work, files, host) = fixture();
    write(
        &files,
        "8.5",
        &(pool(1, "/apps/missing") + &pool(2, "/apps/present")),
    );
    let before = fs::metadata(pool_path(&files, "8.5")).unwrap().ino();
    let result = inspect(
        &files,
        &input(&[(
            "8.5",
            pool(1, "/apps/missing") + &pool(3, "/apps/gone") + &pool(2, "/apps/present"),
        )]),
    )
    .unwrap();
    assert_eq!(result.versions, ["8.5"]);
    assert_eq!(
        result.missing_pools,
        [
            MissingPool {
                pool: "orbit-app-instance-1".into(),
                version: "8.5".into(),
                directory: "/apps/missing".into(),
                installed: true
            },
            MissingPool {
                pool: "orbit-app-instance-3".into(),
                version: "8.5".into(),
                directory: "/apps/gone".into(),
                installed: false
            }
        ]
    );
    assert_eq!(
        fs::metadata(pool_path(&files, "8.5")).unwrap().ino(),
        before
    );
    assert!(host.changes.is_empty());
}
#[test]
fn missing_desired_pools_are_reported_after_other_pools_publish_and_stale_pools_retire() {
    let (_work, files, mut host) = fixture();
    write(&files, "8.4", &pool(9, "/apps/gone"));
    let result = converge(
        &files,
        &mut host,
        &input(&[("8.5", pool(1, "/apps/present") + &pool(2, "/apps/missing"))]),
    )
    .unwrap();
    assert_eq!(result.missing_pools.len(), 1);
    assert_eq!(result.missing_pools[0].pool, "orbit-app-instance-2");
    assert_eq!(
        fs::read_to_string(pool_path(&files, "8.5")).unwrap(),
        pool(1, "/apps/present")
    );
    assert!(!pool_path(&files, "8.4").exists());
}
#[test]
fn retires_both_sides_before_activating_a_version_swap() {
    let (_work, files, mut host) = fixture();
    write(&files, "8.4", &pool(1, "/apps/present"));
    write(&files, "8.5", &pool(2, "/apps/present"));
    converge(
        &files,
        &mut host,
        &input(&[
            ("8.4", pool(2, "/apps/present")),
            ("8.5", pool(1, "/apps/present")),
        ]),
    )
    .unwrap();
    assert_eq!(
        host.changes,
        [
            ("reload-or-restart".into(), "8.4".into(), None),
            ("reload-or-restart".into(), "8.5".into(), None),
            (
                "reload-or-restart".into(),
                "8.4".into(),
                Some(pool(2, "/apps/present"))
            ),
            (
                "reload-or-restart".into(),
                "8.5".into(),
                Some(pool(1, "/apps/present"))
            )
        ]
    );
}
#[test]
fn invalid_input_unsafe_paths_and_failed_validation_change_no_live_state() {
    let (_work, files, mut host) = fixture();
    for bad in [
        input(&[("../8.5", pool(1, "/apps/present"))]),
        input(&[("8.5", "[global]\ninclude=/evil\n".into())]),
        input(&[("8.5", pool(1, "relative"))]),
        input(&[
            ("8.4", pool(1, "/apps/present")),
            ("8.5", pool(1, "/apps/present")),
        ]),
    ] {
        assert_eq!(
            converge(&files, &mut host, &bad).unwrap_err(),
            Error::Invalid {}
        );
    }
    let wanted = input(&[("8.5", pool(1, "/apps/present"))]);
    let live = pool_path(&files, "8.5");
    symlink("www.conf", &live).unwrap();
    assert_eq!(
        converge(&files, &mut host, &wanted).unwrap_err(),
        Error::Unsafe {}
    );
    fs::remove_file(&live).unwrap();
    write(&files, "8.5", &pool(2, "/apps/present"));
    host.fail_validation = true;
    assert_eq!(
        converge(&files, &mut host, &wanted).unwrap_err(),
        failed("validate")
    );
    assert_eq!(fs::read_to_string(live).unwrap(), pool(2, "/apps/present"));
    assert!(host.changes.is_empty());
}
#[test]
fn failed_activation_restores_earlier_versions_modes_and_service_state() {
    let (_work, files, mut host) = fixture();
    let previous = pool(1, "/apps/present") + &pool(9, "/apps/gone");
    write(&files, "8.5", &previous);
    fs::set_permissions(pool_path(&files, "8.5"), fs::Permissions::from_mode(0o600)).unwrap();
    host.services.insert("8.4".into(), (false, false));
    host.fail_activation = Some("8.4".into());
    let result = converge(
        &files,
        &mut host,
        &input(&[("8.4", pool(1, "/apps/present"))]),
    );
    assert_eq!(result.unwrap_err(), failed("reload-or-restart"));
    assert_eq!(
        fs::read_to_string(pool_path(&files, "8.5")).unwrap(),
        pool(1, "/apps/present")
    );
    assert_eq!(
        fs::metadata(pool_path(&files, "8.5")).unwrap().mode() & 0o7777,
        0o600
    );
    assert!(!pool_path(&files, "8.4").exists());
    assert_eq!(host.services["8.4"], (false, false));
    assert_eq!(host.services["8.5"], (true, true));
}
#[test]
fn failed_recovery_is_explicit_and_stopped_current_services_are_started() {
    let (_work, files, mut host) = fixture();
    let wanted = input(&[("8.5", pool(1, "/apps/present"))]);
    write(&files, "8.5", &wanted.configurations["8.5"]);
    host.services.insert("8.5".into(), (false, false));
    assert!(converge(&files, &mut host, &wanted).unwrap().changed);
    assert_eq!(host.services["8.5"], (true, true));
    host.fail_activation = Some("8.5".into());
    host.fail_recovery = true;
    assert_eq!(
        converge(
            &files,
            &mut host,
            &input(&[("8.5", pool(2, "/apps/present"))])
        )
        .unwrap_err(),
        Error::RecoveryFailed {}
    );
    assert_eq!(
        fs::read_to_string(pool_path(&files, "8.5")).unwrap(),
        wanted.configurations["8.5"]
    );
}
#[test]
fn a_shared_pool_mutation_takes_its_lock_without_locking_itself_twice() {
    let (_work, files, _) = fixture();
    let wanted = input(&[]);
    let locks = crate::command::lock::acquire_all(
        &files.root.join("locks"),
        &Converge::target(&wanted).unwrap(),
        Converge::RESOURCES,
        std::time::Instant::now(),
    )
    .unwrap();
    assert!(!locks.is_empty());
}

#[test]
fn later_failure_removes_newly_published_versions_and_restores_empty_files() {
    let (_work, files, mut host) = fixture();
    let root = files.path("/etc/php/8.6/fpm");
    fs::create_dir_all(root.join("pool.d")).unwrap();
    fs::write(
        root.join("php-fpm.conf"),
        "include=/etc/php/8.6/fpm/pool.d/*.conf\n",
    )
    .unwrap();
    fs::write(root.join("pool.d/www.conf"), "unrelated pool\n").unwrap();
    write(&files, "8.4", "");
    fs::set_permissions(pool_path(&files, "8.4"), fs::Permissions::from_mode(0o600)).unwrap();
    host.fail_activation = Some("8.6".into());
    let wanted = input(&[
        ("8.4", pool(1, "/apps/present")),
        ("8.5", pool(2, "/apps/present")),
        ("8.6", pool(3, "/apps/present")),
    ]);
    assert_eq!(
        converge(&files, &mut host, &wanted).unwrap_err(),
        failed("reload-or-restart")
    );
    assert_eq!(fs::read_to_string(pool_path(&files, "8.4")).unwrap(), "");
    assert_eq!(
        fs::metadata(pool_path(&files, "8.4")).unwrap().mode() & 0o7777,
        0o600
    );
    assert!(!pool_path(&files, "8.5").exists());
    assert!(!pool_path(&files, "8.6").exists());
    let versions: Vec<&str> = host
        .changes
        .iter()
        .filter(|(action, _, _)| action == "reload-or-restart")
        .map(|(_, v, _)| v.as_str())
        .collect();
    assert_eq!(versions, ["8.4", "8.5", "8.6", "8.6", "8.5", "8.4"]);
}

#[test]
fn refuses_unsafe_sibling_pools_and_ambiguous_main_before_publishing_any_version() {
    let (_work, files, mut host) = fixture();
    let wanted = input(&[
        ("8.4", pool(1, "/apps/present")),
        ("8.5", pool(2, "/apps/present")),
    ]);
    let main = files.path("/etc/php/8.5/fpm/php-fpm.conf");
    fs::write(
        &main,
        "include=/etc/php/8.5/fpm/pool.d/*.conf\ninclude = /etc/php/8.5/fpm/pool.d/*.conf\n",
    )
    .unwrap();
    assert_eq!(
        converge(&files, &mut host, &wanted).unwrap_err(),
        Error::Invalid {}
    );
    fs::write(main, "include=/etc/php/8.5/fpm/pool.d/*.conf\n").unwrap();
    symlink(
        "www.conf",
        pool_path(&files, "8.5").with_file_name("foreign.conf"),
    )
    .unwrap();
    assert_eq!(
        converge(&files, &mut host, &wanted).unwrap_err(),
        Error::Unsafe {}
    );
    assert!(!pool_path(&files, "8.4").exists());
    assert!(!pool_path(&files, "8.5").exists());
    assert!(host.changes.is_empty());
}
