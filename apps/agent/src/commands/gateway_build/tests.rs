use super::*;
use crate::commands::recipe::WorkDir;

fn git_at(path: &Path, args: &[&str]) -> String {
    let mut git = process("/usr/bin/git");
    GitRead::default().configure(&mut git);
    let output = git
        .args([
            "-c",
            "user.name=Orbit",
            "-c",
            "user.email=orbit@example.test",
            "-c",
            "init.defaultBranch=main",
        ])
        .arg("-C")
        .arg(path)
        .args(args)
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "git {args:?}: {}",
        String::from_utf8_lossy(&output.stderr)
    );
    String::from_utf8(output.stdout).unwrap().trim().into()
}
struct Fixture {
    work: WorkDir,
    checkout: String,
    origin: PathBuf,
    repository: PathBuf,
    step: PathBuf,
    sha: String,
}
impl Fixture {
    fn new() -> Self {
        let work = WorkDir::new().unwrap();
        let origin = work.0.join("origin");
        fs::create_dir(&origin).unwrap();
        git_at(&origin, &["init", "--quiet"]);
        for directory in [
            "apps/cli/storage",
            "apps/gateway/public",
            "apps/gateway/bootstrap/cache",
            "apps/gateway/storage/logs",
        ] {
            fs::create_dir_all(origin.join(directory)).unwrap();
            fs::write(
                origin.join(directory).join(".gitignore"),
                "*\n!.gitignore\n",
            )
            .unwrap();
        }
        for app in ["apps/cli", "apps/gateway"] {
            fs::write(origin.join(app).join("composer.json"), "{}").unwrap();
        }
        fs::write(
            origin.join(".gitignore"),
            "/REVISION\napps/*/vendor/\napps/gateway/.env\n",
        )
        .unwrap();
        fs::write(
            origin.join("apps/gateway/artisan"),
            r#"<?php
if (file_exists(__DIR__.'/config.fail')) { exit(1); }
file_put_contents(getenv('APP_CONFIG_CACHE'), json_encode([
    'revision' => trim(file_get_contents(dirname(__DIR__, 2).'/REVISION')),
    'environment' => file_get_contents(__DIR__.'/.env'),
    'inherited_cargo' => getenv('CARGO_MANIFEST_DIR'),
]));
"#,
        )
        .unwrap();
        git_at(&origin, &["add", "."]);
        git_at(&origin, &["commit", "--quiet", "-m", "seed"]);
        let sha = git_at(&origin, &["rev-parse", "HEAD"]);
        fs::create_dir(work.0.join("shared")).unwrap();
        let repository = work.0.join("shared/orbit.git");
        git_at(
            &work.0,
            &["clone", "--quiet", "--bare", "origin", "shared/orbit.git"],
        );
        fs::write(
            work.0.join("shared/gateway.env"),
            "APP_ENV=production\nAPP_KEY=private-sentinel\n",
        )
        .unwrap();
        let checkout = work
            .0
            .join("orbit/apps/gateway")
            .to_str()
            .unwrap()
            .to_owned();
        let step = work.0.join("gateway-release-step.lock");
        Self {
            work,
            checkout,
            origin,
            repository,
            step,
            sha,
        }
    }
    fn inspect(&self) -> InspectInput {
        InspectInput {
            checkout: self.checkout.clone(),
            revision: self.sha.clone(),
            minimum_free_bytes: 1024,
            reserved_bytes: 512,
        }
    }
    fn prepare(&self) -> PrepareInput {
        PrepareInput {
            checkout: self.checkout.clone(),
            revision: self.sha.clone(),
            minimum_free_bytes: 1024,
            reserved_bytes: 512,
            git_read: None,
            step_lock: self.step.clone(),
        }
    }
    fn finish(&self) -> FinishInput {
        FinishInput {
            checkout: self.checkout.clone(),
            sha: self.sha.clone(),
            step_lock: self.step.clone(),
        }
    }
    fn release(&self) -> ReleaseInput {
        ReleaseInput {
            checkout: self.checkout.clone(),
            release: self.sha[..12].into(),
            step_lock: self.step.clone(),
        }
    }
    fn path(&self) -> PathBuf {
        self.work.0.join("releases").join(&self.sha[..12])
    }
    fn commit(&mut self) {
        git_at(&self.origin, &["add", "."]);
        git_at(
            &self.origin,
            &["commit", "--quiet", "--allow-empty", "-m", "next"],
        );
        self.sha = git_at(&self.origin, &["rev-parse", "HEAD"]);
    }
    fn build(&self, host: &mut Fake) {
        assert!(!prepare(&self.prepare(), host, deadline()).unwrap().reused);
        assert!(finish(&self.finish(), host, deadline()).unwrap().changed);
    }
}
impl Drop for Fixture {
    fn drop(&mut self) {
        // Sealed worktrees need writable directories for cleanup; never chmod their shared links.
        for (path, meta) in entries(&self.work.0, &[]).unwrap_or_default() {
            if meta.is_dir() {
                let _ = fs::set_permissions(path, fs::Permissions::from_mode(meta.mode() | 0o200));
            }
        }
    }
}
struct Fake {
    free: Option<u64>,
    calls: Vec<Vec<String>>,
    fail_composer: bool,
    fail_configuration: bool,
    failure_output: bool,
}
impl Default for Fake {
    fn default() -> Self {
        Self {
            free: Some(1 << 40),
            calls: vec![],
            fail_composer: false,
            fail_configuration: false,
            failure_output: false,
        }
    }
}
impl Host for Fake {
    fn free_space(&self, _: &Path) -> Option<u64> {
        self.free
    }
    fn run(&mut self, mut command: Process, _: u64) -> io::Result<incus::Output> {
        let args: Vec<_> = command
            .get_args()
            .map(|s| s.to_str().unwrap().to_owned())
            .collect();
        let program = command.get_program().to_str().unwrap().to_owned();
        self.calls
            .push([vec![program.clone()], args.clone()].concat());
        if self.failure_output {
            return Ok(incus::Output {
                code: 1,
                stdout: String::new(),
                stderr: String::new(),
                truncated: false,
            });
        }
        if program == "/usr/bin/composer" {
            if self.fail_composer {
                return Ok(incus::Output {
                    code: 1,
                    stdout: String::new(),
                    stderr: "install failed".into(),
                    truncated: false,
                });
            }
            let path = PathBuf::from(args[0].strip_prefix("--working-dir=").unwrap());
            if args[1] == "install" {
                fs::create_dir_all(path.join("vendor"))?;
                fs::write(path.join("vendor/autoload.php"), "<?php")?;
            }
            return Ok(incus::Output {
                code: 0,
                stdout: String::new(),
                stderr: String::new(),
                truncated: false,
            });
        }
        if program == "/usr/bin/php8.5" {
            let keys: Vec<_> = command
                .get_envs()
                .filter_map(|(key, value)| value.map(|_| key.to_str().unwrap()))
                .collect();
            assert_eq!(keys, ["APP_CONFIG_CACHE", "HOME", "LANG", "PATH"]);
            if self.fail_configuration {
                return Ok(incus::Output {
                    code: 1,
                    stdout: String::new(),
                    stderr: "must not publish configuration output".into(),
                    truncated: false,
                });
            }
        }
        incus::capture_input(&mut command, None)
    }
}
fn deadline() -> Instant {
    Instant::now() + Duration::from_secs(30)
}
fn mode(path: &Path) -> u32 {
    fs::symlink_metadata(path).unwrap().mode() & 0o7777
}
fn failure<T>(result: Result<T, Error>, expected: Failure) -> Error {
    let error = result.err().expect("operation succeeded");
    assert_eq!(error.failure, expected);
    error
}

