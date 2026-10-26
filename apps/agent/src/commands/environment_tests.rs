//! The environment commands against a sandbox base that the test user owns, as the Instance user owns its checkout.

use super::*;
use std::os::unix::fs::{symlink, MetadataExt, PermissionsExt};
use std::path::PathBuf;

struct Base(PathBuf);

impl Base {
    fn new() -> Self {
        let path = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!("orbit-environment-{:016x}", rand::random::<u64>()));
        fs::create_dir_all(&path).unwrap();
        Self(path)
    }

    fn input(&self, mode: Operation) -> EnvironmentInput {
        EnvironmentInput {
            mode,
            base: self.0.display().to_string(),
            user: super::super::source::user_name(rustix::process::geteuid().as_raw()),
            maximum: 1024,
            require_home: false,
            required_capacity: 0,
            file: ".env".into(),
            managed_keys: Vec::new(),
            content: None,
        }
    }
}

impl Drop for Base {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}

#[test]
fn writes_once_reads_back_and_keeps_an_identical_file() {
    let base = Base::new();
    assert_eq!(access(&base.input(Operation::Read)).state, State::Missing);
    assert_eq!(
        access(&base.input(Operation::ReadCheck)).state,
        State::Refused
    );
    assert_eq!(access(&base.input(Operation::WriteCheck)).state, State::Ok);

    let mut write = base.input(Operation::Write);
    write.content = Some("APP_KEY=secret\n".into());
    assert_eq!(access(&write).state, State::Changed);
    let metadata = fs::metadata(base.0.join(".env")).unwrap();
    assert_eq!(metadata.permissions().mode() & 0o777, 0o600);
    assert_eq!(metadata.uid(), rustix::process::geteuid().as_raw());
    assert_eq!(access(&write).state, State::Unchanged);

    let read = access(&base.input(Operation::Read));
    assert_eq!(read.state, State::Ok);
    assert_eq!(read.content.as_deref(), Some("QVBQX0tFWT1zZWNyZXQK"));
    let leftovers = fs::read_dir(&base.0)
        .unwrap()
        .flatten()
        .filter(|entry| {
            entry
                .file_name()
                .to_string_lossy()
                .starts_with(".env.orbit-")
        })
        .count();
    assert_eq!(leftovers, 0);
}

#[test]
fn refuses_a_link_on_the_way_or_in_place_of_the_file_and_an_oversized_file() {
    let base = Base::new();
    fs::create_dir(base.0.join("real")).unwrap();
    symlink(base.0.join("real"), base.0.join("linked")).unwrap();
    let mut linked = base.input(Operation::Read);
    linked.base = base.0.join("linked").display().to_string();
    assert_eq!(access(&linked).state, State::Refused);

    symlink("/etc/hostname", base.0.join(".env")).unwrap();
    assert_eq!(access(&base.input(Operation::Read)).state, State::Refused);

    fs::remove_file(base.0.join(".env")).unwrap();
    fs::write(base.0.join(".env"), "x".repeat(2048)).unwrap();
    assert_eq!(access(&base.input(Operation::Read)).state, State::Refused);
}

#[test]
fn merges_the_managed_keys_into_an_untracked_testing_file() {
    let base = Base::new();
    fs::write(
        base.0.join(".env.testing"),
        "A=1\nexport DB_DATABASE=old\nB=2",
    )
    .unwrap();
    let mut merge = base.input(Operation::Merge);
    merge.file = ".env.testing".into();
    merge.managed_keys = vec!["DB_DATABASE".into(), "ORBIT_TEST".into()];
    merge.content = Some("ORBIT_TEST=1\nDB_DATABASE=new\nOTHER=ignored\n".into());

    assert_eq!(access(&merge).state, State::Changed);
    assert_eq!(
        fs::read_to_string(base.0.join(".env.testing")).unwrap(),
        "A=1\nDB_DATABASE=new\nB=2\nORBIT_TEST=1\n"
    );

    merge.managed_keys = vec!["lower".into()];
    assert_eq!(access(&merge).state, State::Refused);
}

#[test]
fn leaves_a_tracked_testing_file_alone() {
    let base = Base::new();
    let git = |arguments: &[&str]| {
        assert!(Process::new("git")
            .args(["-c", "user.name=O", "-c", "user.email=o@example.test"])
            .args(arguments)
            .current_dir(&base.0)
            .output()
            .unwrap()
            .status
            .success());
    };
    git(&["init", "--quiet"]);
    fs::write(base.0.join(".env.testing"), "DB_DATABASE=tracked\n").unwrap();
    git(&["add", ".env.testing"]);
    let mut merge = base.input(Operation::Merge);
    merge.file = ".env.testing".into();
    merge.managed_keys = vec!["DB_DATABASE".into()];
    merge.content = Some("DB_DATABASE=new\n".into());

    assert_eq!(access(&merge).state, State::Tracked);
    assert_eq!(
        fs::read_to_string(base.0.join(".env.testing")).unwrap(),
        "DB_DATABASE=tracked\n"
    );
}

