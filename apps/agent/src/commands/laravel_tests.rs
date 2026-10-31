//! The development Laravel commands against a sandbox checkout that the test user owns.

use super::*;
use std::fs;
use std::os::unix::fs::symlink;

struct App(PathBuf);

impl App {
    fn new() -> Self {
        let path = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!("orbit-laravel-{:016x}", rand::random::<u64>()));
        fs::create_dir_all(path.join("bootstrap/cache")).unwrap();
        fs::write(path.join("artisan"), "").unwrap();
        Self(path)
    }
}

impl Drop for App {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}

#[test]
fn reads_the_development_profile_and_refuses_a_linked_manifest() {
    let app = App::new();
    fs::write(app.0.join("composer.json"), "{}").unwrap();
    let input = ProfileInput {
        application: app.0.clone(),
        owner: super::super::source::user_name(rustix::process::geteuid().as_raw()),
        entry_point: "artisan".into(),
    };

    let read = profile(&input).unwrap();
    assert_eq!(
        read.kind,
        super::super::production_home::ProfileKind::Composer
    );
    assert_eq!(read.entry_point.as_deref(), Some("regular"));

    fs::remove_file(app.0.join("composer.json")).unwrap();
    symlink("/etc/hostname", app.0.join("composer.json")).unwrap();
    assert_eq!(
        profile(&input).unwrap().kind,
        super::super::production_home::ProfileKind::Unsafe
    );
}

#[test]
fn composer_metadata_owned_by_another_account_is_unsafe() {
    let app = App::new();
    fs::write(app.0.join("composer.json"), "{}").unwrap();
    let uid = rustix::process::geteuid().as_raw();
    assert_eq!(
        composer_profile(&app.0, "artisan", uid + 1).unwrap().kind,
        super::super::production_home::ProfileKind::Unsafe
    );
}
