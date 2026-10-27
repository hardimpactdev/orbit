//! Real ACL and identity proofs. Run only on a disposable Linux host with sudo, acl and a caddy account.
#![cfg(target_os = "linux")]
use serde_json::{json, Value};
use std::{
    fs,
    os::unix::fs::{symlink, MetadataExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Child, Command, Output},
    time::{Duration, Instant},
};

fn run(program: &str, args: &[&str]) -> Output {
    Command::new(program).args(args).output().unwrap()
}
fn ok(program: &str, args: &[&str]) -> String {
    let output = run(program, args);
    assert!(
        output.status.success(),
        "{program} {args:?}: {}",
        String::from_utf8_lossy(&output.stderr)
    );
    String::from_utf8(output.stdout).unwrap()
}
fn text(path: &Path) -> &str {
    path.to_str().unwrap()
}
fn mode(path: &Path, bits: u32) {
    fs::set_permissions(path, fs::Permissions::from_mode(bits)).unwrap();
}
#[path = "support/privileged_agent.rs"]
mod privileged_agent;
#[path = "support/executable.rs"]
mod test_support;
use privileged_agent::Agent;

struct Fixture {
    root: PathBuf,
    agent: Agent,
    user: String,
}
impl Fixture {
    fn new() -> Self {
        assert_ne!(
            rustix::process::geteuid().as_raw(),
            0,
            "Run the test suite as the managed user."
        );
        let root = PathBuf::from(
            ok(
                "mktemp",
                &["-d", "-p", "/var/tmp", "orbit-caddy-native.XXXXXX"],
            )
            .trim(),
        );
        let fixture = Self {
            root,
            agent: Agent::new(),
            user: ok("id", &["-un"]).trim().into(),
        };
        fixture.directory("checkout/web/site", 0o755);
        mode(&fixture.path("checkout"), 0o700);
        mode(&fixture.path("checkout/web"), 0o700);
        fixture.git("checkout", &["init", "--quiet"]);
        for (name, body) in [
            ("web/site/index.html", "first page"),
            (".env", "SECRET=value"),
            ("source.txt", "private source"),
            ("web/private.txt", "private sibling"),
        ] {
            fixture.write(&format!("checkout/{name}"), body);
        }
        fixture
    }
    fn path(&self, name: &str) -> PathBuf {
        self.root.join(name)
    }
    fn write(&self, name: &str, body: &str) {
        fs::write(self.path(name), body).unwrap();
    }
    fn directory(&self, name: &str, bits: u32) {
        fs::create_dir_all(self.path(name)).unwrap();
        mode(&self.path(name), bits);
    }
    fn git(&self, name: &str, args: &[&str]) -> String {
        let mut all = vec![
            "-C",
            text(&self.root),
            "-c",
            "user.name=Test",
            "-c",
            "user.email=test@example.test",
        ];
        let path = self.path(name);
        all[1] = text(&path);
        all.extend(args);
        ok("git", &all)
    }
    fn site(&self, checkout: &str, web: &str) -> Value {
        json!({"checkout": self.path(checkout), "relative_root": web, "application": self.path(checkout)})
    }
    fn start(&self, sites: Vec<Value>, wrapper: Option<&str>) -> Child {
        let path = match wrapper {
            Some(script) => {
                self.directory("bin", 0o700);
                test_support::executable(self.path("bin/setfacl"), script);
                mode(&self.path("bin/setfacl"), 0o700);
                format!(
                    "{}:/usr/local/bin:/usr/bin:/bin",
                    self.path("bin").display()
                )
            }
            None => "/usr/local/bin:/usr/bin:/bin".into(),
        };
        self.agent.start(
            "instance.grant-development-caddy-access",
            json!({"user":self.user, "sites":sites}),
            &path,
        )
    }

