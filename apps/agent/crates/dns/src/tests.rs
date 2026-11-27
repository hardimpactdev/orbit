use std::cell::RefCell;
use std::collections::HashMap;
use std::fs;
use std::os::unix::fs::{MetadataExt, PermissionsExt};
use std::path::{Path, PathBuf};

use serde_json::json;

use super::{catalog_sha256, publish_records, System, CATALOG, RECORDS};

struct Fake {
    root: tempfile::TempDir,
    blobs: HashMap<String, Vec<u8>>,
    runs: RefCell<Vec<Vec<String>>>,
    fail: Option<&'static str>,
}

impl Fake {
    fn new() -> Self {
        let root = tempfile::tempdir().unwrap();
        fs::create_dir_all(root.path().join("etc/dnsmasq.d")).unwrap();
        fs::create_dir_all(root.path().join("var/lib/orbit/private-dns")).unwrap();
        let blobs = HashMap::from([("r".to_owned(), b"address=/.test/10.44.0.7\n".to_vec()), ("c".to_owned(), b"{}\n".to_vec())]);
        Self { root, blobs, runs: RefCell::new(Vec::new()), fail: None }
    }

    fn file(&self, path: &str) -> PathBuf {
        self.path(path)
    }
}

impl System for Fake {
    fn root(&self) -> &Path {
        self.root.path()
    }

    fn run(&self, _seconds: u32, argv: &[&str]) -> Result<(), String> {
        self.runs.borrow_mut().push(argv.iter().map(|arg| (*arg).to_owned()).collect());
        if self.fail.is_some_and(|program| argv[0] == program) {
            return Err("failed".to_owned());
        }
        Ok(())
    }

    fn blob(&self, sha256: &str) -> Result<Vec<u8>, String> {
        self.blobs.get(sha256).cloned().ok_or_else(|| "missing".to_owned())
    }

    fn owner(&self) -> (u32, u32) {
        let meta = fs::metadata(self.root.path()).unwrap();
        (meta.uid(), meta.gid())
    }
}

fn input() -> serde_json::Value {
    json!({"records": {"blob": {"sha256": "r"}}, "catalog": {"blob": {"sha256": "c"}}})
}

#[test]
fn places_both_files_then_checks_and_restarts_dnsmasq_and_the_listener() {
    let fake = Fake::new();
    assert_eq!(publish_records(&fake, &input()), Ok(2));
    assert_eq!(fs::read(fake.file(RECORDS)).unwrap(), b"address=/.test/10.44.0.7\n");
    assert_eq!(fs::read(fake.file(CATALOG)).unwrap(), b"{}\n");
    assert_eq!(fs::metadata(fake.file(RECORDS)).unwrap().permissions().mode() & 0o7777, 0o644);
    assert_eq!(
        *fake.runs.borrow(),
        vec![
            vec!["/usr/sbin/dnsmasq".to_owned(), "--test".to_owned()],
            vec!["/usr/bin/systemctl".to_owned(), "restart".to_owned(), "dnsmasq.service".to_owned(), "orbit-private-dns.service".to_owned()],
        ]
    );
}

#[test]
fn a_repeat_changes_nothing_but_still_restarts() {
    let fake = Fake::new();
    publish_records(&fake, &input()).unwrap();
    assert_eq!(publish_records(&fake, &input()), Ok(0));
    assert_eq!(fake.runs.borrow().len(), 4);
}

#[test]
fn a_wrong_mode_or_a_link_in_place_is_replaced() {
    let fake = Fake::new();
    publish_records(&fake, &input()).unwrap();
    fs::set_permissions(fake.file(RECORDS), fs::Permissions::from_mode(0o666)).unwrap();
    let elsewhere = fake.root.path().join("elsewhere");
    fs::write(&elsewhere, "{}\n").unwrap();
    fs::remove_file(fake.file(CATALOG)).unwrap();
    std::os::unix::fs::symlink(&elsewhere, fake.file(CATALOG)).unwrap();

    assert_eq!(publish_records(&fake, &input()), Ok(2));
    assert!(!fs::symlink_metadata(fake.file(CATALOG)).unwrap().file_type().is_symlink());
    assert_eq!(fs::read(&elsewhere).unwrap(), b"{}\n");
}

