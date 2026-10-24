use super::*;
use crate::commands::recipe::WorkDir;
use std::os::unix::fs::{symlink, PermissionsExt};

struct Fixture {
    work: WorkDir,
    db: Connection,
    input: SnapshotInput,
}
impl Fixture {
    fn new() -> Self {
        let work = WorkDir::new().unwrap();
        let path = work.0.join("gateway.sqlite");
        let db = Connection::open(&path).unwrap();
        db.execute_batch("PRAGMA journal_mode=WAL; PRAGMA wal_autocheckpoint=0; CREATE TABLE notes(body TEXT); INSERT INTO notes VALUES ('clean')").unwrap();
        let input = SnapshotInput {
            database: path,
            orbit_home: work.0.clone(),
            release: "0123456789ab".into(),
            kept_snapshots: 3,
            minimum_free_bytes: 1024,
            step_lock: work.0.join("gateway-release-step.lock"),
        };
        Self { work, db, input }
    }
    fn take(&self) -> PathBuf {
        snapshot(
            &self.input,
            Instant::now() + Duration::from_secs(10),
            |_| Some(1 << 40),
        )
        .unwrap()
        .path
    }
    fn snapshots(&self) -> Vec<PathBuf> {
        let mut paths: Vec<_> = fs::read_dir(self.work.0.join("backups"))
            .unwrap()
            .map(|e| e.unwrap().path())
            .collect();
        paths.sort();
        paths
    }
}
fn rows(path: &Path) -> Vec<String> {
    let db = Connection::open_with_flags(path, OpenFlags::SQLITE_OPEN_READ_ONLY).unwrap();
    let mut statement = db.prepare("SELECT body FROM notes ORDER BY rowid").unwrap();
    statement
        .query_map([], |r| r.get(0))
        .unwrap()
        .map(Result::unwrap)
        .collect()
}
#[test]
fn snapshots_include_committed_wal_data_are_private_and_repeated_attempts_keep_distinct_copies() {
    let f = Fixture::new();
    let before = fs::metadata(&f.input.database).unwrap().len()
        + fs::metadata(format!("{}-wal", f.input.database.display()))
            .unwrap()
            .len();
    assert_eq!(
        inspect(&InspectInput {
            database: f.input.database.clone()
        })
        .unwrap()
        .bytes,
        before
    );
    assert!(!f.work.0.join("backups").exists());
    let first = f.take();
    f.db.execute("INSERT INTO notes VALUES ('half-migrated')", [])
        .unwrap();
    let writer = Connection::open(&f.input.database).unwrap();
    writer
        .execute("INSERT INTO notes VALUES ('concurrent')", [])
        .unwrap();
    let second = f.take();
    assert_ne!(first, second);
    assert_eq!(rows(&first), ["clean"]);
    assert_eq!(rows(&second), ["clean", "half-migrated", "concurrent"]);
    assert_eq!(
        fs::metadata(&first).unwrap().permissions().mode() & 0o777,
        0o600
    );
    assert_eq!(
        fs::metadata(first.parent().unwrap())
            .unwrap()
            .permissions()
            .mode()
            & 0o777,
        0o700
    );
    assert_eq!(f.snapshots().len(), 2);
}
#[test]
fn retention_keeps_the_new_snapshot_even_when_an_old_one_has_a_future_time_and_protects_a_paused_copy(
) {
    let mut f = Fixture::new();
    f.input.kept_snapshots = 1;
    let first = f.take();
    fs::write(
        f.work.0.join("gateway-release.paused"),
        serde_json::to_vec(&serde_json::json!({"snapshot":first})).unwrap(),
    )
    .unwrap();
    let second = f.take();
    fs::File::open(&second)
        .unwrap()
        .set_modified(std::time::SystemTime::now() + Duration::from_secs(60))
        .unwrap();
    let third = f.take();
    assert!(first.exists() && third.exists());
    assert!(!second.exists());
    assert_eq!(f.snapshots().len(), 2);
}
#[test]
fn retention_keeps_the_configured_newest_copies() {
    let mut f = Fixture::new();
    f.input.kept_snapshots = 2;
    let old = f.take();
    fs::File::open(&old)
        .unwrap()
        .set_modified(std::time::SystemTime::UNIX_EPOCH)
        .unwrap();
    let next = f.take();
    let new = f.take();
    assert!(!old.exists());
    let mut expected = vec![next, new];
    expected.sort();
    assert_eq!(f.snapshots(), expected);
}
#[test]
fn low_space_invalid_policy_and_expired_deadline_refuse_before_creating_backups() {
    let mut f = Fixture::new();
    let deadline = Instant::now() + Duration::from_secs(10);
    assert!(matches!(
        snapshot(&f.input, deadline, |_| Some(0)),
        Err(Error::DiskLow { .. })
    ));
    assert!(matches!(
        snapshot(&f.input, Instant::now(), |_| Some(1 << 40)),
        Err(Error::Failed {})
    ));
    f.input.kept_snapshots = 0;
    assert!(matches!(
        snapshot(&f.input, deadline, |_| Some(1 << 40)),
        Err(Error::Invalid {})
    ));
    f.input.kept_snapshots = 1;
    f.input.release = "../outside".into();
    assert!(matches!(
        snapshot(&f.input, deadline, |_| Some(1 << 40)),
        Err(Error::Invalid {})
    ));
    assert!(!f.work.0.join("backups").exists());
}
#[test]
fn malformed_database_and_linked_paths_refuse_without_publishing_or_pruning() {
    let mut f = Fixture::new();
    let original = f.take();
    let outside = f.work.0.join("outside");
    fs::create_dir(&outside).unwrap();
    let linked = f.work.0.join("linked.sqlite");
    symlink(&f.input.database, &linked).unwrap();
    assert!(matches!(database(&linked), Err(Error::Unavailable {})));
    symlink(&original, f.work.0.join("backups/pre-linked.sqlite")).unwrap();
    assert!(matches!(
        snapshot(
            &f.input,
            Instant::now() + Duration::from_secs(10),
            |_| Some(1 << 40)
        ),
        Err(Error::Unavailable {})
    ));
    assert!(original.exists());
    let invalid = f.work.0.join("invalid.sqlite");
    fs::write(&invalid, "not SQLite").unwrap();
    f.input.database = invalid;
    f.input.orbit_home = outside.clone();
    f.input.step_lock = outside.join("gateway-release-step.lock");
    assert!(matches!(
        snapshot(
            &f.input,
            Instant::now() + Duration::from_secs(10),
            |_| Some(1 << 40)
        ),
        Err(Error::Unavailable {})
    ));
    assert!(!outside.join("backups").exists());
    symlink(f.work.0.join("backups"), outside.join("backups")).unwrap();
    assert!(matches!(
        snapshot(
            &f.input,
            Instant::now() + Duration::from_secs(10),
            |_| Some(1 << 40)
        ),
        Err(Error::Failed {})
    ));
}
#[test]
fn an_uncommitted_writer_does_not_enter_the_snapshot() {
    let f = Fixture::new();
    f.db.execute_batch("BEGIN; INSERT INTO notes VALUES ('uncommitted')")
        .unwrap();
    let path = f.take();
    assert_eq!(rows(&path), ["clean"]);
    f.db.execute_batch("ROLLBACK").unwrap();
}

