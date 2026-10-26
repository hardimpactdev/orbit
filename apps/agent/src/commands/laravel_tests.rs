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
fn invalid_cache_refuses_before_creating_changing_or_chmodding_the_environment() {
    for cache in [
        "<?php return ['app' => ['url' => 'a', 'url' => 'b']];",
        "<?php return ['app' => ['url' => getenv('APP_URL')]];",
        "<?php return ['app' => ['url' => 'a'];",
    ] {
        for environment in [
            None,
            Some("APP_URL=http://old.test\n"),
            Some("APP_URL=https://shop.test\n"),
        ] {
            let app = App::new();
            let env_path = app.0.join(".env");
            if let Some(bytes) = environment {
                fs::write(&env_path, bytes).unwrap();
                fs::set_permissions(&env_path, fs::Permissions::from_mode(0o644)).unwrap();
            }
            let cache_path = app.0.join("bootstrap/cache/config.php");
            fs::write(&cache_path, cache).unwrap();
            fs::set_permissions(&cache_path, fs::Permissions::from_mode(0o640)).unwrap();

            assert!(write_url(&app.input()).is_err());
            assert_eq!(fs::read_to_string(&env_path).ok().as_deref(), environment);
            if environment.is_some() {
                assert_eq!(fs::metadata(&env_path).unwrap().mode() & 0o777, 0o644);
            }
            assert_eq!(fs::read_to_string(&cache_path).unwrap(), cache);
            assert_eq!(fs::metadata(&cache_path).unwrap().mode() & 0o777, 0o640);
        }
    }
}

