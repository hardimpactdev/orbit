use std::cell::RefCell;
use std::collections::BTreeMap;
use std::fs;
use std::os::unix::ffi::OsStrExt as _;
use std::os::unix::fs::{MetadataExt as _, PermissionsExt as _};
use std::path::Path;

use serde_json::json;

use super::*;

/// What a command answers: its exit code, `None` for a signal, and its output bytes.
type Answer = (Option<i32>, Vec<u8>);

/// A scratch root: every user and group is the test's own, blobs live in a map, and commands are recorded and answered
/// by the first rule whose program they run.
struct Fake {
    root: tempfile::TempDir,
    blobs: BTreeMap<String, Vec<u8>>,
    fail: RefCell<Vec<&'static str>>,
    answers: RefCell<Vec<(&'static str, String)>>,
    ran: RefCell<Vec<Vec<String>>>,
    /// The `set_xattr` call that fails, counted from 1.
    fail_set: std::cell::Cell<Option<usize>>,
    /// The `set_xattr` call after which the next attribute read fails, counted from 1.
    fail_read_after: std::cell::Cell<Option<usize>>,
    sets: std::cell::Cell<usize>,
    fail_next_read: std::cell::Cell<bool>,
    /// The test unit: enabled, running; `None` when systemd cannot be read.
    unit: RefCell<Option<(bool, bool)>>,
    /// An `ActiveState` systemd reports instead, such as a unit stuck `activating`.
    transition: RefCell<Option<&'static str>>,
    /// A program whose run stops the action, as a crash would.
    stop: RefCell<Option<&'static str>>,
    /// A program after whose run a folder turns read-only, so the next write in it fails.
    seal: RefCell<Option<(&'static str, std::path::PathBuf)>>,
    /// A destination and a file that it becomes a hard link to on the next user lookup, which a primitive makes between
    /// reading its input and changing the destination.
    relink: RefCell<Option<(std::path::PathBuf, std::path::PathBuf)>>,
    /// What `getent -- <database> <key>` answers, by `<database> <key>`: its exit code and output bytes. A key it does not hold
    /// exits 2.
    getent: RefCell<BTreeMap<String, Answer>>,
}

impl Fake {
    fn new() -> Self {
        Self { root: tempfile::tempdir().expect("a scratch root"), blobs: BTreeMap::new(), fail: RefCell::default(), answers: RefCell::default(), ran: RefCell::default(), fail_set: std::cell::Cell::default(), fail_read_after: std::cell::Cell::default(), sets: std::cell::Cell::default(), fail_next_read: std::cell::Cell::default(), unit: RefCell::new(Some((true, true))), transition: RefCell::default(), stop: RefCell::default(), seal: RefCell::default(), relink: RefCell::default(), getent: RefCell::default() }
    }

    fn put(&mut self, content: &[u8]) -> serde_json::Value {
        let digest = sha256(content);
        self.blobs.insert(digest.clone(), content.to_vec());
        json!({"blob": {"sha256": digest}})
    }

    fn write(&self, path: &str, content: &[u8]) {
        let dest = self.path(path);
        fs::create_dir_all(dest.parent().expect("a parent")).expect("parent folder");
        fs::write(&dest, content).expect("write");
        fs::set_permissions(&dest, fs::Permissions::from_mode(0o640)).expect("mode");
    }

    fn read(&self, path: &str) -> Vec<u8> {
        fs::read(self.path(path)).expect("read")
    }

    /// Turns the sealed folder read-only once its program runs.
    fn seal_after(&self, argv: &[String]) {
        if let Some((_, folder)) = self.seal.borrow().as_ref().filter(|(program, _)| argv.iter().any(|arg| arg == program)) {
            fs::set_permissions(folder, fs::Permissions::from_mode(0o500)).expect("seal");
        }
    }

    fn ran(&self, program: &str) -> usize {
        self.ran.borrow().iter().filter(|argv| argv.iter().any(|arg| arg == program)).count()
    }
}

/// The test's own user and group: it owns its scratch files.
fn me() -> (u32, u32) {
    let own = tempfile::tempfile().expect("a file").metadata().expect("meta");
    (own.uid(), own.gid())
}

impl System for Fake {
    fn root(&self) -> &Path {
        self.root.path()
    }

    #[expect(clippy::panic_in_result_fn, reason = "a scripted stop stands for a crash of the executor")]
    fn run(&self, _seconds: u32, argv: &[String]) -> Result<String, String> {
        self.ran.borrow_mut().push(argv.to_vec());
        assert!(!self.stop.borrow().is_some_and(|program| argv.iter().any(|arg| arg == program)), "the executor stopped");
        self.seal_after(argv);
        if self.fail.borrow().iter().any(|program| argv.iter().any(|arg| arg == program)) {
            return Err("scripted failure".to_owned());
        }
        if let Some((_, out)) = self.answers.borrow().iter().find(|(program, _)| argv.iter().any(|arg| arg == program)) {
            return Ok(out.clone());
        }
        let words: Vec<&str> = argv.iter().map(String::as_str).collect();
        match words.iter().find(|arg| arg.starts_with('/') && (arg.ends_with("systemctl") || arg.ends_with("update-ca-certificates") || arg.ends_with("openssl"))) {
            Some(&"/usr/bin/systemctl") => {
                let mut unit = self.unit.borrow_mut();
                let Some((enabled, running)) = unit.as_mut() else { return Err("Failed to connect to bus".to_owned()) };
                let active = self.transition.borrow().unwrap_or(if *running { "active" } else { "inactive" });
                match words[1] {
                    "show" => Ok(format!("LoadState=loaded\nActiveState={active}\nUnitFileState={}\n", if *enabled { "enabled" } else { "disabled" })),
                    "enable" => { *enabled = true; Ok(String::new()) }
                    "disable" => { *enabled = false; Ok(String::new()) }
                    "start" => { *running = true; Ok(String::new()) }
                    "stop" => { *running = false; Ok(String::new()) }
                    _ => Ok(String::new()),
                }
            }
            Some(&"/usr/bin/openssl") => {
                let file = words.last().expect("a file");
                let file = if file.starts_with(&*self.root().to_string_lossy()) { Path::new(file).to_path_buf() } else { self.path(file) };
                // `verify -CAfile <bundle> <file>`: trusted when the bundle holds the file's certificate.
                if words.contains(&"verify") {
                    let squash = |bytes: Vec<u8>| String::from_utf8_lossy(&bytes).split_whitespace().collect::<String>().replace("-----BEGINCERTIFICATE-----", "").replace("-----ENDCERTIFICATE-----", "");
                    let held = squash(fs::read(&file).unwrap_or_default());
                    let named = words[words.iter().position(|arg| *arg == "-CAfile").expect("-CAfile") + 1];
                    let bundle = squash(fs::read(named).unwrap_or_default());
                    return if !held.is_empty() && bundle.contains(&held) { Ok(format!("{}: OK\n", file.display())) } else { Err(format!("error 20 at 0 depth lookup: unable to get local issuer certificate\nerror {}: verification failed", file.display())) };
                }
                match fs::read(file) {
                    Ok(bytes) if bytes.starts_with(b"-----BEGIN CERTIFICATE-----") => Ok(format!("{}\n", &sha256(&bytes)[..8])),
                    _ => Err("unable to load certificate".to_owned()),
                }
            }
            Some(&"/usr/sbin/update-ca-certificates") => {
                // Name links `<stem>.pem`, subject-hash links `<hash>.0` (the fake hash is the content's), and the bundle built
                // from every name link. `--fresh` drops every link first.
                let store = self.path("/usr/local/share/ca-certificates");
                let links = self.path("/etc/ssl/certs");
                fs::create_dir_all(&links).expect("links");
                for link in fs::read_dir(&links).expect("links") {
                    let link = link.expect("link").path();
                    let is_link = fs::symlink_metadata(&link).is_ok_and(|meta| meta.file_type().is_symlink());
                    if is_link && (words.contains(&"--fresh") || fs::metadata(&link).is_err()) { fs::remove_file(&link).expect("stale link"); }
                }
                let mut bundle = Vec::new();
                let mut certs: Vec<_> = fs::read_dir(&store).expect("store").map(|cert| cert.expect("cert").path()).collect();
                certs.sort();
                for cert in certs {
                    let name = cert.file_name().expect("name").to_string_lossy().into_owned();
                    if let Some(stem) = name.strip_suffix(".crt").filter(|stem| !stem.starts_with('.')) {
                        let bytes = fs::read(&cert).expect("cert");
                        let link = links.join(format!("{stem}.pem"));
                        if fs::symlink_metadata(&link).is_err() { std::os::unix::fs::symlink(&cert, &link).expect("link"); }
                        let hashed = links.join(format!("{}.0", &sha256(&bytes)[..8]));
                        if fs::symlink_metadata(&hashed).is_err() { std::os::unix::fs::symlink(&link, &hashed).expect("hash link"); }
                        bundle.extend_from_slice(&bytes);
                    }
                }
                fs::write(links.join("ca-certificates.crt"), bundle).expect("bundle");
                Ok(String::new())
            }
            _ => Ok(String::new()),
        }
    }

    fn user(&self, name: &str) -> Result<u32, String> {
        if let Some((dest, source)) = self.relink.borrow_mut().take() {
            fs::remove_file(&dest).expect("remove the destination");
            fs::hard_link(source, dest).expect("relink");
        }
        Ok(match name {
            "ghost" => return Err("no user ghost".to_owned()),
            "web" => WEB,
            "other" => OTHER_USER,
            _ => me().0,
        })
    }

    fn group(&self, name: &str) -> Result<u32, String> {
        match name {
            "ghost" => Err("no group ghost".to_owned()),
            "team" => Ok(TEAM),
            _ => Ok(me().1),
        }
    }

    fn xattr(&self, fd: std::os::fd::BorrowedFd<'_>, name: &str) -> std::io::Result<Option<Vec<u8>>> {
        if self.fail_next_read.replace(false) {
            return Err(std::io::Error::other("scripted read failure"));
        }
        acl::read_xattr(fd, name)
    }

    fn set_xattr(&self, fd: std::os::fd::BorrowedFd<'_>, name: &str, value: Option<&[u8]>) -> std::io::Result<()> {
        self.sets.set(self.sets.get() + 1);
        if self.fail_set.get() == Some(self.sets.get()) {
            return Err(std::io::Error::other("scripted write failure"));
        }
        acl::write_xattr(fd, name, value)?;
        if self.fail_read_after.get() == Some(self.sets.get()) {
            self.fail_next_read.set(true);
        }
        Ok(())
    }

    fn status(&self, _seconds: u32, argv: &[String]) -> Result<(Option<i32>, Vec<u8>), String> {
        self.ran.borrow_mut().push(argv.to_vec());
        let [program, dashes, database, key] = argv else { return Err("not a getent call".to_owned()) };
        if (program.as_str(), dashes.as_str()) != ("/usr/bin/getent", "--") {
            return Err("not a fixed getent argv".to_owned());
        }
        Ok(self.getent.borrow().get(&format!("{database} {key}")).cloned().unwrap_or((Some(2), Vec::new())))
    }

    fn run_clean(&self, limit: std::time::Duration, argv: &[String]) -> Result<String, String> {
        self.run(u32::try_from(limit.as_secs()).unwrap_or(u32::MAX), argv)
    }

    fn blob(&self, digest: &str) -> Result<Vec<u8>, String> {
        self.blobs.get(digest).cloned().ok_or_else(|| "missing".to_owned())
    }
}

fn owner() -> serde_json::Value {
    json!({"user": "someone", "group": "someone", "mode": "0644"})
}

fn apply(fake: &mut Fake, desired: &[u8], from: &[u8], validators: &serde_json::Value) -> Result<u32, Failure> {
    let content = fake.put(desired);
    let input = json!({
        "lock": "/tmp-lock/given.lock", "recovery_dir": "/recovery",
        "files": [{"path": "/conf/site.conf", "content": content, "from_sha256": sha256(from), "owner": owner()}],
        "validators": validators,
        "units": [{"unit": "web.service", "load": "reload"}],
    });
    config_apply(fake, &input)
}

fn lock_folder(fake: &Fake) {
    fs::create_dir_all(fake.path("/tmp-lock")).expect("lock folder");
}

#[test]
fn config_apply_writes_validates_and_reloads_then_repeats_without_change() {
    let mut fake = Fake::new();
    lock_folder(&fake);
    fake.write("/conf/site.conf", b"old\n");
    let validators = json!([{"kind": "caddy", "config": "/conf/site.conf", "user": "web"}]);
    assert_eq!(apply(&mut fake, b"new\n", b"old\n", &validators), Ok(1));
    assert_eq!(fake.read("/conf/site.conf"), b"new\n");
    assert_eq!(fs::metadata(fake.path("/conf/site.conf")).expect("meta").mode() & 0o7777, 0o644);
    assert_eq!(fake.ran("validate"), 1);
    assert_eq!(fake.ran("reload"), 1);
    // A repeat finds the desired bytes, changes nothing, and still validates and reloads.
    assert_eq!(apply(&mut fake, b"new\n", b"old\n", &validators), Ok(0));
    assert_eq!(fake.ran("reload"), 2);
    // The lock file is the one the input names.
    assert!(fake.path("/tmp-lock/given.lock").exists());
}

#[test]
fn config_apply_refuses_a_file_that_holds_neither_digest() {
    let mut fake = Fake::new();
    lock_folder(&fake);
    fake.write("/conf/site.conf", b"changed by someone else\n");
    let failure = apply(&mut fake, b"new\n", b"old\n", &json!([])).expect_err("a conflict");
    assert_eq!(failure.code, "owned_revision_conflict");
    assert_eq!(fake.read("/conf/site.conf"), b"changed by someone else\n");
    assert_eq!(fake.ran("reload"), 0);
}

#[test]
fn config_apply_puts_back_the_exact_prior_bytes_when_the_validator_fails() {
    let mut fake = Fake::new();
    lock_folder(&fake);
    fake.write("/conf/site.conf", b"old\n");
    fake.fail.borrow_mut().push("/usr/sbin/php-fpm8.4");
    let failure = apply(&mut fake, b"new\n", b"old\n", &json!([{"kind": "php-fpm", "version": "8.4", "fpm_config": "/conf/fpm.conf", "ini_scan_dir": ["/a", "/b"]}])).expect_err("a failed validation");
    assert_eq!(failure.code, "config.validate_failed");
    assert_eq!(fake.read("/conf/site.conf"), b"old\n");
    assert_eq!(fs::metadata(fake.path("/conf/site.conf")).expect("meta").mode() & 0o7777, 0o640);
    assert_eq!(fake.ran("reload"), 0);
    let argv = fake.ran.borrow().iter().find(|argv| argv.iter().any(|arg| arg == "/usr/sbin/php-fpm8.4")).cloned().expect("the validator ran");
    assert_eq!(argv, strings(&["/usr/bin/env", "PHP_INI_SCAN_DIR=/a:/b", "/usr/sbin/php-fpm8.4", "-t", "--fpm-config", "/conf/fpm.conf"]));
}

#[test]
fn config_apply_rolls_back_and_reloads_the_old_file_when_the_reload_fails() {
    let mut fake = Fake::new();
    lock_folder(&fake);
    fake.write("/conf/site.conf", b"old\n");
    fake.fail.borrow_mut().push("web.service");
    let failure = apply(&mut fake, b"new\n", b"old\n", &json!([{"kind": "dnsmasq", "conf_file": "/conf/dnsmasq.conf", "conf_dir": "/conf/dnsmasq.d", "skip_suffixes": [".dpkg-dist"]}])).expect_err("a failed reload");
    // The unit fails its recovery reload too, and the failure says so.
    assert_eq!(failure.code, "config.restore_failed");
    // The old bytes are back, and the unit loads them again.
    assert_eq!(fake.read("/conf/site.conf"), b"old\n");
    assert_eq!(fake.ran("web.service"), 2);
    assert_eq!(fake.ran("/usr/sbin/dnsmasq"), 1);
    assert!(fake.ran.borrow().iter().any(|argv| argv == &strings(&["/usr/sbin/dnsmasq", "--test", "--conf-file=/conf/dnsmasq.conf", "--conf-dir=/conf/dnsmasq.d,.dpkg-dist"])), "dnsmasq checks the configuration the service loads");
}

#[test]
fn config_apply_refuses_a_validator_outside_the_list_and_a_relative_lock() {
    let mut fake = Fake::new();
    lock_folder(&fake);
    fake.write("/conf/site.conf", b"old\n");
    for validator in [json!({"kind": "sh", "command": "true"}), json!({"kind": "caddy", "config": "/x", "user": "web", "argv": ["x"]})] {
        let failure = apply(&mut fake, b"new\n", b"old\n", &json!([validator])).expect_err("an unknown validator");
        assert_eq!(failure.code, "host.input_invalid");
    }
    let content = fake.put(b"new\n");
    let relative = json!({"lock": "given.lock", "recovery_dir": "/recovery", "files": [{"path": "/conf/site.conf", "content": content, "from_sha256": null, "owner": owner()}]});
    assert_eq!(config_apply(&fake, &relative).expect_err("a relative lock").code, "host.input_invalid");
    assert_eq!(fake.read("/conf/site.conf"), b"old\n");
    assert!(fake.ran.borrow().is_empty());
}

#[test]
fn config_apply_waits_on_the_lock_the_input_names() {
    let mut fake = Fake::new();
    lock_folder(&fake);
    let held = fs::File::create(fake.path("/tmp-lock/given.lock")).expect("lock file");
    held.lock().expect("held by another writer");
    fs::create_dir_all(fake.path("/conf")).expect("conf folder");
    let content = fake.put(b"new\n");
    let input = json!({"lock": "/tmp-lock/given.lock", "recovery_dir": "/recovery", "files": [{"path": "/conf/site.conf", "content": content, "from_sha256": null, "owner": owner()}]});
    // The fake's clock does not wait: the real budget elapses through sleep; take a short lock wait through the helper.
    let failure = lock::exclusive(&fake, "/tmp-lock/given.lock", std::time::Duration::from_millis(300)).expect_err("busy");
    assert_eq!(failure.code, "host.lock_busy");
    drop(held);
    assert_eq!(config_apply(&fake, &input), Ok(1));
}

#[test]
fn file_ensure_places_a_file_and_repeats_without_change() {
    let mut fake = Fake::new();
    let content = fake.put(b"hello\n");
    fs::create_dir_all(fake.path("/srv")).expect("folder");
    let input = json!({"state": "file", "path": "/srv/a.txt", "content": content, "owner": owner()});
    assert_eq!(file_ensure(&fake, &input), Ok(1));
    assert_eq!(file_ensure(&fake, &input), Ok(0));
    assert_eq!(fake.read("/srv/a.txt"), b"hello\n");
}

#[test]
fn file_ensure_refuses_a_guarded_file_that_changed() {
    let mut fake = Fake::new();
    fake.write("/srv/a.txt", b"someone else\n");
    let content = fake.put(b"hello\n");
    let input = json!({"state": "file", "path": "/srv/a.txt", "content": content, "owner": owner(), "from_sha256": sha256(b"old\n")});
    assert_eq!(file_ensure(&fake, &input).expect_err("conflict").code, "owned_revision_conflict");
    assert_eq!(fake.read("/srv/a.txt"), b"someone else\n");
}

#[test]
fn file_ensure_converges_links_folders_and_absence() {
    let fake = Fake::new();
    let folder = json!({"state": "directory", "path": "/srv/d/e", "owner": {"user": "u", "group": "g", "mode": "0750"}});
    assert!(file_ensure(&fake, &folder).expect("folder") >= 1);
    assert_eq!(file_ensure(&fake, &folder), Ok(0));
    let link = json!({"state": "link", "path": "/srv/d/current", "target": "e", "user": "u", "group": "g"});
    assert_eq!(file_ensure(&fake, &link), Ok(1));
    assert_eq!(file_ensure(&fake, &link), Ok(0));
    let absent = json!({"state": "absent", "path": "/srv/d", "recursive": true});
    assert_eq!(file_ensure(&fake, &absent), Ok(1));
    assert_eq!(file_ensure(&fake, &absent), Ok(0));
    assert_eq!(file_ensure(&fake, &json!({"state": "absent", "path": "/srv/../etc"})).expect_err("dots").code, "host.input_invalid");
}

fn copy(source: &str, dest: &str, mode: &str) -> serde_json::Value {
    json!({"state": "file", "path": dest, "source_path": source, "owner": {"user": "someone", "group": "someone", "mode": mode}})
}

fn mode_of(fake: &Fake, path: &str) -> u32 {
    fs::symlink_metadata(fake.path(path)).expect("meta").mode() & 0o7777
}

#[test]
fn file_ensure_copies_a_source_path_and_repeats_without_change() {
    let fake = Fake::new();
    fake.write("/src/leaf.key", b"key\n");
    fs::set_permissions(fake.path("/src/leaf.key"), fs::Permissions::from_mode(0o600)).expect("mode");
    fs::create_dir_all(fake.path("/dest")).expect("folder");
    let mut input = copy("/src/leaf.key", "/dest/leaf.key", "0600");
    input["source_sha256"] = json!(sha256(b"key\n"));
    assert_eq!(file_ensure(&fake, &input), Ok(1));
    assert_eq!(file_ensure(&fake, &input), Ok(0));
    assert_eq!(fake.read("/dest/leaf.key"), b"key\n");
    assert_eq!(mode_of(&fake, "/dest/leaf.key"), 0o600);
    assert_eq!(fake.read("/src/leaf.key"), b"key\n", "the source is only read");
    assert_eq!(mode_of(&fake, "/src/leaf.key"), 0o600);
}

#[test]
fn file_ensure_from_a_source_repairs_only_the_mode_of_the_same_content() {
    let fake = Fake::new();
    fake.write("/src/leaf.key", b"key\n");
    fake.write("/dest/leaf.key", b"key\n");
    let before = fs::metadata(fake.path("/dest/leaf.key")).expect("meta").ino();
    assert_eq!(file_ensure(&fake, &copy("/src/leaf.key", "/dest/leaf.key", "0600")), Ok(1));
    assert_eq!(mode_of(&fake, "/dest/leaf.key"), 0o600);
    assert_eq!(fs::metadata(fake.path("/dest/leaf.key")).expect("meta").ino(), before, "the same content is not written again");
    assert_eq!(file_ensure(&fake, &copy("/src/leaf.key", "/dest/leaf.key", "0600")), Ok(0));
}

#[test]
fn file_ensure_refuses_a_source_with_another_digest_before_writing() {
    let fake = Fake::new();
    fake.write("/src/leaf.key", b"other\n");
    fs::create_dir_all(fake.path("/dest")).expect("folder");
    let mut input = copy("/src/leaf.key", "/dest/leaf.key", "0600");
    input["source_sha256"] = json!(sha256(b"key\n"));
    assert_eq!(file_ensure(&fake, &input).expect_err("mismatch").code, "file.source_mismatch");
    assert!(!fake.path("/dest/leaf.key").exists());
    input["source_sha256"] = json!("../../etc/shadow");
    assert_eq!(file_ensure(&fake, &input).expect_err("no digest").code, "host.input_invalid");
}

#[test]
fn file_ensure_refuses_a_source_that_is_a_link_missing_or_a_folder() {
    let fake = Fake::new();
    fake.write("/src/leaf.key", b"key\n");
    fs::create_dir_all(fake.path("/dest")).expect("folder");
    std::os::unix::fs::symlink(fake.path("/src/leaf.key"), fake.path("/src/link.key")).expect("link");
    fs::create_dir_all(fake.path("/src/folder")).expect("folder");
    for (source, code) in [("/src/link.key", "file.source_link"), ("/src/gone.key", "file.source_missing"), ("/src/folder", "file.source_not_file"), ("/src/./leaf.key", "host.input_invalid"), ("src/leaf.key", "host.input_invalid")] {
        assert_eq!(file_ensure(&fake, &copy(source, "/dest/leaf.key", "0600")).expect_err(source).code, code, "{source}");
        assert!(!fake.path("/dest/leaf.key").exists(), "{source}");
    }
    // A source behind a folder the executor cannot enter is unreadable, never missing.
    fs::set_permissions(fake.path("/src"), fs::Permissions::from_mode(0o000)).expect("seal");
    let unreadable = file_ensure(&fake, &copy("/src/leaf.key", "/dest/leaf.key", "0600"));
    fs::set_permissions(fake.path("/src"), fs::Permissions::from_mode(0o755)).expect("unseal");
    // Root enters any folder; the tests run as a normal user (AGENTS.md).
    if me().0 != 0 {
        assert_eq!(unreadable.expect_err("unreadable").code, "file.ensure_failed");
    }
}

#[test]
fn file_ensure_refuses_a_source_that_is_the_destination() {
    let fake = Fake::new();
    fake.write("/dest/leaf.key", b"key\n");
    fs::hard_link(fake.path("/dest/leaf.key"), fake.path("/dest/hard.key")).expect("hard link");
    for source in ["/dest/leaf.key", "/dest/hard.key"] {
        assert_eq!(file_ensure(&fake, &copy(source, "/dest/leaf.key", "0600")).expect_err(source).code, "file.source_alias", "{source}");
    }
    assert_eq!(mode_of(&fake, "/dest/leaf.key"), 0o640, "nothing changed");
}

#[test]
fn file_ensure_refuses_a_destination_that_becomes_the_source_after_the_source_is_read() {
    // Review counterexample: the destination turns into a hard link to the source between the source's read and the
    // destination's open. With owner or mode drift the source itself was repaired; without, the copy reported 0 changes.
    for (source_mode, case) in [(0o644, "mode drift"), (0o600, "no drift")] {
        let fake = Fake::new();
        fake.write("/src/leaf.key", b"key\n");
        fs::set_permissions(fake.path("/src/leaf.key"), fs::Permissions::from_mode(source_mode)).expect("mode");
        fake.write("/dest/leaf.key", b"other\n");
        *fake.relink.borrow_mut() = Some((fake.path("/dest/leaf.key"), fake.path("/src/leaf.key")));
        assert_eq!(file_ensure(&fake, &copy("/src/leaf.key", "/dest/leaf.key", "0600")).expect_err(case).code, "file.source_alias", "{case}");
        assert!(fake.relink.borrow().is_none(), "{case}: the destination changed between the lookups");
        assert_eq!(mode_of(&fake, "/src/leaf.key"), source_mode, "{case}: the source is never changed");
        assert_eq!(fake.read("/src/leaf.key"), b"key\n", "{case}");
    }
}

#[test]
fn file_ensure_from_a_source_replaces_a_destination_link_and_keeps_the_guard() {
    let fake = Fake::new();
    fake.write("/src/leaf.key", b"key\n");
    fake.write("/elsewhere/target", b"keep\n");
    fs::create_dir_all(fake.path("/dest")).expect("folder");
    std::os::unix::fs::symlink(fake.path("/elsewhere/target"), fake.path("/dest/leaf.key")).expect("link");
    let mut guarded = copy("/src/leaf.key", "/dest/leaf.key", "0600");
    guarded["from_sha256"] = json!(sha256(b"keep\n"));
    assert_eq!(file_ensure(&fake, &guarded).expect_err("a link").code, "file.ensure_failed");
    assert!(fs::symlink_metadata(fake.path("/dest/leaf.key")).expect("link").file_type().is_symlink());
    assert_eq!(file_ensure(&fake, &copy("/src/leaf.key", "/dest/leaf.key", "0600")), Ok(1));
    assert!(fs::symlink_metadata(fake.path("/dest/leaf.key")).expect("file").file_type().is_file());
    assert_eq!(fake.read("/dest/leaf.key"), b"key\n");
    assert_eq!(fake.read("/elsewhere/target"), b"keep\n", "the file behind the link is never written");
    assert_eq!(mode_of(&fake, "/elsewhere/target"), 0o640);
    fake.write("/dest/leaf.key", b"someone else\n");
    assert_eq!(file_ensure(&fake, &guarded).expect_err("conflict").code, "owned_revision_conflict");
    assert_eq!(fake.read("/dest/leaf.key"), b"someone else\n");
}

#[test]
fn file_ensure_takes_exactly_one_of_content_and_source_path() {
    let mut fake = Fake::new();
    fake.write("/src/leaf.key", b"key\n");
    fs::create_dir_all(fake.path("/dest")).expect("folder");
    let content = fake.put(b"key\n");
    let mut both = copy("/src/leaf.key", "/dest/leaf.key", "0600");
    both["content"] = content.clone();
    let neither = json!({"state": "file", "path": "/dest/leaf.key", "owner": owner()});
    let digest_on_blob = json!({"state": "file", "path": "/dest/leaf.key", "content": content, "source_sha256": sha256(b"key\n"), "owner": owner()});
    for input in [both, neither, digest_on_blob] {
        assert_eq!(file_ensure(&fake, &input).expect_err("one source").code, "host.input_invalid", "{input}");
    }
    assert!(!fake.path("/dest/leaf.key").exists());
}

#[test]
fn dotenv_ensure_sets_the_last_key_and_appends_a_missing_one() {
    let fake = Fake::new();
    fake.write("/app/.env", b"A=1\nAPP_URL=old\nAPP_URL=older\n");
    let input = json!({"path": "/app/.env", "user": "u", "values": [{"key": "APP_URL", "value": "https://x.test"}, {"key": "NEW", "value": "y"}]});
    assert_eq!(dotenv_ensure(&fake, &input), Ok(1));
    assert_eq!(fake.read("/app/.env"), b"A=1\nAPP_URL=old\nAPP_URL=https://x.test\nNEW=y\n");
    assert_eq!(fs::metadata(fake.path("/app/.env")).expect("meta").mode() & 0o7777, 0o640);
    assert_eq!(dotenv_ensure(&fake, &input), Ok(0));
    let missing = json!({"path": "/app/none.env", "user": "u", "values": [{"key": "A", "value": "1"}]});
    assert_eq!(dotenv_ensure(&fake, &missing).expect_err("missing").code, "dotenv.ensure_failed");
}

#[test]
fn unit_ensure_changes_only_what_differs() {
    let fake = Fake::new();
    let input = json!({"unit": "web.service", "enabled": true, "state": "running", "budget_ms": 1000});
    assert_eq!(unit_ensure(&fake, &input), Ok(0));
    assert_eq!(fake.ran("start") + fake.ran("enable"), 0);
    assert_eq!(unit_ensure(&fake, &json!({"unit": "web.service", "enabled": false, "state": "stopped", "budget_ms": 1000})), Ok(2));
    assert_eq!(unit_ensure(&fake, &json!({"unit": "--now.service", "enabled": true, "state": "running", "budget_ms": 1000})).expect_err("flag").code, "host.input_invalid");
}

#[test]
fn unit_ensure_fails_when_systemd_cannot_be_read() {
    let fake = Fake::new();
    *fake.unit.borrow_mut() = None;
    // An unread unit is no stopped unit (review counterexample: a read error passed as disabled and stopped).
    assert_eq!(unit_ensure(&fake, &json!({"unit": "web.service", "enabled": false, "state": "stopped", "budget_ms": 1000})).expect_err("unread").code, "unit.ensure_failed");
}

#[test]
fn trust_ensure_rebuilds_the_bundle_only_on_a_change() {
    let mut fake = Fake::new();
    fs::create_dir_all(fake.path("/usr/local/share/ca-certificates")).expect("store");
    let certificate = fake.put(b"-----BEGIN CERTIFICATE-----\nx\n-----END CERTIFICATE-----\n");
    let input = json!({"state": "present", "name": "a-root.crt", "certificate": certificate, "owner": {"user": "u", "group": "g", "mode": "0640"}});
    assert_eq!(trust_ensure(&fake, &input), Ok(1));
    assert_eq!(trust_ensure(&fake, &input), Ok(0));
    assert_eq!(fake.ran("/usr/sbin/update-ca-certificates"), 1);
    assert_eq!(fs::metadata(fake.path("/usr/local/share/ca-certificates/a-root.crt")).expect("cert").mode() & 0o7777, 0o640, "owner and mode come from input");
    // A rebuild that failed left the source without trust: a retry rebuilds (review counterexample: Ok(0)).
    fs::remove_file(fake.path("/etc/ssl/certs/a-root.pem")).expect("trust lost");
    assert_eq!(trust_ensure(&fake, &input), Ok(1));
    assert_eq!(trust_ensure(&fake, &json!({"state": "absent", "name": "../x.crt", "certificate": certificate})).expect_err("name").code, "host.input_invalid");
    assert_eq!(trust_ensure(&fake, &json!({"state": "absent", "name": "a-root.crt", "certificate": certificate})), Ok(1));
    assert!(fs::symlink_metadata(fake.path("/etc/ssl/certs/a-root.pem")).is_err());
    assert_eq!(trust_ensure(&fake, &json!({"state": "absent", "name": "a-root.crt", "certificate": certificate})), Ok(0));
    // Content openssl does not read never goes in place.
    let junk_text = json!({"state": "present", "name": "c.crt", "certificate": fake.put(b"not pem"), "owner": {"user": "u", "group": "g", "mode": "0644"}});
    assert_eq!(trust_ensure(&fake, &junk_text).expect_err("not a certificate").code, "trust.ensure_failed");
    assert!(!fake.path("/usr/local/share/ca-certificates/c.crt").exists());
}

#[test]
fn probe_waits_for_the_expectation_within_its_budget() {
    let fake = Fake::new();
    fake.answers.borrow_mut().push(("/usr/bin/curl", "{\"a.test\"}".to_owned()));
    let input = json!({"url": "http://127.0.0.1:2019/config/", "budget_ms": 600, "lacks": ["\"a.test\""]});
    assert_eq!(probe(&fake, &input).expect_err("still served").code, "http.probe_unmet");
    assert_eq!(probe(&fake, &json!({"url": "http://127.0.0.1:2019/config/", "budget_ms": 600, "contains": ["a.test"]})), Ok(0));
    assert_eq!(probe(&fake, &json!({"url": "file:///etc/shadow", "budget_ms": 1000})).expect_err("scheme").code, "host.input_invalid");
}

#[test]
fn config_apply_replay_after_a_stop_restores_the_prior_state_absence_and_metadata_included() {
    let mut fake = Fake::new();
    lock_folder(&fake);
    fs::create_dir_all(fake.path("/conf")).expect("conf");
    let validators = json!([{"kind": "caddy", "config": "/conf/site.conf", "user": "web"}]);
    let stopped = |fake: &Fake, input: &serde_json::Value| {
        *fake.stop.borrow_mut() = Some("validate");
        assert!(std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| config_apply(fake, input))).is_err());
        *fake.stop.borrow_mut() = None;
        fake.fail.borrow_mut().push("validate");
    };
    // Review counterexample: the first creation stops after install and before validation; the replay must remove it.
    let content = fake.put(b"new\n");
    let created = json!({"lock": "/tmp-lock/given.lock", "recovery_dir": "/recovery", "files": [{"path": "/conf/site.conf", "content": content, "from_sha256": null, "owner": owner()}], "validators": validators});
    stopped(&fake, &created);
    assert_eq!(fake.read("/conf/site.conf"), b"new\n", "the stop left the unvalidated file");
    assert_eq!(config_apply(&fake, &created).expect_err("invalid").code, "config.validate_failed");
    assert!(!fake.path("/conf/site.conf").exists(), "the replay restores absence");
    // A change of bytes: the replay restores the rendered-from bytes, never the candidate.
    fake.fail.borrow_mut().clear();
    fake.write("/conf/site.conf", b"old\n");
    fs::set_permissions(fake.path("/conf/site.conf"), fs::Permissions::from_mode(0o644)).expect("mode");
    let changed = json!({"lock": "/tmp-lock/given.lock", "recovery_dir": "/recovery", "files": [{"path": "/conf/site.conf", "content": fake.put(b"new\n"), "from_sha256": sha256(b"old\n"), "owner": owner()}], "validators": validators});
    stopped(&fake, &changed);
    assert_eq!(config_apply(&fake, &changed).expect_err("invalid").code, "config.validate_failed");
    assert_eq!(fake.read("/conf/site.conf"), b"old\n");
    // Review counterexample: a change of metadata only; the replay puts the prior mode back.
    fake.fail.borrow_mut().clear();
    fs::set_permissions(fake.path("/conf/site.conf"), fs::Permissions::from_mode(0o600)).expect("mode");
    let metadata = json!({"lock": "/tmp-lock/given.lock", "recovery_dir": "/recovery", "files": [{"path": "/conf/site.conf", "content": fake.put(b"old\n"), "from_sha256": null, "owner": owner()}], "validators": validators});
    stopped(&fake, &metadata);
    assert_eq!(fs::metadata(fake.path("/conf/site.conf")).expect("meta").mode() & 0o7777, 0o644, "the stop left the new mode");
    assert_eq!(config_apply(&fake, &metadata).expect_err("invalid").code, "config.validate_failed");
    assert_eq!(fs::metadata(fake.path("/conf/site.conf")).expect("meta").mode() & 0o7777, 0o600);
    // A run that ends drops its record.
    fake.fail.borrow_mut().clear();
    assert_eq!(config_apply(&fake, &metadata), Ok(1));
    assert!(fs::read_dir(fake.path("/recovery")).expect("recovery").next().is_none());
}

