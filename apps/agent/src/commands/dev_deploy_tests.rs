//! The development deploy commands against a checkout of a local origin that the test user owns, as the managed
//! account owns its checkouts.

use super::*;
use std::process::Command as Process;

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
    work: PathBuf,
    origin: PathBuf,
    home: PathBuf,
}

impl Fixture {
    /// An origin with two commits on `main`, the second of which removes `packages/old`, and a checkout of the first.
    fn new() -> Self {
        let base = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!("orbit-dev-deploy-{:016x}", rand::random::<u64>()));
        let work = base.join("work");
        fs::create_dir_all(work.join("packages/old")).unwrap();
        fs::write(work.join("packages/old/index.js"), "old\n").unwrap();
        fs::write(work.join("README"), "one\n").unwrap();
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
        let home = base.join("checkout");
        sh_git(
            &base,
            &[
                "clone",
                "--quiet",
                origin.to_str().unwrap(),
                home.to_str().unwrap(),
            ],
        );
        Self {
            base,
            work,
            origin,
            home,
        }
    }

    fn checkout(&self) -> Checkout {
        Checkout {
            home: self.home.clone(),
            repository: self.origin.display().to_string(),
            instance: 3,
        }
    }

    /// Pushes a second commit that removes `packages/old` and returns it.
    fn advance(&self) -> String {
        sh_git(&self.work, &["rm", "--quiet", "-r", "packages/old"]);
        fs::write(self.work.join("README"), "two\n").unwrap();
        sh_git(&self.work, &["commit", "--quiet", "-am", "two"]);
        sh_git(
            &self.work,
            &["push", "--quiet", self.origin.to_str().unwrap(), "main"],
        );
        sh_git(&self.work, &["rev-parse", "HEAD"])
    }

    fn target(&self, commit: Option<&str>) -> Result<Target, DevDeployError> {
        target(&TargetInput {
            checkout: self.checkout(),
            branch: "main".into(),
            commit: commit.map(String::from),
            protocols: vec!["file".into()],
            git_read: GitRead::default(),
        })
    }
}

impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.base);
    }
}

#[test]
fn targets_the_fetched_branch_or_a_commit_it_contains() {
    let fixture = Fixture::new();
    let second = fixture.advance();

    assert_eq!(
        fixture.target(None),
        Ok(Target {
            commit: second.clone()
        })
    );
    assert_eq!(fixture.target(Some(&second)), Ok(Target { commit: second }));
    assert_eq!(
        fixture.target(Some(&"a".repeat(40))),
        Err(DevDeployError::NotOnBranch {})
    );
}

#[test]
fn refuses_a_checkout_with_another_origin() {
    let fixture = Fixture::new();
    let mut checkout = fixture.checkout();
    checkout.repository = "https://example.test/other.git".into();

    assert_eq!(
        checkout.guard(),
        Err(DevDeployError::Invalid {
            check: "the checkout's origin is the repository".into()
        })
    );
}

#[test]
fn checks_out_the_target_and_drops_untracked_files_in_removed_directories() {
    let fixture = Fixture::new();
    let initial = sh_git(&fixture.home, &["rev-parse", "HEAD"]);
    let sibling = fixture.base.join("sibling");
    sh_git(
        &fixture.home,
        &[
            "worktree",
            "add",
            "--quiet",
            "-b",
            "sibling",
            sibling.to_str().unwrap(),
            "HEAD",
        ],
    );
    fs::create_dir(fixture.home.join(".cache")).unwrap();
    fs::write(fixture.home.join(".cache/warm"), "cached").unwrap();
    fs::write(fixture.home.join(".env"), "APP_ENV=development\n").unwrap();
    let second = fixture.advance();
    fixture.target(None).unwrap();
    fs::create_dir_all(fixture.home.join("packages/old/node_modules/dep")).unwrap();
    fs::write(fixture.home.join("packages/old/node_modules/dep/x.js"), "").unwrap();
    fs::set_permissions(
        fixture.home.join("packages/old/node_modules/dep"),
        fs::Permissions::from_mode(0o555),
    )
    .unwrap();

    let input = CheckoutInput {
        checkout: fixture.checkout(),
        branch: "main".into(),
        commit: second.clone(),
    };
    assert_eq!(checkout(&input), Ok(Target { commit: second }));

    assert!(!fixture.home.join("packages").exists());
    assert_eq!(
        fs::read_to_string(fixture.home.join("README")).unwrap(),
        "two\n"
    );
    assert_eq!(sh_git(&sibling, &["rev-parse", "HEAD"]), initial);
    assert_eq!(
        fs::read_to_string(fixture.home.join(".cache/warm")).unwrap(),
        "cached"
    );
    assert_eq!(
        fs::read_to_string(fixture.home.join(".env")).unwrap(),
        "APP_ENV=development\n"
    );
    assert!(!fixture.home.join("current").exists());
    assert!(!fixture.home.join("releases").exists());
}

