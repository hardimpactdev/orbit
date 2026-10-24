//! The home commands against a sandbox home that the test user owns, as the production user owns one on a Node. The
//! source state belongs to the test user here, as it belongs to root there. Creating and removing a system account
//! needs root, so these tests cover the rules around it.

use super::*;
use std::os::unix::fs::symlink;

fn sh_git(directory: &Path, arguments: &[&str]) -> String {
    let output = Process::new("git")
        .args([
            "-c",
            "user.name=Orbit",
            "-c",
            "user.email=orbit@example.test",
            "-c",
            "init.defaultBranch=main",
        ])
        .args(arguments)
        .current_dir(directory)
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "git {arguments:?}: {}",
        String::from_utf8_lossy(&output.stderr)
    );
    String::from_utf8(output.stdout).unwrap().trim().to_string()
}

struct Fixture {
    base: PathBuf,
    layout: Layout,
    home: Home,
}

impl Fixture {
    /// An origin whose `main` holds `public/` and a tracked `.env`, and an empty home.
    fn new() -> Self {
        let base = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!(
                "orbit-production-home-{:016x}",
                rand::random::<u64>()
            ));
        let work = base.join("work");
        fs::create_dir_all(work.join("public")).unwrap();
        fs::write(work.join("public/index.php"), "<?php\n").unwrap();
        fs::write(work.join(".env"), "APP_KEY=\n").unwrap();
        fs::write(
            work.join("composer.json"),
            "{\"require\":{\"php\":\"^8.4\"}}",
        )
        .unwrap();
        sh_git(&work, &["init", "--quiet"]);
        sh_git(&work, &["add", "."]);
        sh_git(&work, &["commit", "--quiet", "-m", "one"]);
        let origin = base.join("origin.git");
        sh_git(
            &base,
            &[
                "clone",
                "--quiet",
                "--bare",
                work.to_str().unwrap(),
                origin.to_str().unwrap(),
            ],
        );
        let uid = rustix::process::geteuid().as_raw();
        let user = super::super::source::user_name(uid);
        let home = base.join("home").join(&user);
        fs::create_dir_all(&home).unwrap();
        fs::set_permissions(&home, fs::Permissions::from_mode(0o700)).unwrap();
        Self {
            layout: Layout {
                home_root: base.join("home"),
                state_root: base.join("var/lib/orbit/app-instance-sources"),
                root_uid: uid,
            },
            home: Home {
                repository: origin.display().to_string(),
                user,
                home,
                instance: 7,
            },
            base,
        }
    }

    fn source(&self, allow_existing: bool) -> SourceInput {
        SourceInput {
            home: self.home.clone(),
            allow_existing,
            git_read: GitRead::default(),
        }
    }

    fn release_home(&self) -> ReleaseHome {
        ReleaseHome {
            repository: self.home.repository.clone(),
            user: self.home.user.clone(),
            home: self.home.home.clone(),
            instance: self.home.instance,
            relative_root: "public".into(),
            application_suffix: String::new(),
            served: Vec::new(),
        }
    }

    fn resolve(&self, clone_target: bool) -> ResolveInput {
        ResolveInput {
            home: self.release_home(),
            branch: "main".into(),
            clone_target,
            git_read: GitRead::default(),
        }
    }
}

impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.base);
    }
}

#[test]
fn clones_the_initial_release_into_an_empty_home_with_its_markers() {
    let fixture = Fixture::new();

    assert_eq!(
        prepare_source(&fixture.source(false), &fixture.layout),
        Ok(Prepared {})
    );

    let state = fixture.layout.state_root.join("7");
    assert_eq!(
        fs::read(state.join("initial-clone")).unwrap(),
        fixture.home.clone_marker()
    );
    assert_eq!(
        fs::read(state.join("release-layout")).unwrap(),
        fixture.home.layout_marker()
    );
    assert_eq!(fs::metadata(&state).unwrap().mode() & 0o7777, 0o700);
    for path in [
        "var",
        "var/lib",
        "var/lib/orbit",
        "var/lib/orbit/app-instance-sources",
    ] {
        assert_eq!(
            fs::metadata(fixture.base.join(path)).unwrap().mode() & 0o7777,
            0o700
        );
    }
    assert_eq!(
        fs::metadata(state.join("release-layout")).unwrap().mode() & 0o7777,
        0o600
    );
    assert_eq!(
        fs::metadata(fixture.home.home.join(".env")).unwrap().mode() & 0o7777,
        0o600
    );
    assert!(fixture.home.home.join("releases/initial/.git").is_dir());

    assert_eq!(
        prepare_source(&fixture.source(true), &fixture.layout),
        Ok(Prepared {})
    );
    assert_eq!(
        prepare_source(&fixture.source(false), &fixture.layout),
        Err(invalid("the initial clone is new"))
    );
}