    fn call(&self, sites: Vec<Value>, wrapper: Option<&str>) -> Value {
        result(self.start(sites, wrapper))
    }
    fn grant(&self, sites: Vec<Value>) -> Value {
        let result = self.call(sites, None);
        assert_eq!(result["ok"], true, "{result}");
        result["data"].clone()
    }
    fn read(&self, name: &str) -> Option<String> {
        let output = run(
            "sudo",
            &["-n", "-u", "caddy", "cat", text(&self.path(name))],
        );
        if output.status.success() {
            Some(String::from_utf8(output.stdout).unwrap())
        } else {
            assert!(
                String::from_utf8_lossy(&output.stderr).contains("Permission denied"),
                "{output:?}"
            );
            None
        }
    }
    fn acl(&self, names: &[&str]) -> String {
        let paths: Vec<_> = names.iter().map(|name| self.path(name)).collect();
        let mut args = vec!["-p"];
        args.extend(paths.iter().map(|path| text(path)));
        ok("getfacl", &args)
    }
    fn recursive_acl(&self, name: &str) -> String {
        ok("getfacl", &["-R", "-p", text(&self.path(name))])
    }
    fn commit(&self) {
        self.git("checkout", &["add", "."]);
        self.git("checkout", &["commit", "-qm", "initial"]);
    }
    fn storage(&self, relative: &str, bits: u32) -> Value {
        let app = format!("checkout{relative}");
        self.directory(&format!("{app}/public"), 0o755);
        self.directory(&format!("{app}/storage/app/public"), bits);
        self.write(
            &format!("{app}/storage/app/public/photo.txt"),
            "public photo",
        );
        self.write(&format!("{app}/storage/app/private.txt"), "private photo");
        symlink(
            "../storage/app/public",
            self.path(&format!("{app}/public/storage")),
        )
        .unwrap();
        json!({"checkout":self.path("checkout"), "relative_root":format!("{}/public",relative.trim_start_matches('/')).trim_start_matches('/'), "application":self.path(&app)})
    }
}
impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = run("sudo", &["-n", "rm", "-rf", "--", text(&self.root)]);
    }
}
fn result(child: Child) -> Value {
    let output = child.wait_with_output().unwrap();
    assert!(output.status.success(), "{output:?}");
    serde_json::from_slice(&output.stdout).unwrap_or_else(|error| panic!("{error}: {output:?}"))
}
fn refused(value: &Value, code: &str) {
    assert_eq!(value["ok"], false, "{value}");
    assert_eq!(value["error"]["code"], code, "{value}");
}
const FAIL_GRANT: &str = "#!/bin/bash\nfor argument in \"$@\"; do if [ \"$argument\" = u:caddy:r-X ]; then exit 1; fi; done\nexec /usr/bin/setfacl \"$@\"\n";

#[test]
#[ignore = "requires disposable Linux host, sudo, acl and caddy"]
fn nested_web_root_preserves_source_and_parent_modes_on_repeat() {
    let f = Fixture::new();
    let before = fs::metadata(&f.root).unwrap().mode();
    let source_mode = fs::metadata(f.path("checkout/.env")).unwrap().mode();
    for _ in 0..2 {
        f.grant(vec![f.site("checkout", "web/site")]);
    }
    assert_eq!(
        f.read("checkout/web/site/index.html").as_deref(),
        Some("first page")
    );
    for name in [".env", "source.txt", ".git/config", "web/private.txt"] {
        assert_eq!(f.read(&format!("checkout/{name}")), None);
    }
    f.write("checkout/web/site/next.html", "next page");
    assert_eq!(
        f.read("checkout/web/site/next.html").as_deref(),
        Some("next page")
    );
    assert_eq!(fs::metadata(&f.root).unwrap().mode() & 7, before & 7);
    assert_eq!(
        fs::metadata(f.path("checkout/.env")).unwrap().mode(),
        source_mode
    );
}