#[test]
fn refuses_tracked_changes_a_diverged_branch_and_a_running_lifecycle_step() {
    let fixture = Fixture::new();
    let second = fixture.advance();
    fixture.target(None).unwrap();
    let input = CheckoutInput {
        checkout: fixture.checkout(),
        branch: "main".into(),
        commit: second,
    };

    fs::write(fixture.home.join("README"), "edited\n").unwrap();
    assert!(
        matches!(checkout(&input), Err(DevDeployError::Dirty { changes }) if changes.contains("README"))
    );
    sh_git(&fixture.home, &["checkout", "--quiet", "--", "README"]);

    fs::write(fixture.home.join("local"), "").unwrap();
    sh_git(&fixture.home, &["add", "local"]);
    sh_git(&fixture.home, &["commit", "--quiet", "-m", "local"]);
    assert_eq!(checkout(&input), Err(DevDeployError::Diverged {}));

    let held = fs::File::open(&fixture.home).unwrap();
    rustix::fs::flock(&held, rustix::fs::FlockOperation::LockExclusive).unwrap();
    assert_eq!(
        fixture.checkout().lock().err(),
        Some(DevDeployError::Busy {})
    );
}

#[test]
fn runs_a_step_in_the_checkout_with_redacted_output_and_no_inherited_lock() {
    let fixture = Fixture::new();
    let input = StepInput {
        vp_environment: None,
        checkout: fixture.checkout(),
        script: "pwd\necho token=s3cret >&2\nsleep 5 &\nexit 3\n".into(),
        step: "build".into(),
        timeout_seconds: 60,
        operation: None,
        secrets: vec!["s3cret".into()],
    };

    let started = std::time::Instant::now();
    let output = step(&input, None).unwrap();

    assert!(
        started.elapsed() < Duration::from_secs(3),
        "the step's leftover process stops with it"
    );
    assert_eq!(output.exit_code, 3);
    let pwd = format!(
        "{}
",
        fixture.home.display()
    );
    assert!(output
        .output
        .iter()
        .any(|chunk| chunk.stream == "stdout" && chunk.value == pwd));
    assert!(output
        .output
        .iter()
        .any(|chunk| chunk.stream == "stderr" && chunk.value == "token=[REDACTED]\n"));
    // Another test's fork can hold a copy of the lock for an instant before its exec closes it; a leftover of this
    // step would hold it for seconds.
    let free = (0..20).any(|_| {
        fixture.checkout().lock().is_ok() || {
            std::thread::sleep(Duration::from_millis(50));
            false
        }
    });
    assert!(
        free,
        "a process the step left behind does not hold the lock"
    );
    let leftovers = fs::read_dir(fixture.home.join(".git"))
        .unwrap()
        .flatten()
        .filter(|entry| {
            entry
                .file_name()
                .to_string_lossy()
                .starts_with("orbit-deploy-step.")
        })
        .count();
    assert_eq!(leftovers, 0);
}

#[test]
fn stops_a_step_and_every_process_it_started_at_its_timeout() {
    let fixture = Fixture::new();
    let input = StepInput {
        vp_environment: None,
        checkout: fixture.checkout(),
        script: "echo started\nsleep 30 &\necho $! > child-pid\nsleep 30\n".into(),
        step: "hang".into(),
        timeout_seconds: 1,
        operation: None,
        secrets: Vec::new(),
    };

    let started = std::time::Instant::now();
    let output = step(&input, None).unwrap();

    assert!(started.elapsed() < Duration::from_secs(5));
    assert_eq!(output.exit_code, 143);
    assert_eq!(output.output[0].value, "started\n");
    let child: i32 = fs::read_to_string(fixture.home.join("child-pid"))
        .unwrap()
        .trim()
        .parse()
        .unwrap();
    assert!(
        !Path::new(&format!("/proc/{child}")).exists(),
        "the step's background process stopped"
    );
}

