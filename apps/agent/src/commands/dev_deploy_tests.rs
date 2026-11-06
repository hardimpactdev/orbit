//! The development deploy commands against a checkout of a local origin that the test user owns, as the managed
//! account owns its checkouts.

use super::*;
use std::os::unix::fs::PermissionsExt;
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
    crate::test_support::executable(&executable, "#!/bin/sh\nprintf '%s' \"$VP_HOME\"\n");
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
