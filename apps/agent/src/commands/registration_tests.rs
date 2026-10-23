//! Registration against temporary checkouts. The Gateway's privileged `RemoteRegistrationSourceManagerTest` runs the
//! same commands through its runner, with interruptions at each durable checkpoint.

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
}

impl Fixture {
    /// A checkout at `<base>/source` with one commit and an origin, and an empty apps directory.
    fn new() -> Self {
        let base = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!("orbit-registration-{:016x}", rand::random::<u64>()));
        let source = base.join("source");
        fs::create_dir_all(&source).unwrap();
        fs::create_dir_all(base.join("apps/acme")).unwrap();
        fs::set_permissions(&source, fs::Permissions::from_mode(0o755)).unwrap();
        sh_git(&source, &["init", "--quiet"]);
        sh_git(
            &source,
            &["remote", "add", "origin", "git@github.com:acme/Site.git"],
        );
        fs::write(source.join("README.md"), "one\n").unwrap();
        sh_git(&source, &["add", "README.md"]);
        sh_git(&source, &["commit", "--quiet", "-m", "one"]);
        Self { base }
    }

    fn source(&self) -> PathBuf {
        self.base.join("source")
    }

    fn destination(&self) -> PathBuf {
        self.base.join("apps/acme/site")
    }

    fn rows(&self, path: &Path) -> Result<Vec<SourceRow>, RegistrationError> {
        let uid = rustix::process::geteuid().as_raw();
        let gid = rustix::process::getegid().as_raw();
        inspect(&InspectInput {
            path: path.into(),
            include_worktrees: false,
            user: user_name(uid),
            group: group_name(gid),
        })
    }

    fn member(&self) -> Member {
        let row = self.rows(&self.source()).unwrap().remove(0);
        Member {
            id: 7,
            source: self.source(),
            destination: self.destination(),
            application: self.destination(),
            layout: row.layout.into(),
            commit: row.commit,
            branch: row.branch,
            detached: row.detached,
            digest: row.source_digest,
            common: row.common_repository_path.into(),
            worktrees: row.worktree_paths.into_iter().map(PathBuf::from).collect(),
            source_device: None,
            source_inode: None,
        }
    }
}

impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.base);
    }
}

#[test]
fn inspection_reports_the_identity_layout_and_slug_of_a_checkout() {
    let fixture = Fixture::new();
    let row = fixture.rows(&fixture.source()).unwrap().remove(0);
    assert_eq!(row.path, fixture.source().display().to_string());
    assert_eq!(
        (row.layout, row.branch.as_deref(), row.detached),
        ("checkout", Some("main"), false)
    );
    assert_eq!(row.repository_url, "git@github.com:acme/Site.git");
    assert_eq!(row.inferred_slug, "site");
    assert_eq!(row.inferred_root, None);
}

#[test]
fn inspection_refuses_a_checkout_that_others_may_write() {
    let fixture = Fixture::new();
    fs::set_permissions(fixture.source(), fs::Permissions::from_mode(0o757)).unwrap();
    assert_eq!(
        fixture.rows(&fixture.source()),
        Err(invalid(
            "the entry has no special bits and no write for others"
        ))
    );
}

#[test]
fn the_digest_changes_with_content_and_mode_but_not_with_unrelated_refs() {
    let fixture = Fixture::new();
    let digest = source_digest(&fixture.source()).unwrap();
    sh_git(
        &fixture.source(),
        &["update-ref", "refs/heads/another-agent", "HEAD"],
    );
    assert_eq!(source_digest(&fixture.source()).unwrap(), digest);
    fs::set_permissions(
        fixture.source().join("README.md"),
        fs::Permissions::from_mode(0o600),
    )
    .unwrap();
    assert_ne!(source_digest(&fixture.source()).unwrap(), digest);
}

#[test]
fn slugs_follow_the_repository_name_of_each_origin_form() {
    assert_eq!(inferred_slug("git@github.com:acme/Site.git"), "site");
    // As in the Python it replaces, `.git` is removed before the trailing slash.
    assert_eq!(
        inferred_slug("https://github.com/acme/site.git/"),
        "site.git"
    );
    assert_eq!(inferred_slug("ssh://git@example.test:22/acme/Repo"), "repo");
    assert_eq!(inferred_slug("https://example.test/acme/site?x=1"), "site");
}

#[test]
fn prepare_moves_the_source_and_cleanup_keeps_only_the_destination() {
    let fixture = Fixture::new();
    let member = fixture.member();
    let identities = relocate(Operation::Prepare, std::slice::from_ref(&member)).unwrap();
    assert_eq!(
        identities,
        [Identity {
            id: 7,
            source_device: identities[0].source_device,
            source_inode: identities[0].source_inode
        }]
    );
    assert!(identities[0].source_inode.is_some());
    assert!(!fixture.source().exists() && verify(&fixture.destination(), &member).is_ok());
    assert!(relocate(Operation::Cleanup, &[member]).is_ok());
    assert!(!fixture.source().exists());
}