#[test]
fn config_apply_reloads_on_recovery_only_the_units_it_reached() {
    let mut fake = Fake::new();
    lock_folder(&fake);
    fake.write("/conf/site.conf", b"old\n");
    fake.fail.borrow_mut().push("first.service");
    let content = fake.put(b"new\n");
    let input = json!({"lock": "/tmp-lock/given.lock", "recovery_dir": "/recovery", "files": [{"path": "/conf/site.conf", "content": content, "from_sha256": sha256(b"old\n"), "owner": owner()}],
        "units": [{"unit": "first.service", "load": "reload"}, {"unit": "second.service", "load": "restart"}]});
    let failure = config_apply(&fake, &input).expect_err("a failed load");
    assert_eq!(failure.code, "config.restore_failed", "the recovery reload of the failed unit fails too, and says so");
    assert_eq!(fake.ran("second.service"), 0, "a unit the forward pass never reached is not restarted");
    assert_eq!(fake.read("/conf/site.conf"), b"old\n");
}

#[test]
fn config_apply_refuses_aliases_and_paths_in_another_spelling() {
    let mut fake = Fake::new();
    lock_folder(&fake);
    fs::create_dir_all(fake.path("/conf")).expect("conf");
    let content = fake.put(b"x\n");
    let file = |path: &str| json!({"path": path, "content": content.clone(), "from_sha256": null, "owner": owner()});
    for files in [vec![file("/conf/a"), file("/conf/./a")], vec![file("/conf/a"), file("/conf/b/../a")], vec![file("/conf//a")], vec![file("/tmp-lock/given.lock")]] {
        let input = json!({"lock": "/tmp-lock/given.lock", "recovery_dir": "/recovery", "files": files});
        assert_eq!(config_apply(&fake, &input).expect_err("an alias").code, "host.input_invalid");
    }
    std::os::unix::fs::symlink(fake.path("/conf"), fake.path("/alias")).expect("alias");
    let input = json!({"lock": "/tmp-lock/given.lock", "recovery_dir": "/recovery", "files": [file("/conf/a"), file("/alias/a")]});
    assert_eq!(config_apply(&fake, &input).expect_err("one file by two names").code, "host.input_invalid");
    assert!(!fake.path("/conf/a").exists());
    assert_eq!(file_ensure(&fake, &json!({"state": "absent", "path": "/.", "recursive": true})).expect_err("root").code, "host.input_invalid");
    assert_eq!(file_ensure(&fake, &json!({"state": "absent", "path": "/", "recursive": true})).expect_err("root").code, "host.input_invalid");
}