#[test]
#[ignore = "requires disposable Linux host, sudo, acl and caddy"]
fn selected_and_candidate_releases_keep_shared_git_private() {
    for selected in [false, true] {
        let f = Fixture::new();
        f.commit();
        f.git(
            "checkout",
            &["worktree", "add", "--detach", "releases/initial", "HEAD"],
        );
        f.directory("checkout/.git/orbit-development-releases", 0o700);
        f.write(
            "checkout/.git/orbit-development-releases/identity",
            "fixture",
        );
        f.write(
            "checkout/.git/orbit-development-releases/release-initial",
            "fixture:initial",
        );
        symlink("releases/initial", f.path("checkout/current")).unwrap();
        let checkout = if selected {
            "checkout/current"
        } else {
            "checkout/releases/initial"
        };
        let before = fs::metadata(f.path("checkout/.git")).unwrap().mode();
        for _ in 0..2 {
            f.grant(vec![f.site(checkout, "web/site")]);
        }
        assert_eq!(
            f.read("checkout/current/web/site/index.html").as_deref(),
            Some("first page")
        );
        for name in [
            ".git/config",
            ".git/worktrees/initial/HEAD",
            "current/.env",
            "current/source.txt",
        ] {
            assert_eq!(f.read(&format!("checkout/{name}")), None);
        }
        assert_eq!(
            fs::metadata(f.path("checkout/.git")).unwrap().mode(),
            before
        );
        assert!(!f
            .git("checkout/current", &["rev-parse", "--git-common-dir"])
            .is_empty());
    }
}

#[test]
#[ignore = "requires disposable Linux host, sudo, acl and caddy"]
fn unsafe_web_links_refuse_before_any_acl_change() {
    for root_link in [false, true] {
        let f = Fixture::new();
        f.directory("outside", 0o755);
        f.write("outside/private", "outside");
        if root_link {
            fs::remove_dir_all(f.path("checkout/web/site")).unwrap();
            symlink(f.path("outside"), f.path("checkout/web/site")).unwrap();
        } else {
            symlink(f.path("outside/private"), f.path("checkout/web/site/leak")).unwrap();
        }
        let before = f.acl(&["checkout", "outside"]);
        refused(
            &f.call(vec![f.site("checkout", "web/site")], None),
            "instance.caddy_access_invalid",
        );
        assert_eq!(f.acl(&["checkout", "outside"]), before);
    }
}

#[test]
#[ignore = "requires disposable Linux host, sudo, acl and caddy"]
fn only_laravel_public_storage_is_readable_in_root_and_nested_apps() {
    for relative in ["", "/server/web"] {
        let f = Fixture::new();
        let site = f.storage(relative, 0o755);
        f.grant(vec![site]);
        assert_eq!(
            f.read(&format!("checkout{relative}/public/storage/photo.txt"))
                .as_deref(),
            Some("public photo")
        );
        assert_eq!(
            f.read(&format!("checkout{relative}/storage/app/private.txt")),
            None
        );
    }
}

#[test]
#[ignore = "requires disposable Linux host, sudo, acl and caddy"]
fn nested_checkout_keeps_both_web_roots_readable() {
    let f = Fixture::new();
    f.directory("checkout/.worktrees/feature/public", 0o755);
    f.git("checkout/.worktrees/feature", &["init", "--quiet"]);
    f.write(
        "checkout/.worktrees/feature/public/index.html",
        "nested page",
    );
    f.write("checkout/.worktrees/feature/.env", "secret");
    f.grant(vec![
        f.site("checkout/.worktrees/feature", "public"),
        f.site("checkout", "web/site"),
    ]);
    assert_eq!(
        f.read("checkout/.worktrees/feature/public/index.html")
            .as_deref(),
        Some("nested page")
    );
    assert_eq!(
        f.read("checkout/web/site/index.html").as_deref(),
        Some("first page")
    );
    assert_eq!(f.read("checkout/.worktrees/feature/.env"), None);
}

