use super::recipe::Output as ProcessOutput;
use super::*;
use crate::commands::recipe::WorkDir;
use std::os::unix::fs::symlink;
const REVISION: &str = "d79ef822ab8136e393ed5f86e2b56afc68d04874";
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
