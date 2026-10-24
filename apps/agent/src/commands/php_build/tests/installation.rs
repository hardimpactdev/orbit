use super::*;

#[test]
fn install_exact_pins_then_repeat_without_downloads_or_restarts() {
    let mut f = Fixture::new();
    let report = f.apply().unwrap();
    assert!(matches!(report.outcome, operations::Outcome::Applied));
    assert_eq!(report.data.changed, ["php8.5-cli", "php8.5-fpm"]);
    assert_eq!(f.host.packages["php8.5-cli"], "2.0");
    assert_eq!(paths(&f.files.path(CACHE)).unwrap().len(), 2);
    assert!(!f.files.path("/usr/sbin/policy-rc.d").exists());
    assert!(f.files.path("/etc/php/8.5/fpm/pool.d/www.conf").exists());
    f.host.calls.clear();
    let repeat = f.apply().unwrap();
    assert!(matches!(repeat.outcome, operations::Outcome::Current));
    assert!(repeat.data.changed.is_empty());
    assert!(repeat.data.restarted.is_empty());
    assert!(f.calls("curl").is_empty());
    assert!(f.calls("apt-get").is_empty());
}

#[test]
fn failed_partial_install_restores_previous_packages_and_removes_revived_additions() {
    for revive in [false, true] {
        let mut f = Fixture::new();
        f.old();
        let added = pin("php8.5-added", "2.0");
        f.host
            .artifacts
            .insert(added.url.clone(), bytes(&added.name, "2.0", "amd64"));
        f.input
            .versions
            .get_mut("8.5")
            .unwrap()
            .packages
            .push(added);
        f.host.revive = revive;
        f.fail("apt-get", 1);
        let error = f.apply().unwrap_err();
        assert_eq!(error.kind, Kind::InstallFailed);
        assert_eq!(error.data.restored, Some(true));
        assert_eq!(f.host.packages["php8.5-cli"], "1.0");
        assert_eq!(f.host.packages["php8.5-fpm"], "1.0");
        assert!(!f.host.packages.contains_key("php8.5-added"));
        assert_eq!(
            f.calls("dpkg")
                .iter()
                .filter(|c| c.argv[1] == "--remove")
                .count(),
            if revive { 2 } else { 1 }
        );
        assert!(!f.files.path("/usr/sbin/policy-rc.d").exists());
        assert!(!f.files.path("/etc/php/8.5/fpm/pool.d/www.conf").exists());
    }
}

#[test]
fn failing_rollback_has_a_distinct_error() {
    let mut f = Fixture::new();
    f.old();
    f.fail("apt-get", 2);
    let error = f.apply().unwrap_err();
    assert_eq!(error.kind, Kind::RollbackFailed);
    assert_eq!(error.data.restored, None);
}

#[test]
fn failed_purge_restores_extra_package() {
    let mut f = Fixture::new();
    f.current();
    f.input.extras = Extras::Purge;
    f.host.packages.insert("php8.5-extra".into(), "1.0".into());
    let source = write(
        &f.files,
        "/extra.deb",
        bytes("php8.5-extra", "1.0", "amd64"),
    );
    packages::keep(&f.files, &source, "php8.5-extra").unwrap();
    f.fail("dpkg --purge", 1);
    let error = f.apply().unwrap_err();
    assert_eq!(error.kind, Kind::InstallFailed);
    assert_eq!(error.data.restored, Some(true));
    assert_eq!(f.host.packages["php8.5-extra"], "1.0");
}

#[test]
fn repairs_package_files_but_keeps_modified_conffiles() {
    for (damage, repaired) in [
        ("??5?????? /usr/bin/php8.5", true),
        ("missing /usr/bin/php8.5", true),
        ("??5?????? c /etc/php/8.5/cli/php.ini", false),
        ("", false),
    ] {
        let mut f = Fixture::new();
        f.current();
        f.host.damaged.insert("php8.5-cli".into(), damage.into());
        let report = f.apply().unwrap();
        assert_eq!(!report.data.repaired.is_empty(), repaired);
        assert_eq!(
            f.calls("apt-get")
                .iter()
                .any(|c| c.argv.contains(&"--reinstall".into())),
            repaired
        );
    }
}

#[test]
fn repack_removes_absent_conffiles_and_preserves_disabled_default_pool() {
    let mut f = Fixture::new();
    f.host.packages.insert("php8.5-fpm".into(), "1.0".into());
    f.apply().unwrap();
    assert!(f
        .calls("env")
        .iter()
        .any(|c| c.argv.contains(&"dpkg-repack".into())));
    assert!(!f.files.path("/etc/php/8.5/fpm/pool.d/www.conf").exists());
    for call in f
        .calls("dpkg-deb")
        .iter()
        .filter(|c| c.argv[1] == "--build")
    {
        assert!(
            !Path::new(&call.argv[3]).exists(),
            "repack work directory must be removed"
        );
    }
}

#[test]
fn absent_rollback_archive_refuses_before_replacing_packages() {
    let mut f = Fixture::new();
    f.host.packages.insert("php8.5-fpm".into(), "1.0".into());
    f.host.empty_repack = true;
    assert_eq!(f.apply().unwrap_err().kind, Kind::RollbackUnavailable);
    assert_eq!(f.host.packages["php8.5-fpm"], "1.0");
    assert!(!f.host.packages.contains_key("php8.5-cli"));
    assert!(f
        .calls("apt-get")
        .iter()
        .all(|c| !c.argv.contains(&"--".into())));
}

#[test]
fn preserves_enabled_default_pool_and_preexisting_restart_policy() {
    let mut f = Fixture::new();
    f.old();
    let pool = write(
        &f.files,
        "/etc/php/8.5/fpm/pool.d/www.conf",
        "listen=/run/php/default.sock\n",
    );
    let policy = write(&f.files, "/usr/sbin/policy-rc.d", "existing policy");
    f.apply().unwrap();
    assert!(pool.exists());
    assert_eq!(fs::read_to_string(policy).unwrap(), "existing policy");
}

#[test]
fn unowned_extensions_and_module_links_are_restored_after_failed_validation() {
    let mut f = Fixture::new();
    f.current();
    f.input.extras = Extras::Purge;
    let ini = write(
        &f.files,
        "/etc/php/8.5/mods-available/custom.ini",
        "extension=custom.so\n",
    );
    let library = write(
        &f.files,
        "/usr/lib/php/20250925/custom.so",
        "custom library",
    );
    let link = module_link(&f.files, "cli", "custom");
    f.host.cli_extra = "unexpected".into();
    let error = f.apply().unwrap_err();
    assert_eq!(error.kind, Kind::ModulesMismatch);
    assert_eq!(error.data.restored, Some(true));
    assert_eq!(fs::read_to_string(ini).unwrap(), "extension=custom.so\n");
    assert_eq!(fs::read_to_string(library).unwrap(), "custom library");
    assert_eq!(
        fs::read_link(link).unwrap(),
        Path::new("../../mods-available/custom.ini")
    );
    assert!(!f
        .files
        .path("/etc/php/8.5/cli/conf.d/99-cve-2026-109618-upload-progress.ini")
        .exists());
}