#[test]
fn an_identical_repeat_keeps_the_file_and_mode_drift_is_repaired_by_a_replacement() {
    let base = Base::new();
    let mut write = base.input(Operation::Write);
    write.content = Some("APP_KEY=same\n".into());
    access(&write);
    let inode = fs::metadata(base.0.join(".env")).unwrap().ino();

    assert_eq!(access(&write).state, State::Unchanged);
    assert_eq!(fs::metadata(base.0.join(".env")).unwrap().ino(), inode);

    fs::set_permissions(base.0.join(".env"), fs::Permissions::from_mode(0o644)).unwrap();
    assert_eq!(access(&write).state, State::Changed);
    assert_eq!(
        fs::metadata(base.0.join(".env"))
            .unwrap()
            .permissions()
            .mode()
            & 0o777,
        0o600
    );
}

impl Base {
    fn sync_input(&self) -> SyncInput {
        let input = self.input(Operation::WriteCheck);
        SyncInput {
            base: input.base,
            user: input.user,
            maximum: input.maximum,
            require_home: input.require_home,
            required_capacity: input.required_capacity,
        }
    }
}

#[test]
fn sync_inspection_reads_a_writable_file_without_changing_it_on_repeated_calls() {
    let base = Base::new();
    let file = base.0.join(".env");
    fs::write(&file, "APP_KEY=secret\n").unwrap();
    let before = fs::metadata(&file).unwrap();
    for _ in 0..2 {
        let result = inspect_sync(base.sync_input());
        assert_eq!(result.preflight, outcome(State::Ok));
        assert_eq!(
            result.existing,
            Some(Outcome {
                state: State::Ok,
                content: Some("QVBQX0tFWT1zZWNyZXQK".into()),
                installed: false,
            })
        );
        let after = fs::metadata(&file).unwrap();
        assert_eq!(
            (after.ino(), after.mode(), after.mtime(), after.mtime_nsec()),
            (
                before.ino(),
                before.mode(),
                before.mtime(),
                before.mtime_nsec()
            )
        );
        assert_eq!(fs::read_to_string(&file).unwrap(), "APP_KEY=secret\n");
        assert_eq!(fs::read_dir(&base.0).unwrap().count(), 1);
    }
}

#[test]
fn sync_inspection_reports_an_absent_file_without_creating_it() {
    let base = Base::new();
    for _ in 0..2 {
        assert_eq!(
            inspect_sync(base.sync_input()),
            SyncInspection {
                preflight: outcome(State::Ok),
                existing: Some(outcome(State::Missing)),
            }
        );
        assert_eq!(fs::read_dir(&base.0).unwrap().count(), 0);
    }
}

#[test]
fn sync_inspection_stops_before_reading_when_the_write_preflight_refuses() {
    let base = Base::new();
    fs::write(base.0.join(".env"), "SECRET=keep\n").unwrap();
    for failure in ["capacity", "home", "owner", "directory-mode"] {
        let mut input = base.sync_input();
        match failure {
            "capacity" => input.required_capacity = u64::MAX,
            "home" => input.require_home = true,
            "owner" => input.user = "root".into(),
            "directory-mode" => {
                fs::set_permissions(&base.0, fs::Permissions::from_mode(0o500)).unwrap()
            }
            _ => unreachable!(),
        }
        assert_eq!(
            inspect_sync(input),
            SyncInspection {
                preflight: outcome(State::Refused),
                existing: None,
            },
            "{failure}"
        );
        fs::set_permissions(&base.0, fs::Permissions::from_mode(0o700)).unwrap();
        assert_eq!(
            fs::read_to_string(base.0.join(".env")).unwrap(),
            "SECRET=keep\n"
        );
    }
}

#[test]
fn sync_inspection_refuses_links_without_reading_or_changing_the_target() {
    let base = Base::new();
    let target = Base::new();
    fs::write(target.0.join(".env"), "SECRET=keep\n").unwrap();
    symlink(target.0.join(".env"), base.0.join(".env")).unwrap();
    assert_eq!(
        inspect_sync(base.sync_input()),
        SyncInspection {
            preflight: outcome(State::Refused),
            existing: None,
        }
    );
    symlink(&target.0, base.0.join("linked")).unwrap();
    let mut input = base.sync_input();
    input.base = base.0.join("linked").display().to_string();
    assert_eq!(
        inspect_sync(input),
        SyncInspection {
            preflight: outcome(State::Refused),
            existing: None,
        }
    );
    assert_eq!(
        fs::read_to_string(target.0.join(".env")).unwrap(),
        "SECRET=keep\n"
    );
}

#[test]
fn sync_inspection_distinguishes_read_refusal_from_a_successful_write_preflight() {
    let base = Base::new();
    let content = "x".repeat(2048);
    fs::write(base.0.join(".env"), &content).unwrap();
    assert_eq!(
        inspect_sync(base.sync_input()),
        SyncInspection {
            preflight: outcome(State::Ok),
            existing: Some(outcome(State::Refused)),
        }
    );
    assert_eq!(fs::read_to_string(base.0.join(".env")).unwrap(), content);
}
