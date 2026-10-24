use super::*;

#[test]
fn inspection_reports_pending_without_writing_settings_or_cache() {
    let mut f = Fixture::new();
    assert_eq!(
        f.inspect().unwrap().plan.pending,
        ["php8.5-cli", "php8.5-fpm"]
    );
    assert!(!f.files.path(CACHE).exists());
    assert!(paths(&f.files.path("/etc/php/8.5/cli/conf.d"))
        .unwrap()
        .is_empty());
    assert!(f.calls("apt-get").is_empty());
}

#[test]
fn invalid_input_and_platform_refuse_before_program_execution() {
    let mut f = Fixture::new();
    f.input.repack.package = "other-program".into();
    assert_eq!(f.apply().unwrap_err().kind, Kind::InputInvalid);
    assert!(f.host.calls.is_empty());
    f.input.repack.package = "dpkg-repack".into();
    write(
        &f.files,
        "/etc/os-release",
        "ID=debian\nVERSION_CODENAME=trixie\n",
    );
    assert_eq!(f.apply().unwrap_err().kind, Kind::PlatformUnsupported);
    assert!(f.host.calls.is_empty());
    assert!(!f.files.path(CACHE).exists());
}

#[test]
fn unavailable_architecture_and_conflicting_pins_refuse_before_changes() {
    let mut f = Fixture::new();
    f.host.arch = "arm64".into();
    assert_eq!(f.apply().unwrap_err().kind, Kind::DownloadFailed);
    assert!(!f.files.path(CACHE).exists());
    f.host.arch = "amd64".into();
    let mut other = f.input.versions["8.5"].clone();
    other.packages[0].version = "3.0".into();
    f.input.versions.insert("8.6".into(), other);
    assert_eq!(f.apply().unwrap_err().kind, Kind::InputInvalid);
    assert!(!f.files.path(CACHE).exists());
}

#[test]
fn sources_and_extra_packages_refuse_before_changes() {
    let mut f = Fixture::new();
    let source = write(
        &f.files,
        "/etc/apt/sources.list.d/custom.sources",
        "URIs: https://packages.sury.org/php\n",
    );
    assert_eq!(f.apply().unwrap_err().kind, Kind::ConflictingSource);
    assert!(source.exists());
    assert!(!f.files.path(CACHE).exists());
    fs::remove_file(source).unwrap();
    f.current();
    f.host.packages.insert("php8.5-custom".into(), "1.0".into());
    assert_eq!(f.apply().unwrap_err().kind, Kind::ExtraPackages);
    assert!(!f.files.path(CACHE).exists());
    f.input.extras = Extras::Keep;
    f.apply().unwrap();
    assert_eq!(f.host.packages["php8.5-custom"], "1.0");
}

#[test]
fn retired_source_is_removed_and_disabled_opentelemetry_package_is_allowed() {
    let mut f = Fixture::new();
    f.current();
    f.host
        .packages
        .insert("php8.5-opentelemetry".into(), "1.0".into());
    let source = write(
        &f.files,
        RETIRED_SOURCE,
        "URIs: https://packages.sury.org/php\n",
    );
    let key = write(&f.files, RETIRED_KEY, "retired");
    assert!(f.inspect().unwrap().plan.extras.is_empty());
    assert!(source.exists());
    f.apply().unwrap();
    assert!(!source.exists());
    assert!(!key.exists());
    assert!(f.host.packages.contains_key("php8.5-opentelemetry"));
}

#[test]
fn unowned_libraries_use_module_names_and_batch_ownership_lookups() {
    let mut f = Fixture::new();
    write(
        &f.files,
        "/etc/php/8.5/mods-available/settings.ini",
        "session.upload_progress.enabled=0\n",
    );
    write(
        &f.files,
        "/etc/php/8.5/mods-available/arbitrary.ini",
        "zend_extension=opcache.so\nextension=custom.so\n",
    );
    write(&f.files, "/usr/lib/php/20250925/custom.so", "custom");
    let error = f.apply().unwrap_err();
    assert_eq!(error.kind, Kind::UnownedExtensions);
    assert_eq!(
        error.data.extensions.unwrap(),
        ["php8.5:custom+zend opcache"]
    );
    assert!(!f.files.path(CACHE).exists());
    assert_eq!(
        f.calls("dpkg").iter().filter(|c| c.argv[1] == "-S").count(),
        1
    );
}

#[test]
fn packaged_libraries_and_owned_ini_files_follow_package_rules() {
    let mut f = Fixture::new();
    f.current();
    let ini = write(
        &f.files,
        "/etc/php/8.5/mods-available/with,comma.ini",
        "extension=custom.so\n",
    );
    f.host.owned.insert(ini);
    write(
        &f.files,
        "/etc/php/8.5/mods-available/curl.ini",
        "extension=curl.so\n",
    );
    let library = write(&f.files, "/usr/lib/php/20250925/curl.so", "packaged");
    f.host.owned.insert(library.clone());
    assert!(f.inspect().unwrap().plan.unowned.is_empty());
    f.host.owned.remove(&library);
    assert_eq!(f.inspect().err().unwrap().kind, Kind::UnownedExtensions);
    f.fail("dpkg -S", 1);
    assert_eq!(f.inspect().err().unwrap().kind, Kind::ExecutionFailed);
}
