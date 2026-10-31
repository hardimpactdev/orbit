//! Read-only Instance observations against temporary source and removal-state fixtures.

use super::*;
use crate::command::{CommandError, Context, Platform};
use base64::Engine;
use std::{process::Command as Process, sync::OnceLock, time::Instant};

const ORIGIN: &str = "ssh://git@example.test/acme/site.git";

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

fn me() -> (String, String) {
    let uid = rustix::process::geteuid().as_raw();
    let gid = rustix::process::getegid().as_raw();
    (user_name(uid), group_name(gid))
}

fn context() -> Context {
    Context::new(Platform::Linux, Instant::now() + Duration::from_secs(60))
}

/// An origin with one published commit on `main`, shared by every test.
fn origin_fixture() -> &'static Path {
    static ORIGIN_FIXTURE: OnceLock<PathBuf> = OnceLock::new();
    ORIGIN_FIXTURE.get_or_init(|| {
        let directory =
            std::env::temp_dir().join(format!("orbit-source-origin-{}", std::process::id()));
        let _ = fs::remove_dir_all(&directory);
        fs::create_dir_all(&directory).unwrap();
        let origin = directory.join("origin.git");
        sh_git(
            &directory,
            &["init", "--quiet", "--bare", origin.to_str().unwrap()],
        );
        let seed = directory.join("seed");
        sh_git(
            &directory,
            &[
                "clone",
                "--quiet",
                origin.to_str().unwrap(),
                seed.to_str().unwrap(),
            ],
        );
        sh_git(
            &seed,
            &["commit", "--quiet", "--allow-empty", "-m", "Initial"],
        );
        sh_git(&seed, &["push", "--quiet", "origin", "HEAD:main"]);
        origin
    })
}

/// An apps root with one Project directory and its own copy of the origin.
struct Sandbox {
    base: PathBuf,
    root: PathBuf,
    project: PathBuf,
    origin: PathBuf,
}

impl Sandbox {
    fn new() -> Self {
        let base = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!("orbit-source-{:016x}", rand::random::<u64>()));
        let root = base.join("apps");
        let project = root.join("acme");
        fs::create_dir_all(&project).unwrap();
        let origin = base.join("origin.git");
        let copied = Process::new("cp")
            .args(["-a"])
            .arg(origin_fixture())
            .arg(&origin)
            .status()
            .unwrap();
        assert!(copied.success());
        Self {
            base,
            root,
            project,
            origin,
        }
    }

    fn checkout(&self, name: &str) -> PathBuf {
        let path = self.project.join(name);
        sh_git(
            &self.base,
            &[
                "clone",
                "--quiet",
                self.origin.to_str().unwrap(),
                path.to_str().unwrap(),
            ],
        );
        sh_git(&path, &["remote", "set-url", "origin", ORIGIN]);
        sh_git(&path, &["checkout", "--quiet", "-b", name]);
        path
    }

    fn head(path: &Path) -> String {
        sh_git(path, &["rev-parse", "HEAD"])
    }
}

impl Drop for Sandbox {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.base);
    }
}

fn code<T>(result: Result<T, SourceError>) -> &'static str {
    match result {
        Ok(_) => "ok",
        Err(error) => error.code(),
    }
}

fn decode(value: &str) -> Vec<u8> {
    base64::engine::general_purpose::STANDARD
        .decode(value)
        .unwrap()
}

fn publication_at(
    root: &Path,
    origin: &str,
    commit: &str,
    _: &GitRead,
) -> Result<bool, &'static str> {
    assert_eq!(origin, ORIGIN);
    is_published(
        root.parent().unwrap().join("origin.git").to_str().unwrap(),
        commit,
        &GitRead::default(),
    )
}
// ---- inspect ----------------------------------------------------------------------------------------------------

fn inspect_input(sandbox: &Sandbox, checkout: &Path) -> InspectSourceInput {
    let (user, group) = me();
    InspectSourceInput {
        checkout: checkout.into(),
        root: sandbox.root.clone(),
        user,
        group,
        layout: "checkout".into(),
        branch: "dev".into(),
        inspect_content: true,
        unresolved: false,
        allow_absent: false,
        origin: ORIGIN.into(),
        prepare_id: String::new(),
    }
}

#[test]
fn inspection_reports_the_checkout_evidence() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    let output = InspectSource::run(inspect_input(&sandbox, &checkout), &context()).unwrap();
    assert_eq!(decode(&output.top), bytes(&checkout));
    assert_eq!(decode(&output.common), bytes(&checkout.join(".git")));
    assert_eq!(decode(&output.origin), ORIGIN.as_bytes());
    assert_eq!(decode(&output.branch), b"dev");
    assert_eq!(decode(&output.commit), Sandbox::head(&checkout).as_bytes());
    assert_eq!(decode(&output.dirty), b"0");
    assert_eq!(
        decode(&output.source_identity),
        identity(&checkout).as_bytes()
    );
    let mut listed = b"worktree ".to_vec();
    listed.extend_from_slice(bytes(&checkout));
    listed.push(0);
    assert!(decode(&output.worktrees).starts_with(&listed));
}

