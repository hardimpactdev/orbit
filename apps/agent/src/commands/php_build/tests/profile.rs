use super::*;
use crate::commands::php_profile::{self, Error, Input};

fn fixture() -> Fixture {
    let f = Fixture::new();
    write(&f.files, "/usr/sbin/php-fpm8.5", "fake binary\n");
    write(
        &f.files,
        "/etc/orbit/example.conf",
        "[global]\ninclude=/etc/orbit/example-pool.conf\n",
    );
    f
}

fn input() -> Input {
    Input {
        version: "8.5".into(),
        runtime_ini: include_str!(
            "../../../../../gateway/tests/Fixtures/PhpRuntimeIni/app-dev.ini"
        )
        .into(),
        release: Release {
            id: "ubuntu".into(),
            codenames: vec!["resolute".into()],
        },
    }
}

#[test]
fn shared_module_is_fpm_only_and_reloads_only_when_changed_and_valid() {
    for (active, valid) in [(true, true), (true, false), (false, true)] {
        let mut f = fixture();
        if active {
            f.host.active.insert(UNIT.into());
        }
        if !valid {
            f.host.invalid.insert(UNIT.into());
        }
        let result = php_profile::configure(&mut f.host, &f.files, &input()).unwrap();
        assert!(result.changed);
        assert_eq!(result.reloaded, active && valid);
        assert_eq!(
            result.deferred,
            if active && !valid {
                vec![UNIT.to_owned()]
            } else {
                vec![]
            }
        );
        assert!(
            fs::read_to_string(f.files.path("/etc/php/8.5/fpm/php-fpm.conf"))
                .unwrap()
                .contains("process_control_timeout = 30s")
        );
        assert_eq!(
            fs::read_to_string(
                f.files
                    .path("/etc/php/8.5/mods-available/orbit-runtime.ini")
            )
            .unwrap(),
            input().runtime_ini
        );
        assert_eq!(
            fs::read_link(f.files.path("/etc/php/8.5/fpm/conf.d/99-orbit-runtime.ini")).unwrap(),
            Path::new("../../mods-available/orbit-runtime.ini")
        );
        assert!(paths(&f.files.path("/etc/php/8.5/cli/conf.d"))
            .unwrap()
            .is_empty());
        assert_eq!(f.host.enabled[UNIT], "enabled");
        assert_eq!(f.host.active.contains(UNIT), active);
        let repeat = php_profile::configure(&mut f.host, &f.files, &input()).unwrap();
        assert!(!repeat.changed);
        assert!(!repeat.reloaded);
    }
}

#[test]
fn invalid_settings_and_release_refuse_without_execution_or_changes() {
    for invalid in [
        "auto_prepend_file = /tmp/code.php\n",
        "opcache.memory_consumption = 1\nopcache.memory_consumption = 1\n",
        "opcache.jit = tracing\n",
    ] {
        let mut f = fixture();
        let mut input = input();
        input.runtime_ini.push_str(invalid);
        assert!(matches!(
            php_profile::configure(&mut f.host, &f.files, &input),
            Err(Error::InputInvalid {})
        ));
        assert!(f.host.calls.is_empty());
    }
    let mut f = fixture();
    write(
        &f.files,
        "/etc/os-release",
        "ID=debian\nVERSION_CODENAME=trixie\n",
    );
    assert!(matches!(
        php_profile::configure(&mut f.host, &f.files, &input()),
        Err(Error::PlatformUnsupported { .. })
    ));
    assert!(f.host.calls.is_empty());
}

