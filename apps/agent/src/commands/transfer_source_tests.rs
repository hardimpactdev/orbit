use super::*;
use crate::commands::recipe::WorkDir;
use std::{os::unix::fs::MetadataExt, time::Instant};
fn context() -> Context {
    Context::new(Platform::Linux, Instant::now() + Duration::from_secs(20))
}
fn command(path: &Path, args: &[&str]) -> String {
    let output = Process::new("/usr/bin/git")
        .args([
            "-c",
            "user.name=Orbit fixture",
            "-c",
            "user.email=fixture@example.test",
            "-C",
        ])
        .arg(path)
        .args(args)
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
    String::from_utf8(output.stdout).unwrap().trim().to_owned()
}
struct Fixture {
    work: WorkDir,
    source: PathBuf,
    archives: Vec<PathBuf>,
}
impl Fixture {
    fn new() -> Self {
        let work = WorkDir::new().unwrap();
        let source = work.0.join("source");
        fs::create_dir(&source).unwrap();
        command(&source, &["init", "--quiet", "-b", "main"]);
        fs::write(source.join("README"), "committed\n").unwrap();
        fs::write(source.join("database.sqlite"), "committed database").unwrap();
        command(&source, &["add", "."]);
        command(&source, &["commit", "--quiet", "-m", "initial"]);
        Self {
            work,
            source,
            archives: vec![],
        }
    }
    fn capture(
        &mut self,
        layout: &str,
        sqlite: Option<&Path>,
    ) -> (PathBuf, String, Option<String>, bool) {
        let result = capture(&self.source, layout, sqlite).unwrap();
        let TransferOutput::Captured {
            archive,
            head,
            branch,
            detached,
            ..
        } = result
        else {
            panic!("missing capture")
        };
        self.archives.push(archive.clone());
        (archive, head, branch, detached)
    }
}
impl Drop for Fixture {
    fn drop(&mut self) {
        for archive in &self.archives {
            let _ = fs::remove_file(archive);
        }
    }
}
#[test]
fn checkout_stream_preserves_binary_contents_and_closes_only_the_selected_environment() {
    for suffix in ["", "server/web"] {
        let mut f = Fixture::new();
        let directory = f.source.join(suffix);
        fs::create_dir_all(&directory).unwrap();
        fs::write(directory.join(".env"), "APP_KEY=private\n").unwrap();
        fs::set_permissions(directory.join(".env"), fs::Permissions::from_mode(0o664)).unwrap();
        fs::write(f.source.join("binary"), [0, 255, 10]).unwrap();
        let (archive, head, branch, detached) = f.capture("checkout", None);
        assert_eq!(fs::metadata(&archive).unwrap().mode() & 0o777, 0o600);
        for _ in 0..2 {
            let mut frames = vec![];
            let result = read_archive(
                &ArchiveInput {
                    archive: archive.clone(),
                },
                &context(),
                &mut frames,
            )
            .unwrap();
            assert_eq!(result.bytes, fs::metadata(&archive).unwrap().len());
            assert!(!frames.is_empty());
        }
        let destination = f.work.0.join("destination");
        let environment = destination.join(suffix).join(".env");
        materialize(
            File::open(&archive).unwrap(),
            &destination,
            &head,
            branch.as_deref(),
            detached,
            &environment,
            &context(),
        )
        .unwrap();
        assert_eq!(fs::read(destination.join("binary")).unwrap(), [0, 255, 10]);
        assert_eq!(
            fs::read_to_string(&environment).unwrap(),
            "APP_KEY=private\n"
        );
        assert_eq!(fs::metadata(&environment).unwrap().mode() & 0o007, 0);
        assert_eq!(
            fs::metadata(destination.join("README")).unwrap().mode() & 0o004,
            0o004
        );
        assert!(matches!(
            materialize(
                File::open(&archive).unwrap(),
                &destination,
                &head,
                branch.as_deref(),
                detached,
                &environment,
                &context()
            ),
            Err(TransferError::Archive {})
        ));
        assert_eq!(fs::read(destination.join("binary")).unwrap(), [0, 255, 10]);
    }
}
#[test]
fn linked_worktrees_keep_dirty_files_head_and_branch_without_restoring_selected_sqlite() {
    for detached in [false, true] {
        let mut f = Fixture::new();
        let linked = f.work.0.join("linked");
        command(
            &f.source,
            &[
                "worktree",
                "add",
                "--quiet",
                "-b",
                "transfer",
                linked.to_str().unwrap(),
            ],
        );
        f.source = linked;
        if detached {
            command(&f.source, &["checkout", "--quiet", "--detach"]);
        }
        fs::write(f.source.join("README"), "dirty\n").unwrap();
        for suffix in ["-wal", "-shm"] {
            fs::write(f.source.join(format!("database.sqlite{suffix}")), "omit").unwrap();
        }
        let sqlite = f.source.join("database.sqlite");
        let (archive, head, branch, detached) = f.capture("worktree", Some(&sqlite));
        let destination = f.work.0.join("destination");
        materialize(
            File::open(&archive).unwrap(),
            &destination,
            &head,
            branch.as_deref(),
            detached,
            &destination.join(".env"),
            &context(),
        )
        .unwrap();
        assert_eq!(
            fs::read_to_string(destination.join("README")).unwrap(),
            "dirty\n"
        );
        for suffix in ["", "-wal", "-shm"] {
            assert!(!destination
                .join(format!("database.sqlite{suffix}"))
                .exists());
        }
        assert_eq!(command(&destination, &["rev-parse", "HEAD"]), head);
        assert_eq!(
            command(&destination, &["rev-parse", "--abbrev-ref", "HEAD"]),
            if detached { "HEAD" } else { "transfer" }
        );
        for path in [
            destination.clone(),
            destination.join(".git"),
            destination.join(".git/index"),
        ] {
            assert_eq!(
                fs::metadata(path).unwrap().uid(),
                crate::command::identity::effective_uid()
            );
        }
    }
}
#[test]
fn corrupt_archives_and_bad_intent_refuse_before_creating_the_destination() {
    let mut f = Fixture::new();
    let (archive, head, branch, detached) = f.capture("checkout", None);
    let destination = f.work.0.join("destination");
    assert!(matches!(
        materialize(
            File::open(&archive).unwrap(),
            &destination,
            &head,
            branch.as_deref(),
            detached,
            &f.work.0.join("outside.env"),
            &context()
        ),
        Err(TransferError::Archive {})
    ));
    fs::write(&archive, "invalid archive").unwrap();
    for _ in 0..2 {
        assert!(matches!(
            materialize(
                File::open(&archive).unwrap(),
                &destination,
                &head,
                branch.as_deref(),
                detached,
                &destination.join(".env"),
                &context()
            ),
            Err(TransferError::Archive {})
        ));
        assert!(!destination.exists());
    }
}
#[test]
fn rejects_archive_links_before_extracting_through_them() {
    let f = Fixture::new();
    let archive = f.work.0.join("malicious.tar");
    let file = File::create(&archive).unwrap();
    let mut tar = tar::Builder::new(file);
    let mut header = tar::Header::new_gnu();
    header.set_entry_type(tar::EntryType::Symlink);
    header.set_size(0);
    header.set_mode(0o777);
    header.set_link_name(&f.work.0).unwrap();
    header.set_cksum();
    tar.append_data(&mut header, "escape", io::empty()).unwrap();
    let mut header = tar::Header::new_gnu();
    header.set_size(6);
    header.set_mode(0o600);
    header.set_cksum();
    tar.append_data(&mut header, "escape/overwritten", &b"secret"[..])
        .unwrap();
    tar.finish().unwrap();
    drop(tar);
    let destination = f.work.0.join("destination");
    let head = command(&f.source, &["rev-parse", "HEAD"]);
    assert!(matches!(
        materialize(
            File::open(archive).unwrap(),
            &destination,
            &head,
            Some("main"),
            false,
            &destination.join(".env"),
            &context()
        ),
        Err(TransferError::Archive {})
    ));
    assert!(!destination.exists());
    assert!(!f.work.0.join("overwritten").exists());
}
#[test]
fn download_refuses_unmanaged_public_or_linked_archives_without_output() {
    let mut f = Fixture::new();
    let (archive, ..) = f.capture("checkout", None);
    let mut frames = vec![];
    fs::set_permissions(&archive, fs::Permissions::from_mode(0o644)).unwrap();
    assert!(read_archive(
        &ArchiveInput {
            archive: archive.clone()
        },
        &context(),
        &mut frames
    )
    .is_err());
    let original = f.work.0.join("archive");
    fs::rename(&archive, &original).unwrap();
    std::os::unix::fs::symlink(&original, &archive).unwrap();
    assert!(read_archive(
        &ArchiveInput {
            archive: archive.clone()
        },
        &context(),
        &mut frames
    )
    .is_err());
    assert!(read_archive(&ArchiveInput { archive: original }, &context(), &mut frames).is_err());
    assert!(frames.is_empty());
}
#[test]
fn cleanup_and_discard_repeat_but_never_remove_the_common_repository() {
    let f = Fixture::new();
    let input = TransferInput::Cleanup {
        source: f.source.clone(),
        common: Some(f.source.clone()),
    };
    for _ in 0..2 {
        assert!(transfer(&input).is_err());
        assert!(f.source.exists());
    }
    let input = TransferInput::Discard {
        destination: f.source.clone(),
    };
    for _ in 0..2 {
        transfer(&input).unwrap();
    }
    assert!(!f.source.exists());
}
use std::fs::File;

