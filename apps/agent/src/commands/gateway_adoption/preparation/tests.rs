use super::*;
use crate::commands::recipe::{Failure, WorkDir};
use std::{
    os::unix::fs::{symlink, MetadataExt, PermissionsExt},
    time::Instant,
};
struct Fixture {
    _dir: WorkDir,
    layout: Layout,
    input: StorageInput,
    sha: String,
}
impl Fixture {
    fn new() -> Self {
        let dir = WorkDir::new().unwrap();
        let base = dir.0.join("home");
        let checkout = base.join("orbit/apps/gateway");
        fs::create_dir_all(&checkout).unwrap();
        fs::write(checkout.join("tracked"), "original").unwrap();
        fs::write(
            checkout.join(".env"),
            b"APP_ENV=production\nexport APP_VERSION = '1'\nAPP_KEY=private-value\n",
        )
        .unwrap();
        fs::write(checkout.join(".env.bak-1"), "private-backup").unwrap();
        let repo = git2::Repository::init(base.join("orbit")).unwrap();
        repo.remote("origin", "https://github.com/example/orbit.git")
            .unwrap();
        let sha = commit(&repo, "first");
        repo.reference(
            "refs/remotes/origin/main",
            git2::Oid::from_str(&sha).unwrap(),
            true,
            "fixture",
        )
        .unwrap();
        let input = StorageInput {
            checkout: checkout.to_str().unwrap().into(),
        };
        let layout = Layout::new(&input.checkout).unwrap();
        Self {
            _dir: dir,
            layout,
            input,
            sha,
        }
    }
    fn repo_input(&self) -> RepositoryInput {
        RepositoryInput {
            checkout: self.input.checkout.clone(),
            sha: self.sha.clone(),
        }
    }
    fn prepare(&self) {
        repository(&self.repo_input(), &mut Native(&context())).unwrap();
    }
    fn app(&self) -> PathBuf {
        self.layout.current.join("apps/gateway")
    }
}
fn context() -> Context {
    Context::new(Platform::Linux, Instant::now() + Duration::from_secs(30))
}
fn commit(repo: &git2::Repository, message: &str) -> String {
    let mut index = repo.index().unwrap();
    index.add_path(Path::new("apps/gateway/tracked")).unwrap();
    index.write().unwrap();
    let tree = repo.find_tree(index.write_tree().unwrap()).unwrap();
    let signature =
        git2::Signature::new("Orbit", "orbit@example.test", &git2::Time::new(0, 0)).unwrap();
    let parent = repo.head().ok().and_then(|h| h.peel_to_commit().ok());
    repo.commit(
        Some("HEAD"),
        &signature,
        &signature,
        message,
        &tree,
        &parent.iter().collect::<Vec<_>>(),
    )
    .unwrap()
    .to_string()
}
#[test]
fn clean_checkout_preserves_detached_commit_origin_and_remote_refs_in_bare_repository_and_repeats()
{
    let f = Fixture::new();
    let repo = git2::Repository::open(&f.layout.current).unwrap();
    repo.set_head_detached(git2::Oid::from_str(&f.sha).unwrap())
        .unwrap();
    let ctx = context();
    let mut host = Native(&ctx);
    assert_eq!(check(&f.input, &mut host).unwrap().sha, f.sha);
    assert_eq!(check(&f.input, &mut host).unwrap().sha, f.sha);
    assert_eq!(
        repository(&f.repo_input(), &mut host).unwrap().repository,
        "created"
    );
    let path = f.layout.shared().join("orbit.git");
    let shared = git2::Repository::open_bare(&path).unwrap();
    assert_eq!(
        shared.find_remote("origin").unwrap().url(),
        Some("https://github.com/example/orbit.git")
    );
    assert_eq!(
        shared
            .refname_to_id("refs/remotes/origin/main")
            .unwrap()
            .to_string(),
        f.sha
    );
    assert_eq!(origin(&f.input).unwrap().sha, Some(f.sha.clone()));
    let ino = fs::metadata(&path).unwrap().ino();
    assert_eq!(
        repository(&f.repo_input(), &mut host).unwrap().repository,
        "existing"
    );
    assert_eq!(fs::metadata(&path).unwrap().ino(), ino);
    assert_eq!(
        fs::read(f.app().join(".env")).unwrap(),
        b"APP_ENV=production\nexport APP_VERSION = '1'\nAPP_KEY=private-value\n"
    );
}
#[test]
fn checkout_refuses_tracked_changes_before_creating_shared_state_but_ignores_untracked_files() {
    let f = Fixture::new();
    fs::write(f.app().join("tracked"), "changed").unwrap();
    assert!(
        matches!(check(&f.input, &mut Native(&context())), Err(Error::Changes {count: 1, path}) if path == "apps/gateway/tracked")
    );
    assert!(matches!(
        repository(&f.repo_input(), &mut Native(&context())),
        Err(Error::Changes { .. })
    ));
    assert!(!f.layout.shared().exists());
    fs::write(f.app().join("tracked"), "original").unwrap();
    assert_eq!(check(&f.input, &mut Native(&context())).unwrap().sha, f.sha);
}
#[test]
fn checkout_refuses_links_and_missing_git_or_commit() {
    let f = Fixture::new();
    fs::rename(&f.layout.current, f.layout.base.join("other")).unwrap();
    symlink("other", &f.layout.current).unwrap();
    assert!(matches!(
        check(&f.input, &mut Native(&context())),
        Err(Error::Unsupported {})
    ));
    fs::remove_file(&f.layout.current).unwrap();
    fs::rename(f.layout.base.join("other"), &f.layout.current).unwrap();
    fs::remove_dir_all(f.layout.current.join(".git")).unwrap();
    assert!(matches!(
        check(&f.input, &mut Native(&context())),
        Err(Error::NotCheckout {})
    ));
    git2::Repository::init(&f.layout.current).unwrap();
    assert!(matches!(
        check(&f.input, &mut Native(&context())),
        Err(Error::NotCheckout {})
    ));
    assert!(!f.layout.shared().exists());
}
#[test]
fn repository_refuses_changed_commit_and_foreign_partial_without_removing_it() {
    let f = Fixture::new();
    let wrong = RepositoryInput {
        checkout: f.input.checkout.clone(),
        sha: "f".repeat(40),
    };
    assert!(matches!(
        repository(&wrong, &mut Native(&context())),
        Err(Error::RepositoryConflict {})
    ));
    fs::create_dir_all(f.layout.shared().join("orbit.git.partial")).unwrap();
    fs::write(f.layout.shared().join("orbit.git.partial/keep"), "foreign").unwrap();
    assert!(matches!(
        repository(&f.repo_input(), &mut Native(&context())),
        Err(Error::RepositoryConflict {})
    ));
    assert_eq!(
        fs::read_to_string(f.layout.shared().join("orbit.git.partial/keep")).unwrap(),
        "foreign"
    );
    assert!(!f.layout.shared().join("orbit.git").exists());
}
#[test]
fn repository_resumes_an_unpublished_clone_and_refuses_a_conflicting_published_repository() {
    let f = Fixture::new();
    fs::create_dir(f.layout.shared()).unwrap();
    let partial = f.layout.shared().join("orbit.git.partial");
    git2::build::RepoBuilder::new()
        .bare(true)
        .clone(f.layout.current.to_str().unwrap(), &partial)
        .unwrap();
    f.prepare();
    assert!(!partial.exists());
    let shared = f.layout.shared().join("orbit.git");
    fs::remove_dir_all(&shared).unwrap();
    git2::Repository::init_bare(&shared).unwrap();
    assert!(matches!(
        repository(&f.repo_input(), &mut Native(&context())),
        Err(Error::RepositoryConflict {})
    ));
    assert!(git2::Repository::open_bare(&shared)
        .unwrap()
        .is_empty()
        .unwrap());
}
#[test]
fn target_fetches_only_a_matching_local_head_and_resolves_prefixes_then_repeats() {
    let f = Fixture::new();
    f.prepare();
    let source = f.layout.base.join("source");
    let repo = git2::Repository::clone(f.layout.current.to_str().unwrap(), &source).unwrap();
    fs::write(source.join("apps/gateway/tracked"), "next").unwrap();
    let sha = commit(&repo, "next");
    let input = TargetInput {
        checkout: f.input.checkout.clone(),
        target: sha[..9].into(),
        source: Some(source.to_str().unwrap().into()),
    };
    for _ in 0..2 {
        assert_eq!(target(&input, &mut Native(&context())).unwrap().sha, sha);
    }
    let unknown = TargetInput {
        target: "f".repeat(40),
        ..input
    };
    assert_eq!(
        target(&unknown, &mut Native(&context())).unwrap().sha,
        "f".repeat(40)
    );
    assert_eq!(origin(&f.input).unwrap().sha, Some(f.sha));
}
#[test]
fn missing_adoption_ref_reports_no_phase_one_release() {
    let f = Fixture::new();
    assert_eq!(origin(&f.input).unwrap().sha, None);
    f.prepare();
    git2::Repository::open_bare(f.layout.shared().join("orbit.git"))
        .unwrap()
        .find_reference("refs/orbit/pre-adopt")
        .unwrap()
        .delete()
        .unwrap();
    assert_eq!(origin(&f.input).unwrap().sha, None);
}
#[test]
fn environment_is_private_preserves_original_and_backups_and_repeats_then_restores_and_repeats() {
    let f = Fixture::new();
    f.prepare();
    let original = fs::read(f.app().join(".env")).unwrap();
    let out = environment(&f.input).unwrap();
    assert_eq!(
        (out.env, out.env_link, out.app_version_removed),
        ("created", "linked", true)
    );
    let target = f.layout.shared().join("gateway.env");
    assert_eq!(fs::read_link(f.app().join(".env")).unwrap(), target);
    assert_eq!(fs::read(&target).unwrap(), b"APP_ENV=production\n# export APP_VERSION = '1' (left out by gateway:release:adopt: each release reports its REVISION)\nAPP_KEY=private-value\n");
    assert_eq!(fs::read(f.app().join(".env.pre-adopt")).unwrap(), original);
    assert_eq!(
        out.env_backups,
        [f.layout
            .shared()
            .join("env-backups/.env.bak-1")
            .to_str()
            .unwrap()]
    );
    for path in [
        &target,
        &f.app().join(".env.pre-adopt"),
        &PathBuf::from(&out.env_backups[0]),
    ] {
        assert_eq!(fs::metadata(path).unwrap().mode() & 0o777, 0o600);
    }
    assert_eq!(
        fs::metadata(f.layout.shared().join("env-backups"))
            .unwrap()
            .mode()
            & 0o777,
        0o700
    );
    let ino = fs::metadata(&target).unwrap().ino();
    assert_eq!(environment(&f.input).unwrap().env_link, "existing");
    assert_eq!(fs::metadata(&target).unwrap().ino(), ino);
    let json = serde_json::to_string(&out).unwrap();
    assert!(!json.contains("private-value"));
    assert!(!json.contains("private-backup"));
    assert_eq!(
        restore_environment(&f.input).unwrap().environment,
        "restored"
    );
    assert_eq!(
        restore_environment(&f.input).unwrap().environment,
        "unchanged"
    );
    assert_eq!(fs::read(f.app().join(".env")).unwrap(), original);
    assert_eq!(environment(&f.input).unwrap().env, "existing");
}
#[test]
fn environment_preserves_empty_version_comments_crlf_and_non_utf8_values() {
    let f = Fixture::new();
    f.prepare();
    let bytes = b"# APP_VERSION=old\r\nAPP_VERSION=\r\nOTHER=\xff\r\nAPP_VERSION = value";
    fs::write(f.app().join(".env"), bytes).unwrap();
    environment(&f.input).unwrap();
    assert_eq!(fs::read(f.layout.shared().join("gateway.env")).unwrap(), b"# APP_VERSION=old\r\nAPP_VERSION=\r\nOTHER=\xff\r\n# APP_VERSION = value (left out by gateway:release:adopt: each release reports its REVISION)\n");
    assert_eq!(fs::read(f.app().join(".env.pre-adopt")).unwrap(), bytes);
}
#[test]
fn environment_conflicts_refuse_before_any_publication() {
    for conflict in ["gateway.env", "saved", "backup", "link", "next"] {
        let f = Fixture::new();
        f.prepare();
        let source = f.app().join(".env");
        let original = fs::read(&source).unwrap();
        let target = f.layout.shared().join("gateway.env");
        match conflict {
            "gateway.env" => fs::write(&target, "foreign").unwrap(),
            "saved" => fs::write(f.app().join(".env.pre-adopt"), "foreign").unwrap(),
            "backup" => {
                fs::create_dir(f.layout.shared().join("env-backups")).unwrap();
                fs::write(f.layout.shared().join("env-backups/.env.bak-1"), "foreign").unwrap();
            }
            "link" => {
                fs::remove_file(&source).unwrap();
                symlink("foreign", &source).unwrap();
            }
            "next" => fs::write(f.app().join(".env.adopt-link"), "foreign").unwrap(),
            _ => unreachable!(),
        }
        assert!(
            matches!(environment(&f.input), Err(Error::EnvConflict {})),
            "{conflict}"
        );
        if conflict != "gateway.env" {
            assert!(!target.exists());
        }
        if conflict != "link" {
            assert_eq!(fs::read(&source).unwrap(), original);
        }
    }
}
#[test]
fn environment_missing_or_unsafe_paths_refuse_and_restore_never_replaces_a_foreign_link() {
    let f = Fixture::new();
    f.prepare();
    fs::remove_file(f.app().join(".env")).unwrap();
    assert!(matches!(environment(&f.input), Err(Error::EnvMissing {})));
    assert_eq!(
        restore_environment(&f.input).unwrap().environment,
        "unchanged"
    );
    symlink("foreign", f.app().join(".env")).unwrap();
    fs::write(f.app().join(".env.pre-adopt"), "saved").unwrap();
    assert!(matches!(
        restore_environment(&f.input),
        Err(Error::EnvConflict {})
    ));
    assert_eq!(
        fs::read_link(f.app().join(".env")).unwrap(),
        Path::new("foreign")
    );
    fs::remove_file(f.app().join(".env")).unwrap();
    fs::write(f.app().join(".env"), "env").unwrap();
    fs::remove_dir_all(f.layout.shared()).unwrap();
    symlink(&f.layout.current, f.layout.shared()).unwrap();
    assert!(matches!(environment(&f.input), Err(Error::Conflict {})));
}
#[test]
fn invalid_identity_refuses_before_mutation_and_git_failures_remain_typed() {
    let f = Fixture::new();
    let input = RepositoryInput {
        sha: "HEAD".into(),
        ..f.repo_input()
    };
    assert!(matches!(
        repository(&input, &mut Native(&context())),
        Err(Error::Invalid {})
    ));
    let input = TargetInput {
        checkout: f.input.checkout.clone(),
        target: "main".into(),
        source: None,
    };
    assert!(matches!(
        target(&input, &mut Native(&context())),
        Err(Error::Invalid {})
    ));
    assert!(!f.layout.shared().exists());
    struct Broken;
    impl Host for Broken {
        fn execute(&mut self, _: &Invocation) -> Result<Output, Failure> {
            Err(Failure::new("test", "private-output"))
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            unreachable!()
        }
    }
    assert!(matches!(
        check(&f.input, &mut Broken),
        Err(Error::RepositoryFailed {})
    ));
    assert!(!f.layout.shared().exists());
}
#[test]
fn private_environment_write_failure_keeps_serving_file_unchanged() {
    let f = Fixture::new();
    f.prepare();
    let before = fs::read(f.app().join(".env")).unwrap();
    fs::set_permissions(f.layout.shared(), fs::Permissions::from_mode(0o500)).unwrap();
    let result = environment(&f.input);
    fs::set_permissions(f.layout.shared(), fs::Permissions::from_mode(0o750)).unwrap();
    assert!(matches!(result, Err(Error::EnvFailed {})));
    assert_eq!(fs::read(f.app().join(".env")).unwrap(), before);
    assert!(!f.app().join(".env.pre-adopt").exists());
}