#[test]
#[ignore = "requires disposable Linux host, sudo, acl and caddy"]
fn recovery_restores_grants_and_retains_private_snapshots_only_on_failure() {
    for linked in [false, true] {
        for fail_recovery in [false, true] {
            let f = Fixture::new();
            let checkout = if linked {
                f.commit();
                f.git(
                    "checkout",
                    &["worktree", "add", "--detach", "releases/initial", "HEAD"],
                );
                "checkout/releases/initial"
            } else {
                "checkout"
            };
            for name in [r"x\057y", r"back\\slash"] {
                f.write(&format!("{checkout}/web/site/{name}"), "escaped name");
            }
            let path = f.path(&format!("{checkout}/web"));
            let ancestors: Vec<_> = path
                .ancestors()
                .take_while(|path| *path != f.root.parent().unwrap())
                .map(|p| p.strip_prefix(&f.root).unwrap().to_str().unwrap())
                .collect();
            let snapshot = || {
                f.recursive_acl(&format!("{checkout}/web/site"))
                    + &f.acl(&ancestors)
                    + &f.acl(&["checkout/.git"])
            };
            let before = snapshot();
            let wrapper = if fail_recovery {
                FAIL_GRANT.replace(
                    "if [",
                    "if [ \"$argument\" = --restore ]; then exit 1; fi; if [",
                )
            } else {
                FAIL_GRANT.into()
            };
            let value = f.call(vec![f.site(checkout, "web/site")], Some(&wrapper));
            refused(
                &value,
                if fail_recovery {
                    "instance.caddy_access_recovery_failed"
                } else {
                    "instance.caddy_access_failed"
                },
            );
            assert!(!fs::read_dir(f.path("checkout/.git")).unwrap().any(|e| e
                .unwrap()
                .file_name()
                .to_string_lossy()
                .starts_with("orbit-caddy-acl.")));
            if fail_recovery {
                let retained = value["error"]["details"]["snapshots"].as_str().unwrap();
                assert!(retained.starts_with("/var/tmp/orbit-caddy-acl."));
                let metadata = fs::metadata(retained).unwrap();
                assert_eq!(metadata.uid(), rustix::process::geteuid().as_raw());
                assert_eq!(metadata.mode() & 0o777, 0o700);
                assert_eq!(fs::metadata(format!("{retained}/root")).unwrap().len(), 0);
                ok("setfacl", &[&format!("--restore={retained}/managed")]);
                fs::remove_dir_all(retained).unwrap();
            } else {
                assert!(value["error"]["details"]["snapshots"].is_null());
            }
            assert_eq!(snapshot(), before);
            for name in [".env", "source.txt", "web/private.txt"] {
                assert!(f
                    .acl(&[&format!("{checkout}/{name}")])
                    .contains("user:caddy:---"));
            }
        }
    }
}

#[test]
#[ignore = "requires disposable Linux host, sudo, acl and caddy"]
fn only_vanished_entries_are_harmless_during_a_walk() {
    for (error, success) in [
        ("No such file or directory", true),
        ("Operation not permitted", false),
    ] {
        let f = Fixture::new();
        let script = format!("#!/bin/bash\n/usr/bin/setfacl \"$@\" || exit\nfor argument in \"$@\"; do if [ \"$argument\" = u:caddy:r-X ]; then printf 'setfacl: gone.js: {error}\\n' >&2; exit 1; fi; done\n");
        let value = f.call(vec![f.site("checkout", "web/site")], Some(&script));
        assert_eq!(value["ok"], success, "{value}");
        if success {
            assert_eq!(
                f.read("checkout/web/site/index.html").as_deref(),
                Some("first page")
            );
            assert_eq!(f.read("checkout/source.txt"), None);
        } else {
            assert!(value["error"]["details"]["reason"]
                .as_str()
                .unwrap()
                .contains(error));
        }
    }
}

#[test]
#[ignore = "requires disposable Linux host, sudo, acl and caddy"]
fn foreign_owned_entries_fail_and_restore_grants() {
    for entry in ["foreign.txt", "web/site/foreign.html"] {
        let f = Fixture::new();
        let name = format!("checkout/{entry}");
        f.write(&name, "foreign");
        ok("sudo", &["-n", "chown", "root", text(&f.path(&name))]);
        let before_foreign = f.acl(&[&name]);
        let before =
            f.recursive_acl("checkout/web/site") + &f.acl(&["checkout/web", "checkout", ""]);
        let value = f.call(vec![f.site("checkout", "web/site")], None);
        refused(&value, "instance.caddy_access_failed");
        assert!(value["error"]["details"]["reason"]
            .as_str()
            .unwrap()
            .contains(&format!(
                "{}: Operation not permitted",
                f.path(&name).display()
            )));
        assert_eq!(f.acl(&[&name]), before_foreign);
        assert_eq!(
            f.recursive_acl("checkout/web/site") + &f.acl(&["checkout/web", "checkout", ""]),
            before
        );
    }
}