#[test]
fn a_failed_vacuum_removes_its_candidate_and_keeps_prior_snapshots() {
    use std::{
        fs::OpenOptions,
        io::{Seek, SeekFrom, Write},
    };
    let mut f = Fixture::new();
    f.input.kept_snapshots = 1;
    let old = f.take();
    f.db.execute_batch("PRAGMA wal_checkpoint(TRUNCATE)")
        .unwrap();
    let page_size: i64 =
        f.db.query_row("PRAGMA page_size", [], |r| r.get(0))
            .unwrap();
    let root_page: i64 =
        f.db.query_row(
            "SELECT rootpage FROM sqlite_master WHERE name='notes'",
            [],
            |r| r.get(0),
        )
        .unwrap();
    let mut file = OpenOptions::new()
        .write(true)
        .open(&f.input.database)
        .unwrap();
    file.seek(SeekFrom::Start(((root_page - 1) * page_size) as u64))
        .unwrap();
    file.write_all(&[0]).unwrap();
    assert!(matches!(
        snapshot(
            &f.input,
            Instant::now() + Duration::from_secs(10),
            |_| Some(1 << 40)
        ),
        Err(Error::Failed {})
    ));
    assert_eq!(f.snapshots(), vec![old.clone()]);
    assert_eq!(rows(&old), ["clean"]);
}

#[test]
fn step_lock_refusal_precedes_snapshot_writes_and_does_not_block_size_inspection() {
    let mut f = Fixture::new();
    let held =
        gateway_build::hold_step(&f.input.step_lock, Instant::now() + Duration::from_secs(10))
            .unwrap();
    assert!(
        inspect(&InspectInput {
            database: f.input.database.clone()
        })
        .unwrap()
        .bytes
            > 0
    );
    assert!(matches!(
        snapshot(&f.input, Instant::now(), |_| Some(1 << 40)),
        Err(Error::Busy {})
    ));
    drop(held);
    f.input.step_lock = f.work.0.join("unexpected.lock");
    assert!(matches!(
        snapshot(
            &f.input,
            Instant::now() + Duration::from_secs(10),
            |_| Some(1 << 40)
        ),
        Err(Error::LockUnavailable {})
    ));
    assert!(!f.work.0.join("backups").exists());
}

#[test]
fn retention_never_removes_the_configured_live_database_even_if_its_name_matches_a_snapshot() {
    let mut f = Fixture::new();
    fs::create_dir(f.work.0.join("backups")).unwrap();
    f.input.database = f.work.0.join("backups/pre-live.sqlite");
    f.input.kept_snapshots = 1;
    let live = Connection::open(&f.input.database).unwrap();
    live.execute_batch("CREATE TABLE notes(body TEXT); INSERT INTO notes VALUES ('live')")
        .unwrap();
    let first = f.take();
    let second = f.take();
    assert_ne!(first, second);
    assert!(f.input.database.exists());
    assert_eq!(rows(&f.input.database), ["live"]);
    assert_eq!(rows(&second), ["live"]);
}
