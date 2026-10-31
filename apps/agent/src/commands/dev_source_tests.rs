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
fn preparation_retries_preserve_existing_work_and_require_the_attempt_receipt() {
    use std::os::unix::fs::MetadataExt;
    let fixture = Fixture::new();
    let source = || fixture.source("workspace", Some("attempt-1"));
    fixture.prepare(source(), false, true).unwrap();
    fixture.resolve(source(), "feature", &["file"]).unwrap();
    let checkout = source().checkout;
    fs::create_dir(checkout.join("vendor")).unwrap();
    fs::write(checkout.join("vendor/cache"), "private cache").unwrap();
    fs::write(checkout.join(".git/info/exclude"), "vendor/\n").unwrap();
    fs::write(checkout.join("README"), "uncommitted work").unwrap();
    let inode = fs::metadata(checkout.join("vendor/cache")).unwrap().ino();
    let index = fs::read(checkout.join(".git/index")).unwrap();
    let head = sh_git(&checkout, &["rev-parse", "HEAD"]);

    fixture.prepare(source(), true, true).unwrap();
    fixture.inspect(source(), false).unwrap();
    assert_eq!(
        fs::metadata(checkout.join("vendor/cache")).unwrap().ino(),
        inode
    );
    assert_eq!(
        fs::read_to_string(checkout.join("README")).unwrap(),
        "uncommitted work"
    );
    assert_eq!(fs::read(checkout.join(".git/index")).unwrap(), index);
    assert_eq!(sh_git(&checkout, &["rev-parse", "HEAD"]), head);

    fs::remove_file(checkout.join(".git").join(PREPARE_RECEIPT)).unwrap();
    for allow_existing in [false, true] {
        assert!(fixture.prepare(source(), allow_existing, true).is_err());
        assert!(!checkout.join(".git").join(PREPARE_RECEIPT).exists());
        assert_eq!(
            fs::metadata(checkout.join("vendor/cache")).unwrap().ino(),
            inode
        );
        assert_eq!(fs::read(checkout.join(".git/index")).unwrap(), index);
        assert_eq!(
            fs::read_to_string(checkout.join("README")).unwrap(),
            "uncommitted work"
        );
    }

    // A reservation with no preparation ID retains the documented interrupted-clone path.
    fixture
        .prepare(fixture.source("workspace", None), true, true)
        .unwrap();
    assert_eq!(sh_git(&checkout, &["rev-parse", "HEAD"]), head);
    assert_eq!(fs::read(checkout.join(".git/index")).unwrap(), index);
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

#[test]
fn explicit_and_default_branches_use_the_right_commit_and_preserve_a_local_only_branch() {
    let f = Fixture::new();
    let work = f.base.join("work");
    sh_git(
        &work,
        &["commit", "--quiet", "--allow-empty", "-m", "new main"],
    );
    sh_git(&work, &["push", "--quiet", &f.origin, "main"]);
    let main = sh_git(&work, &["rev-parse", "main"]);
    let feature = sh_git(&work, &["rev-parse", "feature"]);
    assert_ne!(main, feature);
    for (name, selected, branch, expected) in [
        ("default", None, "main", &main),
        ("feature", None, "feature", &feature),
        ("task-12", None, "task-12", &main),
        ("default", Some("feature"), "feature", &feature),
        ("default", Some("missing"), "missing", &main),
        ("t3-1234", Some("t3code/1234"), "t3code/1234", &main),
    ] {
        let checkout_name = format!("case-{:016x}", rand::random::<u64>());
        f.prepare(f.source(&checkout_name, None), false, true)
            .unwrap();
        let resolve = || {
            ResolveDevelopmentSource::run(
                ResolveInput {
                    source: f.source(&checkout_name, None),
                    instance_name: name.into(),
                    default_branch: "main".into(),
                    branch_override: selected.map(str::to_owned),
                    seed_commit: None,
                    network: true,
                    protocols: vec!["file".into()],
                    git_read: GitRead::default(),
                },
                &context(),
            )
        };
        let result = resolve().unwrap();
        assert_eq!(
            (&result.branch, &result.commit),
            (&branch.to_string(), expected)
        );
        let checkout = f.source(&checkout_name, None).checkout;
        assert_eq!(
            sh_git(&checkout, &["symbolic-ref", "--short", "HEAD"]),
            branch
        );
        if branch == "t3code/1234" {
            sh_git(
                &checkout,
                &["commit", "--quiet", "--allow-empty", "-m", "local"],
            );
            let local = sh_git(&checkout, &["rev-parse", "HEAD"]);
            assert_ne!(&local, expected);
            assert_eq!(resolve().unwrap().commit, local);
        }
    }
}

#[test]
fn linked_source_uses_the_seed_commit_even_when_origin_main_is_newer() {
    let f = Fixture::new();
    f.prepare(f.source("default", None), false, true).unwrap();
    let deployed = f
        .resolve(f.source("default", None), "default", &["file"])
        .unwrap()
        .commit;
    let work = f.base.join("work");
    sh_git(
        &work,
        &["commit", "--quiet", "--allow-empty", "-m", "new main"],
    );
    sh_git(&work, &["push", "--quiet", &f.origin, "main"]);
    let newer = sh_git(&work, &["rev-parse", "HEAD"]);
    assert_ne!(deployed, newer);
    let source = || {
        let mut source = f.source("task-12", Some("attempt-1"));
        source.seed_repository = Some(f.source("default", None).checkout);
        source
    };
    PrepareDevelopmentSource::run(
        PrepareInput {
            source: source(),
            allow_existing: false,
            seed_commit: Some(deployed.clone()),
            network: false,
            git_read: GitRead::default(),
        },
        &context(),
    )
    .unwrap();
    let resolved = ResolveDevelopmentSource::run(
        ResolveInput {
            source: source(),
            instance_name: "task-12".into(),
            default_branch: "main".into(),
            branch_override: None,
            seed_commit: Some(deployed.clone()),
            network: false,
            protocols: vec![],
            git_read: GitRead::default(),
        },
        &context(),
    )
    .unwrap();
    assert_eq!(
        resolved,
        ResolveOutput {
            branch: "task-12".into(),
            commit: deployed
        }
    );
    assert!(source().checkout.join(".git").is_file());
    assert!(f.inspect(source(), false).is_ok());
}

#[test]
fn copied_receipts_do_not_authorize_a_replacement_checkout() {
    let f = Fixture::new();
    let source = || f.source("dev", Some("attempt-1"));
    f.prepare(source(), false, true).unwrap();
    let kept = f.base.join("original");
    fs::rename(source().checkout, &kept).unwrap();
    assert!(Process::new("cp")
        .arg("-a")
        .arg(&kept)
        .arg(source().checkout)
        .status()
        .unwrap()
        .success());
    let before = fs::read(source().checkout.join(".git").join(PREPARE_RECEIPT)).unwrap();
    assert_eq!(
        f.inspect(source(), false),
        Err(DevSourceError::Invalid {
            check: "the prepare receipt names this attempt".into()
        })
    );
    assert!(f.prepare(source(), true, true).is_err());
    assert_eq!(
        fs::read(source().checkout.join(".git").join(PREPARE_RECEIPT)).unwrap(),
        before
    );
    assert!(kept.join(".git").is_dir());
}

#[test]
fn configured_origin_is_used_despite_a_local_insteadof_rewrite() {
    let f = Fixture::new();
    let source = || f.source("dev", None);
    f.prepare(source(), false, true).unwrap();
    let checkout = source().checkout;
    sh_git(
        &checkout,
        &[
            "config",
            &format!("url.file://{}.insteadOf", f.origin),
            &f.origin,
        ],
    );
    assert_eq!(f.inspect(source(), false).unwrap().origin, f.origin);
    assert!(!f.prepare(source(), true, true).unwrap().created);
    assert!(f.resolve(source(), "dev", &["file"]).is_ok());
    sh_git(
        &checkout,
        &[
            "remote",
            "set-url",
            "origin",
            "https://wrong.test/other.git",
        ],
    );
    assert_eq!(
        f.inspect(source(), false),
        Err(DevSourceError::Invalid {
            check: "the origin is the Project repository".into()
        })
    );
}

#[test]
fn resolve_does_not_run_command_keys_planted_in_checkout_config() {
    let f = Fixture::new();
    f.prepare(f.source("dev", None), false, true).unwrap();
    let checkout = f.source("dev", None).checkout;
    let markers = f.base.join("markers");
    fs::create_dir(&markers).unwrap();
    let included = f.base.join("included.config");
    fs::write(
        &included,
        format!(
            "[credential]\nhelper = !touch {}/included\n",
            markers.display()
        ),
    )
    .unwrap();
    let hooks = f.base.join("hooks");
    fs::create_dir(&hooks).unwrap();
    fs::write(
        hooks.join("post-checkout"),
        format!("#!/bin/sh\ntouch {}/hook\n", markers.display()),
    )
    .unwrap();
    fs::set_permissions(
        hooks.join("post-checkout"),
        std::os::unix::fs::PermissionsExt::from_mode(0o755),
    )
    .unwrap();
    for (key, value) in [
        (
            "credential.helper",
            format!("!touch {}/credential", markers.display()),
        ),
        (
            "core.sshCommand",
            format!("touch {}/ssh;", markers.display()),
        ),
        (
            "core.fsmonitor",
            format!("touch {}/fsmonitor;", markers.display()),
        ),
        (
            "remote.origin.uploadpack",
            format!("touch {}/upload; git-upload-pack", markers.display()),
        ),
        (
            "remote.origin.receivepack",
            format!("touch {}/receive; git-receive-pack", markers.display()),
        ),
        ("include.path", included.display().to_string()),
        ("core.hooksPath", hooks.display().to_string()),
        ("protocol.ext.allow", "always".into()),
    ] {
        sh_git(&checkout, &["config", "--add", key, &value]);
    }
    assert!(f.resolve(f.source("dev", None), "dev", &["file"]).is_ok());
    assert!(fs::read_dir(&markers).unwrap().next().is_none());
    let rewrite = format!("url.ext::sh -c touch% {}/ext.insteadOf", markers.display());
    sh_git(&checkout, &["config", &rewrite, &f.origin]);
    assert_eq!(
        f.resolve(f.source("dev", None), "dev", &["file"]),
        Err(DevSourceError::GitFailed {
            step: "fetch the origin".into()
        })
    );
    assert!(fs::read_dir(markers).unwrap().next().is_none());
}

#[test]
fn idle_inspection_is_a_local_read_and_creates_no_lock_file() {
    let f = Fixture::new();
    let source = || f.source("dev", None);
    f.prepare(source(), false, true).unwrap();
    f.resolve(source(), "dev", &["file"]).unwrap();
    let checkout = source().checkout;
    let index = fs::read(checkout.join(".git/index")).unwrap();
    let refs = sh_git(&checkout, &["show-ref"]);
    fs::rename(&f.origin, f.base.join("offline.git")).unwrap();
    let entries = || {
        let mut entries: Vec<_> = fs::read_dir(&checkout)
            .unwrap()
            .map(|entry| entry.unwrap().file_name())
            .collect();
        entries.sort();
        entries
    };
    let before = entries();
    let held = fs::File::open(&checkout).unwrap();
    rustix::fs::flock(&held, rustix::fs::FlockOperation::LockExclusive).unwrap();
    assert_eq!(f.inspect(source(), true), Err(DevSourceError::Busy {}));
    drop(held);
    assert_eq!(
        f.inspect(source(), true).unwrap().branch.as_deref(),
        Some("dev")
    );
    assert_eq!(entries(), before);
    assert_eq!(fs::read(checkout.join(".git/index")).unwrap(), index);
    assert_eq!(sh_git(&checkout, &["show-ref"]), refs);
}

#[test]
fn a_branch_held_by_the_seed_uses_a_private_local_branch_without_changing_the_seed() {
    let f = Fixture::new();
    let seed = f.source("default", None);
    f.prepare(f.source("default", None), false, true).unwrap();
    let resolved = f
        .resolve(f.source("default", None), "default", &["file"])
        .unwrap();
    let before = sh_git(&seed.checkout, &["status", "--porcelain"]);
    for (name, selected, attempt) in [
        ("main", None, "attempt-main"),
        ("proof", Some("main"), "attempt-proof"),
    ] {
        let source = || {
            let mut source = f.source(name, Some(attempt));
            source.seed_repository = Some(seed.checkout.clone());
            source
        };
        PrepareDevelopmentSource::run(
            PrepareInput {
                source: source(),
                allow_existing: false,
                seed_commit: Some(resolved.commit.clone()),
                network: false,
                git_read: GitRead::default(),
            },
            &context(),
        )
        .unwrap();
        let run = || {
            ResolveDevelopmentSource::run(
                ResolveInput {
                    source: source(),
                    instance_name: name.into(),
                    default_branch: "main".into(),
                    branch_override: selected.map(str::to_owned),
                    seed_commit: Some(resolved.commit.clone()),
                    network: false,
                    protocols: vec![],
                    git_read: GitRead::default(),
                },
                &context(),
            )
            .unwrap()
        };
        let result = run();
        assert_eq!(result.branch, format!("orbit/instance/{attempt}"));
        assert_eq!(result.commit, resolved.commit);
        assert_eq!(
            sh_git(&source().checkout, &["symbolic-ref", "--short", "HEAD"]),
            result.branch
        );
        assert!(source().checkout.join("README").is_file());
        assert_eq!(run(), result);
    }
    assert_eq!(
        sh_git(&seed.checkout, &["symbolic-ref", "--short", "HEAD"]),
        "main"
    );
    assert_eq!(
        sh_git(&seed.checkout, &["rev-parse", "HEAD"]),
        resolved.commit
    );
    assert_eq!(sh_git(&seed.checkout, &["status", "--porcelain"]), before);
}

#[test]
fn environment_template_uses_the_committed_application_blob_and_refuses_changed_identity() {
    let fixture = Fixture::new();
    fixture
        .prepare(fixture.source("proof", Some("attempt")), false, true)
        .unwrap();
    let checkout = fixture.source("proof", Some("attempt")).checkout;
    let app = checkout.join("server app");
    fs::create_dir_all(&app).unwrap();
    fs::write(
        app.join(".env.example"),
        "APP_NAME=committed\nAPP_SECRET=\n",
    )
    .unwrap();
    fs::write(checkout.join(".env.example"), "APP_NAME=wrong-root\n").unwrap();
    sh_git(&checkout, &["add", "."]);
    sh_git(&checkout, &["commit", "--quiet", "-m", "templates"]);
    let commit = sh_git(&checkout, &["rev-parse", "HEAD"]);
    fs::write(app.join(".env.example"), "APP_NAME=workload-edit\n").unwrap();
    fs::write(app.join(".env"), "PRIVATE_TOKEN=never-read\n").unwrap();
    let read = |application: PathBuf, commit: String| {
        ReadDevelopmentEnvironmentTemplate::run(
            EnvironmentTemplateInput {
                source: fixture.source("proof", Some("attempt")),
                application,
                commit,
            },
            &context(),
        )
    };

    assert_eq!(
        read(app.clone(), commit.clone())
            .unwrap()
            .environment_template,
        "APP_NAME=committed\nAPP_SECRET=\n"
    );
    assert!(read(app, "a".repeat(40)).is_err());
    assert!(read(checkout.join("../outside"), commit.clone()).is_err());
    assert_eq!(
        read(checkout.join("missing"), commit)
            .unwrap()
            .environment_template,
        ""
    );
}

#[test]
fn environment_template_refuses_committed_links_and_oversized_blobs() {
    for linked in [true, false] {
        let fixture = Fixture::new();
        fixture
            .prepare(fixture.source("proof", None), false, true)
            .unwrap();
        let checkout = fixture.source("proof", None).checkout;
        if linked {
            std::os::unix::fs::symlink(".env", checkout.join(".env.example")).unwrap();
        } else {
            fs::write(checkout.join(".env.example"), vec![b'x'; 131_073]).unwrap();
        }
        sh_git(&checkout, &["add", ".env.example"]);
        sh_git(&checkout, &["commit", "--quiet", "-m", "unsafe template"]);
        let result = ReadDevelopmentEnvironmentTemplate::run(
            EnvironmentTemplateInput {
                source: fixture.source("proof", None),
                application: checkout.clone(),
                commit: sh_git(&checkout, &["rev-parse", "HEAD"]),
            },
            &context(),
        );
        assert!(result.is_err());
    }
}