#[test]
fn inspection_reserves_space_and_reuses_a_local_full_sha_without_an_origin_or_writes() {
    let f = Fixture::new();
    let mut host = Fake::default();
    git_at(&f.repository, &["remote", "remove", "origin"]);
    let result = inspect(&f.inspect(), &host).unwrap();
    assert_eq!(result.sha.as_ref(), Some(&f.sha));
    assert!(result.origin.is_none());
    assert!(!f.work.0.join("releases").exists());
    assert!(!f.step.exists());
    host.free = Some(1535);
    failure(
        inspect(&f.inspect(), &host),
        Failure::DiskLow {
            free: 1535,
            needed: 1536,
        },
    );
    failure(
        prepare(&f.prepare(), &mut host, deadline()),
        Failure::DiskLow {
            free: 1535,
            needed: 1536,
        },
    );
    assert!(host.calls.is_empty());
    assert!(!f.step.exists());
}

#[test]
fn prepares_finishes_and_reuses_without_touching_shared_storage_or_running_builds_again() {
    let f = Fixture::new();
    let mut host = Fake::default();
    fs::create_dir_all(f.work.0.join("shared/gateway-storage/logs")).unwrap();
    let log = f.work.0.join("shared/gateway-storage/logs/keep");
    fs::write(&log, "keep").unwrap();
    let original_mode = mode(&log);
    f.build(&mut host);
    let path = f.path();
    let app = path.join("apps/gateway");
    assert_eq!(
        fs::read_link(app.join(".env")).unwrap(),
        f.work.0.join("shared/gateway.env")
    );
    assert_eq!(
        fs::read_link(app.join("storage")).unwrap(),
        f.work.0.join("shared/gateway-storage")
    );
    assert_eq!(mode(&app.join("bootstrap/cache")), 0o700);
    assert_eq!(mode(&app.join("bootstrap/cache/config.php")), 0o600);
    assert_eq!(mode(&path) & 0o222, 0);
    assert_eq!(mode(&app.join("composer.json")) & 0o222, 0);
    assert_ne!(mode(&path.join("apps/cli/storage")) & 0o200, 0);
    assert_eq!(mode(&log), original_mode);
    assert_eq!(fs::read(&log).unwrap(), b"keep");
    let cached: Value =
        serde_json::from_slice(&fs::read(app.join("bootstrap/cache/config.php")).unwrap()).unwrap();
    assert_eq!(cached["revision"], f.sha);
    assert!(cached["environment"]
        .as_str()
        .unwrap()
        .contains("private-sentinel"));
    assert_eq!(cached["inherited_cargo"], false);
    assert_eq!(git_at(&path, &["status", "--porcelain"]), "");
    let calls = host.calls.len();
    assert!(prepare(&f.prepare(), &mut host, deadline()).unwrap().reused);
    assert!(!finish(&f.finish(), &mut host, deadline()).unwrap().changed);
    assert_eq!(host.calls.len(), calls);
    let composer: Vec<_> = host
        .calls
        .iter()
        .filter(|args| args[0] == "/usr/bin/composer")
        .map(|args| args[2..].to_vec())
        .collect();
    assert_eq!(
        composer,
        [
            vec![
                "install",
                "--no-dev",
                "--prefer-dist",
                "--no-interaction",
                "--no-progress"
            ],
            vec!["check-platform-reqs", "--no-dev", "--no-interaction"],
            vec![
                "install",
                "--no-dev",
                "--prefer-dist",
                "--no-interaction",
                "--no-progress"
            ],
            vec!["check-platform-reqs", "--no-dev", "--no-interaction"],
        ]
    );
}