#[test]
fn a_blob_must_be_a_digest_whose_bytes_hash_to_it() {
    let mut fake = Fake::new();
    fs::create_dir_all(fake.path("/srv")).expect("folder");
    let traversal = json!({"state": "file", "path": "/srv/a", "content": {"blob": {"sha256": "../../etc/shadow"}}, "owner": owner()});
    assert_eq!(file_ensure(&fake, &traversal).expect_err("a path").code, "host.input_invalid");
    let digest = sha256(b"promised");
    fake.blobs.insert(digest.clone(), b"something else".to_vec());
    let swapped = json!({"state": "file", "path": "/srv/a", "content": {"blob": {"sha256": digest}}, "owner": owner()});
    assert_eq!(file_ensure(&fake, &swapped).expect_err("other bytes").code, "host.content_missing");
    assert!(!fake.path("/srv/a").exists());
}

#[test]
fn file_ensure_never_changes_a_folder_through_a_link_and_a_guard_needs_the_file() {
    let mut fake = Fake::new();
    fs::create_dir_all(fake.path("/srv/real")).expect("folder");
    fs::set_permissions(fake.path("/srv/real"), fs::Permissions::from_mode(0o755)).expect("mode");
    std::os::unix::fs::symlink(fake.path("/srv/real"), fake.path("/srv/link")).expect("link");
    let folder = json!({"state": "directory", "path": "/srv/link", "owner": {"user": "u", "group": "g", "mode": "0700"}});
    assert_eq!(file_ensure(&fake, &folder).expect_err("a link").code, "file.ensure_failed");
    assert_eq!(fs::metadata(fake.path("/srv/real")).expect("real").mode() & 0o7777, 0o755, "the folder behind the link keeps its mode");
    // A guard that names rendered-from bytes fails on a missing file (review counterexample: the file was made).
    let content = fake.put(b"hello\n");
    let guarded = json!({"state": "file", "path": "/srv/gone.txt", "content": content, "owner": owner(), "from_sha256": sha256(b"old\n")});
    assert_eq!(file_ensure(&fake, &guarded).expect_err("absent").code, "owned_revision_conflict");
    assert!(!fake.path("/srv/gone.txt").exists());
}

#[test]
fn dotenv_ensure_refuses_a_key_named_twice() {
    let fake = Fake::new();
    fake.write("/app/.env", b"A=1\n");
    let input = json!({"path": "/app/.env", "user": "u", "values": [{"key": "A", "value": "2"}, {"key": "A", "value": "3"}]});
    assert_eq!(dotenv_ensure(&fake, &input).expect_err("conflict").code, "host.input_invalid");
    assert_eq!(fake.read("/app/.env"), b"A=1\n");
}

#[test]
fn probe_passes_the_url_literally_and_refuses_an_answer_after_its_budget() {
    let fake = Fake::new();
    fake.answers.borrow_mut().push(("/usr/bin/curl", "ok".to_owned()));
    assert_eq!(probe(&fake, &json!({"url": "http://127.0.0.1/{a,b}", "budget_ms": 600, "contains": ["ok"]})), Ok(0));
    assert!(fake.ran.borrow().iter().all(|argv| argv.contains(&"--globoff".to_owned())));
    let late = Late { fake, reads: RefCell::new(0) };
    assert_eq!(probe(&late, &json!({"url": "http://127.0.0.1/", "budget_ms": 600, "contains": ["ok"]})).expect_err("late").code, "http.probe_unmet");
}

/// A system whose clock jumps past any budget during the first read.
struct Late {
    fake: Fake,
    reads: RefCell<u32>,
}

impl System for Late {
    fn root(&self) -> &Path {
        self.fake.root()
    }

