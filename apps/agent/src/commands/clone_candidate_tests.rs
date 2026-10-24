//! The clone candidate inspection against checkouts of a local origin that the test user owns, as the Instance user
//! owns its source.

use super::*;

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
    origin: PathBuf,
}

impl Fixture {
    /// An origin with one commit on `main`, and a development checkout of it.
    fn new() -> Self {
        let base = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!(
                "orbit-clone-candidate-{:016x}",
                rand::random::<u64>()
            ));
        let work = base.join("work");
        fs::create_dir_all(&work).unwrap();
        fs::write(work.join("README"), "one\n").unwrap();
        sh_git(&work, &["init", "--quiet"]);
        sh_git(&work, &["add", "."]);
        sh_git(&work, &["commit", "--quiet", "-m", "one"]);
        let origin = base.join("origin.git");
        sh_git(&base, &["clone", "--quiet", "--bare", "work", "origin.git"]);
        sh_git(&base, &["clone", "--quiet", "origin.git", "checkout"]);
        Self { base, origin }
    }

    fn input(&self, environment: &str, base: PathBuf, expected: PathBuf) -> CandidateInput {
        CandidateInput {
            environment: environment.into(),
            base,
            user: super::super::source::user_name(identity::effective_uid()),
            repository: self.origin.display().to_string(),
            configured_branch: "main".into(),
            target_branch: "main".into(),
            expected_source: expected,
            git_read: GitRead::default(),
        }
    }

    fn development(&self) -> CandidateInput {
        let checkout = self.base.join("checkout");
        self.input("development", checkout.clone(), checkout)
    }

    fn nested(&self) -> (CandidateInput, PathBuf, PathBuf) {
        let child = self.base.join("child");
        fs::create_dir_all(&child).unwrap();
        fs::write(child.join("readme"), "child\n").unwrap();
        sh_git(&child, &["init", "--quiet"]);
        sh_git(&child, &["add", "."]);
        sh_git(&child, &["commit", "--quiet", "-m", "child"]);
        let parent = self.base.join("parent");
        sh_git(&self.base, &["clone", "--quiet", "child", "parent"]);
        // These paths must stay data even when Git's output needs quoting for a human reader.
        let inner = "inner \t;$(touch marker)'\nleaf";
        let outer = "outer $() space";
        sh_git(
            &parent,
            &[
                "-c",
                "protocol.file.allow=always",
                "submodule",
                "add",
                "--quiet",
                "--name",
                "inner",
                child.to_str().unwrap(),
                inner,
            ],
        );
        sh_git(&parent, &["commit", "--quiet", "-am", "inner"]);
        let input = self.development();
        sh_git(
            &input.base,
            &[
                "-c",
                "protocol.file.allow=always",
                "submodule",
                "add",
                "--quiet",
                parent.to_str().unwrap(),
                outer,
            ],
        );
        sh_git(
            &input.base,
            &[
                "-c",
                "protocol.file.allow=always",
                "submodule",
                "update",
                "--init",
                "--recursive",
                "--quiet",
            ],
        );
        sh_git(&input.base, &["commit", "--quiet", "-am", "outer"]);
        sh_git(&input.base, &["push", "--quiet", "origin", "main"]);
        let outer = input.base.join(outer);
        let inner = outer.join(inner);
        (input, outer, inner)
    }

    /// A production home whose `current` selects a release cloned from the origin.
    fn production(&self) -> CandidateInput {
        let home = self.base.join("home");
        fs::create_dir_all(home.join("releases")).unwrap();
        sh_git(
            &home.join("releases"),
            &["clone", "--quiet", self.origin.to_str().unwrap(), "first"],
        );
        std::os::unix::fs::symlink("releases/first", home.join("current")).unwrap();
        self.input("production", home.clone(), home.join("releases/first"))
    }
}

impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.base);
    }
}

#[test]
fn a_clean_checkout_on_its_branch_answers_its_source_and_commit() {
    let fixture = Fixture::new();
    let input = fixture.development();
    let commit = sh_git(&input.base, &["rev-parse", "HEAD"]);

    assert_eq!(
        inspect(&input),
        Ok(Candidate {
            source: input.base.clone(),
            commit
        })
    );

    let input = fixture.production();
    assert_eq!(
        inspect(&input).map(|candidate| candidate.source),
        Ok(input.expected_source.clone())
    );
}

#[test]
fn a_changed_or_dirty_source_refuses() {
    let fixture = Fixture::new();
    let mut input = fixture.development();
    input.expected_source = fixture.base.join("elsewhere");
    assert_eq!(inspect(&input), Err(CandidateError::ReleaseChanged {}));

    let input = fixture.development();
    fs::write(input.base.join("untracked"), "x").unwrap();
    assert_eq!(inspect(&input), Err(CandidateError::Dirty {}));
}

#[test]
fn another_branch_or_a_detached_development_head_refuses() {
    let fixture = Fixture::new();
    let mut input = fixture.development();
    input.configured_branch = "release".into();
    assert_eq!(inspect(&input), Err(CandidateError::BranchInvalid {}));

    let input = fixture.development();
    sh_git(&input.base, &["checkout", "--quiet", "--detach"]);
    assert_eq!(inspect(&input), Err(CandidateError::BranchInvalid {}));
}

#[test]
fn a_production_home_without_a_selected_release_refuses() {
    let fixture = Fixture::new();
    let input = fixture.production();
    fs::remove_file(input.base.join("current")).unwrap();
    assert_eq!(inspect(&input), Err(CandidateError::ReleaseMissing {}));

    std::os::unix::fs::symlink("..", input.base.join("current")).unwrap();
    assert_eq!(inspect(&input), Err(CandidateError::SourceInvalid {}));
}

