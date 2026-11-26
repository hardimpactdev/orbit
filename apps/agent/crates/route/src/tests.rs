use std::cell::RefCell;
use std::collections::HashMap;
use std::fs;
use std::os::unix::fs::MetadataExt as _;
use std::path::{Path, PathBuf};

use serde_json::json;

use crate::{ensure_csr, publish_site, withdraw_site, Account, System};

/// A scratch root, recorded commands, and the current user standing in for root and `caddy`.
struct Fake {
    root: tempfile::TempDir,
    commands: RefCell<Vec<Vec<String>>>,
    blobs: HashMap<String, Vec<u8>>,
    failing: Vec<&'static str>,
    inactive: bool,
    /// What Caddy's admin API answers.
    admin: String,
}

impl Fake {
    fn new() -> Self {
        Self { root: tempfile::tempdir().expect("scratch root"), commands: RefCell::default(), blobs: HashMap::new(), failing: Vec::new(), inactive: false, admin: String::new() }
    }

    /// The current user and group: what a file the tests make gets.
    fn me() -> (u32, u32) {
        let own = tempfile::NamedTempFile::new().expect("own file");
        let own = fs::metadata(own.path()).expect("own file metadata");
        (own.uid(), own.gid())
    }

    fn reloads(&self, unit: &str) -> usize {
        let wanted = ["/usr/bin/systemctl", "reload", unit];
        self.commands.borrow().iter().filter(|argv| argv.iter().map(String::as_str).eq(wanted)).count()
    }

    fn blob(&mut self, content: &str) -> serde_json::Value {
        let digest = format!("{:064x}", self.blobs.len() + 1);
        self.blobs.insert(digest.clone(), content.as_bytes().to_vec());
        json!({"blob": {"sha256": digest}})
    }

    fn write(&self, value: &str, content: &str) -> PathBuf {
        let path = self.path(value);
        fs::create_dir_all(path.parent().expect("parent")).expect("parents");
        fs::write(&path, content).expect("write");
        path
    }

    fn ran(&self, needle: &str) -> usize {
        self.commands.borrow().iter().filter(|argv| argv.iter().any(|arg| arg == needle)).count()
    }
}

impl System for Fake {
    fn root(&self) -> &Path {
        self.root.path()
    }

    fn run(&self, _seconds: u32, argv: &[String]) -> Result<String, String> {
        self.commands.borrow_mut().push(argv.to_vec());
        if self.failing.iter().any(|needle| argv.iter().any(|arg| arg == needle)) {
            return Err("refused".to_owned());
        }
        match argv.iter().map(String::as_str).collect::<Vec<_>>().as_slice() {
            [_, "genpkey", .., partial] => {
                fs::write(self.path(partial), "KEY").expect("key");
                Ok(String::new())
            }
            ["/usr/bin/install", "-D", .., "-t", folder, _, _] => {
                fs::create_dir_all(self.path(folder)).expect("account copy folder");
                Ok(String::new())
            }
            [_, "req", _, _, key, ..] if fs::read_to_string(self.path(key)).is_ok_and(|text| text == "BROKEN") => Err("unreadable key".to_owned()),
            [_, "req", ..] => Ok("-----BEGIN CERTIFICATE REQUEST-----\nCSR\n-----END CERTIFICATE REQUEST-----\n".to_owned()),
            ["/usr/bin/curl", ..] => Ok(self.admin.clone()),
            [_, "is-active", ..] if self.inactive => Err("inactive".to_owned()),
            _ => Ok(String::new()),
        }
    }

    /// Every account is the current user, except `intruder`, who owns nothing here.
    fn account(&self, name: &str) -> Result<Account, String> {
        let (uid, gid) = Self::me();
        let uid = if name == "intruder" { uid + 1 } else { uid };
        Ok(Account { uid, gid, home: "/home/orbit".to_owned() })
    }

    fn group(&self, _name: &str) -> Result<u32, String> {
        Ok(Self::me().1)
    }

    fn lchown(&self, _path: &Path, _uid: u32, _gid: Option<u32>) -> Result<(), String> {
        Ok(())
    }

