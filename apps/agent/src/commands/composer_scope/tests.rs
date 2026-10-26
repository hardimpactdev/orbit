use super::recipe::Output as ProcessOutput;
use super::*;
use std::os::unix::fs::symlink;
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
#[derive(Default)]
struct Fake {
    calls: Vec<Invocation>,
    fail_at: Option<usize>,
}
impl Host for Fake {
    fn execute(
        &mut self,
        call: &Invocation,
    ) -> std::result::Result<ProcessOutput, recipe::Failure> {
        self.calls.push(call.clone());
        Ok(ProcessOutput {
            code: if self.fail_at == Some(self.calls.len()) {
                1
            } else {
                0
            },
            stdout: "Composer 2.9.0".into(),
            ..Default::default()
        })
    }
    fn wait(&mut self, _: Duration) -> std::result::Result<(), recipe::Failure> {
        panic!("No retries")
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
#[test]
fn scope_conflicts_refuse_before_package_changes_or_directory_creation() {
    for path in [
        "/opt/orbit",
        "/opt/orbit/composer",
        "/opt/orbit/composer/composer.json",
        "/opt/orbit/composer/vendor",
        "/opt/orbit/composer/vendor/bin",
    ] {
        for symlinked in [false, true] {
            let fixture = Fixture::new();
            let bad = fixture.files.path(path);
            fs::create_dir_all(bad.parent().unwrap()).unwrap();
            if symlinked {
                symlink(&fixture.account.home, &bad).unwrap();
            } else if path.ends_with("composer.json") {
                fs::create_dir(&bad).unwrap();
            } else {
                fs::write(&bad, "foreign").unwrap();
            }
            let mut host = Fake::default();
            assert_eq!(
                materialize(&mut host, &fixture.files, &fixture.account),
                Err(Error::Conflict {})
            );
            assert!(host.calls.is_empty());
        }
    }
    let mut fixture = Fixture::new();
    fixture.existing(r#"{"require":{}}"#);
    fixture.account.gid += 1;
    assert_eq!(fixture.inspect(), Err(Error::Conflict {}));
    let mut host = Fake::default();
    assert_eq!(
        materialize(&mut host, &fixture.files, &fixture.account),
        Err(Error::Conflict {})
    );
    assert!(host.calls.is_empty());
}
#[test]
fn materializes_fixed_prerequisites_and_preserves_existing_manifest_on_repeat() {
    let fixture = Fixture::new();
    let mut host = Fake::default();
    materialize(&mut host, &fixture.files, &fixture.account).unwrap();
    assert_eq!(
        fs::read_to_string(fixture.manifest()).unwrap(),
        "{\"require\":{}}\n"
    );
    fs::write(fixture.manifest(), r#"{"require":{"vendor/package":"^1"}}"#).unwrap();
    let modified = fs::metadata(fixture.manifest())
        .unwrap()
        .modified()
        .unwrap();
    materialize(&mut host, &fixture.files, &fixture.account).unwrap();
    assert_eq!(
        fs::read_to_string(fixture.manifest()).unwrap(),
        r#"{"require":{"vendor/package":"^1"}}"#
    );
    assert_eq!(
        fs::metadata(fixture.manifest())
            .unwrap()
            .modified()
            .unwrap(),
        modified
    );
    assert_eq!(host.calls.len(), 6);
    assert_eq!(
        host.calls[0].argv,
        [
            "/usr/bin/apt-get",
            "-o",
            "DPkg::Lock::Timeout=300",
            "update"
        ]
    );
    assert_eq!(
        host.calls[1].argv,
        [
            "/usr/bin/apt-get",
            "-o",
            "DPkg::Lock::Timeout=300",
            "install",
            "--yes",
            "--no-install-recommends",
            "--no-remove",
            "--",
            "composer",
            "git",
            "unzip"
        ]
    );
    assert!(host.calls[2].argv.contains(&format!(
        "COMPOSER_HOME={}",
        fixture.files.path(SCOPE).display()
    )));
    assert!(host.calls[2].argv.ends_with(&[
        "/usr/bin/composer".into(),
        "--version".into(),
        "--no-ansi".into()
    ]));
    for path in [
        fixture.files.path(SCOPE),
        fixture.files.path(SCOPE).join("vendor/bin"),
    ] {
        let meta = fs::metadata(path).unwrap();
        assert_eq!(meta.uid(), fixture.account.uid);
        assert_eq!(meta.gid(), fixture.account.gid);
        assert_eq!(meta.mode() & 0o7777, 0o755);
    }
}
#[test]
fn failed_setup_retains_owned_state_for_retry_without_overwriting_content() {
    for failed_call in [1, 2, 3] {
        let fixture = Fixture::new();
        let mut host = Fake {
            fail_at: Some(failed_call),
            ..Default::default()
        };
        assert_eq!(
            materialize(&mut host, &fixture.files, &fixture.account),
            Err(Error::Failed {})
        );
        if failed_call < 3 {
            assert!(!fixture.manifest().exists());
        }
        let mut retry = Fake::default();
        materialize(&mut retry, &fixture.files, &fixture.account).unwrap();
        assert_eq!(
            fs::read_to_string(fixture.manifest()).unwrap(),
            "{\"require\":{}}\n"
        );
    }
}
#[test]
fn invalid_account_and_unknown_input_refuse_before_setup() {
    let context = Context::new(Platform::Linux, std::time::Instant::now());
    for name in ["", "-root", "root", "invalid\naccount"] {
        assert_eq!(
            Materialize::run(Input { user: name.into() }, &context),
            Err(Error::Invalid {})
        );
    }
    assert!(serde_json::from_str::<Input>(r#"{"user":"orbit","scope":"/tmp/x"}"#).is_err());
    assert!(serde_json::from_str::<Empty>(r#"{"user":"orbit"}"#).is_err());
}