    fn run(&self, seconds: u32, argv: &[String]) -> Result<String, String> {
        *self.reads.borrow_mut() += 1;
        self.fake.run(seconds, argv)
    }

    fn status(&self, seconds: u32, argv: &[String]) -> Result<(Option<i32>, Vec<u8>), String> {
        self.fake.status(seconds, argv)
    }

    fn run_clean(&self, limit: std::time::Duration, argv: &[String]) -> Result<String, String> {
        self.run(u32::try_from(limit.as_secs()).unwrap_or(u32::MAX), argv)
    }

    fn blob(&self, digest: &str) -> Result<Vec<u8>, String> {
        self.fake.blob(digest)
    }

    fn now(&self) -> std::time::Instant {
        std::time::Instant::now() + std::time::Duration::from_secs(u64::from(*self.reads.borrow()) * 3600)
    }
}

#[test]
fn config_apply_checks_dnsmasq_with_the_configuration_it_loads_and_refuses_before_the_restart() {
    let mut fake = Fake::new();
    lock_folder(&fake);
    fake.write("/conf/site.conf", b"old\n");
    fake.fail.borrow_mut().push("/usr/sbin/dnsmasq");
    let content = fake.put(b"bad\n");
    let validator = json!({"kind": "dnsmasq", "conf_file": "/conf/dnsmasq.conf", "conf_dir": "/conf", "skip_suffixes": [".bak"]});
    let input = json!({"lock": "/tmp-lock/given.lock", "recovery_dir": "/recovery", "files": [{"path": "/conf/site.conf", "content": content, "from_sha256": sha256(b"old\n"), "owner": owner()}],
        "validators": [validator], "units": [{"unit": "dnsmasq.service", "load": "restart"}]});
    assert_eq!(config_apply(&fake, &input).expect_err("rejected").code, "config.validate_failed");
    assert!(fake.ran.borrow().iter().any(|argv| argv == &strings(&["/usr/sbin/dnsmasq", "--test", "--conf-file=/conf/dnsmasq.conf", "--conf-dir=/conf,.bak"])), "the folder the service loads, not the one file");
    assert_eq!(fake.ran("restart"), 0, "a rejected set never restarts the service");
    assert_eq!(fake.read("/conf/site.conf"), b"old\n");
    let comma = json!({"lock": "/tmp-lock/given.lock", "recovery_dir": "/recovery", "files": input["files"].clone(), "validators": [{"kind": "dnsmasq", "conf_file": "/conf/dnsmasq.conf", "conf_dir": "/conf,*.x"}]});
    assert_eq!(config_apply(&fake, &comma).expect_err("an extra list item").code, "host.input_invalid");
}

#[test]
fn config_apply_restores_the_last_usable_state_and_never_deletes_a_file_that_existed() {
    let mut fake = Fake::new();
    lock_folder(&fake);
    // Review counterexample: a file already live with the desired bytes and no rendered-from digest, then a failing validator.
    fake.write("/conf/site.conf", b"live\n");
    fake.fail.borrow_mut().push("validate");
    let content = fake.put(b"live\n");
    let validators = json!([{"kind": "caddy", "config": "/conf/site.conf", "user": "web"}]);
    let input = json!({"lock": "/tmp-lock/given.lock", "recovery_dir": "/recovery", "files": [{"path": "/conf/site.conf", "content": content, "from_sha256": null, "owner": owner()}], "validators": validators});
    assert_eq!(config_apply(&fake, &input).expect_err("invalid").code, "config.validate_failed");
    assert_eq!(fake.read("/conf/site.conf"), b"live\n", "a file that existed before the run is never deleted");
    // Review counterexample: A→B loads, B→C fails, and a replay of A→B must not meet a false conflict.
    fake.fail.borrow_mut().clear();
    fake.write("/conf/site.conf", b"A\n");
    assert_eq!(apply(&mut fake, b"B\n", b"A\n", &validators), Ok(1));
    fake.fail.borrow_mut().push("validate");
    assert_eq!(apply(&mut fake, b"C\n", b"B\n", &validators).expect_err("invalid").code, "config.validate_failed");
    fake.fail.borrow_mut().clear();
    assert_eq!(apply(&mut fake, b"B\n", b"A\n", &validators), Ok(0));
    assert_eq!(fake.read("/conf/site.conf"), b"B\n");
    assert!(fs::read_dir(fake.path("/conf")).expect("folder").all(|entry| !entry.expect("entry").file_name().to_string_lossy().contains("previous")));
}

#[test]
fn scratch_work_never_touches_a_file_the_executor_did_not_create() {
    let mut fake = Fake::new();
    lock_folder(&fake);
    fake.write("/conf/a", b"old\n");
    let from = sha256(b"old\n");
    // Review counterexample: a file under the old backup name, even with the rendered-from bytes, is someone else's.
    let lookalike = format!("/conf/.a.previous-{from}");
    fake.write(&lookalike, b"old\n");
    let partial = format!("/conf/.a.partial-{}", std::process::id());
    fake.write(&partial, b"mine\n");
    let validators = json!([{"kind": "caddy", "config": "/conf/a", "user": "web"}]);
    let one = json!({"lock": "/tmp-lock/given.lock", "recovery_dir": "/recovery", "files": [{"path": "/conf/a", "content": fake.put(b"new\n"), "from_sha256": from, "owner": owner()}], "validators": validators});
    fake.fail.borrow_mut().push("validate");
    assert_eq!(config_apply(&fake, &one).expect_err("invalid").code, "config.validate_failed");
    fake.fail.borrow_mut().clear();
    assert_eq!(config_apply(&fake, &one), Ok(1));
    assert_eq!(fake.read(&lookalike), b"old\n");
    assert_eq!(fake.read(&partial), b"mine\n");
    let mut names: Vec<String> = fs::read_dir(fake.path("/conf")).expect("folder").map(|entry| entry.expect("entry").file_name().to_string_lossy().into_owned()).collect();
    names.sort();
    assert_eq!(names, vec![partial.trim_start_matches("/conf/").to_owned(), lookalike.trim_start_matches("/conf/").to_owned(), "a".to_owned()], "nothing else beside the managed file");
    fs::create_dir_all(fake.path("/usr/local/share/ca-certificates")).expect("store");
    fake.write("/usr/local/share/ca-certificates/.r.crt.candidate", b"mine\n");
    let certificate = fake.put(b"-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n");
    assert_eq!(trust_ensure(&fake, &json!({"state": "present", "name": "r.crt", "certificate": certificate, "owner": owner()})), Ok(1));
    assert_eq!(fake.read("/usr/local/share/ca-certificates/.r.crt.candidate"), b"mine\n");
}

#[test]
fn trust_ensure_reads_back_the_bundle_and_hash_links_after_rotation_and_removal() {
    let mut fake = Fake::new();
    fs::create_dir_all(fake.path("/usr/local/share/ca-certificates")).expect("store");
    let first = b"-----BEGIN CERTIFICATE-----\nFIRST\n-----END CERTIFICATE-----\n";
    let second = b"-----BEGIN CERTIFICATE-----\nSECOND\n-----END CERTIFICATE-----\n";
    let present = |fake: &mut Fake, bytes: &[u8]| json!({"state": "present", "name": "r.crt", "certificate": fake.put(bytes), "owner": owner()});
    let bundle = |fake: &Fake| String::from_utf8(fake.read("/etc/ssl/certs/ca-certificates.crt")).expect("text");
    let input = present(&mut fake, first);
    assert_eq!(trust_ensure(&fake, &input), Ok(1));
    // Review counterexample: a rotation whose rebuild failed left the new file under the old links and the old bundle.
    fake.write("/usr/local/share/ca-certificates/r.crt", second);
    fs::set_permissions(fake.path("/usr/local/share/ca-certificates/r.crt"), fs::Permissions::from_mode(0o644)).expect("mode");
    let input = present(&mut fake, second);
    assert_eq!(trust_ensure(&fake, &input), Ok(1));
    assert!(bundle(&fake).contains("SECOND") && !bundle(&fake).contains("FIRST"));
    assert!(fake.path(&format!("/etc/ssl/certs/{}.0", &sha256(second)[..8])).exists(), "the new subject's hash link");
    // Review counterexample: a removal that stopped after the file and its link went, with the bundle still trusting it.
    fs::remove_file(fake.path("/usr/local/share/ca-certificates/r.crt")).expect("file");
    fs::remove_file(fake.path("/etc/ssl/certs/r.pem")).expect("link");
    let absent = json!({"state": "absent", "name": "r.crt", "certificate": fake.put(second)});
    assert_eq!(trust_ensure(&fake, &absent), Ok(1));
    assert!(!bundle(&fake).contains("SECOND"));
    assert_eq!(trust_ensure(&fake, &absent), Ok(0));
}

#[test]
fn trust_ensure_takes_exactly_one_pem_certificate() {
    let mut fake = Fake::new();
    fs::create_dir_all(fake.path("/usr/local/share/ca-certificates")).expect("store");
    let two = b"-----BEGIN CERTIFICATE-----\nONE\n-----END CERTIFICATE-----\n-----BEGIN CERTIFICATE-----\nTWO\n-----END CERTIFICATE-----\n";
    let der = [0x30_u8, 0x82, 0x01, 0x0a, 0x02, 0x82, 0x01, 0x01];
    for bytes in [two.as_slice(), der.as_slice()] {
        let input = json!({"state": "present", "name": "r.crt", "certificate": fake.put(bytes), "owner": owner()});
        assert_eq!(trust_ensure(&fake, &input).expect_err("not one PEM certificate").code, "trust.ensure_failed");
        assert!(!fake.path("/usr/local/share/ca-certificates/r.crt").exists());
        assert_eq!(fake.ran("/usr/sbin/update-ca-certificates"), 0);
    }
}

#[test]
fn unit_ensure_never_counts_a_transitional_state_as_the_one_asked_for() {
    let fake = Fake::new();
    *fake.unit.borrow_mut() = Some((true, false));
    // Review counterexample: a notify service stuck `activating` reported running.
    *fake.transition.borrow_mut() = Some("activating");
    assert_eq!(unit_ensure(&fake, &json!({"unit": "web.service", "enabled": true, "state": "running", "budget_ms": 1000})).expect_err("still activating").code, "unit.ensure_failed");
    *fake.transition.borrow_mut() = Some("deactivating");
    assert_eq!(unit_ensure(&fake, &json!({"unit": "web.service", "enabled": true, "state": "stopped", "budget_ms": 1000})).expect_err("still deactivating").code, "unit.ensure_failed");
    assert_eq!(unit_ensure(&fake, &json!({"unit": "web.service", "enabled": true, "state": "stopped"})).expect_err("no budget").code, "host.input_invalid");
}

#[test]
fn trust_ensure_takes_the_identity_from_input_and_touches_no_other_trust() {
    let mut fake = Fake::new();
    fs::create_dir_all(fake.path("/usr/local/share/ca-certificates")).expect("store");
    let bytes = b"-----BEGIN CERTIFICATE-----\nCA\n-----END CERTIFICATE-----\n";
    let bundle = |fake: &Fake| String::from_utf8(fake.read("/etc/ssl/certs/ca-certificates.crt")).expect("text");
    // Another consumer's working link, outside the store.
    fake.write("/usr/share/ca-certificates/other.crt", b"-----BEGIN CERTIFICATE-----\nOTHER\n-----END CERTIFICATE-----\n");
    fs::create_dir_all(fake.path("/etc/ssl/certs")).expect("links");
    std::os::unix::fs::symlink(fake.path("/usr/share/ca-certificates/other.crt"), fake.path("/etc/ssl/certs/other.pem")).expect("other link");
    // Review counterexample: one certificate under two names; a repeat of either is a repeat.
    let a = json!({"state": "present", "name": "a.crt", "certificate": fake.put(bytes), "owner": owner()});
    let b = json!({"state": "present", "name": "b.crt", "certificate": fake.put(bytes), "owner": owner()});
    assert_eq!(trust_ensure(&fake, &a), Ok(1));
    assert_eq!(trust_ensure(&fake, &b), Ok(1));
    assert_eq!(trust_ensure(&fake, &a), Ok(0));
    assert_eq!(trust_ensure(&fake, &b), Ok(0));
    // Review counterexample: a removal whose update failed; the retry must not answer while the bundle still trusts it.
    for name in ["a.crt", "b.crt"] {
        fs::remove_file(fake.path(&format!("/usr/local/share/ca-certificates/{name}"))).expect("source");
    }
    let absent = json!({"state": "absent", "name": "a.crt", "certificate": fake.put(bytes)});
    assert!(bundle(&fake).contains("CA"));
    assert_eq!(trust_ensure(&fake, &absent), Ok(1));
    assert!(!bundle(&fake).contains("\nCA\n"));
    // Review counterexample: an absent name already gone runs no update, and another consumer's link stays.
    let updates = fake.ran("/usr/sbin/update-ca-certificates");
    assert_eq!(trust_ensure(&fake, &absent), Ok(0));
    assert_eq!(fake.ran("/usr/sbin/update-ca-certificates"), updates);
    assert!(fake.ran.borrow().iter().all(|argv| !argv.contains(&"--fresh".to_owned())));
    assert!(fs::metadata(fake.path("/etc/ssl/certs/other.pem")).is_ok(), "the other consumer's trust link stays");
}

#[test]
fn unit_ensure_keeps_its_budget_across_the_whole_action() {
    let fake = Fake::new();
    *fake.unit.borrow_mut() = Some((true, true));
    // Review counterexample: a 20 ms budget is below what a bounded systemctl call can keep.
    assert_eq!(unit_ensure(&fake, &json!({"unit": "web.service", "enabled": true, "state": "stopped", "budget_ms": 20})).expect_err("below the floor").code, "host.input_invalid");
    assert_eq!(unit_ensure(&fake, &json!({"unit": "web.service", "enabled": true, "state": "stopped", "budget_ms": 1000})), Ok(1));
    assert!(fake.ran.borrow().iter().any(|argv| argv == &strings(&["/usr/bin/systemctl", "stop", "--no-block", "web.service"])), "systemctl never waits past the budget");
}