#[test]
fn short_revisions_fetch_and_unknown_revisions_fail_before_creating_a_release() {
    let mut f = Fixture::new();
    f.commit();
    let mut host = Fake::default();
    let mut inspect_input = f.inspect();
    inspect_input.revision.truncate(9);
    let observation = inspect(&inspect_input, &host).unwrap();
    assert!(observation.sha.is_none());
    assert_eq!(observation.origin, Some(f.origin.to_str().unwrap().into()));
    let mut input = f.prepare();
    input.revision.truncate(9);
    input.git_read = Some(GitRead::default());
    let result = prepare(&input, &mut host, deadline()).unwrap();
    assert_eq!(result.sha, f.sha);
    input.revision = "f".repeat(40);
    failure(prepare(&input, &mut host, deadline()), Failure::Unknown {});
    assert!(!f.work.0.join("releases/ffffffffffff").exists());
    input.revision = "a".repeat(40);
    input.git_read = None;
    failure(prepare(&input, &mut host, deadline()), Failure::Fetch {});
}

#[test]
fn interrupted_builds_repeat_and_configuration_failure_removes_revision() {
    let f = Fixture::new();
    let mut host = Fake {
        fail_composer: true,
        ..Fake::default()
    };
    let error = failure(
        prepare(&f.prepare(), &mut host, deadline()),
        Failure::Dependencies {},
    );
    assert_eq!(error.sha.as_ref(), Some(&f.sha));
    assert!(!f.path().join("REVISION").exists());
    host.fail_composer = false;
    host.fail_configuration = true;
    assert!(!prepare(&f.prepare(), &mut host, deadline()).unwrap().reused);
    failure(
        finish(&f.finish(), &mut host, deadline()),
        Failure::Configuration {},
    );
    assert!(!f.path().join("REVISION").exists());
    host.fail_configuration = false;
    f.build(&mut host);
    let layout = Layout::new(&f.checkout).unwrap();
    assert_eq!(
        prepared(&layout, &f.sha[..12]).unwrap(),
        Some(f.sha.clone())
    );
}