#[test]
fn selected_sqlite_exclusion_is_literal_in_checkouts_and_worktrees() {
    for layout in ["checkout", "worktree"] {
        let mut f = Fixture::new();
        if layout == "worktree" {
            let linked = f.work.0.join("linked");
            command(
                &f.source,
                &[
                    "worktree",
                    "add",
                    "--quiet",
                    "-b",
                    "transfer",
                    linked.to_str().unwrap(),
                ],
            );
            f.source = linked;
        }
        fs::create_dir(f.source.join("database")).unwrap();
        let sqlite = f.source.join("database/selected [1].sqlite");
        for suffix in ["", "-wal", "-shm"] {
            fs::write(format!("{}{suffix}", sqlite.display()), "excluded").unwrap();
        }
        fs::write(f.source.join("database/selected 1.sqlite"), "keep").unwrap();
        let (archive, head, branch, detached) = f.capture(layout, Some(&sqlite));
        let destination = f.work.0.join("destination");
        materialize(
            File::open(archive).unwrap(),
            &destination,
            &head,
            branch.as_deref(),
            detached,
            &destination.join(".env"),
            &context(),
        )
        .unwrap();
        for suffix in ["", "-wal", "-shm"] {
            assert!(!destination
                .join(format!("database/selected [1].sqlite{suffix}"))
                .exists());
        }
        assert_eq!(
            fs::read_to_string(destination.join("database/selected 1.sqlite")).unwrap(),
            "keep"
        );
    }
}