#[test]
fn request_sizes_must_be_positive_bounded_php_quantities_and_both_are_required() {
    for key in ["post_max_size", "upload_max_filesize"] {
        for value in [
            "0",
            "0M",
            "-1",
            "-1M",
            "Off",
            "64MB",
            "1.5M",
            "64M; ignored",
            "64M\nother = 1",
            "1025M",
            "2G",
            "1073741825",
            "9223372036854775808",
            "8589934592G",
            "18446744073709551616M",
        ] {
            let mut f = fixture();
            let mut input = input();
            input.runtime_ini = input
                .runtime_ini
                .replace(&format!("{key} = 64M"), &format!("{key} = {value}"));
            assert!(
                matches!(
                    php_profile::configure(&mut f.host, &f.files, &input),
                    Err(Error::InputInvalid {})
                ),
                "{key} = {value}"
            );
            assert!(f.host.calls.is_empty());
            assert!(!f
                .files
                .path("/etc/php/8.5/mods-available/orbit-runtime.ini")
                .exists());
        }
        for replacement in [String::new(), format!("{key} = 64M\n{key} = 64M\n")] {
            let mut f = fixture();
            let mut input = input();
            input.runtime_ini = input
                .runtime_ini
                .replace(&format!("{key} = 64M\n"), &replacement);
            assert!(matches!(
                php_profile::configure(&mut f.host, &f.files, &input),
                Err(Error::InputInvalid {})
            ));
            assert!(f.host.calls.is_empty());
        }
    }
}

#[test]
fn request_size_overrides_are_detected_before_reload_and_restore_the_module() {
    for (key, value) in [("post_max_size", "8M"), ("upload_max_filesize", "2M")] {
        let mut f = fixture();
        let available = f
            .files
            .path("/etc/php/8.5/mods-available/orbit-runtime.ini");
        fs::write(&available, "previous\n").unwrap();
        f.host.active.insert(DEDICATED.into());
        f.host.ini_overrides.insert(key.into(), value.into());
        assert!(matches!(
            php_profile::configure(&mut f.host, &f.files, &input()),
            Err(Error::Failed { restored: true, .. })
        ));
        assert_eq!(fs::read_to_string(available).unwrap(), "previous\n");
        assert!(!f.host.calls.iter().any(|call| call
            .argv
            .starts_with(&["systemctl".into(), "reload".into()])));
    }
}

#[test]
fn adding_request_limits_reloads_existing_shared_and_dedicated_masters_once() {
    for invalid in [None, Some(UNIT), Some(DEDICATED)] {
        let mut f = fixture();
        let previous = input()
            .runtime_ini
            .replace("post_max_size = 64M\n", "")
            .replace("upload_max_filesize = 64M\n", "");
        write(
            &f.files,
            "/etc/php/8.5/mods-available/orbit-runtime.ini",
            &previous,
        );
        symlink(
            "../../mods-available/orbit-runtime.ini",
            f.files.path("/etc/php/8.5/fpm/conf.d/99-orbit-runtime.ini"),
        )
        .unwrap();
        f.host.active.extend([
            UNIT.into(),
            DEDICATED.into(),
            "orbit-other-php8.4-fpm.service".into(),
        ]);
        if let Some(unit) = invalid {
            f.host.invalid.insert(unit.into());
        }
        let result = php_profile::configure(&mut f.host, &f.files, &input()).unwrap();
        assert!(result.changed && result.reloaded);
        let reloads: Vec<_> = f
            .host
            .calls
            .iter()
            .filter(|call| {
                call.argv
                    .starts_with(&["systemctl".into(), "reload".into()])
            })
            .map(|call| call.argv[2].as_str())
            .collect();
        assert_eq!(
            reloads,
            [UNIT, DEDICATED]
                .into_iter()
                .filter(|unit| Some(*unit) != invalid)
                .collect::<Vec<_>>()
        );
        f.host.calls.clear();
        let repeat = php_profile::configure(&mut f.host, &f.files, &input()).unwrap();
        assert!(!repeat.changed && !repeat.reloaded);
        assert!(!f.host.calls.iter().any(|call| call
            .argv
            .starts_with(&["systemctl".into(), "reload".into()])));
    }
}