#[test]
fn refresh_is_atomic_and_keeps_the_old_cache_and_revision_when_artisan_fails() {
    let f = Fixture::new();
    let mut host = Fake::default();
    f.build(&mut host);
    let cache = f.path().join("apps/gateway/bootstrap/cache/config.php");
    let before = fs::read(&cache).unwrap();
    fs::write(f.work.0.join("shared/gateway.env"), "APP_ENV=new\n").unwrap();
    host.fail_configuration = true;
    failure(
        refresh(&f.release(), &mut host, deadline()),
        Failure::Configuration {},
    );
    assert_eq!(fs::read(&cache).unwrap(), before);
    assert_eq!(
        fs::read_to_string(f.path().join("REVISION"))
            .unwrap()
            .trim(),
        f.sha
    );
    host.fail_configuration = false;
    assert!(
        refresh(&f.release(), &mut host, deadline())
            .unwrap()
            .changed
    );
    assert_ne!(fs::read(&cache).unwrap(), before);
    assert_eq!(mode(&cache), 0o600);
    assert_eq!(
        fs::read_dir(cache.parent().unwrap())
            .unwrap()
            .filter(|e| e
                .as_ref()
                .unwrap()
                .file_name()
                .to_string_lossy()
                .starts_with("config.next-"))
            .count(),
        0
    );
}

#[test]
fn removal_refuses_current_and_repeats_without_following_links_or_changing_hardlinked_files() {
    let f = Fixture::new();
    let mut host = Fake::default();
    f.build(&mut host);
    let outside = f.work.0.join("keep");
    fs::write(&outside, "keep").unwrap();
    fs::set_permissions(&outside, fs::Permissions::from_mode(0o400)).unwrap();
    fs::hard_link(&outside, f.path().join("apps/cli/storage/keep")).unwrap();
    symlink(format!("releases/{}", &f.sha[..12]), f.work.0.join("orbit")).unwrap();
    let calls = host.calls.len();
    failure(
        remove(&f.release(), &mut host, deadline()),
        Failure::Current {},
    );
    assert_eq!(host.calls.len(), calls);
    fs::remove_file(f.work.0.join("orbit")).unwrap();
    assert!(remove(&f.release(), &mut host, deadline()).unwrap().changed);
    assert!(!remove(&f.release(), &mut host, deadline()).unwrap().changed);
    assert!(!f.path().exists());
    assert_eq!(mode(&outside), 0o400);
    assert_eq!(fs::read(&outside).unwrap(), b"keep");
    assert!(f.work.0.join("shared/gateway.env").is_file());
    assert!(f.work.0.join("shared/gateway-storage").is_dir());
    assert!(!f.repository.join("worktrees").join(&f.sha[..12]).exists());
}