#[test]
fn a_failed_check_puts_the_previous_files_back_and_restarts_nothing() {
    let mut fake = Fake::new();
    fs::write(fake.file(RECORDS), "address=/.test/10.44.0.9\n").unwrap();
    fake.fail = Some("/usr/sbin/dnsmasq");
    assert_eq!(publish_records(&fake, &input()).unwrap_err().code, "dns.check_failed");
    assert_eq!(fs::read(fake.file(RECORDS)).unwrap(), b"address=/.test/10.44.0.9\n");
    assert!(!fake.file(CATALOG).exists());
    assert_eq!(fake.runs.borrow().len(), 1);
}

#[test]
fn a_missing_folder_fails_the_write_and_leaves_the_records_as_they_were() {
    let fake = Fake::new();
    fs::write(fake.file(RECORDS), "old\n").unwrap();
    fs::remove_dir_all(fake.root.path().join("var/lib/orbit/private-dns")).unwrap();
    assert_eq!(publish_records(&fake, &input()).unwrap_err().code, "dns.write_failed");
    assert_eq!(fs::read(fake.file(RECORDS)).unwrap(), b"old\n");
    assert!(fake.runs.borrow().is_empty());
}

#[test]
fn a_failed_restart_reports_it() {
    let mut fake = Fake::new();
    fake.fail = Some("/usr/bin/systemctl");
    assert_eq!(publish_records(&fake, &input()).unwrap_err().code, "dns.restart_failed");
}

#[test]
fn refuses_missing_content_and_unknown_fields() {
    let fake = Fake::new();
    let missing = json!({"records": {"blob": {"sha256": "x"}}, "catalog": {"blob": {"sha256": "c"}}});
    assert_eq!(publish_records(&fake, &missing).unwrap_err().code, "dns.content_missing");
    let extra = json!({"records": {"blob": {"sha256": "r"}}, "catalog": {"blob": {"sha256": "c"}}, "argv": ["sh"]});
    assert_eq!(publish_records(&fake, &extra).unwrap_err().code, "dns.input_invalid");
    assert!(fake.runs.borrow().is_empty());
}

fn digest(bytes: &[u8]) -> String {
    super::sha256_hex(bytes)
}

#[test]
fn a_guarded_file_is_replaced_only_from_the_rendered_bytes_and_kept_when_already_desired() {
    let fake = Fake::new();
    fs::write(fake.file(RECORDS), "address=/.test/10.44.0.7\naddress=/old.example/10.44.0.8\n").unwrap();
    fs::write(fake.file(CATALOG), "{}\n").unwrap();
    let input = json!({
        "records": {"blob": {"sha256": "r"}, "from_sha256": digest(b"address=/.test/10.44.0.7\naddress=/old.example/10.44.0.8\n")},
        "catalog": {"blob": {"sha256": "c"}, "from_sha256": digest(b"{\"old\":1}\n")},
    });
    // The catalog already holds the desired bytes, so its own guard is met by them.
    assert!(publish_records(&fake, &input).is_ok());
    assert_eq!(fs::read(fake.file(RECORDS)).unwrap(), b"address=/.test/10.44.0.7\n");
    // A replay finds both desired and changes nothing, but still checks and restarts.
    assert_eq!(publish_records(&fake, &input), Ok(0));
    assert_eq!(fake.runs.borrow().iter().filter(|argv| argv[0] == "/usr/bin/systemctl").count(), 2);
}

#[test]
fn a_guarded_file_changed_elsewhere_refuses_before_any_write() {
    let fake = Fake::new();
    fs::write(fake.file(RECORDS), "address=/other.example/10.44.0.9\n").unwrap();
    fs::write(fake.file(CATALOG), "{\"stale\":1}\n").unwrap();
    let input = json!({
        "records": {"blob": {"sha256": "r"}, "from_sha256": digest(b"address=/old.example/10.44.0.8\n")},
        "catalog": {"blob": {"sha256": "c"}, "from_sha256": digest(b"{\"stale\":1}\n")},
    });
    assert_eq!(publish_records(&fake, &input).map_err(|failure| failure.code), Err("owned_revision_conflict"));
    assert_eq!(fs::read(fake.file(RECORDS)).unwrap(), b"address=/other.example/10.44.0.9\n");
    assert_eq!(fs::read(fake.file(CATALOG)).unwrap(), b"{\"stale\":1}\n");
    assert!(fake.runs.borrow().is_empty());
}