#[test]
fn failed_dedicated_reload_restores_and_reloads_every_master_already_touched() {
    let mut f = fixture();
    let shared_before = fs::read(f.files.path("/etc/php/8.5/fpm/php-fpm.conf")).unwrap();
    let dedicated_before = fs::read(f.files.path("/etc/orbit/example.conf")).unwrap();
    let available = write(
        &f.files,
        "/etc/php/8.5/mods-available/orbit-runtime.ini",
        "previous\n",
    );
    f.host.active.extend([UNIT.into(), DEDICATED.into()]);
    f.fail(&format!("systemctl reload {DEDICATED}"), 1);
    assert!(matches!(
        php_profile::configure(&mut f.host, &f.files, &input()),
        Err(Error::Failed { restored: true, .. })
    ));
    assert_eq!(fs::read_to_string(available).unwrap(), "previous\n");
    assert_eq!(
        fs::read(f.files.path("/etc/php/8.5/fpm/php-fpm.conf")).unwrap(),
        shared_before
    );
    assert_eq!(
        fs::read(f.files.path("/etc/orbit/example.conf")).unwrap(),
        dedicated_before
    );
    for unit in [UNIT, DEDICATED] {
        assert_eq!(
            f.host
                .calls
                .iter()
                .filter(|call| call.argv == ["systemctl", "reload", unit])
                .count(),
            2
        );
    }
}

#[test]
fn failed_publication_restores_previous_content_and_the_exact_previous_link() {
    for prior in [false, true] {
        let mut f = fixture();
        let available = f
            .files
            .path("/etc/php/8.5/mods-available/orbit-runtime.ini");
        let enabled = f.files.path("/etc/php/8.5/fpm/conf.d/99-orbit-runtime.ini");
        if prior {
            fs::write(&available, "previous\n").unwrap();
            symlink("../../mods-available/other.ini", &enabled).unwrap();
        }
        f.host.active.insert(UNIT.into());
        f.fail("systemctl reload", 1);
        assert!(matches!(
            php_profile::configure(&mut f.host, &f.files, &input()),
            Err(Error::Failed { restored: true, .. })
        ));
        if prior {
            assert_eq!(fs::read_to_string(available).unwrap(), "previous\n");
            assert_eq!(
                fs::read_link(enabled).unwrap(),
                Path::new("../../mods-available/other.ini")
            );
        } else {
            assert!(!available.exists());
            assert!(!enabled.is_symlink());
        }
    }
}

#[test]
fn unexpected_loaded_settings_pcov_and_occupied_module_link_refuse() {
    let mut f = fixture();
    let mut input = input();
    input.runtime_ini = input.runtime_ini.replace("512", "256");
    assert!(matches!(
        php_profile::configure(&mut f.host, &f.files, &input),
        Err(Error::Failed { restored: true, .. })
    ));
    assert!(!f
        .files
        .path("/etc/php/8.5/mods-available/orbit-runtime.ini")
        .exists());
    input.runtime_ini = input.runtime_ini.replace("256", "512");
    f.host.fpm_extra = "pcov".into();
    assert!(matches!(
        php_profile::configure(&mut f.host, &f.files, &input),
        Err(Error::Failed { restored: true, .. })
    ));
    let blocked = write(
        &f.files,
        "/etc/php/8.5/fpm/conf.d/99-orbit-runtime.ini",
        "keep\n",
    );
    f.host.calls.clear();
    assert!(matches!(
        php_profile::configure(&mut f.host, &f.files, &input),
        Err(Error::Failed { .. })
    ));
    assert!(f.host.calls.is_empty());
    assert_eq!(fs::read_to_string(blocked).unwrap(), "keep\n");
}