#[test]
fn refuses_a_home_that_is_not_empty_or_another_repository() {
    let fixture = Fixture::new();
    fs::write(fixture.home.home.join("stray"), "").unwrap();
    assert_eq!(
        prepare_source(&fixture.source(false), &fixture.layout),
        Err(invalid("the home is empty"))
    );

    let fixture = Fixture::new();
    prepare_source(&fixture.source(false), &fixture.layout).unwrap();
    let mut other = fixture.source(true);
    other.home.repository = "https://example.test/other.git".into();
    assert_eq!(
        prepare_source(&other, &fixture.layout),
        Err(invalid("the clone marker names this home"))
    );
}

#[test]
fn checks_out_the_branch_and_replaces_a_tracked_env_only_for_a_clone_target() {
    let fixture = Fixture::new();
    prepare_source(&fixture.source(false), &fixture.layout).unwrap();

    assert_eq!(
        resolve(&fixture.resolve(false), &fixture.layout),
        Err(invalid("only a clone target replaces a tracked .env"))
    );

    let resolved = resolve(&fixture.resolve(true), &fixture.layout).unwrap();
    let link = fixture.home.home.join("releases/initial/.env");
    assert_eq!(resolved.branch, "main");
    assert_eq!(resolved.commit.len(), 40);
    assert_eq!(fs::read_link(&link).unwrap(), Path::new("../../.env"));
    assert_eq!(
        resolve(&fixture.resolve(false), &fixture.layout).unwrap(),
        resolved
    );
}

#[test]
fn reads_the_composer_profile_and_refuses_unsafe_metadata() {
    let fixture = Fixture::new();
    prepare_source(&fixture.source(false), &fixture.layout).unwrap();
    resolve(&fixture.resolve(true), &fixture.layout).unwrap();
    let checkout = fixture.home.home.join("releases/initial");
    let input = |relative_root: &str| ProfileInput {
        user: fixture.home.user.clone(),
        relative_root: relative_root.into(),
        checkout: checkout.clone(),
        application: checkout.clone(),
        entry_point: "artisan".into(),
    };

    let read = profile(&input("public")).unwrap();
    assert_eq!(read.kind, ProfileKind::Composer);
    assert_eq!(read.entry_point.as_deref(), Some("absent"));
    let composer = base64::engine::general_purpose::STANDARD
        .decode(read.composer.unwrap())
        .unwrap();
    assert_eq!(composer, b"{\"require\":{\"php\":\"^8.4\"}}");

    assert_eq!(profile(&input("../escape")).unwrap(), unsafe_profile());
    fs::remove_file(checkout.join("composer.json")).unwrap();
    symlink("/etc/hostname", checkout.join("composer.json")).unwrap();
    assert_eq!(profile(&input("public")).unwrap(), unsafe_profile());
    fs::remove_file(checkout.join("composer.json")).unwrap();
    assert_eq!(profile(&input("public")).unwrap().kind, ProfileKind::None);
    fs::write(checkout.join("artisan"), "").unwrap();
    assert_eq!(
        profile(&input("public")).unwrap().kind,
        ProfileKind::Partial
    );
}