#[test]
fn linked_cache_ancestors_refuse_before_changing_any_file() {
    for ancestor in ["bootstrap", "bootstrap/cache"] {
        for has_cache in [false, true] {
            let app = App::new();
            let outside = App::new();
            let cache = "<?php return ['app' => ['url' => 'http://old.test']];";
            let outside_cache = outside.0.join("bootstrap/cache/config.php");
            if has_cache {
                fs::write(&outside_cache, cache).unwrap();
                fs::set_permissions(&outside_cache, fs::Permissions::from_mode(0o640)).unwrap();
            }
            fs::write(app.0.join(".env"), "APP_URL=http://old.test\n").unwrap();
            fs::set_permissions(app.0.join(".env"), fs::Permissions::from_mode(0o644)).unwrap();
            fs::remove_dir_all(app.0.join(ancestor)).unwrap();
            symlink(outside.0.join(ancestor), app.0.join(ancestor)).unwrap();

            assert!(write_url(&app.input()).is_err());
            assert_eq!(
                fs::read_to_string(app.0.join(".env")).unwrap(),
                "APP_URL=http://old.test\n"
            );
            assert_eq!(
                fs::metadata(app.0.join(".env")).unwrap().mode() & 0o777,
                0o644
            );
            assert_eq!(
                fs::read_to_string(&outside_cache).ok().as_deref(),
                has_cache.then_some(cache)
            );
            if has_cache {
                assert_eq!(fs::metadata(&outside_cache).unwrap().mode() & 0o777, 0o640);
            }
        }
    }
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

#[test]
fn url_changes_preserve_other_bytes_modes_and_later_operator_changes() {
    let app = App::new();
    let env = app.0.join(".env");
    let cache = app.0.join("bootstrap/cache/config.php");
    let mut input = app.input();
    for (name, old, next) in [("Acme", "old", "feature"), ("Operator", "operator", "next")] {
        let environment = format!("APP_NAME={name}\nAPP_URL=https://{old}.test\nTOKEN=unchanged\n");
        let configuration = format!("<?php return ['app' => ['name' => '{name}', 'url' => 'https://{old}.test'], 'filesystems' => ['disks' => ['public' => ['url' => 'https://files.test/storage']]], 'token' => 'unchanged'];");
        fs::write(&env, &environment).unwrap();
        fs::write(&cache, &configuration).unwrap();
        fs::set_permissions(&env, fs::Permissions::from_mode(0o640)).unwrap();
        fs::set_permissions(&cache, fs::Permissions::from_mode(0o644)).unwrap();
        input.url = format!("https://{next}.test");

        assert_eq!(write_url(&input), Ok(Written { written: true }));
        assert_eq!(write_url(&input), Ok(Written { written: false }));
        assert_eq!(
            fs::read_to_string(&env).unwrap(),
            environment.replace(&format!("https://{old}.test"), &input.url)
        );
        assert_eq!(
            fs::read_to_string(&cache).unwrap(),
            configuration.replace(&format!("https://{old}.test"), &input.url)
        );
        assert_eq!(fs::metadata(&env).unwrap().mode() & 0o777, 0o640);
        assert_eq!(fs::metadata(&cache).unwrap().mode() & 0o777, 0o644);
    }
}

#[test]
fn template_and_existing_keys_follow_creation_rules_and_environment_stays_private() {
    for existing in [false, true] {
        for key in ["", "base64:template"] {
            let app = App::new();
            let original = format!("APP_NAME=Template\nAPP_KEY={key}\nAPP_URL=https://shop.test\n");
            let path = app.0.join(if existing { ".env" } else { ".env.example" });
            fs::write(&path, &original).unwrap();
            fs::set_permissions(&path, fs::Permissions::from_mode(0o664)).unwrap();
            let mut input = app.input();
            input.app_key_stored = false;
            write_url(&input).unwrap();
            let expected = if key.is_empty() {
                original.replace("APP_KEY=\n", "APP_KEY=\"base64:stored\"\n")
            } else {
                original.clone()
            };
            assert_eq!(fs::read_to_string(app.0.join(".env")).unwrap(), expected);
            assert_eq!(
                fs::metadata(app.0.join(".env")).unwrap().mode() & 0o777,
                0o660
            );
            assert_eq!(write_url(&input), Ok(Written { written: false }));
            if !existing {
                assert_eq!(fs::read_to_string(&path).unwrap(), original);
                assert_eq!(fs::metadata(&path).unwrap().mode() & 0o777, 0o664);
            }
        }
    }
}

#[test]
fn duplicate_url_and_unsafe_files_refuse_without_mutation() {
    for unsafe_file in [".env", ".env.example", "bootstrap/cache/config.php"] {
        let app = App::new();
        let outside = App::new();
        fs::write(outside.0.join("value"), "outside\n").unwrap();
        symlink(outside.0.join("value"), app.0.join(unsafe_file)).unwrap();
        assert!(write_url(&app.input()).is_err());
        assert_eq!(
            fs::read_to_string(outside.0.join("value")).unwrap(),
            "outside\n"
        );
        if unsafe_file != ".env" {
            assert!(!app.0.join(".env").exists());
        }
    }
    let app = App::new();
    let original = "APP_URL=https://first.test\nAPP_NAME=Acme\nAPP_URL=https://second.test\n";
    fs::write(app.0.join(".env"), original).unwrap();
    assert!(write_url(&app.input()).is_err());
    assert_eq!(fs::read_to_string(app.0.join(".env")).unwrap(), original);
    let mut input = app.input();
    input.owner = "orbit-nonexistent-laravel-owner".into();
    assert!(write_url(&input).is_err());
    assert_eq!(fs::read_to_string(app.0.join(".env")).unwrap(), original);
}

#[test]
fn nested_application_writes_leave_repository_environment_alone_and_refuse_linked_parents() {
    let app = App::new();
    let nested = app.0.join("server/web");
    fs::create_dir_all(&nested).unwrap();
    fs::write(app.0.join(".env"), "APP_URL=https://repository.test\n").unwrap();
    fs::write(nested.join("artisan"), "").unwrap();
    fs::write(nested.join("composer.json"), "{}").unwrap();
    let mut input = app.input();
    input.directory = nested.clone();
    input.require_artisan = true;
    assert!(write_url(&input).unwrap().written);
    assert_eq!(
        fs::read_to_string(app.0.join(".env")).unwrap(),
        "APP_URL=https://repository.test\n"
    );
    fs::remove_file(nested.join("artisan")).unwrap();
    let previous = fs::read(nested.join(".env")).unwrap();
    input.url = "https://different.test".into();
    assert!(!write_url(&input).unwrap().written);
    assert_eq!(fs::read(nested.join(".env")).unwrap(), previous);

    symlink(app.0.join("server"), app.0.join("linked")).unwrap();
    input.directory = app.0.join("linked/web");
    assert!(write_url(&input).is_err());
    let profile_input = ProfileInput {
        application: input.directory.clone(),
        owner: input.owner.clone(),
        entry_point: "artisan".into(),
    };
    assert_eq!(
        profile(&profile_input).unwrap().kind,
        super::super::production_home::ProfileKind::Unsafe
    );
    assert_eq!(fs::read(nested.join(".env")).unwrap(), previous);
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