#[test]
fn revision_conflict_current_incomplete_and_foreign_release_paths_refuse_before_rebuild() {
    let f = Fixture::new();
    let mut host = Fake::default();
    prepare(&f.prepare(), &mut host, deadline()).unwrap();
    fs::write(f.path().join("REVISION"), "other").unwrap();
    let calls = host.calls.len();
    failure(
        prepare(&f.prepare(), &mut host, deadline()),
        Failure::Conflict {},
    );
    assert_eq!(host.calls.len(), calls);
    fs::remove_file(f.path().join("REVISION")).unwrap();
    symlink(format!("releases/{}", &f.sha[..12]), f.work.0.join("orbit")).unwrap();
    failure(
        prepare(&f.prepare(), &mut host, deadline()),
        Failure::CurrentIncomplete {},
    );
    fs::remove_file(f.work.0.join("orbit")).unwrap();
    remove(&f.release(), &mut host, deadline()).unwrap();
    symlink(&f.origin, f.path()).unwrap();
    failure(
        prepare(&f.prepare(), &mut host, deadline()),
        Failure::Unsafe {},
    );
    assert!(f.origin.join("apps/gateway/artisan").is_file());
}

#[test]
fn unsafe_cache_and_shared_storage_are_not_followed_or_repaired() {
    let f = Fixture::new();
    let mut host = Fake::default();
    let storage = f.work.0.join("shared/gateway-storage");
    fs::create_dir_all(storage.join("framework")).unwrap();
    symlink(&f.origin, storage.join("framework/cache")).unwrap();
    let before = mode(&f.origin);
    failure(inspect(&f.inspect(), &host), Failure::Unsafe {});
    failure(
        prepare(&f.prepare(), &mut host, deadline()),
        Failure::Unsafe {},
    );
    assert!(host.calls.is_empty());
    assert!(!f.step.exists());
    assert!(!f.path().exists());
    assert!(!f.origin.join("data").exists());
    assert_eq!(mode(&f.origin), before);

    let mut f = Fixture::new();
    fs::remove_dir_all(f.origin.join("apps/gateway/bootstrap/cache")).unwrap();
    symlink(&f.work.0, f.origin.join("apps/gateway/bootstrap/cache")).unwrap();
    f.commit();
    let mut host = Fake::default();
    let mut input = f.prepare();
    input.git_read = Some(GitRead::default());
    let before = mode(&f.work.0);
    failure(prepare(&input, &mut host, deadline()), Failure::Access {});
    assert_eq!(mode(&f.work.0), before);
    assert!(!host.calls.iter().any(|args| args[0] == "/usr/bin/composer"));
}

#[test]
fn a_step_inspection_is_read_only_and_reports_a_live_lock() {
    let f = Fixture::new();
    let input = StepInput {
        step_lock: f.step.clone(),
    };
    assert!(!step(&input).unwrap().busy);
    assert!(!f.step.exists());
    let held = hold_step(&f.step, deadline()).unwrap();
    let before = fs::read(&f.step).unwrap();
    let inode = fs::metadata(&f.step).unwrap().ino();
    assert!(step(&input).unwrap().busy);
    failure(hold_step(&f.step, Instant::now()), Failure::InProgress {});
    assert_eq!(fs::read(&f.step).unwrap(), before);
    assert_eq!(fs::metadata(&f.step).unwrap().ino(), inode);
    drop(held);
    assert!(!step(&input).unwrap().busy);
    assert_eq!(mode(&f.step), 0o600);
    fs::set_permissions(&f.step, fs::Permissions::from_mode(0o644)).unwrap();
    failure(step(&input), Failure::LockUnavailable {});
    assert_eq!(mode(&f.step), 0o644);
}