fn lifecycle_checkout() -> PathBuf {
    let path = fs::canonicalize(std::env::temp_dir())
        .unwrap()
        .join(format!("orbit-lifecycle-{:016x}", rand::random::<u64>()));
    fs::create_dir_all(&path).unwrap();
    path
}

fn lifecycle_input(checkout: &Path, script: &str) -> LifecycleInput {
    LifecycleInput {
        checkout: checkout.to_path_buf(),
        program: StepProgram::Command(script.into()),
        step: "setup".into(),
        timeout_seconds: 30,
        environment: [("ORBIT_SEED_PATH".to_string(), "/seed".to_string())].into(),
    }
}

#[test]
fn a_lifecycle_step_runs_in_the_checkout_with_its_environment_and_reports_its_exit() {
    // Run in a separate process so unrelated parallel tests cannot inherit this test's open flock
    // between their fork and exec. The helper still asserts immediate reuse of the same checkout.
    let output = Process::new(std::env::current_exe().unwrap())
        .args([
            "--ignored",
            "--exact",
            "commands::dev_deploy::tests::lifecycle_repeat_helper",
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
#[ignore = "runs in its own process from the lifecycle repeat test"]
fn lifecycle_repeat_helper() {
    let checkout = lifecycle_checkout();

    let output = lifecycle_step(&lifecycle_input(
        &checkout,
        "pwd\necho \"$ORBIT_SEED_PATH\" >&2\nexit 4\n",
    ))
    .unwrap();

    assert_eq!(output.exit_code, 4);
    assert!(output.output.iter().any(
        |chunk| chunk.stream == "stdout" && chunk.value == format!("{}\n", checkout.display())
    ));
    assert!(output
        .output
        .iter()
        .any(|chunk| chunk.stream == "stderr" && chunk.value == "/seed\n"));
    assert_eq!(
        lifecycle_step(&lifecycle_input(&checkout, "true\n"))
            .unwrap()
            .exit_code,
        0
    );

    let executable = checkout.join("project-tool");
    fs::write(&executable, "#!/bin/sh\nprintf '%s' \"$VP_HOME\"\n").unwrap();
    fs::set_permissions(&executable, fs::Permissions::from_mode(0o700)).unwrap();
    let script = "./project-tool\ncat /proc/$$/cmdline > command-line\n";
    let mut input = lifecycle_input(&checkout, script);
    input
        .environment
        .insert("VP_HOME".into(), "/project/vp".into());
    let output = lifecycle_step(&input).unwrap();
    assert_eq!(output.exit_code, 0);
    assert!(output
        .output
        .iter()
        .any(|chunk| chunk.stream == "stdout" && chunk.value == "/project/vp"));
    let arguments = fs::read_to_string(checkout.join("command-line")).unwrap();
    assert!(
        !arguments.contains(script),
        "project source stays out of argv"
    );

    fs::set_permissions(&executable, fs::Permissions::from_mode(0o600)).unwrap();
    for (script, expected) in [("./project-tool\n", 126), ("./missing-project-tool\n", 127)] {
        let output = lifecycle_step(&lifecycle_input(&checkout, script)).unwrap();
        assert_eq!(output.exit_code, expected);
        assert!(output
            .output
            .iter()
            .any(|chunk| chunk.stream == "stderr" && !chunk.value.is_empty()));
    }
    let _ = fs::remove_dir_all(&checkout);
}

#[test]
fn a_lifecycle_step_refuses_a_busy_or_non_canonical_checkout_before_it_runs() {
    let checkout = lifecycle_checkout();
    let marker = checkout.join("ran");
    let script = format!("touch {}\n", marker.display());
    let held = fs::File::open(&checkout).unwrap();
    rustix::fs::flock(&held, rustix::fs::FlockOperation::LockExclusive).unwrap();

    assert_eq!(
        lifecycle_step(&lifecycle_input(&checkout, &script)).err(),
        Some(DevDeployError::Busy {})
    );
    drop(held);
    let link = checkout.with_extension("link");
    std::os::unix::fs::symlink(&checkout, &link).unwrap();
    assert!(matches!(
        lifecycle_step(&lifecycle_input(&link, &script)),
        Err(DevDeployError::Invalid { .. })
    ));
    assert!(!marker.exists());
    let _ = fs::remove_file(&link);
    let _ = fs::remove_dir_all(&checkout);
}

#[test]
fn a_lifecycle_step_reports_that_its_own_bound_stopped_it() {
    let checkout = lifecycle_checkout();
    let mut input = lifecycle_input(&checkout, "sleep 30 &\necho $! > child-pid\nwait\n");
    input.timeout_seconds = 1;

    let started = std::time::Instant::now();
    let output = lifecycle_step(&input).unwrap();

    assert!(started.elapsed() < Duration::from_secs(5));
    assert!(output.timed_out);
    assert_ne!(output.exit_code, 0);
    let child: u32 = fs::read_to_string(checkout.join("child-pid"))
        .unwrap()
        .trim()
        .parse()
        .unwrap();
    assert!(
        !Path::new(&format!("/proc/{child}")).exists(),
        "the lifecycle step's background process stopped"
    );
    let _ = fs::remove_dir_all(&checkout);
}

#[test]
fn ci_cache_selection_uses_the_default_checkout_or_its_recorded_baseline() {
    let checkout = lifecycle_checkout();
    sh_git(&checkout, &["init", "--quiet"]);
    fs::write(checkout.join("code"), "one").unwrap();
    sh_git(&checkout, &["add", "code"]);
    sh_git(&checkout, &["commit", "--quiet", "-m", "one"]);
    let first = sh_git(&checkout, &["rev-parse", "HEAD"]);
    sh_git(
        &checkout,
        &["update-ref", "refs/remotes/origin/main", "HEAD"],
    );
    sh_git(
        &checkout,
        &[
            "symbolic-ref",
            "refs/remotes/origin/HEAD",
            "refs/remotes/origin/main",
        ],
    );
    assert_eq!(ci_cache_baseline(&checkout), Some(first.clone()));

    fs::write(checkout.join("code"), "two").unwrap();
    sh_git(&checkout, &["commit", "--quiet", "-am", "two"]);
    let second = sh_git(&checkout, &["rev-parse", "HEAD"]);
    sh_git(
        &checkout,
        &["update-ref", "refs/remotes/origin/main", "HEAD"],
    );
    sh_git(
        &checkout,
        &["update-ref", "refs/worktree/orbit/ci-baseline", &first],
    );
    assert_eq!(ci_cache_baseline(&checkout), Some(second));

    sh_git(&checkout, &["checkout", "--quiet", "-b", "task"]);
    assert_eq!(ci_cache_baseline(&checkout), Some(first.clone()));
    sh_git(
        &checkout,
        &["update-ref", "-d", "refs/worktree/orbit/ci-baseline"],
    );
    sh_git(&checkout, &["checkout", "--quiet", "--detach", &first]);
    assert_eq!(ci_cache_baseline(&checkout), Some(first.clone()));
    sh_git(&checkout, &["checkout", "--quiet", "-b", "diverged"]);
    fs::write(checkout.join("code"), "own change").unwrap();
    sh_git(&checkout, &["commit", "--quiet", "-am", "own"]);
    assert_eq!(ci_cache_baseline(&checkout), None);
    sh_git(
        &checkout,
        &["update-ref", "refs/worktree/orbit/ci-baseline", &first],
    );
    assert_eq!(ci_cache_baseline(&checkout), Some(first));
    fs::remove_dir_all(checkout).unwrap();
}

#[test]
fn ci_cache_import_reports_missing_tooling_and_cold_baselines_then_runs_the_fixed_importer() {
    // A separate process keeps unrelated fork-based tests from retaining this checkout's flock.
    let output = Process::new(std::env::current_exe().unwrap())
        .args([
            "--ignored",
            "--exact",
            "commands::dev_deploy::tests::ci_cache_import_helper",
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
#[ignore = "runs in its own process from the CI cache import test"]
fn ci_cache_import_helper() {
    let checkout = lifecycle_checkout();
    let mut input = lifecycle_input(&checkout, "");
    input.program = StepProgram::ImportCiCaches;
    let missing = lifecycle_step(&input).unwrap();
    assert_eq!(missing.exit_code, 1);
    assert!(missing.output[0].value.contains("tooling is missing"));
    fs::create_dir(checkout.join("bin")).unwrap();
    fs::write(checkout.join("bin/ci-cache-ref"), "import sys\nfrom pathlib import Path\nPath('imported').write_text('|'.join(sys.argv[1:]))\nprint('imported')\n").unwrap();
    assert_eq!(
        lifecycle_step(&input).unwrap().output[0].value,
        "CI caches cold: no recorded code baseline.\n"
    );
    assert!(!checkout.join("imported").exists());
    sh_git(&checkout, &["init", "--quiet"]);
    sh_git(&checkout, &["add", "bin/ci-cache-ref"]);
    sh_git(&checkout, &["commit", "--quiet", "-m", "importer"]);
    let commit = sh_git(&checkout, &["rev-parse", "HEAD"]);
    sh_git(
        &checkout,
        &["update-ref", "refs/remotes/origin/main", "HEAD"],
    );
    sh_git(
        &checkout,
        &[
            "symbolic-ref",
            "refs/remotes/origin/HEAD",
            "refs/remotes/origin/main",
        ],
    );
    for _ in 0..2 {
        let result = lifecycle_step(&input).unwrap();
        assert_eq!(result.exit_code, 0);
        assert_eq!(result.output[0].value, "imported\n");
        assert_eq!(
            fs::read_to_string(checkout.join("imported")).unwrap(),
            format!("import|--commit|{commit}")
        );
        assert_eq!(
            fs::metadata(checkout.join("imported")).unwrap().mode() & 0o777,
            0o600
        );
    }
    fs::write(checkout.join("bin/ci-cache-ref"), "raise SystemExit(7)\n").unwrap();
    assert_eq!(lifecycle_step(&input).unwrap().exit_code, 7);
    fs::remove_dir_all(checkout).unwrap();
}

#[test]
fn ci_cache_baselines_stay_local_to_each_linked_worktree() {
    let checkout = lifecycle_checkout();
    sh_git(&checkout, &["init", "--quiet"]);
    fs::write(checkout.join("code"), "one").unwrap();
    sh_git(&checkout, &["add", "code"]);
    sh_git(&checkout, &["commit", "--quiet", "-m", "one"]);
    let first = sh_git(&checkout, &["rev-parse", "HEAD"]);
    fs::write(checkout.join("code"), "two").unwrap();
    sh_git(&checkout, &["commit", "--quiet", "-am", "two"]);
    let second = sh_git(&checkout, &["rev-parse", "HEAD"]);
    let linked = checkout.join("linked");
    sh_git(
        &checkout,
        &[
            "worktree",
            "add",
            "--quiet",
            "-b",
            "task",
            linked.to_str().unwrap(),
        ],
    );
    sh_git(
        &checkout,
        &["update-ref", "refs/worktree/orbit/ci-baseline", &first],
    );
    sh_git(
        &linked,
        &["update-ref", "refs/worktree/orbit/ci-baseline", &second],
    );
    assert_eq!(ci_cache_baseline(&checkout), Some(first));
    assert_eq!(ci_cache_baseline(&linked), Some(second));
    fs::remove_dir_all(checkout).unwrap();
}

#[test]
fn a_development_pin_refuses_a_merged_side_parent() {
    let fixture = Fixture::new();
    sh_git(&fixture.work, &["checkout", "-b", "side"]);
    sh_git(&fixture.work, &["commit", "--allow-empty", "-m", "side"]);
    let side = sh_git(&fixture.work, &["rev-parse", "HEAD"]);
    sh_git(&fixture.work, &["checkout", "main"]);
    sh_git(&fixture.work, &["commit", "--allow-empty", "-m", "main"]);
    sh_git(&fixture.work, &["merge", "--no-ff", "side", "-m", "merge"]);
    sh_git(
        &fixture.work,
        &["push", fixture.origin.to_str().unwrap(), "main"],
    );
    assert_eq!(
        fixture.target(Some(&side)),
        Err(DevDeployError::NotOnBranch {})
    );
}