#[test]
fn validates_the_current_selection_and_clears_it() {
    let fixture = Fixture::new();
    prepare_source(&fixture.source(false), &fixture.layout).unwrap();
    resolve(&fixture.resolve(true), &fixture.layout).unwrap();
    let input = LayoutInput {
        home: fixture.release_home(),
    };
    assert_eq!(
        validate_layout(&input, &fixture.layout),
        Ok(Selection { current: None })
    );

    symlink("releases/initial", fixture.home.home.join("current")).unwrap();
    assert_eq!(
        validate_layout(&input, &fixture.layout),
        Ok(Selection {
            current: Some("initial".into())
        })
    );

    assert_eq!(
        clear_current(&input, &fixture.layout),
        Ok(Selection { current: None })
    );
    assert!(!exists(&fixture.home.home.join("current")));
}

#[test]
fn refuses_a_selection_whose_environment_is_not_the_stable_one() {
    let fixture = Fixture::new();
    prepare_source(&fixture.source(false), &fixture.layout).unwrap();
    resolve(&fixture.resolve(true), &fixture.layout).unwrap();
    let link = fixture.home.home.join("releases/initial/.env");
    fs::remove_file(&link).unwrap();
    fs::write(&link, "APP_KEY=other\n").unwrap();
    symlink("releases/initial", fixture.home.home.join("current")).unwrap();

    assert_eq!(
        clear_current(
            &LayoutInput {
                home: fixture.release_home()
            },
            &fixture.layout
        ),
        Err(invalid("the selected release links the stable environment"))
    );
    assert!(is_symlink(&fixture.home.home.join("current")));
}

#[test]
fn refuses_caddy_access_to_a_web_root_that_holds_a_link() {
    let fixture = Fixture::new();
    prepare_source(&fixture.source(false), &fixture.layout).unwrap();
    resolve(&fixture.resolve(true), &fixture.layout).unwrap();
    symlink(
        "/etc",
        fixture.home.home.join("releases/initial/public/escape"),
    )
    .unwrap();

    assert_eq!(
        grant_caddy(
            &CaddyInput {
                user: fixture.home.user.clone(),
                home: fixture.home.home.clone(),
                relative_root: "public".into(),
                clone_target: true,
            },
            &fixture.layout
        ),
        Err(invalid("the web root holds no links"))
    );
}

#[test]
fn removal_clears_leftover_state_and_does_nothing_twice() {
    let base = fs::canonicalize(std::env::temp_dir())
        .unwrap()
        .join(format!(
            "orbit-production-removal-{:016x}",
            rand::random::<u64>()
        ));
    let layout = Layout {
        home_root: base.join("home"),
        state_root: base.join("state"),
        root_uid: rustix::process::geteuid().as_raw(),
    };
    let home = Home {
        repository: "https://example.test/app.git".into(),
        user: "orbit-app-987654".into(),
        home: base.join("home/orbit-app-987654"),
        instance: 987_654,
    };
    fs::create_dir_all(layout.state_root.join("987654")).unwrap();
    fs::set_permissions(
        layout.state_root.join("987654"),
        fs::Permissions::from_mode(0o700),
    )
    .unwrap();

    assert_eq!(remove(&home, &layout), Ok(Removed { removed: true }));
    assert!(!exists(&layout.state_root.join("987654")));
    assert_eq!(remove(&home, &layout), Ok(Removed { removed: false }));
    let _ = fs::remove_dir_all(&base);
}

#[test]
fn the_account_rules_accept_only_orbit_system_accounts() {
    assert!(production_user("orbit-app-12"));
    assert!(!production_user("orbit-app-012"));
    assert!(!production_user("orbit-app-"));
    assert!(!production_user("root"));
    let entry = Entry {
        uid: 998,
        group: "orbit-app-12".into(),
        home: "/home/orbit-app-12".into(),
        shell: SHELL.into(),
    };
    assert!(entry_matches(
        &entry,
        "orbit-app-12",
        Path::new("/home/orbit-app-12")
    ));
    assert!(!entry_matches(
        &Entry { uid: 1000, ..entry },
        "orbit-app-12",
        Path::new("/home/orbit-app-12")
    ));
    let layout = Layout::system();
    assert_eq!(
        prepare_user(
            &UserInput {
                user: "orbit-app-12".into(),
                home: "/home/other".into()
            },
            &layout
        ),
        Err(invalid("the home is the user's"))
    );
    assert_eq!(
        prepare_user(
            &UserInput {
                user: "deploy".into(),
                home: "/home/deploy".into()
            },
            &layout
        ),
        Err(invalid("the user is a production user"))
    );
}

