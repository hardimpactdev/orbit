//! The read-only environment observer against a temporary checkout.
use super::*;
use std::os::unix::fs::{symlink, MetadataExt};
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
        }
    }
}
impl Drop for Base {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}
#[test]
fn reads_without_mutating_the_file_or_creating_missing_files() {
    let base = Base::new();
    assert_eq!(access(&base.input(Operation::Read)).state, State::Missing);
    assert_eq!(
        access(&base.input(Operation::ReadCheck)).state,
        State::Refused
    );
    assert_eq!(fs::read_dir(&base.0).unwrap().count(), 0);
    let path = base.0.join(".env");
    fs::write(&path, "APP_KEY=secret\n").unwrap();
    let before = fs::metadata(&path).unwrap();
    assert_eq!(access(&base.input(Operation::ReadCheck)).state, State::Ok);
    let read = access(&base.input(Operation::Read));
    assert_eq!(read.state, State::Ok);
    assert_eq!(read.content.as_deref(), Some("QVBQX0tFWT1zZWNyZXQK"));
    assert!(!read.installed);
    let after = fs::metadata(&path).unwrap();
    assert_eq!(
        (
            before.ino(),
            before.mode(),
            before.mtime(),
            before.mtime_nsec()
        ),
        (after.ino(), after.mode(), after.mtime(), after.mtime_nsec())
    );
    assert_eq!(fs::read_dir(&base.0).unwrap().count(), 1);
}
#[test]
fn refuses_symlinked_ancestors_destinations_and_oversized_files() {
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
fn observer_schema_rejects_write_modes_and_write_payloads() {
    let base = Base::new();
    for mode in ["write", "merge"] {
        assert!(serde_json::from_value::<EnvironmentInput>(serde_json::json!({"mode":mode,"base":base.0,"user":"nckrtl","maximum":1024,"require_home":false})).is_err());
    }
    assert!(serde_json::from_value::<EnvironmentInput>(serde_json::json!({"mode":"read","base":base.0,"user":"nckrtl","maximum":1024,"require_home":false,"content":"SECRET=new"})).is_err());
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
fn write_preflight_and_sync_inspection_never_create_or_replace_environment_files() {
    let base = Base::new();
    assert!(!ReadEnvironment::MUTATES);
    assert!(!InspectEnvironmentSync::MUTATES);
    for _ in 0..2 {
        assert_eq!(
            access(&base.input(Operation::WriteCheck)),
            outcome(State::Ok)
        );
        assert_eq!(
            inspect_sync(base.sync_input()),
            SyncInspection {
                preflight: outcome(State::Ok),
                existing: Some(outcome(State::Missing))
            }
        );
        assert_eq!(fs::read_dir(&base.0).unwrap().count(), 0);
    }
    let path = base.0.join(".env");
    fs::write(&path, "APP_KEY=secret\n").unwrap();
    let before = fs::metadata(&path).unwrap();
    for _ in 0..2 {
        let observation = inspect_sync(base.sync_input());
        assert_eq!(observation.preflight.state, State::Ok);
        assert_eq!(
            observation.existing.unwrap().content.as_deref(),
            Some("QVBQX0tFWT1zZWNyZXQK")
        );
        let after = fs::metadata(&path).unwrap();
        assert_eq!(
            (
                before.ino(),
                before.mode(),
                before.mtime(),
                before.mtime_nsec()
            ),
            (after.ino(), after.mode(), after.mtime(), after.mtime_nsec())
        );
        assert_eq!(fs::read_to_string(&path).unwrap(), "APP_KEY=secret\n");
        assert_eq!(fs::read_dir(&base.0).unwrap().count(), 1);
    }
}

#[test]
fn sync_inspection_refuses_write_ineligibility_before_reading_private_bytes() {
    use std::os::unix::fs::PermissionsExt;
    let base = Base::new();
    fs::write(base.0.join(".env"), "SECRET=keep\n").unwrap();
    for refusal in ["capacity", "home", "owner", "mode"] {
        let mut input = base.sync_input();
        match refusal {
            "capacity" => input.required_capacity = u64::MAX,
            "home" => input.require_home = true,
            "owner" => input.user = "root".into(),
            "mode" => fs::set_permissions(&base.0, fs::Permissions::from_mode(0o500)).unwrap(),
            _ => unreachable!(),
        }
        assert_eq!(
            inspect_sync(input),
            SyncInspection {
                preflight: outcome(State::Refused),
                existing: None
            },
            "{refusal}"
        );
        fs::set_permissions(&base.0, fs::Permissions::from_mode(0o700)).unwrap();
        assert_eq!(
            fs::read_to_string(base.0.join(".env")).unwrap(),
            "SECRET=keep\n"
        );
    }
    assert!(serde_json::from_value::<EnvironmentInput>(serde_json::json!({"mode":"write-check","base":base.0,"user":"nckrtl","maximum":1024,"require_home":false})).is_ok());
}
