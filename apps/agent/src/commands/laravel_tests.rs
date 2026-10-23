//! The development Laravel commands against a sandbox checkout that the test user owns.

use super::*;
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

    fn input(&self) -> UrlInput {
        UrlInput {
            directory: self.0.clone(),
            owner: super::super::source::user_name(rustix::process::geteuid().as_raw()),
            url: "https://shop.test".into(),
            app_key: "APP_KEY=\"base64:stored\"".into(),
            app_key_stored: true,
            app_name: "APP_NAME=\"Shop\"".into(),
            app_name_stored: false,
            require_artisan: false,
        }
    }
}

impl Drop for App {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}

#[test]
fn a_new_env_takes_the_template_the_stored_key_and_the_project_name() {
    let app = App::new();
    fs::write(
        app.0.join(".env.example"),
        "APP_NAME=Laravel\nAPP_KEY=base64:template\nAPP_URL=http://localhost\n",
    )
    .unwrap();
    fs::set_permissions(
        app.0.join(".env.example"),
        fs::Permissions::from_mode(0o644),
    )
    .unwrap();

    assert_eq!(write_url(&app.input()), Ok(Written { written: true }));

    assert_eq!(
        fs::read_to_string(app.0.join(".env")).unwrap(),
        "APP_NAME=\"Shop\"\nAPP_KEY=\"base64:stored\"\nAPP_URL=https://shop.test\n"
    );
    assert_eq!(
        fs::metadata(app.0.join(".env")).unwrap().mode() & 0o777,
        0o640,
        "others never read it"
    );
}

#[test]
fn an_existing_env_keeps_its_key_and_name_and_only_changes_its_url() {
    let app = App::new();
    fs::write(
        app.0.join(".env"),
        "APP_NAME=Mine\nAPP_KEY=base64:kept\nAPP_URL=http://old.test\n",
    )
    .unwrap();
    let mut input = app.input();
    input.app_key_stored = false;

    assert_eq!(write_url(&input), Ok(Written { written: true }));
    assert_eq!(
        fs::read_to_string(app.0.join(".env")).unwrap(),
        "APP_NAME=Mine\nAPP_KEY=base64:kept\nAPP_URL=https://shop.test\n"
    );
    assert_eq!(write_url(&input), Ok(Written { written: false }));
}

#[test]
fn rewrites_the_one_cached_app_url_and_refuses_an_ambiguous_cache() {
    let app = App::new();
    fs::write(app.0.join(".env"), "APP_URL=http://old.test\n").unwrap();
    let cache = app.0.join("bootstrap/cache/config.php");
    fs::write(&cache, "<?php return array (\n  'app' => array (\n    'name' => 'Shop',\n    'url' => 'http://old.test',\n  ),\n  'mail' => array ('url' => 'smtp://x'),\n);\n").unwrap();
    let mut input = app.input();
    input.url = "https://o'k.test".into();

    write_url(&input).unwrap();

    assert!(fs::read_to_string(&cache)
        .unwrap()
        .contains("'url' => 'https://o\\'k.test',"));
    assert!(fs::read_to_string(&cache)
        .unwrap()
        .contains("'url' => 'smtp://x'"));

    fs::write(
        &cache,
        "<?php return ['app' => ['url' => 'a', 'url' => 'b']];",
    )
    .unwrap();
    assert!(write_url(&input).is_err());
}

#[test]
fn leaves_another_application_without_artisan_alone_and_refuses_a_linked_env() {
    let app = App::new();
    fs::remove_file(app.0.join("artisan")).unwrap();
    let mut input = app.input();
    input.require_artisan = true;
    assert_eq!(write_url(&input), Ok(Written { written: false }));
    assert!(!app.0.join(".env").exists());

    symlink("/etc/hostname", app.0.join(".env")).unwrap();
    input.require_artisan = false;
    assert!(write_url(&input).is_err());
}

#[test]
fn reads_the_development_profile_and_refuses_a_linked_manifest() {
    let app = App::new();
    fs::write(app.0.join("composer.json"), "{}").unwrap();
    let input = ProfileInput {
        application: app.0.clone(),
        owner: app.input().owner,
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
