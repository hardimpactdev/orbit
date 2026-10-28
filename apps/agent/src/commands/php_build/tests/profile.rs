use super::*;
use crate::commands::php_profile::{self, Error, Input};

fn input() -> Input {
    Input { version: "8.5".into(), runtime_ini: "; priority=99\npost_max_size = 64M\nupload_max_filesize = 64M\nopcache.enable = On\nopcache.memory_consumption = 512\nopcache.interned_strings_buffer = 64\nopcache.max_accelerated_files = 65407\nopcache.jit = disable\nopcache.jit_buffer_size = 0\n".into(),
        release: Release { id: "ubuntu".into(), codenames: vec!["resolute".into()] } }
}

#[test]
fn shared_module_is_fpm_only_and_reloads_only_when_changed_and_valid() {
    for (active, valid) in [(true, true), (true, false), (false, true)] {
        let mut f = Fixture::new();
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
        let mut f = Fixture::new();
        let mut input = input();
        input.runtime_ini.push_str(invalid);
        assert!(matches!(
            php_profile::configure(&mut f.host, &f.files, &input),
            Err(Error::InputInvalid {})
        ));
        assert!(f.host.calls.is_empty());
    }
    let mut f = Fixture::new();
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
            "9223372036854775808",
            "8589934592G",
            "18446744073709551616M",
        ] {
            let mut f = Fixture::new();
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
            let mut f = Fixture::new();
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
        let mut f = Fixture::new();
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
        let mut f = Fixture::new();
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
    let mut f = Fixture::new();
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
        let mut f = Fixture::new();
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
    let mut f = Fixture::new();
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