#[test]
fn realpath_missing_resolves_existing_links_and_applies_dots() {
    assert_eq!(
        realpath_missing(Path::new("/tmp/../etc/./missing/../x")),
        PathBuf::from("/etc/x")
    );
}

/// A home whose `current` selects a release with an `apps/docs` Laravel application.
fn served_home(fixture: &Fixture) -> PathBuf {
    let docs = fixture.home.home.join("releases/r1/apps/docs");
    fs::create_dir_all(&docs).unwrap();
    fs::write(docs.join("artisan"), "").unwrap();
    fs::write(
        docs.join(".env.example"),
        "APP_NAME=Laravel\nAPP_KEY=\nAPP_URL=http://localhost\n",
    )
    .unwrap();
    symlink("releases/r1", fixture.home.home.join("current")).unwrap();
    docs
}

fn url_input(fixture: &Fixture, directory: &str) -> DirectoryUrlInput {
    DirectoryUrlInput {
        user: fixture.home.user.clone(),
        home: fixture.home.home.clone(),
        directory: directory.into(),
        url: "https://docs.example.com".into(),
        app_key: "APP_KEY=base64:generated".into(),
        app_name: "APP_NAME=\"Docs\"".into(),
        app_name_stored: false,
    }
}

#[test]
fn writes_app_url_into_a_new_stable_environment_from_the_template() {
    let fixture = Fixture::new();
    served_home(&fixture);

    assert_eq!(
        write_directory_url(&url_input(&fixture, "apps/docs"), &fixture.layout),
        Ok(Written { written: true })
    );

    let environment = fixture.home.home.join("env/apps/docs/.env");
    assert_eq!(
        fs::read_to_string(&environment).unwrap(),
        "APP_NAME=\"Docs\"\nAPP_KEY=base64:generated\nAPP_URL=https://docs.example.com\n"
    );
    assert_eq!(fs::metadata(&environment).unwrap().mode() & 0o777, 0o600);
    assert_eq!(
        fs::metadata(fixture.home.home.join("env/apps"))
            .unwrap()
            .mode()
            & 0o777,
        0o700
    );
}

#[test]
fn an_existing_stable_environment_keeps_its_key_and_name() {
    let fixture = Fixture::new();
    served_home(&fixture);
    let environment = fixture.home.home.join("env/apps/docs/.env");
    fs::create_dir_all(environment.parent().unwrap()).unwrap();
    fs::write(
        &environment,
        "APP_NAME=Mine\nAPP_KEY=base64:kept\nAPP_URL=https://old.example.com",
    )
    .unwrap();
    fs::set_permissions(&environment, fs::Permissions::from_mode(0o644)).unwrap();

    write_directory_url(&url_input(&fixture, "apps/docs"), &fixture.layout).unwrap();

    assert_eq!(
        fs::read_to_string(&environment).unwrap(),
        "APP_NAME=Mine\nAPP_KEY=base64:kept\nAPP_URL=https://docs.example.com"
    );
    assert_eq!(fs::metadata(&environment).unwrap().mode() & 0o777, 0o640);
    assert_eq!(
        write_directory_url(&url_input(&fixture, "apps/docs"), &fixture.layout),
        Ok(Written { written: false })
    );
}

#[test]
fn writes_nothing_before_a_release_or_for_a_directory_without_artisan_and_refuses_a_linked_environment(
) {
    let fixture = Fixture::new();
    assert_eq!(
        write_directory_url(&url_input(&fixture, "apps/docs"), &fixture.layout),
        Ok(Written { written: false })
    );
    let docs = served_home(&fixture);
    fs::remove_file(docs.join("artisan")).unwrap();
    assert_eq!(
        write_directory_url(&url_input(&fixture, "apps/docs"), &fixture.layout),
        Ok(Written { written: false })
    );
    fs::write(docs.join("artisan"), "").unwrap();
    fs::create_dir_all(fixture.home.home.join("env/apps/docs")).unwrap();
    symlink(
        "/etc/hostname",
        fixture.home.home.join("env/apps/docs/.env"),
    )
    .unwrap();
    assert_eq!(
        write_directory_url(&url_input(&fixture, "apps/docs"), &fixture.layout),
        Err(invalid("the stable environment is a regular file"))
    );
}

