//! The development source commands against temporary repositories. The origin is a local bare repository, so a
//! fetch uses Git's `file` transport, which a test allows or refuses on purpose.

use super::*;
use crate::command::{Context, Platform};
use std::{process::Command as Process, time::Instant};

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

fn context() -> Context {
    Context::new(Platform::Linux, Instant::now() + Duration::from_secs(60))
}

struct Fixture {
    base: PathBuf,
    origin: String,
}

impl Fixture {
    /// An origin with `main` and `feature`, each with one commit, and an apps root.
    fn new() -> Self {
        let base = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!("orbit-dev-source-{:016x}", rand::random::<u64>()));
        let work = base.join("work");
        fs::create_dir_all(&work).unwrap();
        sh_git(&work, &["init", "--quiet"]);
        fs::write(work.join("README"), "one\n").unwrap();
        sh_git(&work, &["add", "README"]);
        sh_git(&work, &["commit", "--quiet", "-m", "one"]);
        sh_git(&work, &["branch", "feature"]);
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
        fs::create_dir_all(base.join("apps")).unwrap();
        Self {
            base,
            origin: origin.display().to_string(),
        }
    }

    fn source(&self, name: &str, prepare_id: Option<&str>) -> Source {
        let uid = rustix::process::geteuid().as_raw();
        let gid = rustix::process::getegid().as_raw();
        Source {
            repository: self.origin.clone(),
            checkout: self.base.join("apps/acme").join(name),
            root: self.base.join("apps"),
            user: user_name(uid),
            group: group_name(gid),
            prepare_id: prepare_id.map(str::to_string),
            seed_repository: None,
        }
    }

    fn prepare(
        &self,
        source: Source,
        allow_existing: bool,
        network: bool,
    ) -> Result<PrepareOutput, DevSourceError> {
        PrepareDevelopmentSource::run(
            PrepareInput {
                source,
                allow_existing,
                seed_commit: None,
                network,
                git_read: GitRead::default(),
            },
            &context(),
        )
    }

    fn resolve(
        &self,
        source: Source,
        name: &str,
        protocols: &[&str],
    ) -> Result<ResolveOutput, DevSourceError> {
        ResolveDevelopmentSource::run(
            ResolveInput {
                source,
                instance_name: name.into(),
                default_branch: "main".into(),
                branch_override: None,
                seed_commit: None,
                network: true,
                protocols: protocols
                    .iter()
                    .map(|protocol| protocol.to_string())
                    .collect(),
                git_read: GitRead::default(),
            },
            &context(),
        )
    }

    fn inspect(&self, source: Source, require_idle: bool) -> Result<InspectOutput, DevSourceError> {
        InspectDevelopmentSource::run(
            InspectInput {
                source,
                verify_origin: true,
                require_idle,
            },
            &context(),
        )
    }
}

impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.base);
    }
}

#[test]
fn prepares_a_clone_with_its_receipt_and_accepts_it_again_only_when_allowed() {
    let fixture = Fixture::new();
    let source = || fixture.source("dev", Some("attempt-1"));
    assert_eq!(
        fixture.prepare(source(), false, true),
        Ok(PrepareOutput { created: true })
    );
    let receipt = fs::read_to_string(source().checkout.join(".git").join(PREPARE_RECEIPT)).unwrap();
    assert_eq!(
        receipt,
        format!("attempt-1:{}\n", identity(&source().checkout))
    );
    assert_eq!(
        fixture.prepare(source(), true, true),
        Ok(PrepareOutput { created: false })
    );
    assert_eq!(
        fixture.prepare(source(), false, true),
        Err(DevSourceError::Invalid {
            check: "an existing checkout is allowed".into()
        })
    );
}

#[test]
fn a_task_vm_checkout_names_the_origin_without_fetching_it() {
    let fixture = Fixture::new();
    assert!(fixture
        .prepare(fixture.source("task-1", None), false, false)
        .is_ok());
    let checkout = fixture.source("task-1", None).checkout;
    assert_eq!(
        origin_url(&checkout).as_deref(),
        Some(fixture.origin.as_str())
    );
    assert_eq!(sh_git(&checkout, &["for-each-ref"]), "");
}

