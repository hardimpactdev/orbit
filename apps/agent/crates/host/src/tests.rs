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
}

impl Fake {
    fn new() -> Self {
        Self { root: tempfile::tempdir().expect("a scratch root"), blobs: BTreeMap::new(), fail: RefCell::default(), answers: RefCell::default(), ran: RefCell::default(), acls: RefCell::default(), unit: RefCell::new(Some((true, true))), transition: RefCell::default() }
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

    fn run(&self, _seconds: u32, argv: &[String]) -> Result<String, String> {
        self.ran.borrow_mut().push(argv.to_vec());
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
                        if let Some(mask) = rest.strip_prefix("m::") {
                            lines.retain(|held| !held.starts_with(&format!("{tag}mask::")));
                            lines.push(format!("{tag}mask::{mask}"));
                        } else {
                            let (user, perms) = rest.strip_prefix("u:").expect("a user entry").split_once(':').expect("perms");
                            lines.retain(|held| !held.starts_with(&format!("{tag}user:{user}:")));
                            lines.push(format!("{tag}user:{user}:{perms}"));
                        }
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
        "lock": "/tmp-lock/given.lock",
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
    let relative = json!({"lock": "given.lock", "files": [{"path": "/conf/site.conf", "content": content, "from_sha256": null, "owner": owner()}]});
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
    let input = json!({"lock": "/tmp-lock/given.lock", "files": [{"path": "/conf/site.conf", "content": content, "from_sha256": null, "owner": owner()}]});
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
fn acl_ensure_compares_exact_effective_permissions_on_every_path_and_counts_them() {
    let fake = Fake::new();
    fs::create_dir_all(fake.path("/srv/tree/sub")).expect("folders");
    fs::write(fake.path("/srv/tree/sub/file"), "x").expect("file");
    // `--x` is not `---` (review counterexample: desired `---` reported Ok(0)).
    fake.acls.borrow_mut().insert("/srv/tree".to_owned(), vec!["user:web:--x".to_owned()]);
    assert_eq!(acl_ensure(&fake, &json!({"path": "/srv/tree", "user": "web", "perms": "---"})), Ok(1));
    // A mask that cuts the entry is a difference too.
    fake.acls.borrow_mut().insert("/srv/tree".to_owned(), vec!["user:web:r-x".to_owned(), "mask::r--".to_owned()]);
    assert_eq!(acl_ensure(&fake, &json!({"path": "/srv/tree", "user": "web", "perms": "r-x"})), Ok(1));
    // Recursive: every path is counted, `X` is search on folders only, and a repeat writes nothing.
    let recursive = json!({"path": "/srv/tree", "user": "web", "perms": "r-X", "recursive": true});
    assert_eq!(acl_ensure(&fake, &recursive), Ok(2), "the sub folder and the file change; the root already holds r-x");
    assert_eq!(fake.acls.borrow().get("/srv/tree/sub/file").cloned(), Some(vec!["user:web:r--".to_owned(), "mask::r--".to_owned()]), "the mask is written explicitly");
    let writes = fake.ran("/usr/bin/setfacl");
    assert_eq!(acl_ensure(&fake, &recursive), Ok(0));
    assert_eq!(fake.ran("/usr/bin/setfacl"), writes);
}

#[test]
fn unit_ensure_changes_only_what_differs() {
    let fake = Fake::new();
    let input = json!({"unit": "web.service", "enabled": true, "state": "running", "budget_ms": 600});
    assert_eq!(unit_ensure(&fake, &input), Ok(0));
    assert_eq!(fake.ran("start") + fake.ran("enable"), 0);
    assert_eq!(unit_ensure(&fake, &json!({"unit": "web.service", "enabled": false, "state": "stopped", "budget_ms": 600})), Ok(2));
    assert_eq!(unit_ensure(&fake, &json!({"unit": "--now.service", "enabled": true, "state": "running", "budget_ms": 600})).expect_err("flag").code, "host.input_invalid");
}

#[test]
fn unit_ensure_fails_when_systemd_cannot_be_read() {
    let fake = Fake::new();
    *fake.unit.borrow_mut() = None;
    // An unread unit is no stopped unit (review counterexample: a read error passed as disabled and stopped).
    assert_eq!(unit_ensure(&fake, &json!({"unit": "web.service", "enabled": false, "state": "stopped", "budget_ms": 600})).expect_err("unread").code, "unit.ensure_failed");
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
    assert_eq!(trust_ensure(&fake, &json!({"state": "absent", "name": "../x.crt"})).expect_err("name").code, "host.input_invalid");
    assert_eq!(trust_ensure(&fake, &json!({"state": "absent", "name": "a-root.crt"})), Ok(1));
    assert!(fs::symlink_metadata(fake.path("/etc/ssl/certs/a-root.pem")).is_err());
    assert_eq!(trust_ensure(&fake, &json!({"state": "absent", "name": "a-root.crt"})), Ok(0));
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
    assert_eq!(probe(&fake, &json!({"url": "file:///etc/shadow", "budget_ms": 600})).expect_err("scheme").code, "host.input_invalid");
}

#[test]
fn config_apply_replay_after_a_stop_keeps_the_rendered_from_bytes_as_previous() {
    let mut fake = Fake::new();
    lock_folder(&fake);
    fake.write("/conf/site.conf", b"old\n");
    fake.fail.borrow_mut().push("validate");
    let validators = json!([{"kind": "caddy", "config": "/conf/site.conf", "user": "web"}]);
    // The first run writes the candidate, keeps the old bytes beside it, and fails validation: the old bytes go back.
    assert_eq!(apply(&mut fake, b"new\n", b"old\n", &validators).expect_err("invalid").code, "config.validate_failed");
    assert_eq!(fake.read("/conf/site.conf"), b"old\n");
    // A stop between the move and the validation leaves the candidate live and the kept copy beside it.
    fake.write("/conf/site.conf", b"new\n");
    fake.write(&format!("/conf/.site.conf.previous-{}", sha256(b"old\n")), b"old\n");
    assert_eq!(apply(&mut fake, b"new\n", b"old\n", &validators).expect_err("still invalid").code, "config.validate_failed");
    assert_eq!(fake.read("/conf/site.conf"), b"old\n", "the replay restores the rendered-from bytes, not the unvalidated candidate");
    // A run that loads drops the kept copy.
    fake.fail.borrow_mut().clear();
    assert_eq!(apply(&mut fake, b"new\n", b"old\n", &validators), Ok(1));
    assert!(!fake.path(&format!("/conf/.site.conf.previous-{}", sha256(b"old\n"))).exists());
}

#[test]
fn config_apply_reloads_on_recovery_only_the_units_it_reached() {
    let mut fake = Fake::new();
    lock_folder(&fake);
    fake.write("/conf/site.conf", b"old\n");
    fake.fail.borrow_mut().push("first.service");
    let content = fake.put(b"new\n");
    let input = json!({"lock": "/tmp-lock/given.lock", "files": [{"path": "/conf/site.conf", "content": content, "from_sha256": sha256(b"old\n"), "owner": owner()}],
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
        let input = json!({"lock": "/tmp-lock/given.lock", "files": files});
        assert_eq!(config_apply(&fake, &input).expect_err("an alias").code, "host.input_invalid");
    }
    std::os::unix::fs::symlink(fake.path("/conf"), fake.path("/alias")).expect("alias");
    let input = json!({"lock": "/tmp-lock/given.lock", "files": [file("/conf/a"), file("/alias/a")]});
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
    let input = json!({"lock": "/tmp-lock/given.lock", "files": [{"path": "/conf/site.conf", "content": content, "from_sha256": sha256(b"old\n"), "owner": owner()}],
        "validators": [validator], "units": [{"unit": "dnsmasq.service", "load": "restart"}]});
    assert_eq!(config_apply(&fake, &input).expect_err("rejected").code, "config.validate_failed");
    assert!(fake.ran.borrow().iter().any(|argv| argv == &strings(&["/usr/sbin/dnsmasq", "--test", "--conf-file=/conf/dnsmasq.conf", "--conf-dir=/conf,.bak"])), "the folder the service loads, not the one file");
    assert_eq!(fake.ran("restart"), 0, "a rejected set never restarts the service");
    assert_eq!(fake.read("/conf/site.conf"), b"old\n");
    let comma = json!({"lock": "/tmp-lock/given.lock", "files": input["files"].clone(), "validators": [{"kind": "dnsmasq", "conf_file": "/conf/dnsmasq.conf", "conf_dir": "/conf,*.x"}]});
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
    let input = json!({"lock": "/tmp-lock/given.lock", "files": [{"path": "/conf/site.conf", "content": content, "from_sha256": null, "owner": owner()}], "validators": validators});
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
fn scratch_work_never_touches_a_declared_file_the_lock_or_an_unmanaged_sibling() {
    let mut fake = Fake::new();
    lock_folder(&fake);
    fake.write("/conf/a", b"old\n");
    let from = sha256(b"old\n");
    let content = fake.put(b"new\n");
    let file = |path: &str, from: Option<&str>| json!({"path": path, "content": content.clone(), "from_sha256": from, "owner": owner()});
    let kept = format!("/conf/.a.previous-{from}");
    // A declared file, or the lock, under the kept copy's name refuses before anything changes.
    let both = json!({"lock": "/tmp-lock/given.lock", "files": [file("/conf/a", Some(&from)), file(&kept, None)]});
    assert_eq!(config_apply(&fake, &both).expect_err("a kept name declared").code, "host.input_invalid");
    let lock = json!({"lock": kept, "files": [file("/conf/a", Some(&from))]});
    assert_eq!(config_apply(&fake, &lock).expect_err("the lock under a kept name").code, "host.input_invalid");
    // An unrelated file under the kept name is not ours: the run refuses and leaves it.
    fake.write(&kept, b"someone else's\n");
    let one = json!({"lock": "/tmp-lock/given.lock", "files": [file("/conf/a", Some(&from))]});
    assert_eq!(config_apply(&fake, &one).expect_err("a taken name").code, "config.write_failed");
    assert_eq!(fake.read(&kept), b"someone else's\n");
    assert_eq!(fake.read("/conf/a"), b"old\n");
    fs::remove_file(fake.path(&kept)).expect("clear");
    // Siblings under the old fixed scratch names survive a successful run.
    let partial = format!("/conf/.a.partial-{}", std::process::id());
    fake.write(&partial, b"mine\n");
    assert_eq!(config_apply(&fake, &one), Ok(1));
    assert_eq!(fake.read(&partial), b"mine\n");
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
    assert_eq!(trust_ensure(&fake, &json!({"state": "absent", "name": "r.crt"})), Ok(1));
    assert!(!bundle(&fake).contains("SECOND"));
    assert_eq!(trust_ensure(&fake, &json!({"state": "absent", "name": "r.crt"})), Ok(0));
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
    assert_eq!(unit_ensure(&fake, &json!({"unit": "web.service", "enabled": true, "state": "running", "budget_ms": 300})).expect_err("still activating").code, "unit.ensure_failed");
    *fake.transition.borrow_mut() = Some("deactivating");
    assert_eq!(unit_ensure(&fake, &json!({"unit": "web.service", "enabled": true, "state": "stopped", "budget_ms": 300})).expect_err("still deactivating").code, "unit.ensure_failed");
    assert_eq!(unit_ensure(&fake, &json!({"unit": "web.service", "enabled": true, "state": "stopped"})).expect_err("no budget").code, "host.input_invalid");
}