#[test]
fn prepare_resumes_from_a_complete_stage_and_rebuilds_an_incomplete_one() {
    let fixture = Fixture::new();
    let member = fixture.member();
    copy_tree(&fixture.source(), &member.stage()).unwrap();
    fs::write(member.stage().join("README.md"), "partial stage\n").unwrap();
    assert!(relocate(Operation::Prepare, std::slice::from_ref(&member)).is_ok());
    assert!(!member.stage().exists() && verify(&fixture.destination(), &member).is_ok());

    let second = Fixture::new();
    let member = second.member();
    copy_tree(&second.source(), &member.stage()).unwrap();
    assert!(relocate(Operation::Prepare, std::slice::from_ref(&member)).is_ok());
    assert!(
        !member.stage().exists()
            && second.source().exists()
            && verify(&second.destination(), &member).is_ok()
    );
}

#[test]
fn a_changed_source_is_refused_before_anything_moves() {
    let fixture = Fixture::new();
    let member = fixture.member();
    fs::write(fixture.source().join("README.md"), "changed\n").unwrap();
    assert_eq!(
        relocate(Operation::Prepare, &[member]),
        Err(invalid("the content is the recorded one"))
    );
    assert!(fixture.source().exists() && !fixture.destination().exists());
}

#[test]
fn cleanup_refuses_an_original_that_was_replaced_after_prepare() {
    let fixture = Fixture::new();
    let mut member = fixture.member();
    copy_tree(&fixture.source(), &fixture.destination()).unwrap();
    member.source_device = Some(0);
    member.source_inode = Some(0);
    assert_eq!(
        relocate(Operation::Cleanup, &[member]),
        Err(invalid("the original is the one prepare left"))
    );
    assert!(fixture.source().exists());
}

#[test]
fn adoption_closes_the_environment_file_to_other_users() {
    let fixture = Fixture::new();
    let mut member = fixture.member();
    member.destination = fixture.source();
    member.application = fixture.source();
    fs::write(fixture.source().join(".env"), "APP_KEY=secret\n").unwrap();
    fs::set_permissions(
        fixture.source().join(".env"),
        fs::Permissions::from_mode(0o664),
    )
    .unwrap();
    member.digest = source_digest(&fixture.source()).unwrap();
    relocate(Operation::Adopt, std::slice::from_ref(&member)).unwrap();
    relocate(Operation::AdoptFinalize, &[member]).unwrap();
    assert_eq!(
        fs::metadata(fixture.source().join(".env")).unwrap().mode() & 0o777,
        0o660
    );
}

#[test]
fn a_laravel_receipt_restores_the_files_and_directory_times_it_kept() {
    let fixture = Fixture::new();
    let application = fixture.source();
    fs::create_dir_all(application.join("bootstrap/cache")).unwrap();
    fs::write(application.join(".env"), "APP_URL=old\n").unwrap();
    let input = |operation| ReceiptInput {
        checkout: fixture.source(),
        instance_id: 3,
        operation,
        application: application.clone(),
    };
    let before = fs::metadata(application.join("bootstrap")).unwrap().mtime();
    receipt(&input(ReceiptOperation::Prepare)).unwrap();
    fs::write(application.join(".env"), "APP_URL=new\n").unwrap();
    fs::write(application.join("bootstrap/cache/config.php"), "<?php\n").unwrap();
    receipt(&input(ReceiptOperation::Restore)).unwrap();
    assert_eq!(
        fs::read_to_string(application.join(".env")).unwrap(),
        "APP_URL=old\n"
    );
    assert!(!application.join("bootstrap/cache/config.php").exists());
    assert_eq!(
        fs::metadata(application.join("bootstrap")).unwrap().mtime(),
        before
    );
    assert!(!fixture.base.join(".orbit-registration-url-3").exists());
}

#[test]
fn an_incomplete_receipt_is_refused_and_kept() {
    let fixture = Fixture::new();
    let directory = fixture.base.join(".orbit-registration-url-4");
    fs::create_dir(&directory).unwrap();
    fs::write(
        directory.join("manifest"),
        r#"{"files":[true,false],"directories":[null,null,null]}"#,
    )
    .unwrap();
    let input = ReceiptInput {
        checkout: fixture.source(),
        instance_id: 4,
        operation: ReceiptOperation::Restore,
        application: fixture.source(),
    };
    assert_eq!(receipt(&input), Err(invalid("each kept file has its copy")));
    assert!(directory.join("manifest").exists());
}

#[test]
fn a_copied_tree_keeps_the_modes_and_times_of_files_directories_and_links() {
    let fixture = Fixture::new();
    std::os::unix::fs::symlink("README.md", fixture.source().join("readme-link")).unwrap();
    let copy = fixture.base.join("copy");
    copy_tree(&fixture.source(), &copy).unwrap();
    for relative in ["", "README.md", "readme-link", ".git"] {
        let (from, to) = (
            fs::symlink_metadata(fixture.source().join(relative)).unwrap(),
            fs::symlink_metadata(copy.join(relative)).unwrap(),
        );
        assert_eq!(
            (to.mode(), to.mtime(), to.mtime_nsec()),
            (from.mode(), from.mtime(), from.mtime_nsec()),
            "{relative}"
        );
    }
}
