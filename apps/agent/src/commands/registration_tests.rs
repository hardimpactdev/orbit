//! Registration owns filesystem safety and recovery. PHP tests fake receipts and check database checkpoints.

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

#[derive(Debug, PartialEq, Eq)]
struct Entry {
    path: PathBuf,
    mode: u32,
    uid: u32,
    gid: u32,
    modified: (i64, i64),
    bytes: Option<Vec<u8>>,
    link: Option<PathBuf>,
}
fn manifest(root: &Path, include_git: bool) -> Vec<Entry> {
    fn walk(root: &Path, path: &Path, include_git: bool, rows: &mut Vec<Entry>) {
        let metadata = fs::symlink_metadata(path).unwrap();
        rows.push(Entry {
            path: path.strip_prefix(root).unwrap().into(),
            mode: metadata.mode(),
            uid: metadata.uid(),
            gid: metadata.gid(),
            modified: (metadata.mtime(), metadata.mtime_nsec()),
            bytes: metadata.is_file().then(|| fs::read(path).unwrap()),
            link: metadata.is_symlink().then(|| fs::read_link(path).unwrap()),
        });
        if metadata.is_dir() {
            let mut entries: Vec<_> = fs::read_dir(path)
                .unwrap()
                .map(|e| e.unwrap().path())
                .collect();
            entries.sort();
            for entry in entries {
                if include_git || entry != root.join(".git") {
                    walk(root, &entry, include_git, rows);
                }
            }
        }
    }
    let mut entries = Vec::new();
    walk(root, root, include_git, &mut entries);
    entries
}
fn complete_member(member: &mut Member, identities: &[Identity]) {
    let identity = identities
        .iter()
        .find(|identity| identity.id == member.id)
        .unwrap();
    member.source_device = identity.source_device;
    member.source_inode = identity.source_inode;
}
fn dirty(f: &Fixture) {
    fs::create_dir(f.source().join("bin")).unwrap();
    fs::write(f.source().join("bin/run"), "#!/bin/sh\nexit 0\n").unwrap();
    fs::set_permissions(
        f.source().join("bin/run"),
        fs::Permissions::from_mode(0o750),
    )
    .unwrap();
    std::os::unix::fs::symlink("README.md", f.source().join("readme-link")).unwrap();
    fs::write(f.source().join("README.md"), "dirty\n").unwrap();
    fs::write(f.source().join("staged.txt"), "staged\n").unwrap();
    sh_git(&f.source(), &["add", "staged.txt"]);
    fs::write(f.source().join("untracked.txt"), "untracked\n").unwrap();
    sh_git(&f.source(), &["config", "orbit.fixture", "preserved"]);
}

#[test]
#[cfg(target_os = "linux")]
fn cross_filesystem_recovery_preserves_dirty_source_at_every_durable_checkpoint() {
    struct Destination(PathBuf);
    impl Drop for Destination {
        fn drop(&mut self) {
            let _ = fs::remove_dir_all(&self.0);
        }
    }
    for checkpoint in [
        "initial",
        "incomplete",
        "complete",
        "duplicate",
        "destination-only",
    ] {
        let f = Fixture::new();
        dirty(&f);
        let target = Destination(
            PathBuf::from("/dev/shm")
                .join(format!("orbit-registration-{:016x}", rand::random::<u64>())),
        );
        fs::create_dir(&target.0).unwrap();
        assert_ne!(
            fs::metadata(f.source()).unwrap().dev(),
            fs::metadata(&target.0).unwrap().dev()
        );
        let mut member = f.member();
        member.destination = target.0.join("destination");
        member.application = member.destination.clone();
        let before = manifest(&f.source(), true);
        match checkpoint {
            "incomplete" | "complete" => {
                copy_tree(&f.source(), &member.stage()).unwrap();
                if checkpoint == "incomplete" {
                    fs::write(member.stage().join("README.md"), "partial").unwrap();
                }
            }
            "duplicate" | "destination-only" => {
                copy_tree(&f.source(), &member.destination).unwrap();
                if checkpoint == "destination-only" {
                    fs::remove_dir_all(f.source()).unwrap();
                }
            }
            _ => {}
        }
        let identities = relocate(Operation::Prepare, std::slice::from_ref(&member)).unwrap();
        complete_member(&mut member, &identities);
        assert_eq!(manifest(&member.destination, true), before, "{checkpoint}");
        assert!(!member.stage().exists());
        relocate(Operation::Cleanup, std::slice::from_ref(&member)).unwrap();
        relocate(Operation::Cleanup, std::slice::from_ref(&member)).unwrap();
        assert!(!member.source.exists());
        assert_eq!(manifest(&member.destination, true), before, "{checkpoint}");
    }
}

