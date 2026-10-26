use super::*;
use crate::{command::CommandError, commands::recipe::WorkDir};
use serde_json::json;
use std::os::unix::fs::{symlink, PermissionsExt};

struct Fixture {
    work: WorkDir,
    input: ProtectInput,
}
impl Fixture {
    fn new() -> Self {
        let work = WorkDir::new().unwrap();
        let source = work.0.join("source #é.sqlite");
        Connection::open(&source).unwrap().execute_batch("CREATE TABLE notes(id INTEGER PRIMARY KEY, body BLOB); INSERT INTO notes VALUES(1, X'00FF');").unwrap();
        let input = ProtectInput {
            identity: Identity {
                orbit_home: work.0.clone(),
                record: 7,
                sha: "a".repeat(40),
            },
            source,
        };
        Self { work, input }
    }
    fn protect(&self) -> Result<Recovery, Error> {
        protect(&self.input, Instant::now() + Duration::from_secs(10))
    }
    fn verification(&self, result: &Recovery) -> VerifyInput {
        VerifyInput {
            identity: self.input.identity.clone(),
            sha256: result.sha256.clone(),
        }
    }
    fn receipt(&self) -> ReceiptInput {
        ReceiptInput { identity: self.input.identity.clone(), attributes: serde_json::from_value(json!({"id":7,"sha":"a".repeat(40),"phases":"{\"hold\":{\"record\":7}}","message":"é / exact bytes"})).unwrap() }
    }
}
fn code<T: std::fmt::Debug>(result: Result<T, Error>, expected: &str) {
    assert_eq!(result.unwrap_err().code(), expected);
}
#[test]
fn preserves_private_complete_snapshot_repeats_and_verifies_after_original_retention() {
    let f = Fixture::new();
    let source = fs::read(&f.input.source).unwrap();
    let result = f.protect().unwrap();
    let inode = fs::metadata(&result.path).unwrap().ino();
    assert_eq!(fs::read(&result.path).unwrap(), source);
    assert_eq!(fs::metadata(&result.path).unwrap().mode() & 0o777, 0o600);
    assert_eq!(
        fs::metadata(result.path.parent().unwrap()).unwrap().mode() & 0o777,
        0o700
    );
    assert_eq!(f.protect().unwrap(), result);
    assert_eq!(fs::metadata(&result.path).unwrap().ino(), inode);
    fs::remove_file(&f.input.source).unwrap();
    for _ in 0..2 {
        verify(
            &f.verification(&result),
            Instant::now() + Duration::from_secs(10),
        )
        .unwrap();
    }
    assert_eq!(
        fs::read_dir(result.path.parent().unwrap()).unwrap().count(),
        1
    );
    assert!(!PathBuf::from(format!("{}-wal", result.path.display())).exists());
    assert!(!PathBuf::from(format!("{}-shm", result.path.display())).exists());
}
#[test]
fn invalid_identity_snapshot_and_sidecars_refuse_before_creating_recovery_artifacts() {
    for fault in [
        "id",
        "sha",
        "relative-home",
        "aliased-home",
        "missing",
        "symlink",
        "directory",
        "corrupt",
        "wal",
        "journal",
        "empty-sidecar-link",
    ] {
        let mut f = Fixture::new();
        match fault {
            "id" => f.input.identity.record = 0,
            "sha" => f.input.identity.sha = "main".into(),
            "relative-home" => f.input.identity.orbit_home = "relative".into(),
            "aliased-home" => {
                let link = f.work.0.join("alias");
                symlink(&f.work.0, &link).unwrap();
                f.input.identity.orbit_home = link;
            }
            "missing" => fs::remove_file(&f.input.source).unwrap(),
            "symlink" => {
                let link = f.work.0.join("source-link");
                symlink(&f.input.source, &link).unwrap();
                f.input.source = link;
            }
            "directory" => f.input.source = f.work.0.clone(),
            "corrupt" => fs::write(&f.input.source, b"not SQLite").unwrap(),
            "wal" | "journal" => {
                fs::write(format!("{}-{fault}", f.input.source.display()), b"live").unwrap()
            }
            "empty-sidecar-link" => {
                let empty = f.work.0.join("empty");
                fs::write(&empty, []).unwrap();
                symlink(empty, format!("{}-wal", f.input.source.display())).unwrap();
            }
            _ => unreachable!(),
        }
        code(f.protect(), "gateway.release_recovery_invalid");
        assert!(!f.work.0.join("recovery").exists(), "{fault}");
    }
}
#[test]
fn reports_bounded_foreign_keys_without_publishing_a_copy() {
    let f = Fixture::new();
    let db = Connection::open(&f.input.source).unwrap();
    db.execute_batch("PRAGMA foreign_keys=OFF; CREATE TABLE parents(id INTEGER PRIMARY KEY); CREATE TABLE children(id INTEGER PRIMARY KEY, parent INTEGER REFERENCES parents(id)); WITH RECURSIVE numbers(n) AS (VALUES(1) UNION ALL SELECT n+1 FROM numbers WHERE n<25) INSERT INTO children SELECT n,999 FROM numbers;").unwrap();
    drop(db);
    let error = f.protect().unwrap_err();
    assert_eq!(error.code(), "gateway.release_recovery_foreign_keys");
    let Error::ForeignKeys { violations, limit } = error else {
        panic!()
    };
    assert_eq!(limit, 20);
    assert_eq!(violations.len(), 20);
    assert_eq!(
        serde_json::to_value(&violations[0]).unwrap(),
        json!({"table":"children","rowid":1,"parent":"parents","fkid":0})
    );
    assert!(!f.work.0.join("recovery").exists());
    Connection::open(&f.input.source)
        .unwrap()
        .execute_batch("INSERT INTO parents VALUES(999)")
        .unwrap();
    f.protect().unwrap();
}
#[test]
fn refuses_unsafe_or_changed_protected_artifacts_without_replacing_them() {
    for fault in [
        "contents",
        "hash",
        "mode",
        "directory-mode",
        "symlink",
        "directory-link",
        "wal",
        "journal",
    ] {
        let f = Fixture::new();
        let result = f.protect().unwrap();
        let mut input = f.verification(&result);
        match fault {
            "contents" => fs::write(&result.path, b"changed").unwrap(),
            "hash" => input.sha256 = "b".repeat(64),
            "mode" => fs::set_permissions(&result.path, fs::Permissions::from_mode(0o644)).unwrap(),
            "directory-mode" => fs::set_permissions(
                result.path.parent().unwrap(),
                fs::Permissions::from_mode(0o755),
            )
            .unwrap(),
            "symlink" => {
                fs::remove_file(&result.path).unwrap();
                symlink(&f.input.source, &result.path).unwrap();
            }
            "directory-link" => {
                fs::rename(f.work.0.join("recovery"), f.work.0.join("saved")).unwrap();
                symlink("saved", f.work.0.join("recovery")).unwrap();
            }
            "wal" | "journal" => {
                fs::write(format!("{}-{fault}", result.path.display()), b"live").unwrap()
            }
            _ => unreachable!(),
        }
        let before = fs::read(&result.path).unwrap();
        code(
            verify(&input, Instant::now() + Duration::from_secs(10)),
            "gateway.release_recovery_invalid",
        );
        assert_eq!(fs::read(&result.path).unwrap(), before);
        if fault != "hash" {
            code(f.protect(), "gateway.release_recovery_invalid");
        }
        assert_eq!(fs::read(&result.path).unwrap(), before);
    }
}
#[test]
fn receipt_is_private_repeatable_exact_data_and_never_overwritten() {
    let f = Fixture::new();
    f.protect().unwrap();
    let mut input = f.receipt();
    assert!(write_receipt(&input).unwrap().changed);
    let target = path(&input.identity, "json").unwrap();
    let meta = fs::metadata(&target).unwrap();
    assert_eq!(meta.mode() & 0o777, 0o600);
    assert_eq!(
        read_receipt(&input.identity).unwrap().attributes,
        input.attributes
    );
    assert!(!write_receipt(&input).unwrap().changed);
    assert_eq!(fs::metadata(&target).unwrap().ino(), meta.ino());
    let before = fs::read(&target).unwrap();
    input.attributes.insert("message".into(), json!("changed"));
    code(write_receipt(&input), "gateway.release_restore_required");
    assert_eq!(fs::read(&target).unwrap(), before);
    assert_eq!(fs::read_dir(target.parent().unwrap()).unwrap().count(), 2);
}
#[test]
fn refuses_receipt_identity_size_and_unsafe_storage_without_changing_existing_data() {
    for fault in [
        "record",
        "sha",
        "too-large",
        "missing-directory",
        "directory-mode",
        "symlink",
        "mode",
        "invalid-json",
        "oversized-file",
    ] {
        let f = Fixture::new();
        f.protect().unwrap();
        let mut input = f.receipt();
        let target = path(&input.identity, "json").unwrap();
        match fault {
            "record" => {
                input.attributes.insert("id".into(), json!(8));
            }
            "sha" => {
                input.attributes.insert("sha".into(), json!("b".repeat(40)));
            }
            "too-large" => {
                input
                    .attributes
                    .insert("message".into(), json!("x".repeat(RECEIPT_BYTES as usize)));
            }
            "missing-directory" => {
                fs::remove_dir_all(target.parent().unwrap()).unwrap();
            }
            "directory-mode" => {
                fs::set_permissions(target.parent().unwrap(), fs::Permissions::from_mode(0o755))
                    .unwrap()
            }
            "symlink" => symlink(&f.input.source, &target).unwrap(),
            "mode" | "invalid-json" | "oversized-file" => {
                write_receipt(&input).unwrap();
                if fault == "mode" {
                    fs::set_permissions(&target, fs::Permissions::from_mode(0o644)).unwrap();
                } else {
                    fs::write(
                        &target,
                        if fault == "invalid-json" {
                            b"[]".to_vec()
                        } else {
                            vec![b' '; RECEIPT_BYTES as usize + 1]
                        },
                    )
                    .unwrap();
                }
            }
            _ => unreachable!(),
        }
        let before = fs::read(&target).ok();
        code(write_receipt(&input), "gateway.release_restore_required");
        assert_eq!(fs::read(&target).ok(), before, "{fault}");
        code(
            read_receipt(&input.identity),
            "gateway.release_restore_required",
        );
    }
}