#[test]
#[ignore = "requires disposable Linux host, sudo, acl and caddy"]
fn acl_passage_and_private_modes_survive_repeated_grants() {
    let f = Fixture::new();
    for name in ["", "checkout", "checkout/web"] {
        mode(&f.path(name), 0o755);
    }
    mode(&f.path("checkout/.env"), 0o600);
    fs::rename(
        f.path("checkout/.env"),
        f.path("checkout/web/site/leak.txt"),
    )
    .unwrap();
    for _ in 0..2 {
        let data = f.grant(vec![f.site("checkout", "web/site")]);
        assert_eq!(
            data["unreadable"],
            json!([f.path("checkout/web/site/leak.txt")])
        );
        assert!(f
            .acl(&["checkout", "checkout/web"])
            .contains("user:caddy:--x"));
        assert_eq!(f.read("checkout/web/site/leak.txt"), None);
        assert_eq!(f.read("checkout/web/private.txt"), None);
        assert_eq!(
            f.read("checkout/web/site/index.html").as_deref(),
            Some("first page")
        );
        assert_eq!(
            fs::metadata(f.path("checkout/web/site/leak.txt"))
                .unwrap()
                .mode()
                & 0o777,
            0o600
        );
        assert!(f.acl(&["checkout/web/site/leak.txt"]).contains("mask::---"));
    }
}

#[test]
#[ignore = "requires disposable Linux host, sudo, acl and caddy"]
fn unreadable_web_root_and_storage_refuse_and_restore() {
    for storage in [false, true] {
        let f = Fixture::new();
        let site = if storage {
            f.storage("", 0o700)
        } else {
            mode(&f.path("checkout/web/site"), 0o700);
            f.site("checkout", "web/site")
        };
        let before = if storage {
            f.recursive_acl("checkout/public") + &f.recursive_acl("checkout/storage/app/public")
        } else {
            f.recursive_acl("checkout/web/site")
        };
        let value = f.call(vec![site], None);
        refused(&value, "instance.caddy_access_failed");
        assert!(value["error"]["details"]["reason"]
            .as_str()
            .unwrap()
            .contains(if storage {
                "Caddy cannot read the public storage"
            } else {
                "Caddy cannot read the Web root"
            }));
        assert_eq!(
            if storage {
                f.recursive_acl("checkout/public") + &f.recursive_acl("checkout/storage/app/public")
            } else {
                f.recursive_acl("checkout/web/site")
            },
            before
        );
    }
}

#[test]
#[ignore = "requires disposable Linux host, sudo, acl and caddy"]
fn reports_unreadable_files_in_both_web_root_and_public_storage() {
    let f = Fixture::new();
    let site = f.storage("", 0o755);
    f.write("checkout/public/index.html", "page");
    for name in [
        "checkout/public/secret.txt",
        "checkout/storage/app/public/hidden.txt",
    ] {
        f.write(name, "secret");
        mode(&f.path(name), 0o600);
    }
    let data = f.grant(vec![site]);
    let mut reported: Vec<String> = serde_json::from_value(data["unreadable"].clone()).unwrap();
    reported.sort();
    assert_eq!(
        reported,
        vec![
            text(&f.path("checkout/public/secret.txt")).to_owned(),
            text(&f.path("checkout/storage/app/public/hidden.txt")).to_owned()
        ]
    );
    assert_eq!(
        f.read("checkout/public/index.html").as_deref(),
        Some("page")
    );
}

#[test]
#[ignore = "requires disposable Linux host, sudo, acl and caddy"]
fn restores_root_owned_passage_after_failure_then_grants_it() {
    let f = Fixture::new();
    ok("sudo", &["-n", "chown", "root:root", text(&f.root)]);
    ok(
        "sudo",
        &[
            "-n",
            "setfacl",
            "-m",
            &format!("u:{}:rwx", f.user),
            text(&f.root),
        ],
    );
    let snapshot = || {
        f.recursive_acl("checkout/web/site")
            + &f.acl(&["checkout/web", "checkout", "", "checkout/.git"])
    };
    let before = snapshot();
    refused(
        &f.call(vec![f.site("checkout", "web/site")], Some(FAIL_GRANT)),
        "instance.caddy_access_failed",
    );
    assert_eq!(snapshot(), before);
    f.grant(vec![f.site("checkout", "web/site")]);
    assert_eq!(
        f.read("checkout/web/site/index.html").as_deref(),
        Some("first page")
    );
    assert!(f.acl(&[""]).contains("user:caddy:--x"));
    assert_eq!(fs::metadata(&f.root).unwrap().uid(), 0);
    assert_eq!(f.read("checkout/source.txt"), None);
}