#[test]
fn the_gateway_signed_guarded_input_decodes() {
    let fixture: serde_json::Value =
        serde_json::from_str(include_str!("../../../contract/fixtures/executor-dns-guarded-records.json")).expect("shared fixture");
    let mut fake = Fake::new();
    fake.blobs = HashMap::from([(digest(b"address=/other.example/10.44.0.8\n"), b"address=/other.example/10.44.0.8\n".to_vec()), (digest(b"{}\n"), b"{}\n".to_vec())]);
    fs::write(fake.file(RECORDS), "address=/shop.example/10.44.0.7\naddress=/other.example/10.44.0.8\n").unwrap();
    fs::write(fake.file(CATALOG), "{\"shop.example\":1}\n").unwrap();
    assert_eq!(publish_records(&fake, &fixture["fields"]["input"]), Ok(2));
}

/// The catalog Swamp's route withdrawal wrote on the nested VPN Node (ORB-6310 proof), and the Gateway's render of the same
/// state: Swamp keeps its merge's key order and writes `{}` for the empty overrides, PHP writes `requesters` first and `[]`.
const SWAMP_CATALOG: &[u8] = b"{\"records\":{\"gateway.orbit\":\"10.45.0.1\",\"reverb.orbit\":\"10.45.0.1\",\"swamp.orbit\":\"10.45.0.1\"},\"requesters\":{\"10.45.0.1\":1,\"10.45.0.10\":2,\"10.45.0.2\":3,\"10.45.0.3\":4},\"suffixes\":{\"nested-dev\":\"10.45.0.2\",\"nested-prod\":\"10.45.0.3\"},\"overrides\":{}}\n";
const GATEWAY_CATALOG: &[u8] = b"{\"requesters\":{\"10.45.0.1\":1,\"10.45.0.10\":2,\"10.45.0.2\":3,\"10.45.0.3\":4},\"records\":{\"gateway.orbit\":\"10.45.0.1\",\"reverb.orbit\":\"10.45.0.1\",\"swamp.orbit\":\"10.45.0.1\"},\"suffixes\":{\"nested-dev\":\"10.45.0.2\",\"nested-prod\":\"10.45.0.3\"},\"overrides\":[]}\n";
/// The canonical digest both sides compute; the Gateway's test (RouteFileGuardsTest) pins the same value.
const CANONICAL_CATALOG_SHA256: &str = "141b5eab6c46db1a9c5e2615fc229b8b9bf0253d3f4079b95e6ce4470a03baba";

#[test]
fn a_catalog_swamp_rewrote_matches_the_canonical_digest_the_gateway_rendered_from() {
    assert_eq!(catalog_sha256(SWAMP_CATALOG).as_deref(), Some(CANONICAL_CATALOG_SHA256));
    assert_eq!(catalog_sha256(GATEWAY_CATALOG).as_deref(), Some(CANONICAL_CATALOG_SHA256));
    // An already canonical file keeps its plain digest.
    assert_eq!(catalog_sha256(b"{\"shop.example\":1}\n"), Some(digest(b"{\"shop.example\":1}\n")));
    assert_eq!(catalog_sha256(b"not json"), None);

    let mut fake = Fake::new();
    fake.blobs.insert("c".to_owned(), b"{\"records\":{}}\n".to_vec());
    fs::write(fake.file(RECORDS), "address=/.test/10.44.0.7\n").unwrap();
    fs::write(fake.file(CATALOG), SWAMP_CATALOG).unwrap();
    let input = json!({
        "records": {"blob": {"sha256": "r"}, "from_sha256": digest(b"address=/.test/10.44.0.7\n")},
        "catalog": {"blob": {"sha256": "c"}, "from_sha256": CANONICAL_CATALOG_SHA256},
    });
    assert!(publish_records(&fake, &input).is_ok());
    assert_eq!(fs::read(fake.file(CATALOG)).unwrap(), b"{\"records\":{}}\n");
}

#[test]
fn a_catalog_with_another_answer_still_refuses() {
    let fake = Fake::new();
    fs::write(fake.file(RECORDS), "address=/.test/10.44.0.7\n").unwrap();
    let foreign = String::from_utf8(SWAMP_CATALOG.to_vec()).unwrap().replace("\"swamp.orbit\":\"10.45.0.1\"", "\"swamp.orbit\":\"10.45.0.9\"");
    fs::write(fake.file(CATALOG), &foreign).unwrap();
    let input = json!({
        "records": {"blob": {"sha256": "r"}, "from_sha256": digest(b"address=/.test/10.44.0.7\n")},
        "catalog": {"blob": {"sha256": "c"}, "from_sha256": CANONICAL_CATALOG_SHA256},
    });
    assert_eq!(publish_records(&fake, &input).map_err(|failure| failure.code), Err("owned_revision_conflict"));
    assert_eq!(fs::read(fake.file(CATALOG)).unwrap(), foreign.as_bytes());
}
