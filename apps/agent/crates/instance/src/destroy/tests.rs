use std::cell::RefCell;
use std::collections::VecDeque;

use serde_json::json;

use super::*;

const INSTANCE: &str = "/usr/bin/incus query /1.0/instances/instance-12?project=orbit-instances-nested";
const STOP: &str = "/usr/bin/incus --project orbit-instances-nested stop --force instance-12";
const DELETE: &str = "/usr/bin/incus --project orbit-instances-nested delete --force instance-12";
const INNER: &str =
    "/usr/bin/incus query /1.0/storage-pools/orbit-instances/volumes/custom/inner-12?project=orbit-instances-nested";
const VOLUMES: &str =
    "/usr/bin/incus query /1.0/storage-pools/orbit-instances/volumes?project=orbit-instances-nested&recursion=1";
const ACL: &str = "/usr/bin/incus query /1.0/network-acls/instance-12?project=default";
const RULES: &str = "/usr/sbin/ufw show added";
const DISCARD: &str = "/usr/bin/systemd-run --unit=orbit-instance-power-12 --wait --pipe --collect --quiet /usr/local/libexec/orbit-instance-power discard --instance 12";
const MISSING: Result<&str, &str> = Err("Error: Not Found");
const SIBLING_RULE: &str = "ufw route allow from any to 192.0.2.24 port 3773 comment 'orbit:instance-120-t3'";

