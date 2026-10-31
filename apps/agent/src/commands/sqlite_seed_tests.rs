//! A seed between two placements the test user owns, as the source, target, and transport accounts would on a Node.

use super::*;

struct Fixture {
    root: PathBuf,
    user: String,
}

impl Fixture {
    fn new() -> Self {
        let root = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!("orbit-sqlite-seed-{:016x}", rand::random::<u64>()));
        fs::create_dir_all(root.join("source/database")).unwrap();
        fs::create_dir_all(root.join("target/database")).unwrap();
        fs::DirBuilder::new()
            .mode(0o700)
            .create(root.join("state"))
            .unwrap();
        let database = Connection::open(root.join("source/database/database.sqlite")).unwrap();
        database
            .execute_batch("CREATE TABLE users (name TEXT); INSERT INTO users VALUES ('ada');")
            .unwrap();
        Self {
            root,
            user: super::super::source::user_name(euid()),
        }
    }

    fn source(&self, mode: &str) -> SourceInput {
        SourceInput {
            mode: mode.into(),
            base: self.root.join("source"),
            source_path: self.root.join("source/database/database.sqlite"),
            source_user: self.user.clone(),
            transport_user: self.user.clone(),
            operation: "a".repeat(64),
            state_dir: self.root.join("state/source"),
            snapshot_path: self.root.join("snapshot.sqlite"),
        }
    }

    fn target(&self, mode: &str, bytes: u64, sha256: &str) -> TargetInput {
        TargetInput {
            mode: mode.into(),
            base: self.root.join("target"),
            target_user: self.user.clone(),
            transport_user: self.user.clone(),
            operation: "a".repeat(64),
            state_dir: self.root.join("state/target"),
            expected_size: bytes,
            expected_digest: sha256.into(),
            environment: "development".into(),
            relative_path: "database/database.sqlite".into(),
        }
    }

    fn destination(&self) -> PathBuf {
        self.root.join("target/database/database.sqlite")
    }
}

impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.root);
    }
}

fn ready(output: SourceOutput) -> (u64, String, PathBuf) {
    match output {
        SourceOutput::Ready {
            bytes,
            sha256,
            snapshot,
        } => (bytes, sha256, snapshot),
        SourceOutput::Cleaned {} => panic!("no snapshot"),
    }
}

#[test]
fn a_seed_installs_once_and_a_retry_reports_it_complete() {
    let fixture = Fixture::new();
    let (bytes, sha256, snapshot) = ready(prepare(&fixture.source("prepare")).unwrap());
    assert_eq!(fs::metadata(&snapshot).unwrap().mode() & 0o777, 0o600);
    // A retry of the source returns the same recorded snapshot.
    assert_eq!(
        ready(prepare(&fixture.source("prepare")).unwrap()).1,
        sha256
    );

    let incoming = match install(&fixture.target("prepare", bytes, &sha256)).unwrap() {
        TargetOutput::Ready { incoming } => incoming,
        other => panic!("{other:?}"),
    };
    fs::copy(&snapshot, &incoming).unwrap();
    assert_eq!(
        install(&fixture.target("install", bytes, &sha256)),
        Ok(TargetOutput::Changed)
    );
    assert_eq!(
        install(&fixture.target("install", bytes, &sha256)),
        Ok(TargetOutput::Complete)
    );
    assert_eq!(
        install(&fixture.target("prepare", bytes, &sha256)),
        Ok(TargetOutput::Complete)
    );
    assert!(!incoming.exists());

    let rows: String = Connection::open(fixture.destination())
        .unwrap()
        .query_row("SELECT name FROM users", [], |row| row.get(0))
        .unwrap();
    assert_eq!(rows, "ada");
    assert_eq!(
        fs::metadata(fixture.destination()).unwrap().mode() & 0o777,
        0o600
    );

    assert_eq!(
        prepare(&fixture.source("cleanup")),
        Ok(SourceOutput::Cleaned {})
    );
    assert_eq!(
        install(&fixture.target("cleanup", 0, "")),
        Ok(TargetOutput::Cleaned)
    );
    assert!(
        !snapshot.exists()
            && !fixture.root.join("state/source").exists()
            && !fixture.root.join("state/target").exists()
    );
    // The installed database stays.
    assert!(fixture.destination().exists());
}

#[test]
fn the_target_never_replaces_an_existing_database() {
    let fixture = Fixture::new();
    let (bytes, sha256, _) = ready(prepare(&fixture.source("prepare")).unwrap());
    fs::write(fixture.destination(), "existing").unwrap();
    fs::set_permissions(fixture.destination(), fs::Permissions::from_mode(0o600)).unwrap();

    assert_eq!(
        install(&fixture.target("prepare", bytes, &sha256)),
        Err(SeedError::Refused {})
    );
    assert_eq!(fs::read(fixture.destination()).unwrap(), b"existing");
}

#[test]
fn a_changed_payload_refuses_and_leaves_no_destination() {
    let fixture = Fixture::new();
    let (bytes, sha256, _) = ready(prepare(&fixture.source("prepare")).unwrap());
    let incoming = match install(&fixture.target("prepare", bytes, &sha256)).unwrap() {
        TargetOutput::Ready { incoming } => incoming,
        other => panic!("{other:?}"),
    };
    fs::write(&incoming, "not the snapshot").unwrap();

    assert_eq!(
        install(&fixture.target("install", bytes, &sha256)),
        Err(SeedError::Refused {})
    );
    assert!(!fixture.destination().exists());
    assert_eq!(
        install(&fixture.target("cleanup", 0, "")),
        Ok(TargetOutput::Cleaned)
    );
    assert!(!incoming.exists());
}

