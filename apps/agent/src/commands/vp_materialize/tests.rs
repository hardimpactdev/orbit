use super::*;
use crate::commands::recipe::{Invocation, Output as ProcessOutput, WorkDir};
use serde_json::{json, Value};
use std::os::unix::fs::{symlink, PermissionsExt};
struct Fixture {
    _work: WorkDir,
    files: Files,
    account: Account,
    input: Input,
}
impl Fixture {
    fn new() -> Self {
        let work = WorkDir::new().unwrap();
        let uid = rustix::process::geteuid().as_raw();
        let gid = rustix::process::getegid().as_raw();
        let files = Files {
            root: work.0.clone(),
            uid,
            gid,
        };
        let account = Account {
            name: crate::command::identity::user_name(uid).unwrap(),
            uid,
            gid,
            home: work.0.join("home"),
        };
        for path in [
            &account.home,
            &files.path("/usr/local/bin"),
            &files.path("/opt/orbit"),
        ] {
            fs::create_dir_all(path).unwrap();
        }
        let recipe: Value = serde_json::from_str(include_str!(
            "../../../../gateway/resources/machine-software/recipe.json"
        ))
        .unwrap();
        let mut tools = serde_json::Map::new();
        for name in ["vp", "vp-cli", "node", "pnpm"] {
            tools.insert(name.into(), recipe["tools"][name].clone());
        }
        let input = serde_json::from_value(json!({"user":account.name,"snapshot":recipe["ubuntu"]["snapshot"],"architecture":if cfg!(target_arch="aarch64") {"arm64"} else {"amd64"}, "tools":tools,"artifact_mirror":recipe["artifact_mirror"],"vp_lock":include_str!("../../../../gateway/resources/machine-software/vite-plus-package-lock.json")})).unwrap();
        Self {
            _work: work,
            files,
            account,
            input,
        }
    }
    fn scope(&self, location: &str) -> PathBuf {
        match location {
            "opt" => self.files.path("/opt/orbit/vite-plus"),
            "home" => self.account.home.join(".vite-plus"),
            _ => self.account.home.join(".local/share/vite-plus"),
        }
    }
    fn existing(&self, location: &str, former: bool) -> PathBuf {
        let scope = self.scope(location);
        fs::create_dir_all(scope.join("bin")).unwrap();
        for binary in BINARIES {
            fs::write(scope.join("bin").join(binary), "runtime").unwrap();
            fs::set_permissions(
                scope.join("bin").join(binary),
                fs::Permissions::from_mode(0o755),
            )
            .unwrap();
            if former {
                let export = if location == "opt" {
                    format!("export VP_HOME={}\n", scope.display())
                } else {
                    String::new()
                };
                let path = self.files.path("/usr/local/bin").join(binary);
                fs::write(
                    &path,
                    format!(
                        "#!/bin/sh\n{export}exec \"{}/bin/{binary}\" \"$@\"\n",
                        scope.display()
                    ),
                )
                .unwrap();
                fs::set_permissions(path, fs::Permissions::from_mode(0o755)).unwrap();
            }
        }
        scope
    }
    fn run(&self, host: &mut impl Host) -> Result<Output> {
        materialize(
            host,
            &self.files,
            &self.account,
            &self.input,
            Instant::now() + Duration::from_secs(900),
        )
    }
}
#[derive(Default)]
struct Verify {
    calls: Vec<Invocation>,
    fail: bool,
}
impl Host for Verify {
    fn wait(&mut self, _: Duration) -> std::result::Result<(), recipe::Failure> {
        panic!("An existing scope needs no download retries")
    }
    fn execute(
        &mut self,
        call: &Invocation,
    ) -> std::result::Result<ProcessOutput, recipe::Failure> {
        assert_eq!(
            call.argv.last().map(String::as_str),
            Some("--version"),
            "unexpected download or mutation: {:?}",
            call.argv
        );
        self.calls.push(call.clone());
        let fail = self.fail && call.argv.iter().any(|arg| arg.ends_with("/npx"));
        Ok(ProcessOutput {
            code: i32::from(fail),
            stdout: "verified version\n".into(),
            ..Default::default()
        })
    }
}
#[test]
fn retains_existing_runtimes_upgrades_owned_launchers_and_repeats_without_downloads() {
    for location in ["opt", "home", "local"] {
        for former in [true, false] {
            let fixture = Fixture::new();
            let scope = fixture.existing(location, former);
            let mut host = Verify::default();
            assert!(fixture.run(&mut host).unwrap().changed);
            let path = fixture.files.path("/usr/local/bin/vp");
            let time = fs::metadata(&path).unwrap().modified().unwrap();
            assert_eq!(fs::read(&path).unwrap(), launcher(&scope, "vp"));
            assert!(!fixture.run(&mut host).unwrap().changed);
            assert_eq!(fs::metadata(path).unwrap().modified().unwrap(), time);
            assert_eq!(fs::read(scope.join("bin/vp")).unwrap(), b"runtime");
            assert_eq!(host.calls.len(), 10);
            assert!(host
                .calls
                .iter()
                .all(|call| call.argv.contains(&format!("VP_HOME={}", scope.display()))));
        }
    }
}
#[test]
fn foreign_launchers_refuse_before_any_publication_or_process() {
    for defect in ["contents", "mode", "symlink", "directory"] {
        let fixture = Fixture::new();
        fixture.existing("home", false);
        let path = fixture.files.path("/usr/local/bin/npx");
        match defect {
            "contents" => {
                fs::write(&path, "foreign").unwrap();
                fs::set_permissions(&path, fs::Permissions::from_mode(0o755)).unwrap();
            }
            "mode" => {
                fs::write(&path, launcher(&fixture.scope("home"), "npx")).unwrap();
            }
            "symlink" => symlink("/bin/true", &path).unwrap(),
            _ => fs::create_dir(&path).unwrap(),
        }
        let mut host = Verify::default();
        assert!(matches!(fixture.run(&mut host), Err(Error::Conflict {})));
        assert!(host.calls.is_empty());
        assert!(!fixture.files.path("/usr/local/bin/vp").exists());
    }
}
#[test]
fn failed_verification_restores_old_launchers_and_removes_new_ones() {
    for former in [true, false] {
        let fixture = Fixture::new();
        fixture.existing("home", former);
        let path = fixture.files.path("/usr/local/bin/vp");
        let before = fs::read(&path).ok();
        let before_time = fs::metadata(&path)
            .ok()
            .map(|meta| meta.modified().unwrap());
        let mut host = Verify {
            fail: true,
            ..Default::default()
        };
        assert!(matches!(fixture.run(&mut host), Err(Error::Failed {})));
        assert_eq!(fs::read(&path).ok(), before);
        assert_eq!(
            fs::metadata(path).ok().map(|meta| meta.modified().unwrap()),
            before_time
        );
        host.fail = false;
        assert!(fixture.run(&mut host).is_ok());
    }
}
#[test]
fn invalid_pins_and_conflicting_scopes_refuse_without_changes() {
    let mut fixture = Fixture::new();
    fixture.input.tools.remove("pnpm");
    let mut host = Verify::default();
    assert!(matches!(fixture.run(&mut host), Err(Error::Invalid {})));
    assert!(host.calls.is_empty());
    let fixture = Fixture::new();
    symlink(&fixture.account.home, fixture.scope("home")).unwrap();
    assert!(matches!(fixture.run(&mut host), Err(Error::Conflict {})));
    assert!(host.calls.is_empty());
}
#[test]
fn incomplete_existing_runtime_fails_before_publishing_launchers() {
    let fixture = Fixture::new();
    let scope = fixture.existing("home", false);
    fs::remove_file(scope.join("bin/pnpm")).unwrap();
    let mut host = Verify::default();
    assert!(matches!(fixture.run(&mut host), Err(Error::Failed {})));
    assert!(host.calls.is_empty());
    assert!(!fixture.files.path("/usr/local/bin/vp").exists());
}
#[test]
fn failed_restore_reports_a_distinct_refusal_and_preserves_the_foreign_path() {
    struct BrokenRestore(PathBuf);
    impl Host for BrokenRestore {
        fn execute(
            &mut self,
            _: &Invocation,
        ) -> std::result::Result<ProcessOutput, recipe::Failure> {
            fs::remove_file(&self.0).unwrap();
            symlink("/bin/true", &self.0).unwrap();
            Ok(ProcessOutput {
                code: 1,
                ..Default::default()
            })
        }
        fn wait(&mut self, _: Duration) -> std::result::Result<(), recipe::Failure> {
            unreachable!()
        }
    }
    let fixture = Fixture::new();
    fixture.existing("home", true);
    let path = fixture.files.path("/usr/local/bin/npx");
    assert!(matches!(
        fixture.run(&mut BrokenRestore(path.clone())),
        Err(Error::Restore {})
    ));
    assert_eq!(fs::read_link(path).unwrap(), Path::new("/bin/true"));
}
#[test]
#[ignore = "Downloads the pinned recipe into a temporary SPIKE scope"]
fn real_first_use_installs_pinned_scope_and_repeats() {
    let fixture = Fixture::new();
    let context = Context::new(Platform::Linux, Instant::now() + Duration::from_secs(900));
    let mut host = recipe::Native(&context);
    assert!(fixture.run(&mut host).unwrap().changed);
    assert!(!fixture.run(&mut host).unwrap().changed);
    assert!(fixture.scope("local").join("bin/pnpm").is_file());
}
