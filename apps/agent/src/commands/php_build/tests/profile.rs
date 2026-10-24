use super::*;
use crate::commands::php_profile::{self, Error, Input};

fn input() -> Input {
    Input { version: "8.5".into(), runtime_ini: "; priority=99\nopcache.enable = On\nopcache.memory_consumption = 512\nopcache.interned_strings_buffer = 64\nopcache.max_accelerated_files = 65407\nopcache.jit = disable\nopcache.jit_buffer_size = 0\n".into(),
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
        f.fail("systemctl reload-or-restart", 1);
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