#[test]
fn refuses_a_parent_that_is_a_link_before_it_creates_anything() {
    let fixture = Fixture::new();
    fs::create_dir(fixture.base.join("elsewhere")).unwrap();
    std::os::unix::fs::symlink(
        fixture.base.join("elsewhere"),
        fixture.base.join("apps/acme"),
    )
    .unwrap();
    assert_eq!(
        fixture.prepare(fixture.source("dev", None), false, true),
        Err(DevSourceError::Invalid {
            check: "each parent is a real directory".into()
        })
    );
    assert!(fs::read_dir(fixture.base.join("elsewhere"))
        .unwrap()
        .next()
        .is_none());
}

#[test]
fn a_receipt_of_another_attempt_or_another_directory_is_refused() {
    let fixture = Fixture::new();
    fixture
        .prepare(fixture.source("dev", Some("attempt-1")), false, true)
        .unwrap();
    assert_eq!(
        fixture.inspect(fixture.source("dev", Some("attempt-2")), false),
        Err(DevSourceError::Invalid {
            check: "the prepare receipt names this attempt".into()
        })
    );
}

#[test]
fn resolves_the_origin_branch_of_the_instance_and_the_default_branch_otherwise() {
    let fixture = Fixture::new();
    fixture
        .prepare(fixture.source("feature", None), false, true)
        .unwrap();
    let resolved = fixture
        .resolve(fixture.source("feature", None), "feature", &["file"])
        .unwrap();
    assert_eq!(resolved.branch, "feature");
    assert_eq!(resolved.commit.len(), 40);

    fixture
        .prepare(fixture.source("other", None), false, true)
        .unwrap();
    let fallback = fixture
        .resolve(fixture.source("other", None), "other", &["file"])
        .unwrap();
    assert_eq!(
        fallback,
        ResolveOutput {
            branch: "other".into(),
            commit: resolved.commit.clone()
        }
    );
    assert_eq!(
        fixture
            .inspect(fixture.source("other", None), false)
            .unwrap(),
        InspectOutput {
            origin: fixture.origin.clone(),
            branch: Some("other".into()),
            commit: Some(resolved.commit)
        }
    );
}

#[test]
fn a_fetch_uses_only_the_allowed_transports() {
    let fixture = Fixture::new();
    fixture
        .prepare(fixture.source("dev", None), false, true)
        .unwrap();
    // The origin is a local path, so Git reaches it over `file`, which this fetch does not allow.
    assert_eq!(
        fixture.resolve(fixture.source("dev", None), "dev", &["https"]),
        Err(DevSourceError::GitFailed {
            step: "fetch the origin".into()
        })
    );
    assert!(fixture
        .resolve(fixture.source("dev", None), "dev", &["https", "file"])
        .is_ok());
}

#[test]
fn an_idle_read_refuses_while_another_operation_holds_the_checkout() {
    let fixture = Fixture::new();
    fixture
        .prepare(fixture.source("dev", None), false, true)
        .unwrap();
    let held = fs::File::open(fixture.source("dev", None).checkout).unwrap();
    rustix::fs::flock(&held, rustix::fs::FlockOperation::LockExclusive).unwrap();
    assert_eq!(
        fixture.inspect(fixture.source("dev", None), true),
        Err(DevSourceError::Busy {})
    );
    assert!(fixture.inspect(fixture.source("dev", None), false).is_ok());
}

#[test]
fn fetch_options_refuse_every_transport_that_is_not_allowed() {
    let options = GitRead::default().fetch_options(&["https".to_string()]);
    assert!(options
        .windows(2)
        .any(|pair| pair == ["-c", "protocol.https.allow=always"]));
    for refused in ["http", "ssh", "git", "file", "ext"] {
        assert!(
            options.contains(&format!("protocol.{refused}.allow=never")),
            "{refused}"
        );
    }
}