#[test]
fn config_apply_replay_of_the_same_order_restores_and_reloads_the_units_it_reached() {
    let mut fake = Fake::new();
    lock_folder(&fake);
    fake.write("/conf/site.conf", b"old\n");
    let input = json!({"lock": "/tmp-lock/given.lock", "recovery_dir": "/recovery",
        "files": [{"path": "/conf/site.conf", "content": fake.put(b"new\n"), "from_sha256": sha256(b"old\n"), "owner": owner()}],
        "units": [{"unit": "first.service", "load": "reload"}, {"unit": "second.service", "load": "restart"}]});
    // The run stops while the second unit restarts: the first already loaded the new file.
    *fake.stop.borrow_mut() = Some("second.service");
    assert!(std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| config_apply(&fake, &input))).is_err());
    *fake.stop.borrow_mut() = None;
    fake.fail.borrow_mut().push("second.service");
    let before = fake.ran("first.service");
    // Review finding 2: the replay restores the prior file and reloads the units the record reached before it applies again.
    assert_eq!(config_apply(&fake, &input).expect_err("the second unit still fails").code, "config.restore_failed");
    assert_eq!(fake.read("/conf/site.conf"), b"old\n");
    assert!(fake.ran("first.service") > before, "the reached unit loads the restored file");
}

#[test]
fn config_apply_refuses_another_order_while_a_record_is_pending() {
    let mut fake = Fake::new();
    lock_folder(&fake);
    fake.write("/conf/site.conf", b"old\n");
    let validators = json!([{"kind": "caddy", "config": "/conf/site.conf", "user": "web"}]);
    let first = json!({"lock": "/tmp-lock/given.lock", "recovery_dir": "/recovery", "files": [{"path": "/conf/site.conf", "content": fake.put(b"first\n"), "from_sha256": sha256(b"old\n"), "owner": owner()}], "validators": validators});
    *fake.stop.borrow_mut() = Some("validate");
    assert!(std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| config_apply(&fake, &first))).is_err());
    *fake.stop.borrow_mut() = None;
    // Review finding 1: a later writer changed the file; another order must not restore over it.
    fake.write("/conf/site.conf", b"later writer\n");
    let other = json!({"lock": "/tmp-lock/given.lock", "recovery_dir": "/recovery", "files": [{"path": "/conf/site.conf", "content": fake.put(b"other\n"), "from_sha256": sha256(b"later writer\n"), "owner": owner()}]});
    assert_eq!(config_apply(&fake, &other).expect_err("pending").code, "config.recovery_pending");
    assert_eq!(fake.read("/conf/site.conf"), b"later writer\n");
}

#[test]
fn config_apply_takes_its_recovery_folder_from_the_order() {
    let mut fake = Fake::new();
    lock_folder(&fake);
    let file = json!([{"path": "/conf/site.conf", "content": fake.put(b"x\n"), "from_sha256": null, "owner": owner()}]);
    for input in [
        json!({"lock": "/tmp-lock/given.lock", "files": file}),
        json!({"lock": "/tmp-lock/given.lock", "recovery_dir": "recovery", "files": file}),
        json!({"lock": "/tmp-lock/given.lock", "recovery_dir": "/conf", "files": file}),
    ] {
        assert_eq!(config_apply(&fake, &input).expect_err("refused").code, "host.input_invalid");
    }
    assert!(!fake.path("/conf/site.conf").exists());
}

#[test]
fn trust_ensure_removes_only_a_source_that_holds_the_given_certificate() {
    let mut fake = Fake::new();
    fs::create_dir_all(fake.path("/usr/local/share/ca-certificates")).expect("store");
    fake.write("/usr/local/share/ca-certificates/r.crt", b"-----BEGIN CERTIFICATE-----\nTHEIRS\n-----END CERTIFICATE-----\n");
    // Review finding 5: other bytes under the name are not this certificate's source.
    let absent = json!({"state": "absent", "name": "r.crt", "certificate": fake.put(b"-----BEGIN CERTIFICATE-----\nOURS\n-----END CERTIFICATE-----\n")});
    assert_eq!(trust_ensure(&fake, &absent).expect_err("mismatch").code, "trust.source_mismatch");
    assert!(fake.read("/usr/local/share/ca-certificates/r.crt").windows(6).any(|window| window == b"THEIRS"));
    assert_eq!(fake.ran("/usr/sbin/update-ca-certificates"), 0);
}

#[test]
fn config_apply_reloads_the_reached_units_when_its_progress_cannot_be_recorded() {
    let mut fake = Fake::new();
    lock_folder(&fake);
    fake.write("/conf/site.conf", b"A\n");
    let input = json!({"lock": "/tmp-lock/given.lock", "recovery_dir": "/recovery",
        "files": [{"path": "/conf/site.conf", "content": fake.put(b"B\n"), "from_sha256": sha256(b"A\n"), "owner": owner()}],
        "units": [{"unit": "first.service", "load": "reload"}, {"unit": "second.service", "load": "restart"}]});
    // Review counterexample: the first unit loads B, then the progress record cannot be written before the second.
    let folder = fake.path("/recovery").join(sha256(b"/tmp-lock/given.lock"));
    *fake.seal.borrow_mut() = Some(("first.service", folder.clone()));
    let failure = config_apply(&fake, &input).expect_err("the record cannot be written");
    fs::set_permissions(&folder, fs::Permissions::from_mode(0o700)).expect("unseal");
    assert_eq!(failure.code, "config.write_failed");
    assert_eq!(fake.read("/conf/site.conf"), b"A\n");
    assert_eq!(fake.ran("first.service"), 2, "the reached unit loads the restored file again");
    assert_eq!(fake.ran("second.service"), 0, "a unit the record does not know is never loaded");
}

#[test]
fn trust_ensure_counts_only_a_conclusive_answer_from_the_named_bundle() {
    let mut fake = Fake::new();
    fs::create_dir_all(fake.path("/usr/local/share/ca-certificates")).expect("store");
    let bytes = b"-----BEGIN CERTIFICATE-----\nCA\n-----END CERTIFICATE-----\n";
    let present = json!({"state": "present", "name": "r.crt", "certificate": fake.put(bytes), "owner": owner()});
    let absent = json!({"state": "absent", "name": "r.crt", "certificate": fake.put(bytes)});
    assert_eq!(trust_ensure(&fake, &present), Ok(1));
    // Review counterexample 3: the bundle lost the CA while its hash link stays; only the named bundle counts.
    fs::write(fake.path("/etc/ssl/certs/ca-certificates.crt"), b"").expect("bundle");
    assert_eq!(trust_ensure(&fake, &present), Ok(1), "a bundle without the CA is not trust");
    assert!(fake.ran.borrow().iter().filter(|argv| argv.contains(&"verify".to_owned())).all(|argv| argv.contains(&"-no-CApath".to_owned()) && argv.contains(&"-no-CAstore".to_owned())));
    // Review counterexample 2: a verify that times out is no answer, for removal and for its retry.
    fake.fail.borrow_mut().push("verify");
    assert_eq!(trust_ensure(&fake, &absent).expect_err("no answer").code, "trust.ensure_failed");
    assert_eq!(trust_ensure(&fake, &absent).expect_err("still no answer").code, "trust.ensure_failed");
    assert_eq!(trust_ensure(&fake, &present).expect_err("no answer").code, "trust.ensure_failed");
}

#[test]
fn file_read_answers_absent_only_for_a_missing_path() {
    let fake = Fake::new();
    assert_eq!(file_read(&fake, &json!({"path": "/etc/gone.conf"})).expect("absent"), Read::Absent);
    for path in ["etc/app.conf", "/etc/./app.conf", "/etc/../app.conf", "/", "/etc/"] {
        assert_eq!(file_read(&fake, &json!({"path": path})).expect_err(path).code, "host.input_invalid", "{path}");
    }
    assert_eq!(file_read(&fake, &json!({"path": "/etc/app.conf", "content": true})).expect_err("unknown field").code, "host.input_invalid");
}

#[test]
fn file_read_answers_a_files_digest_owner_and_mode_and_a_repeat_answers_the_same() {
    let fake = Fake::new();
    fake.write("/etc/app.conf", b"listen = 9000\n");
    fs::set_permissions(fake.path("/etc/app.conf"), fs::Permissions::from_mode(0o4640)).expect("mode");
    let (uid, gid) = me();
    let expected = Read::Present { kind: "file", sha256: Some(sha256(b"listen = 9000\n")), uid, gid, mode: "4640".to_owned() };
    let first = file_read(&fake, &json!({"path": "/etc/app.conf"})).expect("read");
    assert_eq!(first, expected);
    assert_eq!(file_read(&fake, &json!({"path": "/etc/app.conf"})).expect("repeat"), first);
    // It changes nothing, and the answer names no content.
    assert_eq!(fake.read("/etc/app.conf"), b"listen = 9000\n");
    assert_eq!(fs::metadata(fake.path("/etc/app.conf")).expect("meta").mode() & 0o7777, 0o4640);
    let wire = serde_json::to_value(&first).expect("json");
    assert_eq!(wire, json!({"state": "present", "type": "file", "sha256": sha256(b"listen = 9000\n"), "uid": uid, "gid": gid, "mode": "4640"}));
    assert_eq!(serde_json::to_value(Read::Absent).expect("json"), json!({"state": "absent"}));
    assert!(fake.ran.borrow().is_empty(), "it runs no command");
}

#[test]
fn file_read_reports_a_link_and_a_folder_as_themselves() {
    let fake = Fake::new();
    fake.write("/etc/app.conf", b"listen = 9000\n");
    std::os::unix::fs::symlink(fake.path("/etc/app.conf"), fake.path("/etc/link.conf")).expect("link");
    std::os::unix::fs::symlink(fake.path("/etc/gone.conf"), fake.path("/etc/dangling.conf")).expect("dangling link");
    let (uid, gid) = me();
    for path in ["/etc/link.conf", "/etc/dangling.conf"] {
        // The link itself, never the file it names: no digest, and the link's own mode.
        assert_eq!(file_read(&fake, &json!({"path": path})).expect(path), Read::Present { kind: "symlink", sha256: None, uid, gid, mode: "0777".to_owned() }, "{path}");
    }
    fs::create_dir_all(fake.path("/srv/site")).expect("folder");
    fs::set_permissions(fake.path("/srv/site"), fs::Permissions::from_mode(0o2750)).expect("mode");
    assert_eq!(file_read(&fake, &json!({"path": "/srv/site"})).expect("folder"), Read::Present { kind: "directory", sha256: None, uid, gid, mode: "2750".to_owned() });
}

#[test]
fn file_read_fails_a_read_error_and_never_answers_absent() {
    let fake = Fake::new();
    fake.write("/etc/app.conf", b"listen = 9000\n");
    // A plain file where a folder must be is a read error, also for root.
    assert_eq!(file_read(&fake, &json!({"path": "/etc/app.conf/child"})).expect_err("not a folder").code, "file.read_failed");
    fs::set_permissions(fake.path("/etc/app.conf"), fs::Permissions::from_mode(0o000)).expect("seal file");
    let unreadable_file = file_read(&fake, &json!({"path": "/etc/app.conf"}));
    fs::set_permissions(fake.path("/etc"), fs::Permissions::from_mode(0o000)).expect("seal folder");
    let unreadable_folder = file_read(&fake, &json!({"path": "/etc/app.conf"}));
    let behind_folder = file_read(&fake, &json!({"path": "/etc/gone.conf"}));
    fs::set_permissions(fake.path("/etc"), fs::Permissions::from_mode(0o755)).expect("unseal");
    // Root reads any file and enters any folder; the tests run as a normal user (AGENTS.md).
    if me().0 != 0 {
        assert_eq!(unreadable_file.expect_err("unreadable file").code, "file.read_failed");
        assert_eq!(unreadable_folder.expect_err("unreadable folder").code, "file.read_failed");
        assert_eq!(behind_folder.expect_err("a missing file behind a sealed folder is unknown").code, "file.read_failed");
    }
}

#[test]
fn file_read_leaves_the_access_time_as_it_was() {
    let fake = Fake::new();
    fake.write("/etc/app.conf", b"listen = 9000\n");
    // An access time older than the change time, so relatime would update it on a plain read.
    let old = std::time::UNIX_EPOCH + std::time::Duration::from_secs(946_684_800);
    fs::File::options().write(true).open(fake.path("/etc/app.conf")).expect("open").set_times(fs::FileTimes::new().set_accessed(old)).expect("old atime");
    let before = fs::symlink_metadata(fake.path("/etc/app.conf")).expect("meta");
    assert_eq!(before.atime(), 946_684_800);
    assert!(matches!(file_read(&fake, &json!({"path": "/etc/app.conf"})).expect("read"), Read::Present { kind: "file", .. }));
    let after = fs::symlink_metadata(fake.path("/etc/app.conf")).expect("meta");
    assert_eq!((after.atime(), after.atime_nsec()), (before.atime(), before.atime_nsec()), "the read wrote the access time");
    assert_eq!((after.mtime(), after.mtime_nsec()), (before.mtime(), before.mtime_nsec()));
}

const WEB: u32 = 40_001;
const OTHER_USER: u32 = 40_002;
const TEAM: u32 = 40_003;
const NONE: u32 = u32::MAX;

/// A path's list as `(tag, id, perm)`, read straight from its attribute: `None` when it has none.
fn listed(fake: &Fake, path: &str, attribute: &str) -> Option<Vec<(u16, u32, u16)>> {
    let file = fs::File::open(fake.path(path)).expect("open");
    let bytes = acl::read_xattr(std::os::fd::AsFd::as_fd(&file), attribute).expect("read")?;
    assert_eq!(bytes[..4], [2, 0, 0, 0], "version 2");
    Some(bytes[4..].chunks(8).map(|entry| (u16::from_le_bytes([entry[0], entry[1]]), u32::from_le_bytes([entry[4], entry[5], entry[6], entry[7]]), u16::from_le_bytes([entry[2], entry[3]]))).collect())
}

/// Writes a path's list from `(tag, id, perm)` entries, encoded here by hand.
fn put(fake: &Fake, path: &str, attribute: &str, entries: &[(u16, u32, u16)]) {
    let mut bytes = vec![2, 0, 0, 0];
    for (tag, id, perm) in entries {
        bytes.extend(tag.to_le_bytes());
        bytes.extend(perm.to_le_bytes());
        bytes.extend(id.to_le_bytes());
    }
    let file = fs::File::open(fake.path(path)).expect("open");
    acl::write_xattr(std::os::fd::AsFd::as_fd(&file), attribute, Some(&bytes)).expect("write");
}

/// What each path of `paths` holds: both lists and the mode.
type Entries = Option<Vec<(u16, u32, u16)>>;

fn snapshot(fake: &Fake, paths: &[&str]) -> Vec<(Entries, Entries, u32)> {
    paths.iter().map(|path| (listed(fake, path, acl::ACCESS), fs::metadata(fake.path(path)).expect("meta").is_dir().then(|| listed(fake, path, acl::DEFAULT)).flatten(), mode_of(fake, path))).collect()
}

fn changes(acl: Result<Acl, Failure>) -> u32 {
    acl.expect("applied").changes
}