    fn blob(&self, sha256: &str) -> Result<Vec<u8>, String> {
        self.blobs.get(sha256).cloned().ok_or_else(|| "missing".to_owned())
    }

    fn superuser(&self) -> u32 {
        Self::me().0
    }
}

const DIRECTORY: &str = "/etc/caddy/orbit-certificates/app-instance-7/versions/0123456789abcdef";

#[test]
fn a_csr_makes_the_key_once_and_reuses_it() {
    let fake = Fake::new();
    fs::create_dir_all(fake.path(DIRECTORY)).expect("version folder");
    let input = json!({"directory": DIRECTORY, "domain": "shop.orbit"});

    let first = ensure_csr(&fake, &input).expect("first request");
    let second = ensure_csr(&fake, &input).expect("repeat");

    assert!(first.contains("BEGIN CERTIFICATE REQUEST"));
    assert_eq!(first, second);
    assert_eq!(fake.ran("genpkey"), 1, "a repeat keeps the key a signed certificate may already match");
    assert_eq!(fs::read_to_string(fake.path(&format!("{DIRECTORY}/key.pem"))).expect("key"), "KEY");
    assert!(!fake.path(&format!("{DIRECTORY}/key.pem.partial")).exists());
    let request = fake.commands.borrow().iter().rfind(|argv| argv.contains(&"req".to_owned())).cloned().expect("a request");
    assert!(request.ends_with(&["-subj".to_owned(), "/CN=Orbit".to_owned(), "-addext".to_owned(), "subjectAltName=DNS:shop.orbit".to_owned()]));
}

#[test]
fn a_csr_refuses_a_folder_or_domain_outside_its_shape() {
    let fake = Fake::new();
    for input in [
        json!({"directory": "/etc/passwd", "domain": "shop.orbit"}),
        json!({"directory": DIRECTORY, "domain": "shop.orbit -addext x"}),
        json!({"directory": DIRECTORY, "domain": "shop.orbit", "extra": true}),
    ] {
        assert_eq!(ensure_csr(&fake, &input).expect_err("refused").code, "route.input_invalid");
    }
    assert!(fake.commands.borrow().is_empty(), "nothing runs for a refused input");
}

fn site_input(fake: &mut Fake, caddyfile: &str) -> serde_json::Value {
    json!({
        "account": "orbit",
        "acl": [
            {"kind": "deny_defaults", "user": "orbit", "paths": ["/home/orbit/apps/shop"]},
            {"kind": "passage", "user": null, "paths": ["/home/orbit", "/home/orbit/apps"]},
            {"kind": "grant", "user": "orbit", "paths": ["/home/orbit/apps/shop/public", "/home/orbit/apps/shop/storage/app/public"],
             "optional_paths": ["/home/orbit/apps/shop/storage/app/public"]},
        ],
        "url_lines": [{"path": "/home/orbit/apps/shop/.env", "url": "https://shop.orbit", "owner": "orbit"}],
        "php": [{"version": "8.4", "user": null, "content": fake.blob("[orbit]\ngroup = __ORBIT_NODE_GROUP__\nchdir = /__ORBIT_NODE_HOME__/apps\n")}],
        "leaves": [{"scope": "app-instance-7", "version": "0123456789abcdef", "certificate": fake.blob("-----BEGIN CERTIFICATE-----\nLEAF\n-----END CERTIFICATE-----\n")}],
        "root_certificate": fake.blob("-----BEGIN CERTIFICATE-----\nROOT\n-----END CERTIFICATE-----\n"),
        "caddy": fake.blob(caddyfile),
    })
}

fn site_fake() -> Fake {
    let fake = Fake::new();
    fake.write("/home/orbit/apps/shop/.env", "APP_NAME=Shop\nAPP_URL=http://old\nDB=x\n");
    fake.write("/home/orbit/apps/shop/public/index.php", "");
    for dir in ["/etc/php/8.4/fpm/pool.d", DIRECTORY, "/usr/local/share/ca-certificates", "/etc/caddy"] {
        fs::create_dir_all(fake.path(dir)).expect("folder");
    }
    fake
}