#[test]
fn the_repository_must_hold_the_commit_and_the_target_branch() {
    let fixture = Fixture::new();
    let mut input = fixture.development();
    input.target_branch = "missing".into();
    assert_eq!(inspect(&input), Err(CandidateError::TargetBranchMissing {}));

    let input = fixture.development();
    sh_git(
        &input.base,
        &[
            "-c",
            "user.name=O",
            "-c",
            "user.email=o@example.test",
            "commit",
            "--quiet",
            "--allow-empty",
            "-m",
            "local",
        ],
    );
    assert_eq!(inspect(&input), Err(CandidateError::CommitUnavailable {}));

    let mut input = fixture.development();
    input.repository = fixture.base.join("gone.git").display().to_string();
    assert!(
        matches!(inspect(&input), Err(CandidateError::RepositoryUnavailable { stderr }) if stderr.contains("gone.git"))
    );
}

#[test]
fn no_fsmonitor_runs_in_the_checkout_or_its_submodules() {
    let fixture = Fixture::new();
    let child = fixture.base.join("child");
    fs::create_dir_all(&child).unwrap();
    fs::write(child.join("readme"), "child\n").unwrap();
    sh_git(&child, &["init", "--quiet"]);
    sh_git(&child, &["add", "."]);
    sh_git(&child, &["commit", "--quiet", "-m", "child"]);
    let input = fixture.development();
    sh_git(
        &input.base,
        &[
            "-c",
            "protocol.file.allow=always",
            "submodule",
            "add",
            "--quiet",
            child.to_str().unwrap(),
            "nested",
        ],
    );
    sh_git(&input.base, &["commit", "--quiet", "-m", "nested"]);
    sh_git(&input.base, &["push", "--quiet", "origin", "main"]);
    let marker = fixture.base.join("monitor-ran");
    let monitor = fixture.base.join("monitor");
    fs::write(
        &monitor,
        format!("#!/bin/sh\nprintf ran >> '{}'\n", marker.display()),
    )
    .unwrap();
    fs::set_permissions(
        &monitor,
        std::os::unix::fs::PermissionsExt::from_mode(0o755),
    )
    .unwrap();
    for directory in [input.base.clone(), input.base.join("nested")] {
        sh_git(
            &directory,
            &["config", "core.fsmonitor", monitor.to_str().unwrap()],
        );
    }
    sh_git(&input.base.join("nested"), &["status", "--porcelain"]);
    assert!(marker.exists(), "the control run starts the monitor");
    fs::remove_file(&marker).unwrap();

    assert!(inspect(&input).is_ok());
    assert!(!marker.exists());
}

#[test]
fn nested_submodules_with_shell_punctuation_and_newlines_are_data_and_repeat_cleanly() {
    let fixture = Fixture::new();
    let (input, outer, inner) = fixture.nested();
    let commit = sh_git(&input.base, &["rev-parse", "HEAD"]);
    let before = [&input.base, &outer, &inner].map(|path| fs::read(git_index(path)).unwrap());

    for _ in 0..2 {
        assert_eq!(
            inspect(&input),
            Ok(Candidate {
                source: input.base.clone(),
                commit: commit.clone()
            })
        );
    }

    let after = [&input.base, &outer, &inner].map(|path| fs::read(git_index(path)).unwrap());
    assert_eq!(before, after);
    assert!(!input.base.join("marker").exists());
    assert!(!outer.join("marker").exists());
    assert!(!inner.join("marker").exists());
}

fn git_index(path: &Path) -> PathBuf {
    PathBuf::from(sh_git(
        path,
        &["rev-parse", "--path-format=absolute", "--git-path", "index"],
    ))
}

#[test]
fn nested_untracked_changed_and_missing_submodules_refuse_before_the_repository_fetch() {
    let fixture = Fixture::new();
    let (mut input, outer, inner) = fixture.nested();
    input.repository = fixture.base.join("not-a-repository").display().to_string();
    // Even a configured ignore rule cannot hide changes from the inspection.
    sh_git(&outer, &["config", "submodule.inner.ignore", "all"]);
    fs::write(inner.join("untracked"), "keep me").unwrap();
    assert_eq!(inspect(&input), Err(CandidateError::Dirty {}));
    assert_eq!(fs::read(inner.join("untracked")).unwrap(), b"keep me");
    fs::remove_file(inner.join("untracked")).unwrap();
    fs::write(inner.join("readme"), "changed\n").unwrap();
    assert_eq!(inspect(&input), Err(CandidateError::Dirty {}));
    sh_git(&inner, &["checkout", "--", "readme"]);
    sh_git(
        &inner,
        &[
            "commit",
            "--quiet",
            "--allow-empty",
            "-m",
            "unrecorded commit",
        ],
    );
    assert_eq!(inspect(&input), Err(CandidateError::Dirty {}));
    sh_git(&inner, &["checkout", "--quiet", "HEAD^"]);
    fs::remove_dir_all(&inner).unwrap();
    fs::create_dir(&inner).unwrap();
    assert_eq!(inspect(&input), Err(CandidateError::Dirty {}));
    assert!(inner.is_dir());
}

#[test]
fn clone_inspection_has_no_submodule_shell_program() {
    // This architectural rule protects the command boundary even when a shell would give the same result.
    let source = include_str!("clone_candidate.rs");
    assert!(!source.contains("\"foreach\""));
    assert!(!source.contains("\"sh\""));
    assert!(!source.contains("\"bash\""));
}