/// A folder tree: `/srv/app` with `a/b`, a plain file, an executable and a file in the deepest folder, all 0755 or 0644.
fn app_tree(fake: &Fake) -> Vec<&'static str> {
    fs::create_dir_all(fake.path("/srv/app/a/b")).expect("folders");
    for (path, mode) in [("/srv/app/top.txt", 0o644), ("/srv/app/a/run.sh", 0o755), ("/srv/app/a/b/file", 0o644)] {
        fs::write(fake.path(path), "x").expect("file");
        fs::set_permissions(fake.path(path), fs::Permissions::from_mode(mode)).expect("mode");
    }
    for folder in ["/srv/app", "/srv/app/a", "/srv/app/a/b"] {
        fs::set_permissions(fake.path(folder), fs::Permissions::from_mode(0o755)).expect("mode");
    }
    vec!["/srv/app", "/srv/app/a", "/srv/app/a/b", "/srv/app/top.txt", "/srv/app/a/run.sh", "/srv/app/a/b/file"]
}

#[test]
fn acl_ensure_path_scope_sets_one_entry_on_one_path_and_repeats_with_zero() {
    let fake = Fake::new();
    let paths = app_tree(&fake);
    let input = json!({"path": "/srv/app", "user": "web", "perms": "r-x"});
    assert_eq!(acl_ensure(&fake, &input), Ok(Acl { changes: 1, skipped_mounts: 0, skipped_hard_links: 0 }));
    assert_eq!(listed(&fake, "/srv/app", acl::ACCESS), Some(vec![(1, NONE, 7), (2, WEB, 5), (4, NONE, 5), (0x10, NONE, 5), (0x20, NONE, 5)]));
    assert!(paths[1..].iter().all(|path| listed(&fake, path, acl::ACCESS).is_none()), "nothing below the path changes");
    assert_eq!(changes(acl_ensure(&fake, &input)), 0);
    assert_eq!(changes(acl_ensure(&fake, &json!({"path": "/srv/missing", "user": "web", "perms": "r-x", "optional": true}))), 0);
    for bad in [json!({"path": "/srv/app", "user": "web", "perms": "rwxa"}), json!({"path": "/srv/app", "user": "web", "group": "team", "perms": "r-x"}), json!({"path": "/srv/app", "perms": "r-x"}), json!({"path": "/srv/app", "user": "web", "perms": "r-x", "run_as": "app"}), json!({"path": "/srv/app", "user": "web", "perms": "r-x", "scope": "recursive"})] {
        assert_eq!(acl_ensure(&fake, &bad).expect_err("invalid").code, "host.input_invalid", "{bad}");
    }
}

#[test]
fn acl_ensure_compares_exact_effective_permissions() {
    let fake = Fake::new();
    app_tree(&fake);
    // `--x` is not `---`, and a mask that cuts the entry is a difference too.
    put(&fake, "/srv/app/top.txt", acl::ACCESS, &[(1, NONE, 6), (2, WEB, 1), (4, NONE, 4), (0x10, NONE, 1), (0x20, NONE, 4)]);
    assert_eq!(changes(acl_ensure(&fake, &json!({"path": "/srv/app/top.txt", "user": "web", "perms": "---"}))), 1);
    put(&fake, "/srv/app/a", acl::ACCESS, &[(1, NONE, 7), (2, WEB, 5), (4, NONE, 4), (0x10, NONE, 4), (0x20, NONE, 5)]);
    assert_eq!(changes(acl_ensure(&fake, &json!({"path": "/srv/app/a", "user": "web", "perms": "r-x"}))), 1);
    assert_eq!(listed(&fake, "/srv/app/a", acl::ACCESS), Some(vec![(1, NONE, 7), (2, WEB, 5), (4, NONE, 4), (0x10, NONE, 5), (0x20, NONE, 5)]));
    // `X` is search on a folder and an executable, nothing on a plain file.
    assert_eq!(changes(acl_ensure(&fake, &json!({"path": "/srv/app/a/b/file", "user": "web", "perms": "r-X"}))), 1);
    assert_eq!(listed(&fake, "/srv/app/a/b/file", acl::ACCESS).expect("acl")[1], (2, WEB, 4));
}

#[test]
fn acl_ensure_path_scope_refuses_a_mask_that_widens_another_entry() {
    let fake = Fake::new();
    app_tree(&fake);
    let start = [(1, NONE, 7), (2, OTHER_USER, 6), (4, NONE, 4), (0x10, NONE, 4), (0x20, NONE, 5)];
    put(&fake, "/srv/app", acl::ACCESS, &start);
    assert_eq!(changes(acl_ensure(&fake, &json!({"path": "/srv/app", "user": "web", "perms": "r--"}))), 1);
    assert_eq!(listed(&fake, "/srv/app", acl::ACCESS).expect("acl")[4], (0x10, NONE, 4), "the mask stays");
    put(&fake, "/srv/app", acl::ACCESS, &start);
    assert_eq!(acl_ensure(&fake, &json!({"path": "/srv/app", "user": "web", "perms": "rw-"})).expect_err("would widen").code, "acl.ensure_failed");
    assert_eq!(listed(&fake, "/srv/app", acl::ACCESS), Some(start.to_vec()));
}

#[test]
fn acl_ensure_tree_converges_one_entry_on_every_folder_and_file() {
    let fake = Fake::new();
    let paths = app_tree(&fake);
    let input = json!({"path": "/srv/app", "user": "web", "perms": "r-X", "scope": "tree"});
    assert_eq!(acl_ensure(&fake, &input), Ok(Acl { changes: 6, skipped_mounts: 0, skipped_hard_links: 0 }));
    for path in &paths {
        let search = !["/srv/app/top.txt", "/srv/app/a/b/file"].contains(path);
        assert_eq!(listed(&fake, path, acl::ACCESS).expect("acl")[1], (2, WEB, if search { 5 } else { 4 }), "{path}");
    }
    assert_eq!(changes(acl_ensure(&fake, &input)), 0, "a repeat changes nothing");
    put(&fake, "/srv/app/a/b", acl::ACCESS, &[(1, NONE, 7), (2, WEB, 7), (4, NONE, 5), (0x10, NONE, 7), (0x20, NONE, 5)]);
    assert_eq!(changes(acl_ensure(&fake, &input)), 1, "only the drifted path changes");
}

#[test]
fn acl_ensure_tree_takes_a_group_subject_for_access_and_default_entries() {
    let fake = Fake::new();
    app_tree(&fake);
    assert_eq!(changes(acl_ensure(&fake, &json!({"path": "/srv/app", "group": "team", "perms": "r-X", "scope": "tree"}))), 6);
    assert_eq!(listed(&fake, "/srv/app/top.txt", acl::ACCESS), Some(vec![(1, NONE, 6), (4, NONE, 4), (8, TEAM, 4), (0x10, NONE, 4), (0x20, NONE, 4)]));
    assert_eq!(changes(acl_ensure(&fake, &json!({"path": "/srv/app", "group": "team", "perms": "r-x", "default": true, "scope": "tree"}))), 3);
    assert_eq!(listed(&fake, "/srv/app/a", acl::DEFAULT), Some(vec![(1, NONE, 7), (4, NONE, 5), (8, TEAM, 5), (0x10, NONE, 5), (0x20, NONE, 5)]));
}

#[test]
fn acl_ensure_tree_skips_links_and_never_follows_them() {
    let fake = Fake::new();
    app_tree(&fake);
    fs::create_dir_all(fake.path("/outside/deep")).expect("outside");
    fs::write(fake.path("/outside/secret"), "s").expect("secret");
    std::os::unix::fs::symlink(fake.path("/outside"), fake.path("/srv/app/to-folder")).expect("link");
    std::os::unix::fs::symlink(fake.path("/outside/secret"), fake.path("/srv/app/a/to-file")).expect("link");
    std::os::unix::fs::symlink("../../outside", fake.path("/srv/app/a/relative")).expect("link");
    std::os::unix::fs::symlink("/", fake.path("/srv/app/to-root")).expect("link");
    let outside = snapshot(&fake, &["/outside", "/outside/deep", "/outside/secret"]);
    assert_eq!(changes(acl_ensure(&fake, &json!({"path": "/srv/app", "user": "web", "perms": "rwX", "scope": "tree"}))), 6, "four links skipped");
    assert_eq!(snapshot(&fake, &["/outside", "/outside/deep", "/outside/secret"]), outside, "nothing a link points to changes");
    // A root that is a link is skipped; a link among the root's ancestors refuses.
    assert_eq!(changes(acl_ensure(&fake, &json!({"path": "/srv/app/to-folder", "user": "web", "perms": "rwX", "scope": "tree"}))), 0);
    assert_eq!(acl_ensure(&fake, &json!({"path": "/srv/app/to-folder/deep", "user": "web", "perms": "rwX", "scope": "tree"})).expect_err("ancestor link").code, "acl.ensure_failed");
    assert_eq!(acl_ensure(&fake, &json!({"path": "/srv/app/to-folder/deep", "user": "web", "perms": "rwX"})).expect_err("ancestor link").code, "acl.ensure_failed");
    assert_eq!(snapshot(&fake, &["/outside", "/outside/deep", "/outside/secret"]), outside);
}

#[test]
fn acl_ensure_tree_refuses_the_root_its_aliases_and_a_plain_file() {
    let fake = Fake::new();
    app_tree(&fake);
    for root in ["/", "//", "/.", "/..", "/./", "/srv/..", "/srv/../", "/srv/./..", "srv", ""] {
        assert_eq!(acl_ensure(&fake, &json!({"path": root, "user": "web", "perms": "r-x", "scope": "tree"})).expect_err(root).code, "host.input_invalid", "{root}");
    }
    assert_eq!(acl_ensure(&fake, &json!({"path": "/srv/app/top.txt", "user": "web", "perms": "r-x", "scope": "tree"})).expect_err("file").code, "host.input_invalid");
    assert!(listed(&fake, "/srv/app/top.txt", acl::ACCESS).is_none());
}

#[test]
fn acl_ensure_tree_grant_may_widen_the_mask_but_never_owner_or_other_bits() {
    let fake = Fake::new();
    let paths = app_tree(&fake);
    for path in &paths {
        put(&fake, path, acl::ACCESS, &[(1, NONE, 7), (2, OTHER_USER, 6), (4, NONE, 4), (8, TEAM, 5), (0x10, NONE, 4), (0x20, NONE, 1)]);
    }
    let before: Vec<u32> = paths.iter().map(|path| mode_of(&fake, path)).collect();
    assert_eq!(changes(acl_ensure(&fake, &json!({"path": "/srv/app", "user": "web", "perms": "rw-", "scope": "tree"}))), 6);
    for (path, mode) in paths.iter().zip(before) {
        let held = listed(&fake, path, acl::ACCESS).expect("acl");
        // As `setfacl -R -m` does, the mask widens so the entry takes effect; every other entry keeps its own bits.
        assert_eq!(held, vec![(1, NONE, 7), (2, WEB, 6), (2, OTHER_USER, 6), (4, NONE, 4), (8, TEAM, 5), (0x10, NONE, 6), (0x20, NONE, 1)], "{path}");
        assert_eq!(mode_of(&fake, path) & 0o7707, mode & 0o7707, "{path}: owner and other bits");
    }
}

#[test]
fn acl_ensure_tree_sets_a_default_entry_on_folders_only() {
    let fake = Fake::new();
    let paths = app_tree(&fake);
    let input = json!({"path": "/srv/app", "user": "web", "perms": "---", "default": true, "scope": "tree"});
    assert_eq!(changes(acl_ensure(&fake, &input)), 3, "three folders, no file");
    for folder in &paths[..3] {
        // The first default entry seeds the default list from the access list.
        assert_eq!(listed(&fake, folder, acl::DEFAULT), Some(vec![(1, NONE, 7), (2, WEB, 0), (4, NONE, 5), (0x10, NONE, 5), (0x20, NONE, 5)]), "{folder}");
        assert!(listed(&fake, folder, acl::ACCESS).is_none(), "{folder}: no access entry");
    }
    assert!(paths[3..].iter().all(|file| listed(&fake, file, acl::ACCESS).is_none()));
    assert_eq!(changes(acl_ensure(&fake, &input)), 0);
    // The access entry of the same tree reaches the files too and leaves the default lists as they are.
    assert_eq!(changes(acl_ensure(&fake, &json!({"path": "/srv/app", "user": "web", "perms": "---", "scope": "tree"}))), 6);
    assert_eq!(listed(&fake, "/srv/app/a", acl::DEFAULT).expect("default")[1], (2, WEB, 0));
}

#[test]
fn acl_ensure_tree_rolls_every_change_back_when_a_read_fails() {
    let fake = Fake::new();
    let paths = app_tree(&fake);
    fs::create_dir_all(fake.path("/srv/app/z-closed/inner")).expect("closed");
    let before = snapshot(&fake, &paths);
    fs::set_permissions(fake.path("/srv/app/z-closed"), fs::Permissions::from_mode(0o000)).expect("close");
    let input = json!({"path": "/srv/app", "user": "web", "perms": "r-x", "scope": "tree", "optional": true});
    let failure = acl_ensure(&fake, &input).expect_err("unreadable folder");
    fs::set_permissions(fake.path("/srv/app/z-closed"), fs::Permissions::from_mode(0o755)).expect("open");
    assert_eq!(failure.code, "acl.ensure_failed");
    assert!(failure.detail.contains("/srv/app/z-closed"), "{}", failure.detail);
    assert_eq!(snapshot(&fake, &paths), before, "the paths written before the failure are put back");
    assert_eq!(acl_ensure(&fake, &json!({"path": "/srv/none", "user": "web", "perms": "r-x", "scope": "tree"})).expect_err("missing").code, "acl.ensure_failed");
}

#[test]
fn acl_ensure_tree_rolls_back_a_failed_write_and_a_failed_read_back() {
    let fake = Fake::new();
    let paths = app_tree(&fake);
    put(&fake, "/srv/app/a", acl::ACCESS, &[(1, NONE, 7), (2, OTHER_USER, 4), (4, NONE, 5), (0x10, NONE, 5), (0x20, NONE, 5)]);
    let before = snapshot(&fake, &paths);
    let input = json!({"path": "/srv/app", "user": "web", "perms": "r-X", "scope": "tree"});
    fake.fail_set.set(Some(4));
    assert_eq!(acl_ensure(&fake, &input).expect_err("write").code, "acl.ensure_failed");
    assert_eq!(snapshot(&fake, &paths), before, "a failed write leaves no write behind");
    fake.fail_set.set(None);
    fake.sets.set(0);
    fake.fail_read_after.set(Some(5));
    let failure = acl_ensure(&fake, &input).expect_err("read-back");
    assert_eq!(failure.code, "acl.ensure_failed");
    assert!(failure.detail.contains("scripted read failure"), "{}", failure.detail);
    assert_eq!(snapshot(&fake, &paths), before, "a failed read-back leaves no write behind");
    fake.fail_read_after.set(None);
    assert_eq!(changes(acl_ensure(&fake, &input)), 6);
}

