use super::*;
use crate::commands::recipe::WorkDir;
use std::os::unix::fs::symlink;
use std::os::unix::fs::PermissionsExt;
struct Fixture {
    _work: WorkDir,
    files: Files,
    account: Account,
}
impl Fixture {
    fn new() -> Self {
        let work = WorkDir::new().unwrap();
        let uid = identity::effective_uid();
        let gid = rustix::process::getegid().as_raw();
        let files = Files {
            root: work.0.clone(),
            uid,
            gid,
        };
        fs::create_dir_all(files.path("/opt")).unwrap();
        fs::create_dir_all(files.path("/usr/bin")).unwrap();
        let account = Account {
            name: identity::user_name(uid).unwrap(),
            uid,
            gid,
            home: work.0.join("home"),
        };
        fs::create_dir(&account.home).unwrap();
        Self {
            _work: work,
            files,
            account,
        }
    }
    fn manifest(&self) -> std::path::PathBuf {
        self.files.path(SCOPE).join("composer.json")
    }
    fn existing(&self, text: &str) {
        fs::create_dir_all(self.files.path(SCOPE)).unwrap();
        fs::write(self.manifest(), text).unwrap();
        let binary = self.files.path("/usr/bin/composer");
        fs::write(&binary, "not executed by inspection").unwrap();
        fs::set_permissions(binary, fs::Permissions::from_mode(0o755)).unwrap();
    }
    fn inspect(&self) -> Result<Output> {
        inspect(&self.files, &self.account)
    }
}
#[test]
fn reads_root_names_without_hooks_or_changes() {
    let fixture = Fixture::new();
    let text = r#"{"require":{"php":"^8.5","ext-json":"*","vendor/package":"*","legacy/Uppercase":"*"},"scripts":{"post-install-cmd":"touch should-not-run"}}"#;
    fixture.existing(text);
    let modified = fs::metadata(fixture.manifest())
        .unwrap()
        .modified()
        .unwrap();
    for _ in 0..2 {
        assert_eq!(
            fixture.inspect().unwrap().roots,
            ["ext-json", "legacy/Uppercase", "php", "vendor/package"]
        );
    }
    assert_eq!(fs::read_to_string(fixture.manifest()).unwrap(), text);
    assert_eq!(
        fs::metadata(fixture.manifest())
            .unwrap()
            .modified()
            .unwrap(),
        modified
    );
    assert!(!fixture.files.root.join("should-not-run").exists());
    for empty in [
        r#"{"require":{}}"#,
        r#"{"require": []}"#,
        "{}",
        r#"{"config":{},"require-dev":{"v/p":"*"}}"#,
    ] {
        fs::write(fixture.manifest(), empty).unwrap();
        assert!(fixture.inspect().unwrap().roots.is_empty());
    }
}
#[test]
fn distinguishes_absence_conflicts_and_unreadable_manifests() {
    let fixture = Fixture::new();
    assert_eq!(fixture.inspect(), Err(Error::Absent {}));
    fixture.existing(r#"{"require":{}}"#);
    fs::remove_file(fixture.manifest()).unwrap();
    assert_eq!(fixture.inspect(), Err(Error::Absent {}));
    for text in [
        "{",
        "[]",
        r#"{"require":null}"#,
        r#"{"require":["vendor/package"]}"#,
        r#"{"require":{"1":"*"}}"#,
        r#"{"require":{"vendor/pack\nage":"*"}}"#,
        &format!(r#"{{"require":{{"{}":"*"}}}}"#, "a".repeat(256)),
        &" ".repeat(MAX_MANIFEST as usize + 1),
    ] {
        fs::write(fixture.manifest(), text).unwrap();
        assert_eq!(fixture.inspect(), Err(Error::Unreadable {}), "{text:.50}");
    }
    fs::remove_file(fixture.manifest()).unwrap();
    symlink("/etc/passwd", fixture.manifest()).unwrap();
    assert_eq!(fixture.inspect(), Err(Error::Conflict {}));
}