#[test]
fn inspection_names_each_refused_identity_check() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    let decoy = sandbox.base.join("decoy");
    fs::create_dir(&decoy).unwrap();
    std::os::unix::fs::symlink(&decoy, sandbox.project.join("linked")).unwrap();
    let with = |change: &dyn Fn(&mut InspectSourceInput)| {
        let mut input = inspect_input(&sandbox, &checkout);
        change(&mut input);
        code(InspectSource::run(input, &context()))
    };
    assert_eq!(
        with(&|input| input.checkout = sandbox.base.join("outside")),
        "instance.path_mismatch"
    );
    assert_eq!(
        with(&|input| input.checkout = sandbox.project.join("linked")),
        "instance.path_mismatch"
    );
    assert_eq!(
        with(&|input| input.checkout = sandbox.project.join("missing")),
        "instance.path_mismatch"
    );
    assert_eq!(
        with(&|input| input.user = "someone-else".into()),
        "instance.ownership_mismatch"
    );
    assert_eq!(
        with(&|input| input.prepare_id = "another-attempt".into()),
        "instance.ownership_mismatch"
    );
    assert_eq!(
        with(&|input| input.layout = "worktree".into()),
        "instance.layout_mismatch"
    );
    assert_eq!(
        with(&|input| input.branch = "main".into()),
        "instance.branch_mismatch"
    );
    sh_git(&checkout, &["remote", "remove", "origin"]);
    assert_eq!(with(&|_| {}), "instance.origin_mismatch");
    assert!(checkout.join(".git").is_dir());
}

#[test]
fn inspection_reports_dirty_source_only_when_asked() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    fs::write(checkout.join("new.txt"), "work\n").unwrap();
    assert_eq!(
        decode(
            &InspectSource::run(inspect_input(&sandbox, &checkout), &context())
                .unwrap()
                .dirty
        ),
        b"1"
    );
    let mut input = inspect_input(&sandbox, &checkout);
    input.inspect_content = false;
    assert_eq!(
        decode(&InspectSource::run(input, &context()).unwrap().dirty),
        b""
    );
}

#[test]
fn inspection_accepts_the_preparation_receipt_of_this_attempt() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    fs::write(
        checkout.join(".git/orbit-source-prepare"),
        format!("attempt-1:{}\n", identity(&checkout)),
    )
    .unwrap();
    let mut input = inspect_input(&sandbox, &checkout);
    input.prepare_id = "attempt-1".into();
    assert!(InspectSource::run(input, &context()).is_ok());
}

#[test]
fn inspection_reports_an_absent_source_when_absence_is_allowed() {
    let sandbox = Sandbox::new();
    let reserved = sandbox.project.join("reserved");
    let mut input = inspect_input(&sandbox, &reserved);
    input.allow_absent = true;
    input.layout = "worktree".into();
    let output = InspectSource::run(input, &context()).unwrap();
    assert_eq!(decode(&output.source_identity), b"absent");
    let mut listed = b"worktree ".to_vec();
    listed.extend_from_slice(bytes(&reserved));
    listed.extend_from_slice(b"\0\0");
    assert_eq!(decode(&output.worktrees), listed);
}

// ---- remove -----------------------------------------------------------------------------------------------------

#[test]
fn publication_finds_a_commit_on_a_branch_of_the_origin_and_not_a_local_one() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    let published = Sandbox::head(&checkout);
    sh_git(
        &checkout,
        &["commit", "--quiet", "--allow-empty", "-m", "Local only"],
    );
    let check = |commit: String| {
        CheckPublication::run(
            CheckPublicationInput {
                origin: sandbox.origin.to_str().unwrap().into(),
                commit,
                git_read: GitRead::default(),
            },
            &context(),
        )
    };
    assert_eq!(check(published), Ok(PublicationOutput { published: true }));
    assert_eq!(
        check(Sandbox::head(&checkout)),
        Ok(PublicationOutput { published: false })
    );
    let unreachable = CheckPublication::run(
        CheckPublicationInput {
            origin: sandbox
                .base
                .join("missing-origin.git")
                .to_str()
                .unwrap()
                .into(),
            commit: "0".repeat(40),
            git_read: GitRead::default(),
        },
        &context(),
    );
    assert!(matches!(unreachable, Err(SourceError::Failed { .. })));
}

// ---- Laravel routes ---------------------------------------------------------------------------------------------

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

#[test]
fn a_path_is_free_only_when_nothing_and_no_dangling_link_occupies_it() {
    let base = fs::canonicalize(std::env::temp_dir())
        .unwrap()
        .join(format!("orbit-path-free-{:016x}", rand::random::<u64>()));
    fs::create_dir_all(&base).unwrap();
    let context = Context::new(
        Platform::Linux,
        std::time::Instant::now() + Duration::from_secs(5),
    );
    let check = |path: &Path| {
        CheckPathFree::run(PathInput { path: path.into() }, &context)
            .unwrap()
            .free
    };

    assert!(check(&base.join("new")));
    std::os::unix::fs::symlink(base.join("missing"), base.join("dangling")).unwrap();
    assert!(!check(&base.join("dangling")));
    assert!(!check(&base));
    fs::remove_dir_all(&base).unwrap();
}

#[path = "instances_recovery_tests.rs"]
mod recovery;

#[path = "instances_safety_tests.rs"]
mod safety;
