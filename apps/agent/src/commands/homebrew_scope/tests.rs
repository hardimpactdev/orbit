use super::recipe::Output as ProcessOutput;
use super::*;
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
        fs::create_dir_all(files.path("/home")).unwrap();
        let account = Account {
            name: identity::user_name(uid).unwrap(),
            uid,
            gid,
            home: files.path("/home/account"),
        };
        fs::create_dir(&account.home).unwrap();
        Self {
            _work: work,
            files,
            account,
        }
    }
    fn prefix(&self) -> PathBuf {
        self.files.path(PREFIX)
    }
    fn existing(&self) {
        fs::create_dir_all(self.prefix().join("Homebrew/.git")).unwrap();
        fs::create_dir_all(self.prefix().join("bin")).unwrap();
        symlink("../Homebrew/bin/brew", self.prefix().join("bin/brew")).unwrap();
    }
}
struct Fake {
    calls: Vec<Invocation>,
    revision: String,
    tag: bool,
    origin: String,
    dirty: bool,
    fail: Option<String>,
    truncated: bool,
    tag_revision: String,
    version: String,
}
impl Default for Fake {
    fn default() -> Self {
        Self {
            calls: vec![],
            revision: REVISION.into(),
            tag: true,
            origin: ORIGIN.into(),
            dirty: false,
            fail: None,
            truncated: false,
            tag_revision: REVISION.into(),
            version: VERSION.into(),
        }
    }
}
impl Host for Fake {
    fn execute(
        &mut self,
        call: &Invocation,
    ) -> std::result::Result<ProcessOutput, recipe::Failure> {
        self.calls.push(call.clone());
        let args = &call.argv;
        let has = |v: &str| args.iter().any(|arg| arg == v);
        if self.fail.as_ref().is_some_and(|v| has(v)) {
            return Ok(ProcessOutput {
                code: 1,
                ..Default::default()
            });
        }
        let mut out = ProcessOutput {
            truncated: self.truncated,
            ..Default::default()
        };
        if has("clone") {
            fs::create_dir_all(Path::new(args.last().unwrap()).join(".git")).unwrap();
        }
        if has("checkout") {
            self.revision = REVISION.into();
        }
        if has("remote.origin.url") {
            out.stdout = self.origin.clone();
        }
        if has("status") && self.dirty {
            out.stdout = "?? unexpected".into();
        }
        if has("rev-parse") && has("HEAD") {
            out.stdout = self.revision.clone();
        }
        if has("show-ref") {
            out.code = if self.tag { 0 } else { 1 };
        }
        if has("fetch") && has("tag") {
            self.tag = true;
        }
        if has("7.0.0^{commit}") {
            out.stdout = self.tag_revision.clone();
        }
        if has("--version") {
            out.stdout = format!("{}\n", self.version);
        }
        Ok(out)
    }
    fn wait(&mut self, _: Duration) -> std::result::Result<(), recipe::Failure> {
        panic!("No retries")
    }
}
#[test]
fn reads_clean_official_owned_scope_at_any_revision_without_changes() {
    let fixture = Fixture::new();
    let mut host = Fake::default();
    assert_eq!(
        inspect(&mut host, &fixture.files, &fixture.account),
        Err(Error::Absent {})
    );
    assert!(host.calls.is_empty());
    fixture.existing();
    host.revision = "previous".into();
    for _ in 0..2 {
        assert_eq!(
            inspect(&mut host, &fixture.files, &fixture.account)
                .unwrap()
                .prefix,
            fixture.prefix()
        );
    }
    assert_eq!(host.calls.len(), 4);
    for call in host.calls {
        assert!(call.argv.contains(&"config".into()) || call.argv.contains(&"status".into()));
    }
}
#[test]
fn ownership_and_repository_conflicts_refuse_before_packages_or_checkout() {
    for path in [
        "/home/linuxbrew",
        PREFIX,
        "/home/linuxbrew/.linuxbrew/Homebrew",
        "/home/linuxbrew/.linuxbrew/Homebrew/.git",
        "/home/linuxbrew/.linuxbrew/bin/brew",
    ] {
        let fixture = Fixture::new();
        fixture.existing();
        let bad = fixture.files.path(path);
        if bad.is_dir() {
            fs::remove_dir_all(&bad).unwrap();
        } else {
            fs::remove_file(&bad).unwrap();
        }
        symlink(&fixture.account.home, &bad).unwrap();
        let mut host = Fake::default();
        assert_eq!(
            materialize(&mut host, &fixture.files, &fixture.account),
            Err(Error::Conflict {}),
            "{path}"
        );
        assert!(host.calls.is_empty());
    }
    for mut host in [
        Fake {
            origin: "https://example.test/brew".into(),
            ..Default::default()
        },
        Fake {
            dirty: true,
            ..Default::default()
        },
        Fake {
            fail: Some("config".into()),
            ..Default::default()
        },
        Fake {
            truncated: true,
            ..Default::default()
        },
    ] {
        let fixture = Fixture::new();
        fixture.existing();
        assert_eq!(
            materialize(&mut host, &fixture.files, &fixture.account),
            Err(Error::Conflict {})
        );
        assert!(host.calls.iter().all(|call| !call.argv.iter().any(|arg| [
            "/usr/bin/apt-get",
            "fetch",
            "checkout"
        ]
        .contains(&arg.as_str()))));
    }
    let mut fixture = Fixture::new();
    fixture.existing();
    fixture.account.gid += 1;
    assert_eq!(
        inspect(&mut Fake::default(), &fixture.files, &fixture.account),
        Err(Error::Conflict {})
    );
}
#[test]
fn first_use_publishes_owned_scope_and_repeats_without_clone_or_implicit_package_removal() {
    let fixture = Fixture::new();
    let mut host = Fake::default();
    materialize(&mut host, &fixture.files, &fixture.account).unwrap();
    let meta = fs::symlink_metadata(fixture.prefix()).unwrap();
    assert_eq!(
        (meta.uid(), meta.gid()),
        (fixture.account.uid, fixture.account.gid)
    );
    assert_eq!(
        fs::read_link(fixture.prefix().join("bin/brew")).unwrap(),
        Path::new("../Homebrew/bin/brew")
    );
    let first = host.calls.len();
    materialize(&mut host, &fixture.files, &fixture.account).unwrap();
    assert_eq!(
        host.calls
            .iter()
            .filter(|c| c.argv.contains(&"clone".into()))
            .count(),
        1
    );
    assert!(!host.calls[first..]
        .iter()
        .any(|c| c.argv.contains(&"fetch".into())));
    let apt: Vec<_> = host
        .calls
        .iter()
        .filter(|c| c.argv[0] == "/usr/bin/apt-get")
        .collect();
    assert_eq!(apt.len(), 4);
    for call in &apt {
        assert_eq!(call.argv[1..3], ["-o", "DPkg::Lock::Timeout=300"]);
        assert!(!call.argv.contains(&"remove".into()));
    }
    assert_eq!(
        apt[1].argv[3..],
        [
            "install",
            "--yes",
            "--no-install-recommends",
            "--no-remove",
            "--",
            "build-essential",
            "procps",
            "curl",
            "file",
            "git",
            "ca-certificates"
        ]
    );
    assert!(fs::read_dir(fixture.files.path("/home/linuxbrew"))
        .unwrap()
        .all(|entry| entry.unwrap().file_name() == ".linuxbrew"));
}
#[test]
fn upgrade_fetches_revision_then_missing_tag_clears_describe_cache_and_checks_both() {
    let fixture = Fixture::new();
    fixture.existing();
    let cache = fixture.prefix().join("Homebrew/.git/describe-cache");
    fs::create_dir(&cache).unwrap();
    fs::write(cache.join(REVISION), "old description").unwrap();
    let mut host = Fake {
        revision: "previous".into(),
        tag: false,
        ..Default::default()
    };
    materialize(&mut host, &fixture.files, &fixture.account).unwrap();
    assert!(!cache.exists());
    let steps: Vec<_> = host
        .calls
        .iter()
        .filter_map(|c| {
            if c.argv.contains(&"fetch".into()) {
                Some(if c.argv.contains(&"tag".into()) {
                    "tag"
                } else {
                    "revision"
                })
            } else if c.argv.contains(&"checkout".into()) {
                Some("checkout")
            } else {
                None
            }
        })
        .collect();
    assert_eq!(steps, ["revision", "checkout", "tag"]);
    for host in [
        Fake {
            tag_revision: "wrong".into(),
            ..Default::default()
        },
        Fake {
            version: "Homebrew 6.0.22".into(),
            ..Default::default()
        },
    ] {
        assert_eq!(
            materialize(&mut { host }, &fixture.files, &fixture.account),
            Err(Error::Failed {})
        );
    }
}
#[test]
fn failure_cleans_only_its_stage_and_keeps_owned_prefix_for_retry() {
    for failure in ["/usr/bin/apt-get", "clone", "checkout", "config"] {
        let fixture = Fixture::new();
        let mut host = Fake {
            fail: Some(failure.into()),
            ..Default::default()
        };
        assert!(materialize(&mut host, &fixture.files, &fixture.account).is_err());
        let parent = fixture.files.path("/home/linuxbrew");
        if parent.exists() {
            assert!(fs::read_dir(&parent)
                .unwrap()
                .all(|e| e.unwrap().file_name() == ".linuxbrew"));
        }
        materialize(&mut Fake::default(), &fixture.files, &fixture.account).unwrap();
        assert!(fixture.prefix().exists());
    }
}
#[test]
fn invalid_account_and_unknown_fields_refuse_before_setup() {
    let context = Context::new(Platform::Linux, std::time::Instant::now());
    for user in ["", "root", "-option", "bad\nuser"] {
        assert_eq!(
            Materialize::run(Input { user: user.into() }, &context),
            Err(Error::Invalid {})
        );
    }
    assert!(
        serde_json::from_str::<Input>(r#"{"user":"orbit","origin":"https://example.test"}"#)
            .is_err()
    );
    assert!(serde_json::from_str::<Empty>(r#"{"prefix":"/tmp/x"}"#).is_err());
}
