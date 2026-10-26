use super::*;
use crate::{command::CommandError, commands::recipe::WorkDir};
use serde_json::json;
use std::os::unix::fs::PermissionsExt;

struct Fixture {
    _work: WorkDir,
    input: Input,
}
impl Fixture {
    fn new() -> Self {
        let work = WorkDir::new().unwrap();
        let path = work.0.join("database.sqlite");
        let db = Connection::open(&path).unwrap();
        db.execute_batch("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT); INSERT INTO users(email) VALUES ('owner@example.test')").unwrap();
        fs::set_permissions(&work.0, fs::Permissions::from_mode(0o700)).unwrap();
        fs::set_permissions(&path, fs::Permissions::from_mode(0o600)).unwrap();
        Self {
            _work: work,
            input: Input {
                owner: identity::user_name(identity::effective_uid()).unwrap(),
                path: path.display().to_string(),
                sql: "SELECT email FROM users".into(),
            },
        }
    }
    fn run(&self, write: bool) -> Result<Output, Error> {
        query(&self.input, write, Instant::now() + Duration::from_secs(5))
    }
}
#[test]
fn reads_private_database_repeatedly_without_changing_permissions_or_bytes() {
    let f = Fixture::new();
    let prior = fs::read(&f.input.path).unwrap();
    for _ in 0..2 {
        let result = f.run(false).unwrap();
        assert_eq!(
            serde_json::to_value(result).unwrap(),
            json!({"columns":["email"],"rows":[{"email":"owner@example.test"}],"row_count":1,"truncated":false})
        );
        assert_eq!(
            fs::metadata(&f._work.0).unwrap().permissions().mode() & 0o777,
            0o700
        );
        assert_eq!(
            fs::metadata(&f.input.path).unwrap().permissions().mode() & 0o777,
            0o600
        );
        assert_eq!(fs::read(&f.input.path).unwrap(), prior);
    }
}
#[test]
fn only_authorized_writes_change_the_existing_database_and_report_affected_rows() {
    let mut f = Fixture::new();
    f.input.sql = "UPDATE users SET email='new@example.test' WHERE id=1".into();
    let prior = fs::read(&f.input.path).unwrap();
    assert_eq!(f.run(false), Err(Error::Failed {}));
    assert_eq!(fs::read(&f.input.path).unwrap(), prior);
    for _ in 0..2 {
        assert_eq!(f.run(true).unwrap().row_count, 1);
    }
    f.input.sql = "SELECT email FROM users".into();
    assert_eq!(
        serde_json::to_value(f.run(false).unwrap()).unwrap()["rows"],
        json!([{"email":"new@example.test"}])
    );
}
#[test]
fn missing_and_inaccessible_files_refuse_without_creation_or_permission_changes() {
    let mut f = Fixture::new();
    for (mode, write) in [(0o000, false), (0o400, true)] {
        fs::set_permissions(&f.input.path, fs::Permissions::from_mode(mode)).unwrap();
        assert_eq!(f.run(write), Err(Error::Unreadable {}));
        assert_eq!(
            fs::metadata(&f.input.path).unwrap().permissions().mode() & 0o777,
            mode
        );
    }
    f.input.path = f._work.0.join("missing").display().to_string();
    for write in [false, true] {
        assert_eq!(f.run(write), Err(Error::Unreadable {}));
        assert!(!Path::new(&f.input.path).exists());
    }
}
#[test]
fn caps_rows_and_preserves_empty_columns_duplicate_names_and_scalar_values() {
    let mut f = Fixture::new();
    for (count, truncated) in [(500, false), (501, true)] {
        f.input.sql = format!("WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM n WHERE x<{count}) SELECT x FROM n");
        let result = f.run(false).unwrap();
        assert_eq!(
            (result.row_count, result.rows.len(), result.truncated),
            (500, 500, truncated)
        );
    }
    f.input.sql = "SELECT email FROM users WHERE 0".into();
    assert_eq!(f.run(false).unwrap().columns, ["email"]);
    f.input.sql = "SELECT 1 AS dup, 2 AS dup, NULL AS empty, 1.5 AS real, X'6869' AS blob".into();
    let result = f.run(false).unwrap();
    assert_eq!(result.columns, ["dup", "dup", "empty", "real", "blob"]);
    assert_eq!(
        serde_json::to_value(result.rows).unwrap(),
        json!([{"dup":2,"empty":null,"real":1.5,"blob":"hi"}])
    );
}
#[test]
fn rejects_invalid_input_owner_and_sql_without_returning_private_details() {
    let mut f = Fixture::new();
    let original = fs::read(&f.input.path).unwrap();
    for sql in [
        "".into(),
        " ".into(),
        "x".repeat(16385),
        "SELECT\0 1".into(),
        "SELECT private_secret FROM missing".into(),
        "SELECT X'ff'".into(),
    ] {
        f.input.sql = sql;
        let error = f.run(true).unwrap_err();
        assert_eq!(error, Error::Failed {});
        assert!(!error.message().contains("private_secret"));
        assert_eq!(error.details(), json!({}));
        assert_eq!(fs::read(&f.input.path).unwrap(), original);
    }
    f.input.sql = "SELECT 1".into();
    for path in [
        "".into(),
        "relative".into(),
        "/x\0y".into(),
        format!("/{}", "x".repeat(1024)),
    ] {
        f.input.path = path;
        assert_eq!(f.run(true), Err(Error::Failed {}));
    }
    for owner in ["root", "orbit-nonexistent-sqlite-owner", "invalid\0owner"] {
        let input = Input {
            owner: owner.into(),
            path: "/tmp/not-created-by-orbit-query".into(),
            sql: "SELECT 1".into(),
        };
        assert_eq!(
            execute(
                input,
                true,
                &Context::new(Platform::Linux, Instant::now() + Duration::from_secs(5))
            ),
            Err(Error::Unreadable {})
        );
    }
}
#[test]
fn bounds_long_queries_by_the_command_deadline() {
    let mut f = Fixture::new();
    f.input.sql =
        "WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM n) SELECT sum(x) FROM n".into();
    assert_eq!(
        query(&f.input, false, Instant::now()),
        Err(Error::Failed {})
    );
}
#[test]
fn production_owner_is_used_and_root_cannot_read_on_its_behalf() {
    if !std::process::Command::new("sudo")
        .args(["-n", "true"])
        .status()
        .is_ok_and(|s| s.success())
    {
        return;
    }
    let f = Fixture::new();
    let output = std::process::Command::new("sudo")
        .arg("-n")
        .arg("/usr/bin/env")
        .arg(format!("ORBIT_SQLITE_TEST_PATH={}", f.input.path))
        .arg(format!("ORBIT_SQLITE_TEST_USER={}", f.input.owner))
        .arg(std::env::current_exe().unwrap())
        .args([
            "--ignored",
            "--exact",
            "commands::sqlite_query::tests::owner_helper",
            "--nocapture",
        ])
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "{}{}",
        String::from_utf8_lossy(&output.stdout),
        String::from_utf8_lossy(&output.stderr)
    );
}
#[test]
#[ignore = "root identity check invoked by production_owner_is_used_and_root_cannot_read_on_its_behalf"]
fn owner_helper() {
    let path = std::env::var("ORBIT_SQLITE_TEST_PATH").unwrap();
    let owner = std::env::var("ORBIT_SQLITE_TEST_USER").unwrap();
    for (user, succeeds) in [(owner, true), ("nobody".into(), false)] {
        let input = Input {
            owner: user,
            path: path.clone(),
            sql: "SELECT email FROM users".into(),
        };
        let result = execute(
            input,
            false,
            &Context::new(Platform::Linux, Instant::now() + Duration::from_secs(5)),
        );
        if succeeds {
            assert_eq!(result.unwrap().row_count, 1);
        } else {
            assert_eq!(result, Err(Error::Unreadable {}));
        }
        assert_eq!(identity::effective_uid(), 0);
    }
}