#[test]
fn acl_ensure_tree_leaves_hard_linked_files_and_keeps_raw_names() {
    let fake = Fake::new();
    app_tree(&fake);
    fs::create_dir_all(fake.path("/outside")).expect("outside");
    fs::write(fake.path("/outside/shared"), "s").expect("shared");
    fs::hard_link(fake.path("/outside/shared"), fake.path("/srv/app/shared")).expect("hard link");
    let raw = fake.path("/srv/app").join(std::ffi::OsStr::from_bytes(b"caf\xe9"));
    fs::write(&raw, "x").expect("non-UTF-8 name");
    fs::write(fake.path("/srv/app/back\\slash"), "x").expect("backslash name");
    let input = json!({"path": "/srv/app", "user": "web", "perms": "r--", "scope": "tree"});
    assert_eq!(acl_ensure(&fake, &input), Ok(Acl { changes: 8, skipped_mounts: 0, skipped_hard_links: 1 }));
    assert!(listed(&fake, "/outside/shared", acl::ACCESS).is_none(), "a file with a name outside the tree is left as it is");
    let file = fs::File::open(&raw).expect("open");
    assert!(acl::read_xattr(std::os::fd::AsFd::as_fd(&file), acl::ACCESS).expect("read").is_some());
    assert!(listed(&fake, "/srv/app/back\\slash", acl::ACCESS).is_some());
    assert_eq!(acl_ensure(&fake, &input), Ok(Acl { changes: 0, skipped_mounts: 0, skipped_hard_links: 1 }));
}

#[test]
fn acl_ensure_tree_walks_a_large_tree_in_bounded_time() {
    let fake = Fake::new();
    for folder in 0..40 {
        fs::create_dir_all(fake.path(&format!("/srv/big/d{folder}/e"))).expect("folder");
        for file in 0..50 {
            fs::write(fake.path(&format!("/srv/big/d{folder}/e/f{file}")), "x").expect("file");
        }
    }
    // Each changed path holds its descriptor until the order ends: raise the default soft limit of 1,024 to the hard limit.
    let limit = rustix::process::getrlimit(rustix::process::Resource::Nofile);
    rustix::process::setrlimit(rustix::process::Resource::Nofile, rustix::process::Rlimit { current: limit.maximum, maximum: limit.maximum }).expect("raise the open-file limit");
    let input = json!({"path": "/srv/big", "user": "web", "perms": "r-X", "scope": "tree"});
    let start = std::time::Instant::now();
    assert_eq!(changes(acl_ensure(&fake, &input)), 1 + 80 + 2000);
    assert_eq!(changes(acl_ensure(&fake, &input)), 0);
    assert!(start.elapsed() < std::time::Duration::from_secs(10), "{:?}", start.elapsed());
}

#[test]
fn acl_ensure_leaves_a_no_op_order_without_resolving_its_subject() {
    let fake = Fake::new();
    app_tree(&fake);
    std::os::unix::fs::symlink(fake.path("/srv/app"), fake.path("/srv/link")).expect("link");
    // `ghost` resolves to no account: these orders answer 0 because they never look it up.
    for (input, what) in [
        (json!({"path": "/srv/missing", "user": "ghost", "perms": "r-x", "optional": true}), "an optional missing path"),
        (json!({"path": "/srv/missing", "group": "ghost", "perms": "r-x", "optional": true, "scope": "tree"}), "an optional missing tree"),
        (json!({"path": "/srv/link", "user": "ghost", "perms": "r-x"}), "a final link"),
        (json!({"path": "/srv/link", "user": "ghost", "perms": "r-x", "scope": "tree"}), "a final link as a tree root"),
        (json!({"path": "/srv/app/top.txt", "user": "ghost", "perms": "r-x", "default": true}), "a default entry on a file"),
    ] {
        assert_eq!(changes(acl_ensure(&fake, &input)), 0, "{what}");
    }
    // A path the entry applies to looks the subject up, and an unknown one fails.
    assert_eq!(acl_ensure(&fake, &json!({"path": "/srv/app", "user": "ghost", "perms": "r-x"})).expect_err("unknown").code, "acl.ensure_failed");
}

#[test]
fn acl_ensure_path_scope_sets_the_access_entry_on_fifos_sockets_and_closed_files() {
    let fake = Fake::new();
    app_tree(&fake);
    let status = std::process::Command::new("mkfifo").arg(fake.path("/srv/app/pipe")).status().expect("mkfifo");
    assert!(status.success(), "mkfifo");
    let _socket = std::os::unix::net::UnixListener::bind(fake.path("/srv/app/sock")).expect("socket");
    fs::set_permissions(fake.path("/srv/app/sock"), fs::Permissions::from_mode(0o644)).expect("socket mode");
    fs::write(fake.path("/srv/app/closed"), "x").expect("file");
    fs::set_permissions(fake.path("/srv/app/closed"), fs::Permissions::from_mode(0o000)).expect("mode 0000");
    for path in ["/srv/app/pipe", "/srv/app/sock", "/srv/app/closed"] {
        assert_eq!(changes(acl_ensure(&fake, &json!({"path": path, "user": "web", "perms": "r-X"}))), 1, "{path}");
        let bytes = raw(&fake, path);
        assert_eq!((u16::from_le_bytes([bytes[12], bytes[13]]), u32::from_le_bytes([bytes[16], bytes[17], bytes[18], bytes[19]]), u16::from_le_bytes([bytes[14], bytes[15]])), (2, WEB, 4), "{path}");
        assert_eq!(changes(acl_ensure(&fake, &json!({"path": path, "user": "web", "perms": "r-X"}))), 0, "{path}: a repeat");
        assert_eq!(changes(acl_ensure(&fake, &json!({"path": path, "user": "web", "perms": "r-x", "default": true}))), 0, "{path}: no default list");
    }
    // The tree scope still converges folders and plain files only, and never opens a FIFO or a socket.
    let before = (raw(&fake, "/srv/app/pipe"), raw(&fake, "/srv/app/sock"));
    assert_eq!(changes(acl_ensure(&fake, &json!({"path": "/srv/app", "user": "web", "perms": "rwX", "scope": "tree"}))), 7);
    assert_eq!((raw(&fake, "/srv/app/pipe"), raw(&fake, "/srv/app/sock")), before);
}

/// A path's access attribute as bytes, read through an `O_PATH` descriptor, so a FIFO, socket or closed file reads too.
fn raw(fake: &Fake, path: &str) -> Vec<u8> {
    let fd = rustix::fs::open(fake.path(path), rustix::fs::OFlags::PATH | rustix::fs::OFlags::NOFOLLOW, rustix::fs::Mode::empty()).expect("open");
    acl::read_xattr(std::os::fd::AsFd::as_fd(&fd), acl::ACCESS).expect("read").expect("an access list")
}

#[test]
fn acl_ensure_keeps_prior_lists_in_buffers_of_their_exact_size() {
    let fake = Fake::new();
    app_tree(&fake);
    put(&fake, "/srv/app", acl::ACCESS, &[(1, NONE, 7), (2, OTHER_USER, 5), (4, NONE, 5), (0x10, NONE, 5), (0x20, NONE, 5)]);
    let bytes = raw(&fake, "/srv/app");
    assert_eq!((bytes.len(), bytes.capacity()), (4 + 5 * 8, 4 + 5 * 8), "a rollback holds the list's own bytes, not a 64 KiB buffer");
    let file = fs::File::open(fake.path("/srv/app")).expect("open");
    let read = acl::read_xattr(std::os::fd::AsFd::as_fd(&file), acl::ACCESS).expect("read").expect("list");
    assert_eq!(read.capacity(), read.len());
}

/// An account database as `getent` answers it: an ordinary user, and www-data, whose home is not below /home.
fn accounts() -> Fake {
    let fake = Fake::new();
    for (database, key, line) in [
        ("passwd", "alice", "alice:x:1000:1000:Alice:/home/alice:/bin/bash"),
        ("passwd", "www-data", "www-data:x:33:33:www-data:/var/www:/usr/sbin/nologin"),
        ("group", "1000", "alice:x:1000:"),
        ("group", "33", "www-data:x:33:caddy"),
    ] {
        fake.answer(database, key, Some(0), format!("{line}\n").as_bytes());
    }
    fake
}

impl Fake {
    fn answer(&self, database: &str, key: &str, code: Option<i32>, out: &[u8]) {
        self.getent.borrow_mut().insert(format!("{database} {key}"), (code, out.to_vec()));
    }
}

#[test]
fn account_read_answers_an_existing_users_ids_primary_group_and_home() {
    let fake = accounts();
    let read = account_read(&fake, &json!({"user": "alice"})).expect("read");
    assert_eq!(read, Account::Present { user: "alice".to_owned(), uid: 1000, gid: 1000, group: "alice".to_owned(), home: "/home/alice".to_owned() });
    // The answer holds the ids, the group's name and the home, and nothing else: no password and no shadow data.
    assert_eq!(serde_json::to_value(&read).expect("json"), json!({"state": "present", "user": "alice", "uid": 1000, "gid": 1000, "group": "alice", "home": "/home/alice"}));
    // Two fixed getent calls with `--` first; only the checked name and the gid it read are arguments.
    assert_eq!(*fake.ran.borrow(), [strings(&["/usr/bin/getent", "--", "passwd", "alice"]), strings(&["/usr/bin/getent", "--", "group", "1000"])]);
}

#[test]
fn account_read_answers_the_home_nss_holds() {
    let fake = accounts();
    assert_eq!(account_read(&fake, &json!({"user": "www-data"})).expect("read"), Account::Present { user: "www-data".to_owned(), uid: 33, gid: 33, group: "www-data".to_owned(), home: "/var/www".to_owned() });
}

#[test]
fn account_read_answers_absent_only_when_getent_passwd_has_no_such_user() {
    let fake = accounts();
    let read = account_read(&fake, &json!({"user": "nobody-here"})).expect("absent");
    assert_eq!(read, Account::Absent);
    assert_eq!(serde_json::to_value(read).expect("json"), json!({"state": "absent"}));
    assert_eq!(*fake.ran.borrow(), [strings(&["/usr/bin/getent", "--", "passwd", "nobody-here"])]);
}

#[test]
fn account_read_takes_only_a_user_object() {
    let fake = accounts();
    for user in ["", "Alice", "al ice", "../root", "a\0b", "-alice", "--help", "a:b", "a234567890123456789012345678901234"] {
        assert_eq!(account_read(&fake, &json!({"user": user})).expect_err(user).code, "host.input_invalid", "{user}");
    }
    for input in [json!({"user": "alice", "shadow": true}), json!({}), json!(["www-data"]), json!("www-data"), json!({"user": 33})] {
        assert_eq!(account_read(&fake, &input).expect_err("not a user object").code, "host.input_invalid", "{input}");
    }
    assert!(fake.ran.borrow().is_empty(), "a refused input runs nothing");
}

#[test]
fn account_read_fails_a_primary_group_nss_cannot_resolve() {
    let fake = accounts();
    fake.answer("group", "1000", Some(2), b"");
    assert_eq!(account_read(&fake, &json!({"user": "alice"})).expect_err("no primary group").code, "account.read_failed");
}

#[test]
fn account_read_fails_every_other_getent_answer_and_never_answers_absent() {
    // 1: a bad argument; 3: enumeration unsupported; 124: the time limit; 127: no getent; None: killed by a signal.
    for code in [Some(1), Some(3), Some(124), Some(127), None] {
        let fake = accounts();
        fake.answer("passwd", "alice", code, b"");
        assert_eq!(account_read(&fake, &json!({"user": "alice"})).expect_err("passwd").code, "account.read_failed", "{code:?}");
        let fake = accounts();
        fake.answer("group", "1000", code, b"");
        assert_eq!(account_read(&fake, &json!({"user": "alice"})).expect_err("group").code, "account.read_failed", "{code:?}");
    }
}

#[test]
fn account_read_fails_a_line_it_cannot_parse() {
    for line in ["", "garbage\n", "alice:x:1000:1000:Alice:/home/alice\n", "alice:x:1000:1000:A:/home/alice:/bin/sh:extra\n", "alice:x:-1:1000::/home/alice:/bin/sh\n",
        "alice:x:1000:abc::/home/alice:/bin/sh\n", ":x:1000:1000::/home/alice:/bin/sh\n", "alice:x:1000:1000::/home/alice:/bin/sh\nalice:x:1001:1001::/home/a:/bin/sh\n"] {
        let fake = accounts();
        fake.answer("passwd", "alice", Some(0), line.as_bytes());
        assert_eq!(account_read(&fake, &json!({"user": "alice"})).expect_err(line).code, "account.read_failed", "{line}");
    }
    for line in ["", "alice\n", "alice:x:1001:\n", ":x:1000:\n", "alice:x:1000\n"] {
        let fake = accounts();
        fake.answer("group", "1000", Some(0), line.as_bytes());
        assert_eq!(account_read(&fake, &json!({"user": "alice"})).expect_err(line).code, "account.read_failed", "{line}");
    }
}

#[test]
fn account_read_answers_the_canonical_name_nss_resolves() {
    // A source such as sss or LDAP may resolve an alias or a case-folded name to its canonical account.
    let fake = accounts();
    fake.answer("passwd", "alice-ad", Some(0), b"alice:x:1000:1000:Alice:/home/alice:/bin/bash\n");
    let read = account_read(&fake, &json!({"user": "alice-ad"})).expect("read");
    assert_eq!(read, Account::Present { user: "alice".to_owned(), uid: 1000, gid: 1000, group: "alice".to_owned(), home: "/home/alice".to_owned() });
}

#[test]
fn account_read_fails_bytes_that_are_not_utf8_and_never_changes_them() {
    let fake = accounts();
    fake.answer("passwd", "alice", Some(0), b"alice:x:1000:1000::/home/al\xffice:/bin/sh\n");
    assert_eq!(account_read(&fake, &json!({"user": "alice"})).expect_err("home").code, "account.read_failed");
    let fake = accounts();
    fake.answer("group", "1000", Some(0), b"al\xe9ice:x:1000:\n");
    assert_eq!(account_read(&fake, &json!({"user": "alice"})).expect_err("group").code, "account.read_failed");
    // Valid UTF-8 beyond ASCII passes unchanged.
    let fake = accounts();
    fake.answer("passwd", "alice", Some(0), "alice:x:1000:1000::/home/ali\u{e7}e:/bin/sh\n".as_bytes());
    assert!(matches!(account_read(&fake, &json!({"user": "alice"})).expect("read"), Account::Present { home, .. } if home == "/home/ali\u{e7}e"));
}

