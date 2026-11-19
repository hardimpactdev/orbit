use std::cell::RefCell;
use std::collections::HashMap;
use std::fs;
use std::os::unix::fs::{MetadataExt, PermissionsExt};
use std::path::{Path, PathBuf};

use serde_json::json;

use super::{publish_records, System, CATALOG, RECORDS};

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