#[test]
fn malformed_inputs_missing_layout_and_failed_git_refuse_without_running_a_build() {
    let f = Fixture::new();
    let mut host = Fake::default();
    let mut input = f.prepare();
    for bad in ["main", "HEAD~1", "abc", "--upload-pack=x", "ABCDEF1"] {
        input.revision = bad.into();
        failure(
            prepare(&input, &mut host, deadline()),
            Failure::CommitInvalid {},
        );
    }
    assert!(host.calls.is_empty());
    assert!(!f.step.exists());
    input.revision = "a".repeat(40);
    input.git_read = Some(GitRead::default());
    host.failure_output = true;
    failure(prepare(&input, &mut host, deadline()), Failure::Fetch {});
    assert!(!f.work.0.join("releases").exists());
    fs::remove_file(f.work.0.join("shared/gateway.env")).unwrap();
    failure(inspect(&f.inspect(), &host), Failure::LayoutMissing {});
    let mut value = json!({"checkout":f.checkout, "revision":f.sha, "minimum_free_bytes":0, "reserved_bytes":0, "git_read":null, "step_lock":f.step});
    for field in ["argv", "environment", "composer", "php", "script"] {
        value[field] = json!("untrusted");
        assert!(serde_json::from_value::<PrepareInput>(value.clone()).is_err());
        value.as_object_mut().unwrap().remove(field);
    }
}

#[test]
fn removing_a_release_does_not_require_a_usable_shared_environment_or_storage() {
    let f = Fixture::new();
    let mut host = Fake::default();
    f.build(&mut host);
    fs::remove_file(f.work.0.join("shared/gateway.env")).unwrap();
    fs::remove_dir_all(f.work.0.join("shared/gateway-storage")).unwrap();
    symlink(&f.origin, f.work.0.join("shared/gateway-storage")).unwrap();
    assert!(remove(&f.release(), &mut host, deadline()).unwrap().changed);
    assert!(!remove(&f.release(), &mut host, deadline()).unwrap().changed);
    assert!(!f.path().exists());
    assert!(f.origin.join("apps/gateway/artisan").is_file());
}

#[test]
fn migrations_use_the_prepared_cache_clean_environment_fixed_arguments_and_report_failure() {
    let f = Fixture::new();
    let mut host = Fake::default();
    f.build(&mut host);
    host.calls.clear();
    // This sample Artisan stands in for Laravel's idempotent migration ledger.
    let artisan = f.path().join("apps/gateway/artisan");
    fs::set_permissions(&artisan, fs::Permissions::from_mode(0o600)).unwrap();
    fs::write(
        &artisan,
        r#"<?php
if ($argv !== [__FILE__, 'migrate', '--force', '--isolated', '--no-interaction']) { exit(2); }
if (getenv('CARGO_MANIFEST_DIR') !== false || !is_file(getenv('APP_CONFIG_CACHE'))) { exit(3); }
file_put_contents(__DIR__.'/storage/migrated', 'applied');
"#,
    )
    .unwrap();
    assert!(
        migrate(&f.release(), &mut host, deadline())
            .unwrap()
            .changed
    );
    assert!(
        migrate(&f.release(), &mut host, deadline())
            .unwrap()
            .changed
    );
    assert_eq!(
        fs::read_to_string(f.path().join("apps/gateway/storage/migrated")).unwrap(),
        "applied"
    );
    assert_eq!(host.calls.len(), 2);
    host.fail_configuration = true;
    let error = failure(
        migrate(&f.release(), &mut host, deadline()),
        Failure::Migration {},
    );
    assert_eq!(error.sha.as_deref(), Some(f.sha.as_str()));
}
#[test]
fn migrations_refuse_unprepared_releases_and_nonprivate_configuration_before_execution() {
    let f = Fixture::new();
    let mut host = Fake::default();
    failure(
        migrate(&f.release(), &mut host, deadline()),
        Failure::NotPrepared {},
    );
    assert!(host.calls.is_empty());
    f.build(&mut host);
    host.calls.clear();
    fs::set_permissions(
        f.path().join("apps/gateway/bootstrap/cache/config.php"),
        fs::Permissions::from_mode(0o644),
    )
    .unwrap();
    failure(
        migrate(&f.release(), &mut host, deadline()),
        Failure::Configuration {},
    );
    assert!(host.calls.is_empty());
}