#[test]
fn the_getent_argv_queries_one_key_also_under_posixly_correct() {
    // The host's real getent, with and without POSIXLY_CORRECT, which stops option parsing at the first operand.
    let run = |argv: &[String], posix: bool| {
        let mut command = std::process::Command::new(&argv[0]);
        command.args(&argv[1..]).env_remove("POSIXLY_CORRECT");
        if posix {
            command.env("POSIXLY_CORRECT", "1");
        }
        let output = command.output().expect("getent runs");
        (output.status.code(), String::from_utf8(output.stdout).expect("utf-8"))
    };
    for posix in [false, true] {
        let (code, out) = run(&account::getent_argv("passwd", "root"), posix);
        assert_eq!(code, Some(0), "posix {posix}");
        assert_eq!(out.lines().collect::<Vec<_>>().len(), 1, "posix {posix}: {out}");
        assert!(out.starts_with("root:"), "posix {posix}: {out}");
    }
    // The argv with `--` after the database reads `--` as a second key under POSIXLY_CORRECT, and exits 2.
    assert_eq!(run(&strings(&["/usr/bin/getent", "passwd", "--", "root"]), true).0, Some(2));
}

#[test]
fn account_read_repeated_answers_the_same() {
    let fake = accounts();
    for user in ["alice", "www-data", "nobody-here"] {
        let first = account_read(&fake, &json!({"user": user})).expect(user);
        assert_eq!(account_read(&fake, &json!({"user": user})).expect(user), first, "{user}");
    }
}

#[test]
fn account_read_reads_the_hosts_own_account_database() {
    // The Node's system, through the real getent: root's home is /root, not /home/root.
    let system = NodeSystem::new("/nonexistent");
    assert_eq!(account_read(&system, &json!({"user": "root"})).expect("root"), Account::Present { user: "root".to_owned(), uid: 0, gid: 0, group: "root".to_owned(), home: "/root".to_owned() });
    assert_eq!(account_read(&system, &json!({"user": "no-such-user-here"})).expect("absent"), Account::Absent);
}

/// A fake systemd for `systemd.unit.restart` and `systemd.unit.signal`: one unit whose run a restart replaces, a clock the
/// test moves, and every `systemctl` argv it was given. Only the clean runner answers; `run` fails, so a primitive that
/// used the inheriting runner fails its test.
struct Systemd {
    root: tempfile::TempDir,
    load: &'static str,
    active: RefCell<&'static str>,
    invocation: RefCell<u32>,
    entered: RefCell<u64>,
    main_pid: RefCell<u64>,
    /// Reads after a restart before the new run shows; `None` never shows it.
    settle: RefCell<Option<u32>>,
    /// The state the new run settles in.
    settles_as: &'static str,
    pending: RefCell<Option<u32>>,
    calls: RefCell<Vec<Vec<String>>>,
    /// The time limit each call was given.
    limits: RefCell<Vec<std::time::Duration>>,
    /// How long each call takes on the test's clock.
    cost: std::time::Duration,
    /// Each call answers after its full cost, past its time limit, as a command `timeout` could not end at once.
    overrun: bool,
    clock: RefCell<std::time::Instant>,
}

impl Systemd {
    fn new() -> Self {
        Self { root: tempfile::tempdir().expect("a scratch root"), load: "loaded", active: RefCell::new("active"), invocation: RefCell::new(1), entered: RefCell::new(100), main_pid: RefCell::new(42), settle: RefCell::new(Some(2)), settles_as: "active", pending: RefCell::default(), calls: RefCell::default(), limits: RefCell::default(), cost: std::time::Duration::ZERO, overrun: false, clock: RefCell::new(std::time::Instant::now()) }
    }

    fn verbs(&self, verb: &str) -> usize {
        self.calls.borrow().iter().filter(|argv| argv[1] == verb).count()
    }
}

impl System for Systemd {
    fn root(&self) -> &Path {
        self.root.path()
    }

    fn run(&self, _seconds: u32, _argv: &[String]) -> Result<String, String> {
        Err("the inheriting runner".to_owned())
    }

    fn status(&self, _seconds: u32, _argv: &[String]) -> Result<(Option<i32>, Vec<u8>), String> {
        Err("no status".to_owned())
    }

    fn run_clean(&self, limit: std::time::Duration, argv: &[String]) -> Result<String, String> {
        self.calls.borrow_mut().push(argv.to_vec());
        self.limits.borrow_mut().push(limit);
        *self.clock.borrow_mut() += if self.overrun { self.cost } else { self.cost.min(limit) };
        if self.cost > limit && !self.overrun {
            return Err("timeout ended the call".to_owned());
        }
        if argv[0] != "/usr/bin/systemctl" || argv.iter().position(|arg| arg == "--") != Some(argv.len() - 2) {
            return Err(format!("not a fixed systemctl argv with the unit as the one operand after --: {argv:?}"));
        }
        match argv[1].as_str() {
            "show" => {
                let mut pending = self.pending.borrow_mut();
                if let Some(left) = pending.as_mut() {
                    if *left == 0 {
                        *pending = None;
                        *self.invocation.borrow_mut() += 1;
                        *self.entered.borrow_mut() += 1000;
                        *self.active.borrow_mut() = self.settles_as;
                        *self.main_pid.borrow_mut() += 1;
                    } else {
                        *left -= 1;
                        *self.active.borrow_mut() = "activating";
                    }
                }
                let invocation = *self.invocation.borrow();
                Ok(format!(
                    "LoadState={}\nActiveState={}\nInvocationID={invocation:032x}\nActiveEnterTimestampMonotonic={}\nMainPID={}\n",
                    self.load, self.active.borrow(), self.entered.borrow(), self.main_pid.borrow()
                ))
            }
            "restart" => {
                *self.pending.borrow_mut() = *self.settle.borrow();
                if self.settle.borrow().is_none() {
                    *self.active.borrow_mut() = "activating";
                }
                Ok(String::new())
            }
            "kill" => Ok(String::new()),
            verb => Err(format!("unexpected verb {verb}")),
        }
    }

    fn blob(&self, _sha256: &str) -> Result<Vec<u8>, String> {
        Err("no blobs".to_owned())
    }

    fn now(&self) -> std::time::Instant {
        *self.clock.borrow()
    }

    fn sleep(&self, duration: std::time::Duration) {
        *self.clock.borrow_mut() += duration;
    }
}

#[test]
fn unit_restart_restarts_once_and_reads_back_a_new_active_run() {
    let systemd = Systemd::new();
    let answer = unit_restart(&systemd, &json!({"unit": "web.service", "budget_ms": 5000})).expect("restarted");
    let answer: serde_json::Value = serde_json::from_str(&answer).expect("json");
    assert_eq!(answer["state"], "restarted");
    assert_eq!(answer["invocation_id"], format!("{:032x}", 2));
    assert_eq!(answer["active_enter_timestamp_monotonic"], 1100);
    assert_eq!(systemd.verbs("restart"), 1);
    let restart = systemd.calls.borrow().iter().find(|argv| argv[1] == "restart").cloned().expect("a restart");
    assert_eq!(restart, strings(&["/usr/bin/systemctl", "restart", "--no-block", "--", "web.service"]));
}

#[test]
fn unit_restart_fails_its_budget_when_no_new_run_shows() {
    let systemd = Systemd::new();
    *systemd.settle.borrow_mut() = None;
    let failure = unit_restart(&systemd, &json!({"unit": "web.service", "budget_ms": 1000})).expect_err("no new run");
    assert_eq!(failure.code, "unit.restart_timeout");
    assert_eq!(systemd.verbs("restart"), 1, "it never restarts again while it waits");
    // A new run that settles failed is no restart.
    let systemd = Systemd { settles_as: "failed", ..Systemd::new() };
    assert_eq!(unit_restart(&systemd, &json!({"unit": "web.service", "budget_ms": 1000})).expect_err("failed run").code, "unit.restart_failed");
}

#[test]
fn unit_restart_and_signal_refuse_a_unit_systemd_has_not_loaded() {
    let systemd = Systemd { load: "not-found", ..Systemd::new() };
    assert_eq!(unit_restart(&systemd, &json!({"unit": "gone.service", "budget_ms": 1000})).expect_err("unloaded").code, "unit.not_loaded");
    assert_eq!(unit_signal(&systemd, &json!({"unit": "gone.service", "signal": "SIGHUP", "budget_ms": 1000})).expect_err("unloaded").code, "unit.not_loaded");
    assert_eq!(systemd.verbs("restart") + systemd.verbs("kill"), 0);
}

#[test]
fn unit_signal_delivers_one_signal_to_the_main_process() {
    let systemd = Systemd::new();
    let answer = unit_signal(&systemd, &json!({"unit": "web.service", "signal": "SIGUSR1", "budget_ms": 1000})).expect("delivered");
    assert_eq!(answer, r#"{"main_pid":42,"signal":"SIGUSR1","state":"delivered"}"#);
    let kill = systemd.calls.borrow().iter().find(|argv| argv[1] == "kill").cloned().expect("a kill");
    assert_eq!(kill, strings(&["/usr/bin/systemctl", "kill", "--kill-who=main", "--signal=SIGUSR1", "--", "web.service"]));
    // A unit with no main process takes no signal.
    let systemd = Systemd::new();
    *systemd.main_pid.borrow_mut() = 0;
    assert_eq!(unit_signal(&systemd, &json!({"unit": "web.service", "signal": "SIGHUP", "budget_ms": 1000})).expect_err("no main").code, "unit.not_running");
    assert_eq!(systemd.verbs("kill"), 0);
}

#[test]
fn unit_restart_and_signal_refuse_names_signals_and_budgets_outside_their_shape() {
    let systemd = Systemd::new();
    for unit in ["-now.service", "--now.service", ".hidden.service", "../web.service", "/etc/systemd/system/web.service", "web", "web.conf", "web service.service", "worker@1.service", "worker@.service", ""] {
        assert_eq!(unit_restart(&systemd, &json!({"unit": unit, "budget_ms": 1000})).expect_err(unit).code, "host.input_invalid", "{unit}");
    }
    assert_eq!(unit_restart(&systemd, &json!({"unit": format!("{}.service", "a".repeat(250)), "budget_ms": 1000})).expect_err("long").code, "host.input_invalid");
    // A template instance passes only when the input admits templates; the bare template never does.
    assert_eq!(unit_restart(&systemd, &json!({"unit": "worker@.service", "template": true, "budget_ms": 1000})).expect_err("bare").code, "host.input_invalid");
    assert!(unit_restart(&systemd, &json!({"unit": "worker@1.service", "template": true, "budget_ms": 1000})).is_ok());
    for signal in ["HUP", "SIGSTOP", "9", "sighup"] {
        assert_eq!(unit_signal(&systemd, &json!({"unit": "web.service", "signal": signal, "budget_ms": 1000})).expect_err(signal).code, "host.input_invalid");
    }
    for budget in [0, 999, 300_001] {
        assert_eq!(unit_restart(&systemd, &json!({"unit": "web.service", "budget_ms": budget})).expect_err("budget").code, "host.input_invalid");
    }
    assert_eq!(unit_restart(&systemd, &json!({"unit": "web.service", "budget_ms": 1000, "argv": ["x"]})).expect_err("unknown field").code, "host.input_invalid");
    assert_eq!(unit_signal(&systemd, &json!({"unit": "web.service", "budget_ms": 1000})).expect_err("no signal").code, "host.input_invalid");
    assert_eq!(systemd.verbs("restart") + systemd.verbs("kill"), 1, "only the admitted template restarted");
}

#[test]
fn unit_restart_and_signal_give_each_call_exactly_the_budget_left_and_never_count_a_late_answer() {
    use std::time::Duration;
    // Each call takes 300 ms of a 1,000 ms budget: the read, the restart and the first read-back get 1000, 700 and 400 ms.
    let systemd = Systemd { cost: Duration::from_millis(300), ..Systemd::new() };
    *systemd.settle.borrow_mut() = Some(0);
    let answer = unit_restart(&systemd, &json!({"unit": "web.service", "budget_ms": 1000})).expect("in time");
    assert!(answer.contains("restarted"));
    assert_eq!(*systemd.limits.borrow(), [1000, 700, 400].map(Duration::from_millis));

    // At 450 ms a call, the third call has 100 ms left and `timeout` ends it at the deadline: no answer, so no restart counts.
    let systemd = Systemd { cost: Duration::from_millis(450), ..Systemd::new() };
    *systemd.settle.borrow_mut() = Some(0);
    let failure = unit_restart(&systemd, &json!({"unit": "web.service", "budget_ms": 1000})).expect_err("late");
    assert_eq!(failure.code, "unit.restart_timeout");
    assert_eq!(*systemd.limits.borrow(), [1000, 550, 100].map(Duration::from_millis), "never rounded up to a whole second");
    assert_eq!(systemd.verbs("restart"), 1);

    // A call that answers past the deadline anyway: the new active run it reports at 1,350 ms is no timely restart.
    let systemd = Systemd { cost: Duration::from_millis(450), overrun: true, ..Systemd::new() };
    *systemd.settle.borrow_mut() = Some(0);
    assert_eq!(unit_restart(&systemd, &json!({"unit": "web.service", "budget_ms": 1000})).expect_err("late answer").code, "unit.restart_timeout");

    // A read that answers after the deadline refuses before any restart.
    let systemd = Systemd { cost: Duration::from_millis(1001), ..Systemd::new() };
    assert_eq!(unit_restart(&systemd, &json!({"unit": "web.service", "budget_ms": 1000})).expect_err("late read").code, "unit.restart_timeout");
    assert_eq!(systemd.verbs("restart"), 0);

    // A kill that answers after the deadline is no delivered signal.
    let systemd = Systemd { cost: Duration::from_millis(600), ..Systemd::new() };
    let failure = unit_signal(&systemd, &json!({"unit": "web.service", "signal": "SIGHUP", "budget_ms": 1000})).expect_err("late kill");
    assert_eq!(failure.code, "unit.signal_timeout");
    assert_eq!(*systemd.limits.borrow(), [1000, 400].map(Duration::from_millis));
}