#[test]
fn incomplete_stage_without_an_original_is_retained_and_refused() {
    let f = Fixture::new();
    let member = f.member();
    copy_tree(&f.source(), &member.stage()).unwrap();
    fs::write(member.stage().join("README.md"), "partial").unwrap();
    fs::remove_dir_all(f.source()).unwrap();
    let before = manifest(&member.stage(), true);
    assert_eq!(
        relocate(Operation::Prepare, std::slice::from_ref(&member)),
        Err(invalid("an incomplete stage has its original"))
    );
    assert!(!member.destination.exists());
    assert_eq!(manifest(&member.stage(), true), before);
}

#[test]
fn moved_source_resumes_despite_unrelated_refs_but_refuses_a_changed_head() {
    for change_head in [false, true] {
        let f = Fixture::new();
        let member = f.member();
        fs::rename(&member.source, &member.destination).unwrap();
        if change_head {
            sh_git(
                &member.destination,
                &["commit", "--allow-empty", "-m", "changed"],
            );
        } else {
            sh_git(
                &member.destination,
                &["update-ref", "refs/t3/retry", "HEAD"],
            );
        }
        let before = manifest(&member.destination, true);
        let result = relocate(Operation::Prepare, std::slice::from_ref(&member));
        if change_head {
            assert_eq!(result, Err(invalid("the commit is the recorded one")));
        } else {
            result.unwrap();
            relocate(Operation::Cleanup, std::slice::from_ref(&member)).unwrap();
        }
        assert_eq!(manifest(&member.destination, true), before);
        assert!(!member.source.exists());
    }
}

#[test]
fn cleanup_resumes_a_partially_removed_original_only_with_its_recorded_identity() {
    let f = Fixture::new();
    dirty(&f);
    let mut member = f.member();
    copy_tree(&member.source, &member.destination).unwrap();
    let original = fs::metadata(&member.source).unwrap();
    member.source_device = Some(original.dev());
    member.source_inode = Some(original.ino());
    let before = manifest(&member.destination, true);
    fs::remove_file(member.source.join("README.md")).unwrap();
    assert!(member.source.join("untracked.txt").exists());
    relocate(Operation::Cleanup, std::slice::from_ref(&member)).unwrap();
    assert!(!member.source.exists());
    assert_eq!(manifest(&member.destination, true), before);
}

#[test]
fn inspection_uses_configured_origin_and_infers_framework_public_roots() {
    for entry in [None, Some("artisan"), Some("bin/console")] {
        let f = Fixture::new();
        sh_git(
            &f.source(),
            &[
                "config",
                "url.https://rewritten.test/.insteadOf",
                "git@github.com:",
            ],
        );
        if let Some(entry) = entry {
            fs::create_dir_all(f.source().join(entry).parent().unwrap()).unwrap();
            fs::write(f.source().join(entry), "<?php\n").unwrap();
            fs::write(f.source().join("composer.json"), "{}\n").unwrap();
            fs::create_dir(f.source().join("public")).unwrap();
        }
        let row = f.rows(&f.source()).unwrap().remove(0);
        assert_eq!(row.repository_url, "git@github.com:acme/Site.git");
        assert_eq!(row.inferred_root, entry.map(|_| "public"));
    }
}