#[test]
fn a_source_outside_its_base_or_through_a_link_refuses() {
    let fixture = Fixture::new();
    let mut input = fixture.source("prepare");
    input.source_path = fixture.root.join("target/database/database.sqlite");
    assert_eq!(prepare(&input), Err(SeedError::Refused {}));

    std::os::unix::fs::symlink(
        fixture.root.join("source/database"),
        fixture.root.join("source/linked"),
    )
    .unwrap();
    let mut input = fixture.source("prepare");
    input.source_path = fixture.root.join("source/linked/database.sqlite");
    assert_eq!(prepare(&input), Err(SeedError::Refused {}));
    assert!(!fixture.root.join("snapshot.sqlite").exists());
}

#[test]
fn a_corrupt_source_refuses() {
    let fixture = Fixture::new();
    fs::write(
        fixture.root.join("source/database/database.sqlite"),
        "not a database",
    )
    .unwrap();
    assert_eq!(
        prepare(&fixture.source("prepare")),
        Err(SeedError::Refused {})
    );
}

#[test]
fn the_snapshot_holds_writes_that_are_still_in_the_wal() {
    let fixture = Fixture::new();
    let writer = Connection::open(fixture.root.join("source/database/database.sqlite")).unwrap();
    writer
        .execute_batch("PRAGMA journal_mode=WAL; PRAGMA wal_autocheckpoint=0; INSERT INTO users VALUES ('grace');")
        .unwrap();

    let (_, _, snapshot) = ready(prepare(&fixture.source("prepare")).unwrap());

    let count: i64 = Connection::open(&snapshot)
        .unwrap()
        .query_row("SELECT count(*) FROM users", [], |row| row.get(0))
        .unwrap();
    assert_eq!(count, 2);
    drop(writer);
}

#[test]
fn an_interrupted_install_resumes_from_its_recorded_candidate() {
    let fixture = Fixture::new();
    let (bytes, sha256, snapshot) = ready(prepare(&fixture.source("prepare")).unwrap());
    let TargetOutput::Ready { incoming } =
        install(&fixture.target("prepare", bytes, &sha256)).unwrap()
    else {
        panic!("no incoming file");
    };
    fs::copy(&snapshot, &incoming).unwrap();
    // The candidate is copied and recorded, and the link is never made: a later database at the destination refuses.
    fs::write(fixture.destination(), "late").unwrap();
    fs::set_permissions(fixture.destination(), fs::Permissions::from_mode(0o600)).unwrap();
    assert_eq!(
        install(&fixture.target("install", bytes, &sha256)),
        Err(SeedError::Refused {})
    );
    assert_eq!(fs::read(fixture.destination()).unwrap(), b"late");
    // The refusal abandoned the seed, so no candidate stays beside the destination.
    let candidates = fs::read_dir(fixture.root.join("target/database"))
        .unwrap()
        .filter(|entry| {
            entry
                .as_ref()
                .unwrap()
                .file_name()
                .to_string_lossy()
                .starts_with(".database.sqlite.orbit-")
        })
        .count();
    assert_eq!(candidates, 0);
    assert!(!fixture.root.join("state/target").exists());
}

#[test]
fn abandoned_seed_cleanup_repeats_and_a_fresh_snapshot_contains_later_writes() {
    let fixture = Fixture::new();
    let (bytes, digest, snapshot) = ready(prepare(&fixture.source("prepare")).unwrap());
    let TargetOutput::Ready { incoming } =
        install(&fixture.target("prepare", bytes, &digest)).unwrap()
    else {
        panic!("missing incoming file")
    };
    fs::copy(&snapshot, &incoming).unwrap();
    let unrelated = fixture.root.join("unrelated.sqlite");
    fs::write(&unrelated, "unrelated").unwrap();
    for _ in 0..2 {
        assert_eq!(
            prepare(&fixture.source("cleanup")),
            Ok(SourceOutput::Cleaned {})
        );
        assert_eq!(
            install(&fixture.target("cleanup", 0, "")),
            Ok(TargetOutput::Cleaned)
        );
    }
    assert!(!snapshot.exists());
    assert!(!incoming.exists());
    assert_eq!(fs::read_to_string(unrelated).unwrap(), "unrelated");
    let writer = Connection::open(&fixture.source("prepare").source_path).unwrap();
    writer
        .execute_batch("PRAGMA journal_mode=WAL; INSERT INTO users VALUES ('after restore');")
        .unwrap();
    let (_, fresh_digest, fresh_snapshot) = ready(prepare(&fixture.source("prepare")).unwrap());
    assert_ne!(fresh_digest, digest);
    let names: Vec<String> = Connection::open(fresh_snapshot)
        .unwrap()
        .prepare("SELECT name FROM users ORDER BY rowid")
        .unwrap()
        .query_map([], |row| row.get(0))
        .unwrap()
        .map(|row| row.unwrap())
        .collect();
    assert_eq!(names, ["ada", "after restore"]);
}