#[test]
fn schema_check_uses_fixed_status_arguments_and_keeps_failure_identity() {
    let f = Fixture::new();
    let mut host = Fake::default();
    failure(
        check_migrations(&f.release(), &mut host, deadline()),
        Failure::NotPrepared {},
    );
    assert!(host.calls.is_empty());
    f.build(&mut host);
    host.calls.clear();
    let artisan = f.path().join("apps/gateway/artisan");
    fs::set_permissions(&artisan, fs::Permissions::from_mode(0o600)).unwrap();
    fs::write(
        &artisan,
        r#"<?php
if ($argv !== [__FILE__, 'migrate:status', '--pending=3', '--no-interaction']) { exit(2); }
if (getenv('CARGO_MANIFEST_DIR') !== false || !is_file(getenv('APP_CONFIG_CACHE'))) { exit(3); }
"#,
    )
    .unwrap();
    for _ in 0..2 {
        assert!(
            check_migrations(&f.release(), &mut host, deadline())
                .unwrap()
                .complete
        );
    }
    assert_eq!(host.calls.len(), 2);
    fs::write(&artisan, "<?php exit(3);").unwrap();
    let error = failure(
        check_migrations(&f.release(), &mut host, deadline()),
        Failure::SchemaPending {},
    );
    assert_eq!(error.sha.as_ref(), Some(&f.sha));
    host.calls.clear();
    let held = hold_step(&f.step, deadline()).unwrap();
    failure(
        check_migrations(&f.release(), &mut host, Instant::now()),
        Failure::InProgress {},
    );
    assert!(host.calls.is_empty());
    drop(held);
    fs::set_permissions(
        f.path().join("apps/gateway/bootstrap/cache/config.php"),
        fs::Permissions::from_mode(0o644),
    )
    .unwrap();
    failure(
        check_migrations(&f.release(), &mut host, deadline()),
        Failure::Configuration {},
    );
    assert!(host.calls.is_empty());
}

fn handoff_input(f: &Fixture, phase: HandoffPhase) -> HandoffInput {
    HandoffInput {
        checkout: f.checkout.clone(),
        release: f.sha[..12].into(),
        step_lock: f.step.clone(),
        phase,
    }
}

#[test]
fn handoff_runs_the_selected_phase_with_private_cache_clean_environment_and_step_lock() {
    // Other tests fork without exec. They must not inherit this fixture's flock and delay its release.
    let output = Process::new(std::env::current_exe().unwrap())
        .args([
            "--ignored",
            "--exact",
            "commands::gateway_build::tests::handoff_lock_helper",
            "--nocapture",
        ])
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "{}{}",
        String::from_utf8_lossy(&output.stdout),
        String::from_utf8_lossy(&output.stderr)
    );
}