#[test]
fn inspection_refuses_unsafe_git_metadata_and_a_foreign_managed_group() {
    for path in ["", ".git"] {
        let f = Fixture::new();
        let entry = f.source().join(path);
        fs::set_permissions(&entry, fs::Permissions::from_mode(0o777)).unwrap();
        assert!(f.rows(&f.source()).is_err());
        assert!(!f.destination().exists());
    }
    let f = Fixture::new();
    let result = inspect(&InspectInput {
        path: f.source(),
        include_worktrees: false,
        user: user_name(rustix::process::geteuid().as_raw()),
        group: "root".into(),
    });
    assert!(result.is_err());
    assert!(!f.destination().exists());
}

fn members(f: &Fixture) -> Vec<Member> {
    let linked = f.base.join("linked");
    sh_git(
        &f.source(),
        &["worktree", "add", "-b", "feature", linked.to_str().unwrap()],
    );
    let rows = inspect(&InspectInput {
        path: f.source(),
        include_worktrees: true,
        user: user_name(rustix::process::geteuid().as_raw()),
        group: group_name(rustix::process::getegid().as_raw()),
    })
    .unwrap();
    rows.into_iter()
        .enumerate()
        .map(|(index, row)| {
            let destination = f.base.join("apps/acme").join(if row.layout == "checkout" {
                "site"
            } else {
                "feature"
            });
            Member {
                id: index as u64,
                source: row.path.into(),
                application: destination.clone(),
                destination,
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
        })
        .collect()
}

#[test]
fn partially_moved_worktree_set_repairs_links_and_preserves_each_tree() {
    let f = Fixture::new();
    let mut members = members(&f);
    let before: Vec<_> = members
        .iter()
        .map(|member| manifest(&member.source, false))
        .collect();
    let worktree = members
        .iter()
        .find(|member| member.layout == "worktree")
        .unwrap();
    fs::rename(&worktree.source, &worktree.destination).unwrap();
    let identities = relocate(Operation::Prepare, &members).unwrap();
    for member in &mut members {
        complete_member(member, &identities);
    }
    relocate(Operation::Cleanup, &members).unwrap();
    for (member, before) in members.iter().zip(before) {
        assert!(!member.source.exists());
        assert_eq!(manifest(&member.destination, false), before);
        assert_eq!(
            sh_git(&member.destination, &["rev-parse", "HEAD"]),
            member.commit
        );
    }
}

#[test]
fn inspection_refuses_an_unsafe_second_worktree_before_any_move() {
    let f = Fixture::new();
    let members = members(&f);
    let worktree = members
        .iter()
        .find(|member| member.layout == "worktree")
        .unwrap();
    fs::set_permissions(
        worktree.source.join(".git"),
        fs::Permissions::from_mode(0o666),
    )
    .unwrap();
    let result = inspect(&InspectInput {
        path: f.source(),
        include_worktrees: true,
        user: user_name(rustix::process::geteuid().as_raw()),
        group: group_name(rustix::process::getegid().as_raw()),
    });
    assert!(result.is_err());
    for member in &members {
        assert!(member.source.exists());
        assert!(!member.destination.exists());
    }
}

#[test]
fn in_place_worktree_adoption_ignores_unrelated_refs_and_preserves_its_git_link() {
    let f = Fixture::new();
    let mut member = members(&f)
        .into_iter()
        .find(|member| member.layout == "worktree")
        .unwrap();
    member.destination = member.source.clone();
    member.application = member.source.clone();
    let before = fs::read(member.source.join(".git")).unwrap();
    let inode = fs::metadata(&member.source).unwrap().ino();
    sh_git(
        &f.source(),
        &["update-ref", "refs/t3/other-agent", &member.commit],
    );
    for _ in 0..2 {
        relocate(Operation::Adopt, std::slice::from_ref(&member)).unwrap();
        relocate(Operation::AdoptFinalize, std::slice::from_ref(&member)).unwrap();
    }
    assert_eq!(fs::metadata(&member.source).unwrap().ino(), inode);
    assert_eq!(fs::read(member.source.join(".git")).unwrap(), before);
    assert_eq!(source_digest(&member.source).unwrap(), member.digest);
}

#[test]
fn in_place_adoption_refuses_changed_head_index_or_worktree_without_mutation() {
    for change in ["head", "index", "worktree"] {
        let f = Fixture::new();
        let mut member = f.member();
        member.destination = member.source.clone();
        member.application = member.source.clone();
        match change {
            "head" => {
                sh_git(
                    &member.source,
                    &["commit", "--allow-empty", "-m", "changed"],
                );
            }
            "index" => {
                sh_git(
                    &member.source,
                    &["update-index", "--assume-unchanged", "README.md"],
                );
            }
            _ => fs::write(member.source.join("README.md"), "changed").unwrap(),
        }
        let before = manifest(&member.source, true);
        assert!(relocate(Operation::Adopt, &[member.clone()]).is_err());
        assert_eq!(manifest(&member.source, true), before);
    }
}

#[test]
fn laravel_receipts_preserve_root_and_nested_files_modes_and_directory_times_on_repeat() {
    for relative in ["", "server/web"] {
        let f = Fixture::new();
        let application = f.source().join(relative);
        fs::create_dir_all(application.join("bootstrap/cache")).unwrap();
        fs::write(application.join(".env"), "APP_URL=before\n").unwrap();
        fs::write(
            application.join("bootstrap/cache/config.php"),
            "<?php return ['url'=>'before'];\n",
        )
        .unwrap();
        let input = |operation| ReceiptInput {
            checkout: f.source(),
            instance_id: 17,
            operation,
            application: application.clone(),
        };
        let before = manifest(&f.source(), true);
        receipt(&input(ReceiptOperation::Prepare)).unwrap();
        for name in [".env", "bootstrap/cache/config.php"] {
            let next = application.join(format!("{name}.next"));
            fs::write(&next, "changed").unwrap();
            fs::rename(&next, application.join(name)).unwrap();
        }
        receipt(&input(ReceiptOperation::Prepare)).unwrap();
        receipt(&input(ReceiptOperation::Restore)).unwrap();
        assert_eq!(manifest(&f.source(), true), before);
    }
}

#[test]
fn incomplete_laravel_receipt_cannot_be_replaced_by_prepare() {
    let f = Fixture::new();
    let directory = f.base.join(".orbit-registration-url-17");
    fs::create_dir(&directory).unwrap();
    fs::write(directory.join("0"), "partial").unwrap();
    assert!(receipt(&ReceiptInput {
        checkout: f.source(),
        instance_id: 17,
        operation: ReceiptOperation::Prepare,
        application: f.source()
    })
    .is_err());
    assert_eq!(fs::read_to_string(directory.join("0")).unwrap(), "partial");
    assert!(!directory.join("manifest").exists());
}

#[test]
fn relocation_closes_root_and_nested_environments_after_verification() {
    for relative in ["", "server/web"] {
        let f = Fixture::new();
        fs::create_dir_all(f.source().join(relative)).unwrap();
        let environment = f.source().join(relative).join(".env");
        fs::write(&environment, "APP_KEY=private\n").unwrap();
        fs::set_permissions(&environment, fs::Permissions::from_mode(0o644)).unwrap();
        let mut member = f.member();
        member.application = member.destination.join(relative);
        let identities = relocate(Operation::Prepare, std::slice::from_ref(&member)).unwrap();
        complete_member(&mut member, &identities);
        relocate(Operation::Cleanup, std::slice::from_ref(&member)).unwrap();
        assert_eq!(
            fs::metadata(member.application.join(".env"))
                .unwrap()
                .mode()
                & 7,
            0
        );
    }
}