/// A stub `php8.5` for Artisan: `config:cache` writes the cache that `APP_CONFIG_CACHE` names, and `--version` boots
/// only against a cache that returns an array. `FAIL` picks a failure: 1 fails after writing, 2 writes a cache that
/// cannot boot, 3 writes nothing.
const ARTISAN: &str = r#"#!/bin/sh
fail=$(cat "$(dirname "$0")/fail")
if [ "$*" = "artisan --version --no-interaction" ]; then grep -q 'return \[' "$APP_CONFIG_CACHE"; exit; fi
[ "$*" = "artisan config:cache" ] || exit 97
[ "$APP_CONFIG_CACHE" != "$PWD/bootstrap/cache/config.php" ] || exit 98
[ "$fail" = 3 ] && exit 0
printf '<?php return [123];' > "$APP_CONFIG_CACHE"
[ "$fail" = 1 ] && exit 96
[ "$fail" = 2 ] && printf '<?php return false;' > "$APP_CONFIG_CACHE"
exit 0
"#;

#[test]
fn rebuilds_only_an_existing_configuration_cache_of_the_current_release() {
    for suffix in ["", "/apps/site"] {
        for (current, cached, fail) in [
            (true, true, 0),
            (true, false, 0),
            (false, false, 0),
            (true, true, 1),
            (true, true, 2),
            (true, true, 3),
        ] {
            let fixture = Fixture::new();
            let php = fixture.base.join("php");
            fs::create_dir_all(&php).unwrap();
            fs::write(php.join("php8.5"), ARTISAN).unwrap();
            fs::set_permissions(php.join("php8.5"), fs::Permissions::from_mode(0o755)).unwrap();
            fs::write(php.join("fail"), fail.to_string()).unwrap();
            let application = fixture.home.home.join(format!("releases/r1{suffix}"));
            fs::create_dir_all(application.join("bootstrap/cache")).unwrap();
            if current {
                symlink("releases/r1", fixture.home.home.join("current")).unwrap();
            }
            let cache = application.join("bootstrap/cache/config.php");
            if cached {
                fs::write(&cache, "old config").unwrap();
            }
            let input = ConfigCacheInput {
                user: fixture.home.user.clone(),
                home: fixture.home.home.clone(),
                version: "8.5".into(),
                application: fixture.home.home.join(format!("current{suffix}")),
            };

            let result = refresh_config_cache(&input, &php);

            let expected = match (current, cached, fail) {
                (false, _, _) => Ok(ConfigCache {
                    outcome: ConfigOutcome::NoCurrentRelease,
                }),
                (_, true, 0) => Ok(ConfigCache {
                    outcome: ConfigOutcome::ConfigCached,
                }),
                (_, true, _) => Err(failed("rebuild the configuration cache")),
                _ => Ok(ConfigCache {
                    outcome: ConfigOutcome::ConfigUncached,
                }),
            };
            assert_eq!(result, expected, "{suffix} {current} {cached} {fail}");
            let content = fs::read_to_string(&cache).ok();
            match (current, cached, fail) {
                (true, true, 0) => assert_eq!(content.as_deref(), Some("<?php return [123];")),
                (_, true, _) => assert_eq!(content.as_deref(), Some("old config")),
                _ => assert_eq!(content, None),
            }
            let leftovers = fs::read_dir(application.join("bootstrap/cache"))
                .unwrap()
                .flatten()
                .filter(|entry| {
                    entry
                        .file_name()
                        .to_string_lossy()
                        .starts_with(".orbit-config-")
                })
                .count();
            assert_eq!(leftovers, 0);
        }
    }
}