#[test]
fn absent_recipe_version_is_untouched_until_fpm_is_installed() {
    let mut f = fixture();
    let mut missing = input();
    missing.version = "8.6".into();
    let absent = php_profile::configure(&mut f.host, &f.files, &missing).unwrap();
    assert!(!absent.changed && !absent.reloaded);
    assert!(!f.files.path("/etc/php/8.6").exists());
    let binary = f.files.path("/usr/sbin/php-fpm8.5");
    fs::remove_file(&binary).unwrap();
    let absent = php_profile::configure(&mut f.host, &f.files, &input()).unwrap();
    assert!(!absent.changed && !absent.reloaded);
    assert!(f.host.calls.is_empty());
    assert!(!f
        .files
        .path("/etc/php/8.5/mods-available/orbit-runtime.ini")
        .exists());
    assert!(!f
        .files
        .path("/etc/php/8.5/fpm/conf.d/99-orbit-runtime.ini")
        .is_symlink());
    fs::write(binary, "installed later\n").unwrap();
    f.host.active.insert(UNIT.into());
    let installed = php_profile::configure(&mut f.host, &f.files, &input()).unwrap();
    assert!(installed.changed && installed.reloaded);
    assert_eq!(
        fs::read_to_string(
            f.files
                .path("/etc/php/8.5/mods-available/orbit-runtime.ini")
        )
        .unwrap(),
        input().runtime_ini
    );
}

#[test]
fn runtime_module_link_is_idempotent_through_a_symlinked_php_directory() {
    let mut f = fixture();
    let php = f.files.path("/etc/php");
    let real = f.files.path("/etc/php-real");
    fs::rename(&php, &real).unwrap();
    symlink("php-real", &php).unwrap();
    f.host.active.insert(UNIT.into());
    let first = php_profile::configure(&mut f.host, &f.files, &input()).unwrap();
    assert!(first.changed && first.reloaded);
    f.host.calls.clear();
    let repeat = php_profile::configure(&mut f.host, &f.files, &input()).unwrap();
    assert!(!repeat.changed && !repeat.reloaded);
    assert!(!f.host.calls.iter().any(|call| call
        .argv
        .starts_with(&["systemctl".into(), "reload".into()])));
}

#[test]
fn shared_timeout_replaces_zero_and_is_idempotent() {
    let mut f = fixture();
    let path = f.files.path("/etc/php/8.5/fpm/php-fpm.conf");
    fs::write(
        &path,
        "[global]\nprocess_control_timeout = 0\ninclude=/etc/php/8.5/fpm/pool.d/*.conf\n",
    )
    .unwrap();
    php_profile::configure(&mut f.host, &f.files, &input()).unwrap();
    let expected =
        "[global]\nprocess_control_timeout = 30s\ninclude=/etc/php/8.5/fpm/pool.d/*.conf\n";
    assert_eq!(fs::read_to_string(&path).unwrap(), expected);
    assert!(
        !php_profile::configure(&mut f.host, &f.files, &input())
            .unwrap()
            .changed
    );
    assert_eq!(fs::read_to_string(&path).unwrap(), expected);
}

#[test]
fn existing_dedicated_master_gets_its_timeout_before_reload() {
    struct CheckingHost<'a> {
        inner: &'a mut Fake,
        files: &'a Files,
    }
    impl RecipeHost for CheckingHost<'_> {
        fn wait(&mut self, delay: Duration) -> std::result::Result<(), recipe::Failure> {
            self.inner.wait(delay)
        }
        fn execute(&mut self, call: &Invocation) -> std::result::Result<Output, recipe::Failure> {
            if call
                .argv
                .starts_with(&["systemctl".into(), "reload".into()])
            {
                let path = if call.argv[2] == DEDICATED {
                    "/etc/orbit/example.conf"
                } else {
                    "/etc/php/8.5/fpm/php-fpm.conf"
                };
                assert!(fs::read_to_string(self.files.path(path))
                    .unwrap()
                    .contains("process_control_timeout = 30s"));
            }
            self.inner.execute(call)
        }
    }
    let mut f = fixture();
    f.host.active.extend([UNIT.into(), DEDICATED.into()]);
    let mut host = CheckingHost {
        inner: &mut f.host,
        files: &f.files,
    };
    let result = php_profile::configure(&mut host, &f.files, &input()).unwrap();
    assert!(result.changed && result.reloaded);
    assert_eq!(
        fs::read_to_string(f.files.path("/etc/orbit/example.conf"))
            .unwrap()
            .matches("process_control_timeout = 30s")
            .count(),
        1
    );
}