#[test]
fn restore_does_not_follow_a_replaced_application_directory() {
    let f = Fixture::new();
    f.prepare();
    environment(&f.input).unwrap();
    let parked = f.layout.current.join("apps/parked");
    fs::rename(f.app(), &parked).unwrap();
    symlink("parked", f.app()).unwrap();
    assert!(matches!(
        restore_environment(&f.input),
        Err(Error::Conflict {})
    ));
    assert!(fs::symlink_metadata(parked.join(".env"))
        .unwrap()
        .is_symlink());
    assert!(parked.join(".env.pre-adopt").is_file());
}

#[test]
fn environment_finishes_a_staged_link_and_ignores_symlink_backups_without_rewriting_the_saved_file()
{
    let f = Fixture::new();
    f.prepare();
    let original = b"APP_ENV=production\nAPP_VERSION=\n";
    fs::write(f.app().join(".env"), original).unwrap();
    fs::remove_file(f.app().join(".env.bak-1")).unwrap();
    symlink(".env", f.app().join(".env.bak-link")).unwrap();
    private(&f.app().join(".env.pre-adopt"), original).unwrap();
    let ino = fs::metadata(f.app().join(".env.pre-adopt")).unwrap().ino();
    symlink(
        f.layout.shared().join("gateway.env"),
        f.app().join(".env.adopt-link"),
    )
    .unwrap();

    let output = environment(&f.input).unwrap();

    assert!(!output.app_version_removed);
    assert!(output.env_backups.is_empty());
    assert_eq!(fs::read(f.app().join(".env")).unwrap(), original);
    assert_eq!(
        fs::metadata(f.app().join(".env.pre-adopt")).unwrap().ino(),
        ino
    );
    assert!(!f.app().join(".env.adopt-link").exists());
    assert_eq!(environment(&f.input).unwrap().env_link, "existing");
}