#[test]
fn private_artifacts_keep_the_required_modes_under_a_restrictive_caller_umask() {
    let f = Fixture::new();
    let result = std::process::Command::new(std::env::current_exe().unwrap())
        .args([
            "--exact",
            "commands::gateway_recovery::tests::restrictive_umask_helper",
            "--ignored",
        ])
        .env("ORBIT_RECOVERY_TEST_HOME", &f.work.0)
        .output()
        .unwrap();
    assert!(
        result.status.success(),
        "{}{}",
        String::from_utf8_lossy(&result.stdout),
        String::from_utf8_lossy(&result.stderr)
    );
    assert_eq!(
        read_receipt(&f.input.identity).unwrap().attributes,
        f.receipt().attributes
    );
    let recovery = f.protect().unwrap();
    assert_eq!(fs::metadata(recovery.path).unwrap().mode() & 0o777, 0o600);
}

#[test]
#[ignore = "isolated umask helper started by private_artifacts_keep_the_required_modes_under_a_restrictive_caller_umask"]
fn restrictive_umask_helper() {
    let home = PathBuf::from(std::env::var_os("ORBIT_RECOVERY_TEST_HOME").unwrap());
    let identity = Identity {
        orbit_home: home.clone(),
        record: 7,
        sha: "a".repeat(40),
    };
    // This test runs only in its own process, so the mask cannot affect another parallel test.
    unsafe {
        libc::umask(0o777);
    }
    protect(
        &ProtectInput {
            identity: identity.clone(),
            source: home.join("source #é.sqlite"),
        },
        Instant::now() + Duration::from_secs(10),
    )
    .unwrap();
    let attributes = serde_json::from_value(json!({"id":7,"sha":"a".repeat(40),"phases":"{\"hold\":{\"record\":7}}","message":"é / exact bytes"})).unwrap();
    write_receipt(&ReceiptInput {
        identity,
        attributes,
    })
    .unwrap();
}