#[test]
#[ignore = "runs in its own process from the handoff phase test"]
fn handoff_lock_helper() {
    let f = Fixture::new();
    let mut host = Fake::default();
    f.build(&mut host);
    let artisan = f.path().join("apps/gateway/artisan");
    fs::set_permissions(&artisan, fs::Permissions::from_mode(0o600)).unwrap();
    fs::write(&artisan, r#"<?php
if (count($argv) !== 4 || $argv[1] !== 'gateway:release:handoff' || !in_array($argv[2], ['--phase=serve', '--phase=schedule'], true) || $argv[3] !== '--no-interaction') { exit(2); }
if (getenv('CARGO_MANIFEST_DIR') !== false || !is_file(getenv('APP_CONFIG_CACHE'))) { exit(3); }
$lock = fopen(dirname(__DIR__, 4).'/gateway-release-step.lock', 'r+');
if (flock($lock, LOCK_EX | LOCK_NB)) { exit(4); }
echo json_encode(['phase' => $argv[2], 'caddy' => 'unchanged', 'scheduler' => 'restarted', 'cleanup_paused' => false]);
"#).unwrap();
    host.calls.clear();
    for _ in 0..2 {
        for phase in [HandoffPhase::Serve, HandoffPhase::Schedule] {
            let result = handoff(&handoff_input(&f, phase), &mut host, deadline()).unwrap();
            assert!(result.succeeded);
            assert_eq!(result.report["caddy"], "unchanged");
            assert_eq!(result.report["cleanup_paused"], false);
            assert!(
                !step(&StepInput {
                    step_lock: f.step.clone()
                })
                .unwrap()
                .busy
            );
        }
    }
    assert_eq!(host.calls.len(), 4);
    assert_eq!(
        host.calls[0],
        [
            "/usr/bin/php8.5",
            artisan.to_str().unwrap(),
            "gateway:release:handoff",
            "--phase=serve",
            "--no-interaction"
        ]
    );
    assert_eq!(host.calls[1][3], "--phase=schedule");
}

#[test]
fn handoff_preserves_reported_domain_failures_and_refuses_absent_or_malformed_reports() {
    let f = Fixture::new();
    let mut host = Fake::default();
    f.build(&mut host);
    let artisan = f.path().join("apps/gateway/artisan");
    fs::set_permissions(&artisan, fs::Permissions::from_mode(0o600)).unwrap();
    fs::write(&artisan, r#"<?php echo '{"error_code":"gateway.release_scheduler_busy","message":"A tick held the lock."}'; exit(1);"#).unwrap();
    let result = handoff(
        &handoff_input(&f, HandoffPhase::Schedule),
        &mut host,
        deadline(),
    )
    .unwrap();
    assert!(!result.succeeded);
    assert_eq!(
        result.report["error_code"],
        "gateway.release_scheduler_busy"
    );
    for content in [
        "<?php fwrite(STDERR, 'Command is not defined'); exit(1);",
        "<?php echo '[]';",
        "<?php echo 'invalid';",
    ] {
        fs::write(&artisan, content).unwrap();
        let error = failure(
            handoff(
                &handoff_input(&f, HandoffPhase::Serve),
                &mut host,
                deadline(),
            ),
            Failure::Handoff {},
        );
        assert_eq!(error.sha.as_ref(), Some(&f.sha));
    }
}

#[test]
fn handoff_refuses_unprepared_or_nonprivate_releases_and_busy_steps_before_execution() {
    let f = Fixture::new();
    let mut host = Fake::default();
    let input = handoff_input(&f, HandoffPhase::Serve);
    failure(
        handoff(&input, &mut host, deadline()),
        Failure::NotPrepared {},
    );
    assert!(host.calls.is_empty());
    f.build(&mut host);
    host.calls.clear();
    let held = hold_step(&f.step, deadline()).unwrap();
    failure(
        handoff(&input, &mut host, Instant::now()),
        Failure::InProgress {},
    );
    assert!(host.calls.is_empty());
    drop(held);
    fs::set_permissions(
        f.path().join("apps/gateway/bootstrap/cache/config.php"),
        fs::Permissions::from_mode(0o644),
    )
    .unwrap();
    failure(
        handoff(&input, &mut host, deadline()),
        Failure::Configuration {},
    );
    assert!(host.calls.is_empty());
    for json in [
        r#"{"checkout":"/home/orbit/orbit/apps/gateway","release":"0123456789ab","step_lock":"/home/orbit/.orbit/step.lock","phase":"shell"}"#,
        r#"{"checkout":"/home/orbit/orbit/apps/gateway","release":"0123456789ab","step_lock":"/home/orbit/.orbit/step.lock","phase":"serve","executable":"/bin/sh"}"#,
    ] {
        assert!(serde_json::from_str::<HandoffInput>(json).is_err());
    }
}