struct Walking(Option<Child>);
impl Drop for Walking {
    fn drop(&mut self) {
        if let Some(mut child) = self.0.take() {
            let _ = run("sudo", &["-n", "kill", "-TERM", &child.id().to_string()]);
            let _ = child.wait();
        }
    }
}

#[test]
#[ignore = "requires disposable Linux host, sudo, acl and caddy"]
fn removed_and_replaced_entries_inherit_the_correct_acl_at_each_walk_boundary() {
    for attempt in 1..=5 {
        let f = Fixture::new();
        ok("mkfifo", &["-m", "600", text(&f.path("walk-continue"))]);
        let wrapper = format!(
            r#"#!/bin/bash
phase=
for argument in "$@"; do
    case "$argument" in d:u:caddy:---) phase=checkout ;; d:u:caddy:r-x) phase=web ;; esac
done
if [ -n "$phase" ]; then
    printf 'acl-walk:%s\n' "$phase" >> '{root}/walk-phases'
    IFS= read -r acknowledgement < '{root}/walk-continue'
    test "$acknowledgement" = continue || exit 1
fi
exec /usr/bin/setfacl "$@"
"#,
            root = f.root.display()
        );
        for directory in ["checkout/vendor", "checkout/web/site/build"] {
            for entry in ["vanished", "replaced"] {
                f.directory(&format!("{directory}/{entry}"), 0o755);
                f.write(&format!("{directory}/{entry}/file"), "old");
            }
        }
        let mut walk = Walking(Some(
            f.start(vec![f.site("checkout", "web/site")], Some(&wrapper)),
        ));
        for (phase, relative) in [("checkout", "vendor"), ("web", "web/site/build")] {
            let deadline = Instant::now() + Duration::from_secs(10);
            while !fs::read_to_string(f.path("walk-phases"))
                .unwrap_or_default()
                .contains(&format!("acl-walk:{phase}\n"))
            {
                assert!(Instant::now() < deadline, "walk never reached {phase}");
                assert!(
                    walk.0.as_mut().unwrap().try_wait().unwrap().is_none(),
                    "walk ended before {phase}"
                );
                std::thread::sleep(Duration::from_millis(10));
            }
            for name in ["vanished", "replaced"] {
                fs::remove_dir_all(f.path(&format!("checkout/{relative}/{name}"))).unwrap();
            }
            f.directory(&format!("checkout/{relative}/replaced"), 0o755);
            f.write(
                &format!("checkout/{relative}/replaced/file"),
                &format!("replacement {attempt}"),
            );
            fs::write(f.path("walk-continue"), "continue\n").unwrap();
        }
        let value = result(walk.0.take().unwrap());
        assert_eq!(value["ok"], true, "{value}");
        f.directory("checkout/web/site/build/late", 0o755);
        f.write("checkout/web/site/build/late/app.js", "late asset");
        assert_eq!(
            f.read("checkout/web/site/build/replaced/file"),
            Some(format!("replacement {attempt}"))
        );
        assert_eq!(f.read("checkout/vendor/replaced/file"), None);
        assert_eq!(
            f.read("checkout/web/site/build/late/app.js").as_deref(),
            Some("late asset")
        );
        assert_eq!(
            f.read("checkout/web/site/index.html").as_deref(),
            Some("first page")
        );
        for name in [".env", "source.txt", "web/private.txt"] {
            assert_eq!(f.read(&format!("checkout/{name}")), None);
        }
        f.directory("checkout/vendor/late", 0o700);
        f.write("checkout/vendor/late/new.php", "new source");
        assert!(f
            .acl(&["checkout/vendor/late/new.php"])
            .contains("user:caddy:---"));
    }
}