#[test]
fn a_site_places_every_file_and_a_repeat_changes_nothing_but_still_reloads() {
    let mut fake = site_fake();
    let input = site_input(&mut fake, "shop.orbit {\n}\n");

    let first = publish_site(&fake, &input).expect("first publication");
    let second = publish_site(&fake, &input).expect("repeat");

    assert!(first > 0);
    assert_eq!(second, 0, "a repeat finds every file in place");
    assert_eq!(fake.reloads("caddy"), 2, "a repeat after a crash before the reload must still make the files live");
    assert_eq!(fake.reloads("php8.4-fpm.service"), 2, "a running pool reloads on every publication");
    let (_, gid) = Fake::me();
    let pool = fs::read_to_string(fake.path("/etc/php/8.4/fpm/pool.d/orbit-scopes.conf")).expect("pool");
    assert_eq!(pool, format!("[orbit]\ngroup = {gid}\nchdir = /home/orbit/apps\n"));
    assert_eq!(fs::read_to_string(fake.path("/home/orbit/apps/shop/.env")).expect("env"), "APP_NAME=Shop\nAPP_URL=https://shop.orbit\nDB=x\n");
    assert_eq!(fs::read_to_string(fake.path("/etc/caddy/Caddyfile")).expect("live"), "shop.orbit {\n}\n");
    assert_eq!(fs::read_link(fake.path("/etc/caddy/orbit-certificates/app-instance-7/current")).expect("link"), Path::new("versions/0123456789abcdef"));
    assert_eq!(fs::metadata(fake.path("/etc/caddy/Caddyfile")).expect("live").mode() & 0o7777, 0o640);
    assert_eq!(fake.ran("/usr/sbin/update-ca-certificates"), 1, "trust updates only when the root file changes");
}

#[test]
fn access_commands_match_the_gateway_shapes_and_skip_a_missing_optional_path() {
    let mut fake = site_fake();
    let input = site_input(&mut fake, "shop.orbit {\n}\n");
    publish_site(&fake, &input).expect("publication");

    let commands = fake.commands.borrow();
    let runuser = ["/usr/sbin/runuser", "-u", "orbit", "--", "/usr/bin/env", "-C", "/"];
    let expected: [Vec<&str>; 3] = [
        [&runuser[..], &["/usr/bin/find", "-P", "/home/orbit/apps/shop", "-type", "d", "-exec", "/usr/bin/setfacl", "-m", "d:u:caddy:---", "--", "{}", "+"]].concat(),
        vec!["/usr/bin/setfacl", "-m", "u:caddy:--x", "--", "/home/orbit", "/home/orbit/apps"],
        [&runuser[..], &["/usr/bin/setfacl", "-P", "-R", "-n", "-m", "u:caddy:r-X", "--", "/home/orbit/apps/shop/public"]].concat(),
    ];
    for (index, argv) in expected.iter().enumerate() {
        assert_eq!(commands[index], argv.iter().map(|arg| (*arg).to_owned()).collect::<Vec<_>>(), "access command {index}");
    }
}

#[test]
fn a_failed_validation_keeps_the_live_caddyfile_and_reloads_nothing() {
    let mut fake = site_fake();
    fake.write("/etc/caddy/Caddyfile", "old {\n}\n");
    fake.failing.push("validate");
    let input = site_input(&mut fake, "broken {\n");

    let failure = publish_site(&fake, &input).expect_err("validation fails");

    assert_eq!(failure.code, "route.publish_failed");
    assert_eq!(fs::read_to_string(fake.path("/etc/caddy/Caddyfile")).expect("live"), "old {\n}\n");
    assert_eq!(fake.reloads("caddy"), 0);
    let leftovers: Vec<_> = fs::read_dir(fake.path("/etc/caddy")).expect("caddy folder").filter_map(Result::ok)
        .filter(|entry| entry.file_name().to_string_lossy().starts_with(".orbit")).collect();
    assert!(leftovers.is_empty(), "the candidate is removed");
}

