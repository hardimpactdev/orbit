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
            git_read: BTreeMap::new(),
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
    assert!(
        fixture.checkout().lock().is_ok(),
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
