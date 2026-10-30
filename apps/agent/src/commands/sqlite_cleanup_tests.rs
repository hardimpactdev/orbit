use super::*;
use std::os::unix::fs::{symlink, DirBuilderExt, PermissionsExt};

fn clone_directory() -> PathBuf {
    let path = PathBuf::from(format!(
        "/tmp/orbit-sqlite-clone-{:032x}",
        rand::random::<u128>()
    ));
    fs::DirBuilder::new().mode(0o700).create(&path).unwrap();
    path
}

#[test]
fn owner_cleanup_removes_only_its_snapshot_and_repeats_without_root() {
    assert_ne!(
        identity::effective_uid(),
        0,
        "run agent unit tests as a normal user"
    );
    let directory = clone_directory();
    fs::write(
        directory.join("snapshot.sqlite"),
        b"own disposable snapshot",
    )
    .unwrap();
    assert_eq!(
        cleanup(CleanupInput::Clone {
            directory: directory.clone()
        }),
        Ok(CleanupOutput { backend: "absent" })
    );
    assert!(!directory.exists());
    assert!(cleanup(CleanupInput::Clone { directory }).is_ok());
}

#[test]
fn clone_cleanup_refuses_a_foreign_path_symlink_or_public_directory() {
    assert!(clone_cleanup(PathBuf::from("/tmp/orbit-sqlite-clone-../foreign")).is_err());
    let directory = clone_directory();
    fs::set_permissions(&directory, fs::Permissions::from_mode(0o755)).unwrap();
    assert_eq!(
        clone_cleanup(directory.clone()),
        Err(CleanupError::Refused {})
    );
    fs::set_permissions(&directory, fs::Permissions::from_mode(0o700)).unwrap();
    let link = PathBuf::from(format!(
        "/tmp/orbit-sqlite-clone-{:032x}",
        rand::random::<u128>()
    ));
    symlink(&directory, &link).unwrap();
    assert_eq!(clone_cleanup(link.clone()), Err(CleanupError::Refused {}));
    assert!(directory.exists());
    fs::remove_file(link).unwrap();
    fs::remove_dir(directory).unwrap();
}

#[test]
fn cleanup_wire_refuses_copy_prepare_install_and_generic_owner_selection() {
    for raw in [
        r#"{"variant":"clone","directory":"/tmp/orbit-sqlite-clone-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","owner":"root"}"#,
        r#"{"variant":"clone","directory":"/tmp/orbit-sqlite-clone-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","operation":"remove"}"#,
        r#"{"variant":"install","path":"/home/foreign/database.sqlite"}"#,
        r#"{"variant":"prepare","command":"instance.copy-sqlite"}"#,
    ] {
        assert!(serde_json::from_str::<CleanupInput>(raw).is_err());
    }
}

fn seed() -> serde_json::Value {
    serde_json::json!({
        "variant":"seed", "target_instance_id":42,
        "base":"/home/app/database", "source_path":"/home/app/database/database.sqlite",
        "source_user":"app", "transport_user":"orbit", "operation":"b".repeat(64),
        "state_dir":"/var/lib/orbit/app-instance-sqlite-seeds/source-21-target-42",
        "snapshot_path":format!("/tmp/orbit-sqlite-{}.sqlite", "b".repeat(64)),
    })
}

#[test]
fn seed_wrapper_selects_only_cleanup_and_binds_target_operation_and_snapshot() {
    let result = seed_input(serde_json::from_value(seed()).unwrap()).unwrap();
    assert_eq!(result.mode, "cleanup");
    for (field, value) in [
        ("target_instance_id", serde_json::json!(43)),
        ("operation", serde_json::json!("c".repeat(64))),
        (
            "state_dir",
            serde_json::json!(
                "/var/lib/orbit/app-instance-sqlite-seeds/source-21-target-42/../foreign"
            ),
        ),
        ("snapshot_path", serde_json::json!("/tmp/foreign.sqlite")),
        (
            "source_path",
            serde_json::json!("/home/foreign/database.sqlite"),
        ),
    ] {
        let mut value_input = seed();
        value_input[field] = value;
        assert!(
            seed_input(serde_json::from_value(value_input).unwrap()).is_err(),
            "{field}"
        );
    }
    let mut value = seed();
    value["mode"] = serde_json::json!("prepare");
    assert!(serde_json::from_value::<CleanupInput>(value).is_err());
}

#[test]
fn cleanup_command_requires_root_without_exposing_account_actions() {
    assert_eq!(
        CleanupSqlite::privilege(Platform::Linux),
        Some(Privilege::Root)
    );
    assert_eq!(CleanupSqlite::privilege(Platform::Macos), None);
    assert_eq!(CleanupSqlite::NAME, "database.cleanup-sqlite");
}