#[test]
fn failed_environment_restore_keeps_the_shared_link_and_saved_original_for_retry() {
    let f = Fixture::new();
    f.prepare();
    environment(&f.input).unwrap();
    fs::set_permissions(f.app(), fs::Permissions::from_mode(0o500)).unwrap();
    let result = restore_environment(&f.input);
    fs::set_permissions(f.app(), fs::Permissions::from_mode(0o750)).unwrap();

    assert_eq!(result.unwrap().environment, "link_kept");
    assert!(fs::symlink_metadata(f.app().join(".env"))
        .unwrap()
        .is_symlink());
    assert!(f.app().join(".env.pre-adopt").is_file());
    assert_eq!(
        restore_environment(&f.input).unwrap().environment,
        "restored"
    );
}

#[test]
fn checking_a_clean_checkout_does_not_refresh_its_git_index() {
    let f = Fixture::new();
    let index = f.layout.current.join(".git/index");
    let bytes = fs::read(&index).unwrap();
    let inode = fs::metadata(&index).unwrap().ino();
    // The bytes match HEAD but the stat cache no longer matches the file.
    fs::write(f.app().join("tracked"), "original").unwrap();

    assert_eq!(check(&f.input, &mut Native(&context())).unwrap().sha, f.sha);

    assert_eq!(fs::read(&index).unwrap(), bytes);
    assert_eq!(fs::metadata(&index).unwrap().ino(), inode);
}
