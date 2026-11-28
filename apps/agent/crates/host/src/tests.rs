use std::cell::RefCell;
use std::collections::BTreeMap;
use std::fs;
use std::os::unix::fs::{MetadataExt as _, PermissionsExt as _};
use std::path::Path;

use serde_json::json;

use super::*;

/// A scratch root: every user and group is the test's own, blobs live in a map, and commands are recorded and answered
/// by the first rule whose program they run.
struct Fake {
    root: tempfile::TempDir,
    blobs: BTreeMap<String, Vec<u8>>,
    fail: RefCell<Vec<&'static str>>,
    answers: RefCell<Vec<(&'static str, String)>>,
    ran: RefCell<Vec<Vec<String>>>,
    /// Each path's ACL lines, as getfacl lists them and setfacl changes them.
    acls: RefCell<BTreeMap<String, Vec<String>>>,
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
}

impl Fake {
    fn new() -> Self {
        Self { root: tempfile::tempdir().expect("a scratch root"), blobs: BTreeMap::new(), fail: RefCell::default(), answers: RefCell::default(), ran: RefCell::default(), acls: RefCell::default(), unit: RefCell::new(Some((true, true))), transition: RefCell::default(), stop: RefCell::default(), seal: RefCell::default(), relink: RefCell::default() }
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
        let tail = words.iter().position(|arg| *arg == "--").map_or(&words[..0], |at| &words[at + 1..]);
        match words.iter().find(|arg| arg.starts_with('/') && (arg.ends_with("getfacl") || arg.ends_with("setfacl") || arg.ends_with("systemctl") || arg.ends_with("update-ca-certificates") || arg.ends_with("openssl"))) {
            Some(&"/usr/bin/getfacl") => Ok(tail.iter().fold(String::new(), |mut listing, path| {
                use std::fmt::Write as _;
                let _ = write!(listing, "# file: {}\n{}\n", path.replace(' ', "\\040"), self.acls.borrow().get(*path).cloned().unwrap_or_default().join("\n"));
                listing
            })),
            Some(&"/usr/bin/setfacl") => {
                // Each `-m` entry in turn; without an explicit mask (or `-n`), setfacl recomputes the access mask as the union of
                // every group-class entry, as the real one does.
                let entries = words[words.iter().position(|arg| *arg == "-m").expect("-m") + 1];
                let explicit = words.contains(&"-n") || entries.split(',').any(|entry| entry.trim_start_matches("d:").starts_with("m::"));
                for path in tail {
                    let mut acls = self.acls.borrow_mut();
                    let lines = acls.entry((*path).to_owned()).or_default();
                    for entry in entries.split(',') {
                        let (tag, rest) = entry.strip_prefix("d:").map_or(("", entry), |rest| ("default:", rest));
                        let (kind, rest) = rest.split_once(':').expect("a tag");
                        let (qualifier, perms) = rest.split_once(':').expect("perms");
                        let kind = match kind { "u" => "user", "g" => "group", "o" => "other", _ => "mask" };
                        lines.retain(|held| !held.starts_with(&format!("{tag}{kind}:{qualifier}:")));
                        lines.push(format!("{tag}{kind}:{qualifier}:{perms}"));
                    }
                    if !explicit {
                        let group_class = lines.iter().filter(|line| (line.starts_with("user:") && !line.starts_with("user::")) || line.starts_with("group:"));
                        let mask = group_class.fold("---".to_owned(), |held, line| {
                            let perms = line.rsplit(':').next().unwrap_or("---");
                            held.chars().zip(perms.chars()).map(|(a, b)| if a == '-' { b } else { a }).collect()
                        });
                        lines.retain(|held| !held.starts_with("mask::"));
                        lines.push(format!("mask::{mask}"));
                    }
                }
                Ok(String::new())
            }
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

    fn user(&self, _name: &str) -> Result<u32, String> {
        if let Some((dest, source)) = self.relink.borrow_mut().take() {
            fs::remove_file(&dest).expect("remove the destination");
            fs::hard_link(source, dest).expect("relink");
        }
        Ok(me().0)
    }

    fn group(&self, _name: &str) -> Result<u32, String> {
        Ok(me().1)
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
fn acl_ensure_reads_first_and_sets_only_a_differing_entry() {
    let fake = Fake::new();
    fs::create_dir_all(fake.path("/srv/site")).expect("folder");
    let input = json!({"path": "/srv/site", "user": "web", "perms": "r-x", "run_as": "app"});
    assert_eq!(acl_ensure(&fake, &input), Ok(1));
    assert!(fake.ran.borrow().iter().any(|argv| argv == &strings(&["/usr/sbin/runuser", "-u", "app", "--", "/usr/bin/env", "-C", "/", "/usr/bin/setfacl", "-P", "-n", "-m", "u:web:r-x,m::r-x", "--", "/srv/site"])));
    assert_eq!(acl_ensure(&fake, &input), Ok(0));
    assert_eq!(fake.ran("/usr/bin/setfacl"), 1);
    assert_eq!(acl_ensure(&fake, &json!({"path": "/srv/missing", "user": "web", "perms": "r-x", "optional": true})), Ok(0));
    assert_eq!(acl_ensure(&fake, &json!({"path": "/srv/site", "user": "web", "perms": "rwxa"})).expect_err("perms").code, "host.input_invalid");
}

#[test]
fn acl_ensure_compares_exact_effective_permissions_on_its_path() {
    let fake = Fake::new();
    fs::create_dir_all(fake.path("/srv/tree/sub")).expect("folders");
    fs::write(fake.path("/srv/tree/sub/file"), "x").expect("file");
    // `--x` is not `---` (review counterexample: desired `---` reported Ok(0)).
    fake.acls.borrow_mut().insert("/srv/tree".to_owned(), vec!["user:web:--x".to_owned()]);
    assert_eq!(acl_ensure(&fake, &json!({"path": "/srv/tree", "user": "web", "perms": "---"})), Ok(1));
    // A mask that cuts the entry is a difference too.
    fake.acls.borrow_mut().insert("/srv/tree".to_owned(), vec!["user:web:r-x".to_owned(), "mask::r--".to_owned()]);
    assert_eq!(acl_ensure(&fake, &json!({"path": "/srv/tree", "user": "web", "perms": "r-x"})), Ok(1));
    // `X` is search on a folder and nothing on a plain file; a repeat writes nothing.
    let folder = json!({"path": "/srv/tree/sub", "user": "web", "perms": "r-X"});
    assert_eq!(acl_ensure(&fake, &folder), Ok(1));
    assert_eq!(fake.acls.borrow().get("/srv/tree/sub").cloned(), Some(vec!["user:web:r-x".to_owned(), "mask::r-x".to_owned()]), "the mask is written explicitly");
    let file = json!({"path": "/srv/tree/sub/file", "user": "web", "perms": "r-X"});
    assert_eq!(acl_ensure(&fake, &file), Ok(1));
    assert_eq!(fake.acls.borrow().get("/srv/tree/sub/file").cloned(), Some(vec!["user:web:r--".to_owned(), "mask::r--".to_owned()]));
    let writes = fake.ran("/usr/bin/setfacl");
    assert_eq!(acl_ensure(&fake, &folder), Ok(0));
    assert_eq!(fake.ran("/usr/bin/setfacl"), writes);
}

#[test]
fn acl_ensure_acts_on_one_path_and_leaves_its_children_untouched() {
    let fake = Fake::new();
    fs::create_dir_all(fake.path("/srv/top/child")).expect("folders");
    fs::write(fake.path("/srv/top/child/file"), "x").expect("file");
    assert_eq!(acl_ensure(&fake, &json!({"path": "/srv/top", "user": "web", "perms": "r-x"})), Ok(1));
    assert!(fake.acls.borrow().get("/srv/top/child").is_none() && fake.acls.borrow().get("/srv/top/child/file").is_none(), "nothing below the path changes");
    assert!(fake.ran.borrow().iter().all(|argv| !argv.iter().any(|arg| arg.starts_with(&fake.path("/srv/top/child").to_string_lossy().into_owned()) || arg.starts_with("/srv/top/child"))), "no command names a child");
    assert_eq!(acl_ensure(&fake, &json!({"path": "/srv/top", "user": "web", "perms": "r-x", "recursive": true})).expect_err("no recursive input").code, "host.input_invalid");
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
fn acl_ensure_keeps_every_other_entry_effective_permissions() {
    let fake = Fake::new();
    fs::create_dir_all(fake.path("/srv/shared")).expect("folder");
    let start = vec!["group::r--".to_owned(), "user:other:rw-".to_owned(), "mask::r--".to_owned()];
    fake.acls.borrow_mut().insert("/srv/shared".to_owned(), start.clone());
    // Review counterexample: granting one user read recomputed the mask and let another's masked write take effect.
    assert_eq!(acl_ensure(&fake, &json!({"path": "/srv/shared", "user": "web", "perms": "r--"})), Ok(1));
    let held = fake.acls.borrow().get("/srv/shared").cloned().expect("acl");
    assert!(held.contains(&"mask::r--".to_owned()), "the mask stays: {held:?}");
    // An entry the mask could only grant by widening another's effective permissions refuses, before any change.
    fake.acls.borrow_mut().insert("/srv/shared".to_owned(), start.clone());
    let writes = fake.ran("/usr/bin/setfacl");
    assert_eq!(acl_ensure(&fake, &json!({"path": "/srv/shared", "user": "web", "perms": "rw-"})).expect_err("would widen").code, "acl.ensure_failed");
    assert_eq!(fake.ran("/usr/bin/setfacl"), writes);
    assert_eq!(fake.acls.borrow().get("/srv/shared").cloned(), Some(start));
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
fn acl_ensure_seeds_a_first_default_list_from_the_access_list() {
    let fake = Fake::new();
    fs::create_dir_all(fake.path("/srv/team")).expect("folder");
    // Review counterexample: a 0770 folder with no default list; denying one user set the default mask to `---`.
    fake.acls.borrow_mut().insert("/srv/team".to_owned(), vec!["user::rwx".to_owned(), "group::rwx".to_owned(), "other::---".to_owned()]);
    let deny = json!({"path": "/srv/team", "user": "web", "perms": "---", "default": true});
    assert_eq!(acl_ensure(&fake, &deny), Ok(1));
    let held = fake.acls.borrow().get("/srv/team").cloned().expect("acl");
    for line in ["default:user::rwx", "default:group::rwx", "default:other::---", "default:mask::rwx", "default:user:web:---"] {
        assert!(held.contains(&line.to_owned()), "{line} in {held:?}");
    }
    assert_eq!(acl_ensure(&fake, &deny), Ok(0));
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
