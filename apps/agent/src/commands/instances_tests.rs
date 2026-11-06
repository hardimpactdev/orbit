//! The development source commands, run directly against temporary checkouts. Every refusal leaves the source in
//! place. The publication observation reads the local bare fixture repository. No test changes the process environment.

use super::*;
use crate::command::{Context, Platform};
use std::path::Path;
use std::{sync::OnceLock, time::Instant};

fn me() -> (String, String) {
    let uid = rustix::process::geteuid().as_raw();
    let gid = rustix::process::getegid().as_raw();
    (user_name(uid), group_name(gid))
}

fn context() -> Context {
    Context::new(Platform::Linux, Instant::now() + Duration::from_secs(60))
}

/// A temporary directory for a release fixture.
struct Sandbox {
    base: PathBuf,
}

impl Sandbox {
    fn new() -> Self {
        let base = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!("orbit-source-{:016x}", rand::random::<u64>()));
        fs::create_dir_all(&base).unwrap();
        Self { base }
    }
}

impl Drop for Sandbox {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.base);
    }
}

#[test]
fn routes_collide_only_where_a_first_segment_can_match_the_reverb_paths() {
    for (uri, collision) in [
        ("dashboard", false),
        ("broadcasting/auth", false),
        ("application/{id}", false),
        ("application", false),
        ("app-store/{id}", false),
        ("api/users", false),
        ("powersync/sync/stream", false),
        ("{fallbackPlaceholder}", false),
        ("app/{id}", true),
        ("apps", true),
        ("APP", true),
        ("APPS", true),
        ("/App/page", true),
        ("app/{fallbackPlaceholder}", true),
        ("ap{suffix}", true),
        ("app{suffix?}", true),
        ("app.{format?}", true),
        ("{any}", true),
        ("{page?}", true),
    ] {
        assert_eq!(routes_collide(&[uri.to_string()]), collision, "{uri}");
    }
    assert!(!routes_collide(&[
        "dashboard".to_string(),
        "{fallbackPlaceholder}".to_string()
    ]));
    assert!(routes_collide(&[
        "dashboard".to_string(),
        "apps".to_string()
    ]));
}

/// Fake PHP binaries on `PATH`: `php9.9` lists a route, `php9.8` fails, and `php9.7` and `php9.6` print what is not a
/// route list.
fn fake_php() -> &'static Path {
    static DIRECTORY: OnceLock<PathBuf> = OnceLock::new();
    DIRECTORY.get_or_init(|| {
        let directory = std::env::temp_dir().join(format!("orbit-fake-php-{}", std::process::id()));
        fs::create_dir_all(&directory).unwrap();
        for (name, body) in [
            ("php9.9", "printf '%s' '[{\"uri\":\"app/{x}\"}]'\nexit 0"),
            ("php9.8", "echo secret-env-value >&2\nexit 1"),
            ("php9.7", "printf '%s' '{\"sensitive\":\"value\"}'\nexit 0"),
            (
                "php9.6",
                "printf '%s' 'sensitive application error'\nexit 0",
            ),
            ("php9.5", "exec sleep 30"),
        ] {
            let path = directory.join(name);
            crate::test_support::executable(&path, format!("#!/bin/sh\n{body}\n"));
        }
        let path = format!(
            "{}:{}",
            directory.display(),
            std::env::var("PATH").unwrap_or_default()
        );
        std::env::set_var("PATH", path);
        directory
    })
}

fn release_fixture(base: &Path) -> (PathBuf, PathBuf) {
    fake_php();
    let home = base.join("home");
    let release = home.join("releases/r1");
    fs::create_dir_all(&release).unwrap();
    std::os::unix::fs::symlink(&release, home.join("current")).unwrap();
    (home, release)
}

#[test]
fn laravel_routes_are_read_inside_the_selected_release_and_failures_print_nothing() {
    let sandbox = Sandbox::new();
    let (user, _) = me();
    let (home, release) = release_fixture(&sandbox.base);
    let input = |application: PathBuf, php_version: &str| InspectLaravelRoutesInput {
        user: user.clone(),
        home: home.clone(),
        application,
        php_version: php_version.into(),
    };
    assert_eq!(
        InspectLaravelRoutes::run(input(release.clone(), "9.9"), &context()),
        Ok(LaravelRoutesOutput { collision: true })
    );
    assert_eq!(
        InspectLaravelRoutes::run(input(sandbox.base.clone(), "9.9"), &context()),
        Err(LaravelRoutesError::PathMismatch {})
    );
    assert_eq!(
        InspectLaravelRoutes::run(input(release.clone(), "9.8"), &context()),
        Err(LaravelRoutesError::RoutesUnreadable {})
    );
    assert_eq!(
        InspectLaravelRoutes::run(input(release.clone(), "9.7"), &context()),
        Err(LaravelRoutesError::RoutesUnreadable {})
    );
    assert_eq!(
        InspectLaravelRoutes::run(input(release.clone(), "9.6"), &context()),
        Err(LaravelRoutesError::RoutesUnreadable {})
    );
    // A release that hangs at boot fails at the deadline, and its process does not outlive the read.
    let started = Instant::now();
    let short = Context::new(Platform::Linux, Instant::now() + Duration::from_millis(500));
    assert_eq!(
        InspectLaravelRoutes::run(input(release.clone(), "9.5"), &short),
        Err(LaravelRoutesError::RoutesUnreadable {})
    );
    assert!(started.elapsed() < Duration::from_secs(5));
    assert_eq!(
        InspectLaravelRoutes::run(input(release, "../../bin/sh"), &context()),
        Err(LaravelRoutesError::RoutesUnreadable {})
    );
}

#[test]
fn laravel_routes_refuse_a_current_link_outside_the_releases() {
    let sandbox = Sandbox::new();
    let (user, _) = me();
    let (home, _) = release_fixture(&sandbox.base);
    let elsewhere = sandbox.base.join("elsewhere");
    fs::create_dir(&elsewhere).unwrap();
    fs::remove_file(home.join("current")).unwrap();
    std::os::unix::fs::symlink(&elsewhere, home.join("current")).unwrap();
    let input = InspectLaravelRoutesInput {
        user,
        home,
        application: elsewhere,
        php_version: "9.9".into(),
    };
    assert_eq!(
        InspectLaravelRoutes::run(input, &context()),
        Err(LaravelRoutesError::PathMismatch {})
    );
}