#[test]
fn a_stopped_php_unit_starts_and_a_missing_env_fails() {
    let mut fake = site_fake();
    fake.inactive = true;
    let input = site_input(&mut fake, "shop.orbit {\n}\n");
    publish_site(&fake, &input).expect("publication");
    assert_eq!(fake.ran("start"), 1, "a task VM image keeps PHP-FPM off, so a stopped unit starts");

    fs::remove_file(fake.path("/home/orbit/apps/shop/.env")).expect("remove env");
    assert_eq!(publish_site(&fake, &input).expect_err("no .env").code, "route.publish_failed");
}

#[test]
fn a_site_refuses_input_outside_its_shape() {
    let mut fake = site_fake();
    let mut input = site_input(&mut fake, "x\n");
    input["acl"][0]["kind"] = json!("chmod");
    assert_eq!(publish_site(&fake, &input).expect_err("unknown kind").code, "route.input_invalid");
    let mut input = site_input(&mut fake, "x\n");
    input["php"][0]["version"] = json!("8.4; rm");
    assert_eq!(publish_site(&fake, &input).expect_err("bad version").code, "route.input_invalid");
    let mut input = site_input(&mut fake, "x\n");
    input["url_lines"][0]["path"] = json!("/etc/shadow");
    assert_eq!(publish_site(&fake, &input).expect_err("not an .env").code, "route.input_invalid");
    assert!(fake.commands.borrow().is_empty());
}

#[test]
fn an_env_that_reaches_another_users_file_is_refused() {
    let mut fake = site_fake();
    let mut input = site_input(&mut fake, "shop.orbit {\n}\n");
    input["url_lines"][0]["owner"] = json!("intruder");

    assert_eq!(publish_site(&fake, &input).expect_err("not the account's file").code, "route.publish_failed");
    assert_eq!(fs::read_to_string(fake.path("/home/orbit/apps/shop/.env")).expect("env"), "APP_NAME=Shop\nAPP_URL=http://old\nDB=x\n");
}

#[test]
fn a_broken_key_is_replaced_once() {
    let fake = Fake::new();
    fake.write(&format!("{DIRECTORY}/key.pem"), "BROKEN");

    ensure_csr(&fake, &json!({"directory": DIRECTORY, "domain": "shop.orbit"})).expect("a request for a new key");

    assert_eq!(fake.ran("genpkey"), 1);
    assert_eq!(fs::read_to_string(fake.path(&format!("{DIRECTORY}/key.pem"))).expect("key"), "KEY");
}

#[test]
fn a_temporary_file_a_stopped_run_left_does_not_block_the_write() {
    let mut fake = site_fake();
    let pid = std::process::id();
    fake.write(&format!("/etc/caddy/.orbit-route-{pid}-.orbit-candidate-{pid}"), "left over");
    let input = site_input(&mut fake, "shop.orbit {\n}\n");

    publish_site(&fake, &input).expect("publication");

    assert_eq!(fs::read_to_string(fake.path("/etc/caddy/Caddyfile")).expect("live"), "shop.orbit {\n}\n");
}

fn sha256(text: &str) -> String {
    use sha2::Digest as _;
    sha2::Sha256::digest(text.as_bytes()).iter().fold(String::with_capacity(64), |mut hex, byte| {
        use std::fmt::Write as _;
        let _ = write!(hex, "{byte:02x}");
        hex
    })
}

const WITH: &str = "shop.orbit {\n}\nother.orbit {\n}\n";
const WITHOUT: &str = "other.orbit {\n}\n";

fn withdraw_input(fake: &mut Fake, from: &str) -> serde_json::Value {
    fake.write("/etc/php/8.4/fpm/pool.d/orbit-scopes.conf", "[orbit]\n[shop]\n");
    json!({
        "account": "orbit",
        "domains": ["shop.orbit"],
        "leaves": ["app-instance-7"],
        "php": [{"version": "8.4", "user": null, "content": fake.blob("[orbit]\n")}],
        "caddy": {"from_sha256": sha256(from), "content": fake.blob(WITHOUT)},
    })
}

