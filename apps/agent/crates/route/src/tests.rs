use std::cell::RefCell;
use std::collections::{HashMap, VecDeque};
use std::fs;
use std::os::unix::fs::{MetadataExt as _, PermissionsExt as _};
use std::path::{Path, PathBuf};
use std::time::{Duration, Instant};

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
    /// What Caddy's admin API answers first, one read each, before [`Fake::admin`] answers at once, and how long each answer
    /// takes: a reload Caddy has not loaded yet, or an admin API that answers slowly.
    loading: RefCell<VecDeque<(Duration, Result<String, String>)>>,
    /// The waits between read-backs, recorded instead of slept.
    sleeps: RefCell<Vec<Duration>>,
    /// The fake monotonic clock: [`Fake::now`] is `start` plus the time slept and read so far.
    start: Instant,
    elapsed: RefCell<Duration>,
    /// Whole commands that fail.
    failing_argv: Vec<Vec<&'static str>>,
}

impl Fake {
    fn new() -> Self {
        Self { root: tempfile::tempdir().expect("scratch root"), commands: RefCell::default(), blobs: HashMap::new(), failing: Vec::new(), inactive: false, admin: String::new(), loading: RefCell::default(), sleeps: RefCell::default(), start: Instant::now(), elapsed: RefCell::default(), failing_argv: Vec::new() }
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
        if self.failing.iter().any(|needle| argv.iter().any(|arg| arg == needle))
            || self.failing_argv.iter().any(|wanted| argv.iter().map(String::as_str).eq(wanted.iter().copied()))
        {
            return Err("refused".to_owned());
        }
        match argv.iter().map(String::as_str).collect::<Vec<_>>().as_slice() {
            [_, "genpkey", ..] => Ok("-----BEGIN PRIVATE KEY-----\nKEY\n-----END PRIVATE KEY-----\n".to_owned()),
            [_, "pkey", "-noout", "-in", key] if fs::read_to_string(self.path(key)).is_ok_and(|text| text == "BROKEN") => Err("unreadable key".to_owned()),
            ["/usr/bin/install", "-D", .., "-t", folder, _, _] => {
                fs::create_dir_all(self.path(folder)).expect("account copy folder");
                Ok(String::new())
            }
            [_, "req", ..] => Ok("-----BEGIN CERTIFICATE REQUEST-----\nCSR\n-----END CERTIFICATE REQUEST-----\n".to_owned()),
            ["/usr/bin/curl", .., "--max-time", limit, _] => {
                // A read takes its answer's time, or curl gives up at its --max-time.
                let limit = Duration::from_secs_f64(limit.parse().expect("--max-time in seconds"));
                let (takes, answer) = self.loading.borrow_mut().pop_front().unwrap_or_else(|| (Duration::ZERO, Ok(self.admin.clone())));
                *self.elapsed.borrow_mut() += takes.min(limit);
                if takes > limit { Err("operation timed out".to_owned()) } else { answer }
            }
            ["/usr/bin/getent", "group", gid] if gid.chars().all(|c| c.is_ascii_digit()) => Ok(format!("staff:x:{gid}:\n")),
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

    fn now(&self) -> Instant {
        self.start + *self.elapsed.borrow()
    }

    fn sleep(&self, duration: Duration) {
        self.sleeps.borrow_mut().push(duration);
        *self.elapsed.borrow_mut() += duration;
    }

    fn superuser(&self) -> u32 {
        Self::me().0
    }
}

const DIRECTORY: &str = "/etc/caddy/orbit-certificates/app-instance-7/versions/0123456789abcdef";

/// The input the Gateway sends: folder, owner, modes and subject are all its own.
fn csr_input(directory: &str, domain: &str) -> serde_json::Value {
    json!({"directory": directory, "key": format!("{directory}/key.pem"), "user": "root", "group": "caddy", "directory_mode": "0750", "key_mode": "0640", "subject": "/CN=Orbit", "domain": domain})
}

#[test]
fn a_csr_takes_its_folder_owner_modes_and_subject_from_the_input() {
    let fake = Fake::new();
    let directory = "/srv/elsewhere/leaf";
    fs::create_dir_all(fake.path(directory)).expect("folder");
    let mut input = csr_input(directory, "shop.test");
    input["user"] = json!("keeper");
    input["group"] = json!("readers");
    input["directory_mode"] = json!("0700");
    input["key_mode"] = json!("0600");
    input["subject"] = json!("/CN=Someone Else");
    ensure_csr(&fake, &input).expect("a request");
    let commands = fake.commands.borrow();
    assert!(commands.iter().any(|argv| argv == &strings_of(&["/usr/bin/install", "-d", "-o", "keeper", "-g", "readers", "-m", "0700", directory])));
    let key = fs::metadata(fake.path(&format!("{directory}/key.pem"))).expect("key");
    assert_eq!(key.permissions().mode() & 0o7777, 0o600);
    assert!(commands.iter().any(|argv| argv.contains(&"/CN=Someone Else".to_owned())));
}

#[test]
fn a_csr_keeps_a_readable_key_when_the_request_fails_and_puts_its_mode_right() {
    let mut fake = Fake::new();
    fake.write(&format!("{DIRECTORY}/key.pem"), "HEALTHY");
    fs::set_permissions(fake.path(&format!("{DIRECTORY}/key.pem")), fs::Permissions::from_mode(0o644)).expect("mode");
    fake.failing.push("req");
    let mut input = csr_input(DIRECTORY, "shop.orbit");
    input["subject"] = json!("/C=USA");

    assert_eq!(ensure_csr(&fake, &input).expect_err("openssl refuses the subject").code, "app-dev.certificate_request_failed");
    assert_eq!(fs::read_to_string(fake.path(&format!("{DIRECTORY}/key.pem"))).expect("key"), "HEALTHY", "a readable key is never replaced");
    assert_eq!(fake.ran("genpkey"), 0);
    assert_eq!(fs::metadata(fake.path(&format!("{DIRECTORY}/key.pem"))).expect("key").permissions().mode() & 0o7777, 0o640, "the mode is put right on the key in place");
}

#[test]
fn a_csr_never_writes_its_key_through_a_link() {
    let fake = Fake::new();
    fs::create_dir_all(fake.path(DIRECTORY)).expect("folder");
    let elsewhere = fake.path("/victim");
    fs::write(&elsewhere, "VICTIM").expect("victim");
    std::os::unix::fs::symlink(&elsewhere, fake.path(&format!("{DIRECTORY}/key.pem"))).expect("link");

    assert_eq!(ensure_csr(&fake, &csr_input(DIRECTORY, "shop.orbit")).expect_err("a link").code, "app-dev.certificate_request_failed");
    assert_eq!(fs::read_to_string(&elsewhere).expect("victim"), "VICTIM");
}

fn strings_of(values: &[&str]) -> Vec<String> {
    values.iter().map(|value| (*value).to_owned()).collect()
}

#[test]
fn a_csr_makes_the_key_once_and_reuses_it() {
    let fake = Fake::new();
    fs::create_dir_all(fake.path(DIRECTORY)).expect("version folder");
    let input = csr_input(DIRECTORY, "shop.orbit");

    let first = ensure_csr(&fake, &input).expect("first request");
    let second = ensure_csr(&fake, &input).expect("repeat");

    assert!(first.contains("BEGIN CERTIFICATE REQUEST"));
    assert_eq!(first, second);
    assert_eq!(fake.ran("genpkey"), 1, "a repeat keeps the key a signed certificate may already match");
    assert_eq!(fs::read_to_string(fake.path(&format!("{DIRECTORY}/key.pem"))).expect("key"), "-----BEGIN PRIVATE KEY-----\nKEY\n-----END PRIVATE KEY-----\n");
    assert!(fs::read_dir(fake.path(DIRECTORY)).expect("folder").all(|entry| !entry.expect("entry").file_name().to_string_lossy().contains("partial")));
    let request = fake.commands.borrow().iter().rfind(|argv| argv.contains(&"req".to_owned())).cloned().expect("a request");
    assert!(request.ends_with(&["-subj".to_owned(), "/CN=Orbit".to_owned(), "-addext".to_owned(), "subjectAltName=DNS:shop.orbit".to_owned()]));
}

#[test]
fn a_csr_refuses_a_folder_or_domain_outside_its_shape() {
    let fake = Fake::new();
    let with = |key: &str, value: serde_json::Value| {
        let mut input = csr_input(DIRECTORY, "shop.orbit");
        input[key] = value;
        input
    };
    for input in [
        csr_input("/etc/../passwd", "shop.orbit"),
        csr_input(DIRECTORY, "shop.orbit -addext x"),
        with("extra", json!(true)),
        with("subject", json!("/CN=Orbit -x\n")),
        with("key_mode", json!("0640 -R")),
        with("user", json!("root;x")),
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

    ensure_csr(&fake, &csr_input(DIRECTORY, "shop.orbit")).expect("a request for a new key");

    assert_eq!(fake.ran("genpkey"), 1);
    assert_eq!(fs::read_to_string(fake.path(&format!("{DIRECTORY}/key.pem"))).expect("key"), "-----BEGIN PRIVATE KEY-----\nKEY\n-----END PRIVATE KEY-----\n");
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
    assert!(fake.commands.borrow().iter().all(|argv| argv[0] == "/usr/bin/getent"), "nothing but lookups runs for a refused withdrawal");
}

#[test]
fn a_withdrawal_fails_its_read_back_while_caddy_still_serves_the_name() {
    let mut fake = Fake::new();
    fake.write("/etc/caddy/Caddyfile", WITH);
    fake.admin = r#"{"routes":[{"match":[{"host":["shop.orbit"]}]}]}"#.to_owned();
    let input = withdraw_input(&mut fake, WITH);

    let failure = withdraw_site(&fake, &input).expect_err("unverified");
    assert_eq!(failure.code, "route.withdraw_unverified");
    assert_eq!(failure.detail, "Caddy still serves a withdrawn name");
    assert_eq!(fake.ran("http://127.0.0.1:2019/config/"), 20, "an instant answer is read every 250 ms while the 5 s last");
    assert_eq!(*fake.elapsed.borrow(), Duration::from_secs(5), "it fails at the bound, never later");
}

#[test]
fn a_slow_admin_answer_counts_toward_the_bound_and_is_accepted_within_it() {
    let absent = r#"{"routes":[{"match":[{"host":["other.orbit"]}]}]}"#.to_owned();
    let present = r#"{"routes":[{"match":[{"host":["shop.orbit"]}]}]}"#.to_owned();
    let slow = Duration::from_millis(2250);
    let run = |loading: Vec<(Duration, Result<String, String>)>| {
        let mut fake = Fake::new();
        fake.write("/etc/caddy/Caddyfile", WITH);
        fake.admin.clone_from(&present);
        *fake.loading.borrow_mut() = loading.into();
        let input = withdraw_input(&mut fake, WITH);
        let result = withdraw_site(&fake, &input).map_err(|failure| failure.detail);
        let elapsed = *fake.elapsed.borrow();
        (result, fake.ran("http://127.0.0.1:2019/config/"), elapsed)
    };

    // An admin API that answers the configuration without the Route after 2.25 s: the first read gets the whole budget.
    let (result, reads, elapsed) = run(vec![(slow, Ok(absent))]);
    assert!(result.is_ok(), "{result:?}");
    assert_eq!((reads, elapsed), (1, slow));

    // An admin API that never answers: one read that gives up at the bound, not one 2 s read after another.
    let (result, reads, elapsed) = run(vec![(Duration::from_secs(3600), Ok(String::new()))]);
    assert_eq!(result.expect_err("unverified"), "Caddy admin API");
    assert_eq!((reads, elapsed), (1, Duration::from_secs(5)));

    // An admin API that still names the Route after 2.25 s each time: two reads and two waits use the 5 s.
    let (result, reads, elapsed) = run(vec![(slow, Ok(present.clone())), (slow, Ok(present.clone()))]);
    assert_eq!(result.expect_err("unverified"), "Caddy still serves a withdrawn name");
    assert_eq!((reads, elapsed), (2, Duration::from_secs(5)));
}

#[test]
fn a_withdrawal_waits_for_caddy_to_load_the_reloaded_configuration() {
    let mut fake = Fake::new();
    fake.write("/etc/caddy/Caddyfile", WITH);
    fake.write(&format!("{DIRECTORY}/cert.pem"), "LEAF");
    let old = r#"{"routes":[{"match":[{"host":["shop.orbit","other.orbit"]}]}]}"#.to_owned();
    // Op 35060: Caddy answers its old configuration right after `systemctl reload caddy`, then refuses once while it swaps,
    // then serves the configuration without the Route.
    let instant = Duration::ZERO;
    *fake.loading.borrow_mut() = VecDeque::from([(instant, Ok(old.clone())), (instant, Ok(old.clone())), (instant, Ok(old)), (instant, Err("connection refused".to_owned()))]);
    fake.admin = r#"{"routes":[{"match":[{"host":["other.orbit"]}]}]}"#.to_owned();
    let input = withdraw_input(&mut fake, WITH);

    assert!(withdraw_site(&fake, &input).expect("withdrawn without a retry") >= 3);
    assert_eq!(fake.ran("http://127.0.0.1:2019/config/"), 5, "it stops reading once Caddy no longer names the Route");
    assert_eq!(fake.sleeps.borrow().len(), 4);
    assert_eq!(fake.reloads("caddy"), 1, "the wait only reads back; it never reloads again");
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

#[test]
fn a_withdrawal_puts_the_previous_generation_back_when_a_pool_test_or_reload_or_caddy_reload_fails() {
    for (failing, code) in [
        (vec!["/usr/sbin/php-fpm8.4", "-t"], "route.pool_failed"),
        (vec!["/usr/bin/systemctl", "reload", "php8.4-fpm.service"], "route.pool_failed"),
        (vec!["/usr/bin/systemctl", "reload", "caddy"], "route.withdraw_failed"),
    ] {
        let mut fake = Fake::new();
        fake.write("/etc/caddy/Caddyfile", WITH);
        fake.write(&format!("{DIRECTORY}/cert.pem"), "LEAF");
        let input = withdraw_input(&mut fake, WITH);
        fake.failing_argv = vec![failing.clone()];

        assert_eq!(withdraw_site(&fake, &input).expect_err("failed").code, code, "{failing:?}");
        assert_eq!(fs::read_to_string(fake.path("/etc/caddy/Caddyfile")).expect("live"), WITH, "{failing:?}");
        assert_eq!(fs::read_to_string(fake.path("/etc/php/8.4/fpm/pool.d/orbit-scopes.conf")).expect("pool"), "[orbit]\n[shop]\n", "{failing:?}");
        assert!(fake.path(&format!("{DIRECTORY}/cert.pem")).exists(), "the leaf stays while its site may still serve: {failing:?}");
    }
}

#[test]
fn a_withdrawal_refuses_a_changed_scope_of_its_own_and_keeps_an_unrelated_one() {
    let mut fake = Fake::new();
    fake.write("/etc/caddy/Caddyfile", WITH);
    let mut input = withdraw_input(&mut fake, WITH);
    input["php"][0]["from"] = Fake::blob(&mut fake, "[orbit]\n[shop]\n");
    fake.write("/etc/php/8.4/fpm/pool.d/orbit-scopes.conf", "[orbit]\n[shop]\nchanged = 1\n");

    assert_eq!(withdraw_site(&fake, &input).expect_err("refused").code, "owned_revision_conflict");
    assert_eq!(fs::read_to_string(fake.path("/etc/caddy/Caddyfile")).expect("live"), WITH);
    assert!(fake.path("/etc/caddy/.orbit-publish.lock").exists(), "the check ran under the Node's publication lock");

    fake.write("/etc/php/8.4/fpm/pool.d/orbit-scopes.conf", "[foreign]\n[orbit]\n[shop]\n");
    withdraw_site(&fake, &input).expect("withdrawn");
    assert_eq!(fs::read_to_string(fake.path("/etc/php/8.4/fpm/pool.d/orbit-scopes.conf")).expect("pool"), "[foreign]\n[orbit]\n");
}

#[test]
fn a_withdrawal_replaces_a_shared_pool_that_still_holds_its_rendered_from_state() {
    let mut fake = Fake::new();
    fake.write("/etc/caddy/Caddyfile", WITH);
    let mut input = withdraw_input(&mut fake, WITH);
    input["php"][0]["from"] = Fake::blob(&mut fake, "[orbit]\n[shop]\n");

    withdraw_site(&fake, &input).expect("withdrawn");
    assert_eq!(fs::read_to_string(fake.path("/etc/php/8.4/fpm/pool.d/orbit-scopes.conf")).expect("pool"), "[orbit]\n");
}

#[test]
fn a_withdrawal_keeps_a_surviving_scope_whose_group_is_named_or_numeric_byte_for_byte() {
    let gid = Fake::me().1;
    let from = "[orbit]\ngroup = __ORBIT_NODE_GROUP__\n[shop]\ngroup = __ORBIT_NODE_GROUP__\n";
    for (live, kept) in [
        ("[orbit]\ngroup = staff\n[shop]\ngroup = staff\n".to_owned(), "[orbit]\ngroup = staff\n".to_owned()),
        (format!("[orbit]\ngroup = {gid}\n[shop]\ngroup = staff\n"), format!("[orbit]\ngroup = {gid}\n")),
    ] {
        let mut fake = Fake::new();
        fake.write("/etc/caddy/Caddyfile", WITH);
        let mut input = withdraw_input(&mut fake, WITH);
        input["php"][0]["content"] = Fake::blob(&mut fake, "[orbit]\ngroup = __ORBIT_NODE_GROUP__\n");
        input["php"][0]["from"] = Fake::blob(&mut fake, from);
        fake.write("/etc/php/8.4/fpm/pool.d/orbit-scopes.conf", &live);

        withdraw_site(&fake, &input).expect("withdrawn");
        assert_eq!(fs::read_to_string(fake.path("/etc/php/8.4/fpm/pool.d/orbit-scopes.conf")).expect("pool"), kept);
    }
}

#[test]
fn a_withdrawal_leaves_an_absent_unchanged_scope_and_an_absent_pool_absent() {
    let mut fake = Fake::new();
    fake.write("/etc/caddy/Caddyfile", WITH);
    let mut input = withdraw_input(&mut fake, WITH);
    input["php"][0]["from"] = Fake::blob(&mut fake, "[seven]\n[nine]\n");
    input["php"][0]["content"] = Fake::blob(&mut fake, "[seven]\n");
    fake.write("/etc/php/8.4/fpm/pool.d/orbit-scopes.conf", "[eleven]\n[nine]\n");

    withdraw_site(&fake, &input).expect("withdrawn");
    assert_eq!(fs::read_to_string(fake.path("/etc/php/8.4/fpm/pool.d/orbit-scopes.conf")).expect("pool"), "[eleven]\n");

    fs::remove_file(fake.path("/etc/php/8.4/fpm/pool.d/orbit-scopes.conf")).expect("remove");
    fake.write("/etc/caddy/Caddyfile", WITH);
    withdraw_site(&fake, &input).expect("withdrawn again");
    assert!(!fake.path("/etc/php/8.4/fpm/pool.d/orbit-scopes.conf").exists(), "an absent pool is never created");
}

#[test]
fn a_withdrawal_keeps_a_sibling_scope_byte_for_byte_across_the_render_separator() {
    // The Gateway joins scopes with a blank line: removing [nine] changes only what follows [seven].
    let from = "[seven]\ngroup = __ORBIT_NODE_GROUP__\n\n[nine]\ngroup = __ORBIT_NODE_GROUP__\n";
    let desired = "[seven]\ngroup = __ORBIT_NODE_GROUP__\n";
    for (live, kept) in [
        ("[nine]\ngroup = staff\n", ""),
        ("[seven]\ngroup = staff\n\n[nine]\ngroup = staff\n", "[seven]\ngroup = staff\n\n"),
    ] {
        let mut fake = Fake::new();
        fake.write("/etc/caddy/Caddyfile", WITH);
        let mut input = withdraw_input(&mut fake, WITH);
        input["php"][0]["from"] = Fake::blob(&mut fake, from);
        input["php"][0]["content"] = Fake::blob(&mut fake, desired);
        fake.write("/etc/php/8.4/fpm/pool.d/orbit-scopes.conf", live);

        withdraw_site(&fake, &input).expect("withdrawn");
        assert_eq!(fs::read_to_string(fake.path("/etc/php/8.4/fpm/pool.d/orbit-scopes.conf")).expect("pool"), kept, "{live:?}");
    }
}

#[test]
fn a_withdrawal_fails_before_any_change_when_a_guarded_pool_cannot_be_read() {
    let mut fake = Fake::new();
    fake.write("/etc/caddy/Caddyfile", WITH);
    let mut input = withdraw_input(&mut fake, WITH);
    input["php"][0]["from"] = Fake::blob(&mut fake, "[orbit]\n[shop]\n");
    let pool = fake.path("/etc/php/8.4/fpm/pool.d/orbit-scopes.conf");
    fs::remove_file(&pool).expect("remove");
    fs::create_dir_all(&pool).expect("a directory in the pool's place");

    assert_eq!(withdraw_site(&fake, &input).expect_err("unverified").code, "route.withdraw_failed");
    assert_eq!(fs::read_to_string(fake.path("/etc/caddy/Caddyfile")).expect("live"), WITH);
}