type ExpectedCall = (&'static str, Result<&'static str, &'static str>);

struct Fixture {
    expected: RefCell<VecDeque<ExpectedCall>>,
    root: tempfile::TempDir,
}

impl Fixture {
    fn new(expected: &[ExpectedCall]) -> Self {
        Self {
            expected: RefCell::new(expected.iter().copied().collect()),
            root: tempfile::tempdir().expect("scratch"),
        }
    }

    fn file(&self, path: &str) -> std::path::PathBuf {
        let path = self.path(path);
        fs::create_dir_all(path.parent().expect("parent")).expect("fixture directory");
        fs::write(&path, "disposable fixture").expect("fixture file");
        path
    }

    fn checked(&self) {
        assert!(
            self.expected.borrow().is_empty(),
            "{} commands were not checked",
            self.expected.borrow().len()
        );
    }

    fn dispatch(&self, argv: &[String], stdin: Option<&[u8]>) -> ExpectedCall {
        let actual = argv.join(" ");
        assert!(stdin.is_none());
        let expected = self
            .expected
            .borrow_mut()
            .pop_front()
            .unwrap_or_else(|| panic!("unexpected call: {actual}"));
        assert_eq!(actual, expected.0);
        expected
    }
}

impl System for Fixture {
    fn run(&self, _seconds: u32, argv: &[String], stdin: Option<&[u8]>) -> Result<String, String> {
        self.dispatch(argv, stdin).1.map(str::to_owned).map_err(str::to_owned)
    }

    fn blob(&self, _sha256: &str) -> Result<Vec<u8>, String> {
        Err("destroy does not read blobs".to_owned())
    }

    fn read(&self, _path: &str) -> Result<Vec<u8>, String> {
        Err("destroy does not read files as command input".to_owned())
    }

    fn path(&self, value: &str) -> std::path::PathBuf {
        self.root.path().join(value.trim_start_matches('/'))
    }
}

fn input() -> Value {
    serde_json::from_str::<Value>(include_str!("../../../../contract/fixtures/executor-instance-destroy.json"))
        .expect("shared fixture")["fields"]["input"]
        .clone()
}

#[test]
fn destroy_forces_a_frozen_clone_off_and_cleans_only_its_resources() {
    let fixture = Fixture::new(&[
        (
            INSTANCE,
            Ok(
                r#"{"name":"instance-12","type":"container","status":"Frozen","expanded_devices":{"root":{"pool":"orbit-instances"}}}"#,
            ),
        ),
        (STOP, Ok("")),
        (DELETE, Ok("")),
        (INNER, Ok(r#"{"name":"inner-12","type":"custom","used_by":[]}"#)),
        (
            "/usr/bin/incus --project orbit-instances-nested storage volume delete orbit-instances inner-12",
            Ok(""),
        ),
        (
            VOLUMES,
            Ok(
                r#"[{"name":"inner-120","type":"custom"},{"name":"spare-inner-120-abc","type":"custom"},{"name":"instance-120","type":"container"}]"#,
            ),
        ),
        (ACL, Ok("{}")),
        ("/usr/bin/incus network acl delete instance-12 --project default", Ok("")),
        (
            VOLUMES,
            Ok(r#"[{"name":"inner-120","type":"custom"},{"name":"instance-120","type":"container"}]"#),
        ),
        (INSTANCE, MISSING),
        (ACL, MISSING),
        (
            RULES,
            Ok(concat!(
                "ufw route allow from any to 192.0.2.12 port 3773 comment 'orbit:instance-12-t3'\n",
                "ufw route allow from any to 192.0.2.24 port 3773 comment 'orbit:instance-120-t3'\n"
            )),
        ),
        (
            "/usr/sbin/ufw --force route delete allow from any to 192.0.2.12 port 3773",
            Ok(""),
        ),
        (RULES, Ok(SIBLING_RULE)),
        (DISCARD, Ok(r#"{"outcome":"discarded","removed":true}"#)),
    ]);
    let ours = [
        fixture.file("/var/tmp/orbit-task-base-12/bundle"),
        fixture.file("/var/lib/orbit/instance-repositories/12.git/config"),
        fixture.file("/var/lib/orbit/instance-repositories/12.git.seeding/HEAD"),
        fixture.file("/var/lib/orbit/instance-mail/instance-12/pending"),
        fixture.file("/data/caddy/orbit/hibernation/app-instance-12.asleep"),
        fixture.file("/data/caddy/orbit/hibernation/app-instance-12.cold"),
        fixture.file("/data/caddy/orbit/hibernation/app-instance-12.log"),
        fixture.file("/dev/shm/orbit/hibernation/app-instance-12.awake"),
    ];
    let sibling = fixture.file("/var/lib/orbit/instance-repositories/120.git/config");
    let sibling_seed = fixture.file("/var/lib/orbit/instance-repositories/120.git.seeding/HEAD");
    let sibling_marker = fixture.file("/data/caddy/orbit/hibernation/app-instance-120.asleep");

    destroy(&fixture, "12", &input()).expect("remove disposable clone");

    assert!(ours.iter().all(|path| !path.exists()));
    assert!(sibling.is_file() && sibling_seed.is_file() && sibling_marker.is_file());
    fixture.checked();
}

#[test]
fn destroy_retry_after_incus_deletion_still_discards_the_legacy_state() {
    let fixture = Fixture::new(&[
        (INSTANCE, MISSING),
        (INNER, MISSING),
        (VOLUMES, Ok("[]")),
        (ACL, MISSING),
        (VOLUMES, Ok("[]")),
        (INSTANCE, MISSING),
        (ACL, MISSING),
        (RULES, Ok(SIBLING_RULE)),
        (RULES, Ok(SIBLING_RULE)),
        (DISCARD, Ok(r#"{"outcome":"discarded","removed":false}"#)),
    ]);

    destroy(&fixture, "12", &input()).expect("retry disposable clone");

    fixture.checked();
}

#[test]
fn destroy_cannot_change_any_resource_if_the_signed_identity_is_not_owned() {
    let fixture = Fixture::new(&[]);
    for (field, value) in [
        ("name", "instance-120"),
        ("project", "default"),
        ("pool", "other"),
        ("acl", "instance-120"),
        ("inner_volume", "inner-120"),
    ] {
        let mut input = input();
        input[field] = json!(value);
        assert_eq!(
            destroy(&fixture, "12", &input).expect_err("not owned").code,
            "instance.destroy_input_invalid"
        );
    }
    assert_eq!(
        destroy(&fixture, "012", &input()).expect_err("not canonical").code,
        "instance.destroy_input_invalid"
    );
    fixture.checked();
}

#[test]
fn destroy_refuses_a_dataset_that_reappeared_and_does_not_adopt_it() {
    let stopped = Ok(
        r#"{"name":"instance-12","type":"container","status":"Stopped","expanded_devices":{"root":{"pool":"orbit-instances"}}}"#,
    );
    let fixture = Fixture::new(&[
        (INSTANCE, stopped),
        (DELETE, Err("dataset missing")),
        (INSTANCE, stopped),
        (
            "/usr/bin/incus query /1.0/instances/instance-12/snapshots?project=orbit-instances-nested&recursion=1",
            Ok("[]"),
        ),
        (
            "/usr/bin/incus query /1.0/storage-pools/orbit-instances",
            Ok(r#"{"driver":"zfs","config":{"zfs.pool_name":"fixture"}}"#),
        ),
        (
            "/usr/sbin/zfs list -H -o name -t all -r fixture",
            Ok("fixture\nfixture/containers\nfixture/containers/orbit-instances-nested_instance-120\n"),
        ),
        (
            "/usr/sbin/zfs list -H -o name -r -t all fixture/containers/orbit-instances-nested_instance-12",
            Ok("fixture/containers/orbit-instances-nested_instance-12\n"),
        ),
    ]);

    assert_eq!(
        destroy(&fixture, "12", &input()).expect_err("someone holds the name").code,
        "instance.removal_dataset_taken"
    );
    fixture.checked();
}

#[test]
fn destroy_leaves_markers_until_the_legacy_helper_reports_discarded() {
    let fixture = Fixture::new(&[
        (INSTANCE, MISSING),
        (INNER, MISSING),
        (VOLUMES, Ok("[]")),
        (ACL, MISSING),
        (VOLUMES, Ok("[]")),
        (INSTANCE, MISSING),
        (ACL, MISSING),
        (RULES, Ok("")),
        (RULES, Ok("")),
        (DISCARD, Ok(r#"{"outcome":"unknown"}"#)),
    ]);
    let marker = fixture.file("/data/caddy/orbit/hibernation/app-instance-12.asleep");

    assert_eq!(
        destroy(&fixture, "12", &input()).expect_err("no terminal discard").code,
        "instance.power_discard_failed"
    );
    assert!(marker.is_file());
    fixture.checked();
}