#[test]
fn a_withdrawal_removes_the_site_and_leaf_then_a_repeat_changes_nothing_but_reloads() {
    let mut fake = Fake::new();
    fake.write("/etc/caddy/Caddyfile", WITH);
    fake.write(&format!("{DIRECTORY}/cert.pem"), "LEAF");
    fake.write("/home/orbit/.orbit/certificates/app-instance-7/current", "x");
    fake.write("/etc/caddy/orbit-certificates/other-instance-8/current", "SIBLING");
    fake.admin = r#"{"apps":{"http":{"servers":{"srv0":{"routes":[{"match":[{"host":["other.orbit"]}]}]}}}}}"#.to_owned();
    let input = withdraw_input(&mut fake, WITH);

    let first = withdraw_site(&fake, &input).expect("withdrawn");
    assert!(first >= 3, "Caddyfile, pool and leaf folders changed: {first}");
    assert_eq!(fs::read_to_string(fake.path("/etc/caddy/Caddyfile")).expect("live"), WITHOUT);
    assert!(!fake.path("/etc/caddy/orbit-certificates/app-instance-7").exists());
    assert!(!fake.path("/home/orbit/.orbit/certificates/app-instance-7").exists());
    assert!(fake.path("/etc/caddy/orbit-certificates/other-instance-8/current").exists(), "a sibling's leaf stays");

    assert_eq!(withdraw_site(&fake, &input).expect("repeat"), 0);
    assert_eq!(fake.reloads("caddy"), 2, "Caddy reloads on a repeat too, so a crash before the reload still goes live");
    assert_eq!(fake.reloads("php8.4-fpm.service"), 2);
}

#[test]
fn a_withdrawal_refuses_a_caddyfile_changed_outside_the_route_before_any_write() {
    let mut fake = Fake::new();
    fake.write("/etc/caddy/Caddyfile", "shop.orbit {\n}\nother.orbit {\n}\nforeign.orbit {\n}\n");
    fake.write(&format!("{DIRECTORY}/cert.pem"), "LEAF");
    let input = withdraw_input(&mut fake, WITH);

    assert_eq!(withdraw_site(&fake, &input).expect_err("refused").code, "owned_revision_conflict");
    assert!(fake.path(&format!("{DIRECTORY}/cert.pem")).exists());
    assert!(fake.commands.borrow().is_empty(), "nothing runs for a refused withdrawal");
}

#[test]
fn a_withdrawal_fails_its_read_back_while_caddy_still_serves_the_name() {
    let mut fake = Fake::new();
    fake.write("/etc/caddy/Caddyfile", WITH);
    fake.admin = r#"{"routes":[{"match":[{"host":["shop.orbit"]}]}]}"#.to_owned();
    let input = withdraw_input(&mut fake, WITH);

    assert_eq!(withdraw_site(&fake, &input).expect_err("unverified").code, "route.withdraw_unverified");
}

#[test]
fn a_withdrawal_refuses_an_input_outside_its_shape() {
    let mut fake = Fake::new();
    fake.write("/etc/caddy/Caddyfile", WITH);
    let good = withdraw_input(&mut fake, WITH);
    for (field, value) in [("leaves", json!(["../../etc"])), ("domains", json!([])), ("account", json!("Root")), ("extra", json!(true))] {
        let mut input = good.clone();
        input[field] = value;
        assert_eq!(withdraw_site(&fake, &input).expect_err("refused").code, "route.input_invalid", "{field}");
    }
}

#[test]
fn the_gateway_signed_withdrawal_input_decodes_and_names_its_digests() {
    let fixture: serde_json::Value =
        serde_json::from_str(include_str!("../../../contract/fixtures/executor-route-withdraw-site.json")).expect("shared fixture");
    let mut fake = Fake::new();
    fake.write("/etc/caddy/Caddyfile", WITH);
    fake.write("/etc/php/8.4/fpm/pool.d/orbit-scopes.conf", "[orbit]\n[shop]\n");
    let input = &fixture["fields"]["input"];
    assert_eq!(input["caddy"]["from_sha256"], sha256(WITH));
    for content in [WITHOUT, "[orbit]\n"] {
        fake.blobs.insert(sha256(content), content.as_bytes().to_vec());
    }
    withdraw_site(&fake, input).expect("the fixture withdraws");
    assert_eq!(fs::read_to_string(fake.path("/etc/caddy/Caddyfile")).expect("live"), WITHOUT);
}
