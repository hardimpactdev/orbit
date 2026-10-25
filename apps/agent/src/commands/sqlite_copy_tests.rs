use super::*;
use crate::commands::recipe::WorkDir;
struct Fixture {
    work: WorkDir,
    source: PathBuf,
    target: PathBuf,
}
impl Fixture {
    fn new() -> Self {
        let work = WorkDir::new().unwrap();
        let source = work.0.join("source/database.sqlite");
        let target = work.0.join("target/database/database.sqlite");
        fs::create_dir(work.0.join("source")).unwrap();
        fs::create_dir(work.0.join("target")).unwrap();
        Connection::open(&source)
            .unwrap()
            .execute_batch("CREATE TABLE users (name TEXT); INSERT INTO users VALUES ('ada');")
            .unwrap();
        fs::set_permissions(&source, fs::Permissions::from_mode(0o640)).unwrap();
        Self {
            work,
            source,
            target,
        }
    }
    fn local(&self) -> CopyInput {
        CopyInput::Local {
            checkout: self.work.0.join("source"),
            source: self.source.clone(),
            target_checkout: self.work.0.join("target"),
            target: self.target.clone(),
        }
    }
    fn rows(&self) -> Vec<String> {
        Connection::open(&self.target)
            .unwrap()
            .prepare("SELECT name FROM users")
            .unwrap()
            .query_map([], |row| row.get(0))
            .unwrap()
            .collect::<std::result::Result<_, _>>()
            .unwrap()
    }
    fn copied(&self) -> Copied {
        match copy(&self.local()).unwrap() {
            CopyOutput::Copied(result) => result,
            _ => panic!("missing copy report"),
        }
    }
    fn no_temporary_files(&self) {
        assert!(fs::read_dir(self.target.parent().unwrap())
            .unwrap()
            .all(|entry| !entry
                .unwrap()
                .file_name()
                .to_string_lossy()
                .starts_with(".orbit-sqlite-")));
    }
}
#[test]
fn copies_consistent_contents_permissions_and_repeats_without_temporary_files() {
    let f = Fixture::new();
    for _ in 0..2 {
        f.copied();
        assert_eq!(f.rows(), ["ada"]);
        assert_eq!(fs::metadata(&f.target).unwrap().mode() & 0o777, 0o640);
        f.no_temporary_files();
    }
}
#[test]
fn copies_committed_wal_contents_with_reflink_or_snapshot() {
    let f = Fixture::new();
    let writer = Connection::open(&f.source).unwrap();
    writer.execute_batch("PRAGMA journal_mode=WAL; PRAGMA wal_autocheckpoint=0; INSERT INTO users VALUES ('grace');").unwrap();
    assert!(fs::metadata(wal(&f.source)).unwrap().len() > 0);
    let result = f.copied();
    assert_eq!(f.rows(), ["ada", "grace"]);
    let reflink = clone(&f.source, f.target.parent().unwrap());
    match reflink {
        Ok(path) => {
            fs::remove_file(path).unwrap();
            assert_eq!(result.copy, "reflink");
        }
        Err(Fault::UseSnapshot) => assert_eq!(result.copy, "snapshot"),
        _ => panic!("reflink probe failed"),
    }
    f.no_temporary_files();
}
#[test]
fn a_writer_lock_uses_a_snapshot_of_committed_rows() {
    let f = Fixture::new();
    let writer = Connection::open(&f.source).unwrap();
    writer
        .execute_batch(
            "PRAGMA journal_mode=WAL; BEGIN IMMEDIATE; INSERT INTO users VALUES ('uncommitted');",
        )
        .unwrap();
    let started = std::time::Instant::now();
    assert_eq!(f.copied().copy, "snapshot");
    assert_eq!(f.rows(), ["ada"]);
    assert!(started.elapsed() < Duration::from_secs(10));
    f.no_temporary_files();
}
#[test]
fn unsafe_source_paths_refuse_without_creating_the_target() {
    let mut f = Fixture::new();
    std::os::unix::fs::symlink(f.work.0.join("source"), f.work.0.join("source/linked")).unwrap();
    for path in [
        f.work.0.join("target/../source/database.sqlite"),
        f.work.0.join("source/linked/database.sqlite"),
    ] {
        f.source = path;
        assert!(matches!(copy(&f.local()), Err(CopyError::Refused {})));
        assert!(!f.target.exists());
    }
}
#[test]
fn exports_checks_the_transferred_digest_installs_and_cleans_up() {
    let f = Fixture::new();
    let directory = PathBuf::from(format!("{EXPORT_PREFIX}{:032x}", rand::random::<u128>()));
    let result = copy(&CopyInput::Export {
        checkout: f.work.0.join("source"),
        source: f.source.clone(),
        directory: directory.clone(),
    })
    .unwrap();
    let CopyOutput::Copied(report) = result else {
        panic!("missing snapshot report")
    };
    copy(&CopyInput::Prepare {
        target_checkout: f.work.0.join("target"),
        target: f.target.clone(),
    })
    .unwrap();
    let incoming = f.target.parent().unwrap().join(".orbit-sqlite-incoming");
    fs::copy(directory.join("snapshot.sqlite"), &incoming).unwrap();
    let install = |sha256| CopyInput::Install {
        target_checkout: f.work.0.join("target"),
        target: f.target.clone(),
        incoming: incoming.clone(),
        bytes: report.bytes,
        sha256,
        mode: report.mode,
    };
    assert!(matches!(
        copy(&install("0".repeat(64))),
        Err(CopyError::Refused {})
    ));
    assert!(!f.target.exists());
    copy(&install(report.sha256)).unwrap();
    assert_eq!(f.rows(), ["ada"]);
    f.no_temporary_files();
    for _ in 0..2 {
        copy(&CopyInput::Cleanup {
            directory: directory.clone(),
        })
        .unwrap();
    }
    assert!(!directory.exists());
}
#[test]
fn removes_an_existing_copy_or_a_missing_checkout_repeatedly() {
    let f = Fixture::new();
    f.copied();
    for _ in 0..2 {
        copy(&CopyInput::Remove {
            checkout: f.work.0.join("target"),
            path: f.target.clone(),
        })
        .unwrap();
    }
    copy(&CopyInput::Remove {
        checkout: f.work.0.join("gone"),
        path: f.work.0.join("gone/database.sqlite"),
    })
    .unwrap();
    assert!(!f.target.exists());
}
#[test]
fn reports_a_missing_database_as_a_typed_failure() {
    let f = Fixture::new();
    fs::remove_file(&f.source).unwrap();
    assert!(matches!(copy(&f.local()), Err(CopyError::Failed {})));
    assert!(!f.target.exists());
}
