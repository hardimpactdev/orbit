use std::cell::RefCell;

use serde_json::{json, Value};

use super::*;

/// A Node double: answers each `incus` call from a table, and records every call with its standard input.
struct Fake {
    answers: Vec<(String, Result<String, String>)>,
    calls: RefCell<Vec<(String, Option<String>)>>,
    root: tempfile::TempDir,
}

impl Fake {
    fn new(answers: &[(&str, Result<Value, &str>)]) -> Self {
        Self {
            answers: answers
                .iter()
                .map(|(call, answer)| {
                    let text = |value: &Value| value.as_str().map_or_else(|| value.to_string(), str::to_owned);
                    ((*call).to_owned(), answer.as_ref().map(text).map_err(|error| (*error).to_owned()))
                })
                .collect(),
            calls: RefCell::default(),
            root: tempfile::tempdir().expect("scratch"),
        }
    }

    fn calls(&self) -> Vec<String> {
        self.calls.borrow().iter().map(|(call, _)| call.clone()).collect()
    }
}

impl System for Fake {
    fn run(&self, _seconds: u32, argv: &[String], stdin: Option<&[u8]>) -> Result<String, String> {
        let call = argv[1..].join(" ");
        self.calls.borrow_mut().push((call.clone(), stdin.map(|bytes| String::from_utf8_lossy(bytes).into_owned())));
        self.answers
            .iter()
            .find(|(part, _)| call.contains(part.as_str()))
            .map_or_else(|| Ok(String::new()), |(_, answer)| answer.clone())
    }

    fn blob(&self, sha256: &str) -> Result<Vec<u8>, String> {
        if sha256 == "env" {
            return Ok(b"APP_URL=new\n".to_vec());
        }
        Ok(format!("blob {sha256}").into_bytes())
    }

    fn read(&self, path: &str) -> Result<Vec<u8>, String> {
        Err(format!("{path} not in the double"))
    }

    fn path(&self, value: &str) -> std::path::PathBuf {
        self.root.path().join(value.trim_start_matches('/'))
    }
}

fn input() -> Value {
    json!({
        "project": "orbit-instances",
        "name": "instance-12",
        "source": "instance-2",
        "snapshot": "seed-abc123",
        "acl": {"name": "instance-12", "rules": {"description": "Orbit Instance 12", "egress": [], "ingress": [{"action": "allow"}]}},
        "nic_acl": "orbitinst0-proxy",
        "address": "10.253.0.12",
        "config": {"limits.cpu": "4", "security.nesting": "true"},
        "root": {"pool": "orbit-instances", "size": "40GiB"},
        "filters": {"security.ipv4_filtering": "true", "security.mac_filtering": "true"},
        "proxy": {"device": "t3", "listen": "tcp:10.44.0.7:3801", "connect": "tcp:10.253.0.12:3773"},
        "routes": [{"interface_in": "orbit", "interface_out": "orbitinst0", "from": "any", "to": "10.253.0.12", "port": 3773, "comment": "orbit:instance-12-t3"}],
    })
}

const MISSING: Result<Value, &str> = Err("Error: Not Found");

/// A step's JSON answer as a value, so a test never depends on the order of its keys.
fn parsed(text: &str) -> Value {
    serde_json::from_str(text).expect("json")
}

fn snapshots(names: &[(&str, &str)]) -> Value {
    Value::Array(names.iter().map(|(name, at)| json!({"name": name, "created_at": at})).collect())
}

#[test]
fn a_new_copy_gets_its_acl_the_seed_copy_and_the_t3_proxy() {
    let fake = Fake::new(&[
        ("query /1.0/instances/instance-12?", MISSING),
        ("query /1.0/network-acls/", MISSING),
        ("query /1.0/instances/instance-2/snapshots", Ok(snapshots(&[("seed-abc123", "2026-11-19")]))),
        ("query /1.0/storage-pools/", Ok(json!({"driver": "zfs"}))),
    ]);

    let output = clone(&fake, &input()).expect("clone");

    assert_eq!(parsed(&output), parsed(r#"{"seed_taken_at":null,"snapshot":"seed-abc123"}"#));
    let calls = fake.calls();
    assert_eq!(calls[2], "network acl create instance-12 --project default");
    assert_eq!(calls[3], "network acl edit instance-12 --project default");
    let edit = fake.calls.borrow()[3].1.clone().expect("rules on stdin");
    assert_eq!(serde_json::from_str::<Value>(&edit).expect("json"), input()["acl"]["rules"]);
    assert_eq!(
        calls[6],
        "--project orbit-instances copy instance-2/seed-abc123 instance-12 --config limits.cpu=4 --config security.nesting=true \
         --device root,type=disk --device root,path=/ --device root,pool=orbit-instances --device root,size=40GiB \
         --device eth0,ipv4.address=10.253.0.12 --device eth0,security.acls=orbitinst0-proxy,instance-12 \
         --device eth0,security.acls.default.egress.action=reject --device eth0,security.acls.default.ingress.action=reject \
         --device eth0,security.ipv4_filtering=true --device eth0,security.mac_filtering=true"
    );
    assert_eq!(
        calls[7],
        "--project orbit-instances config device add instance-12 t3 proxy listen=tcp:10.44.0.7:3801 connect=tcp:10.253.0.12:3773 nat=true"
    );
    assert_eq!(calls[8], "route allow in on orbit out on orbitinst0 from any to 10.253.0.12 port 3773 proto tcp comment orbit:instance-12-t3");
    assert_eq!(calls.len(), 9);
}

#[test]
fn a_btrfs_root_carries_no_size() {
    let fake = Fake::new(&[
        ("query /1.0/instances/instance-12?", MISSING),
        ("query /1.0/network-acls/", Ok(input()["acl"]["rules"].clone())),
        ("query /1.0/instances/instance-2/snapshots", Ok(snapshots(&[("seed-abc123", "")]))),
        ("query /1.0/storage-pools/", Ok(json!({"driver": "btrfs"}))),
    ]);

    clone(&fake, &input()).expect("clone");

    let copy = fake.calls().into_iter().find(|call| call.contains(" copy ")).expect("copy");
    assert!(!copy.contains("root,size"), "{copy}");
}

#[test]
fn a_repeat_finds_the_copy_and_its_acl_and_changes_nothing() {
    let fake = Fake::new(&[
        ("query /1.0/instances/instance-12?", Ok(json!({"devices": {"t3": {"type": "proxy"}}}))),
        ("query /1.0/network-acls/", Ok(input()["acl"]["rules"].clone())),
    ]);

    assert_eq!(parsed(&clone(&fake, &input()).expect("clone")), parsed(r#"{"seed_taken_at":null,"snapshot":null}"#));
    assert_eq!(fake.calls().len(), 3, "the copy and its ACL are found; only the route rule is applied again, which ufw skips");
}

#[test]
fn a_replaced_seed_copies_the_newest_and_a_retake_refuses() {
    let replaced = Fake::new(&[
        ("query /1.0/instances/instance-12?", MISSING),
        ("query /1.0/network-acls/", Ok(input()["acl"]["rules"].clone())),
        ("query /1.0/instances/instance-2/snapshots", Ok(snapshots(&[("seed-old", "2026-11-01"), ("seed-new", "2026-11-18")]))),
        ("query /1.0/storage-pools/", Ok(json!({"driver": "zfs"}))),
    ]);
    assert_eq!(parsed(&clone(&replaced, &input()).expect("clone")), parsed(r#"{"seed_taken_at":null,"snapshot":"seed-new"}"#));

    let retake = Fake::new(&[
        ("query /1.0/instances/instance-12?", MISSING),
        ("query /1.0/network-acls/", Ok(input()["acl"]["rules"].clone())),
        ("query /1.0/instances/instance-2/snapshots", Ok(snapshots(&[("seed-new", "2026-11-18"), ("next-abc123", "2026-11-19")]))),
    ]);
    assert_eq!(clone(&retake, &input()).map_err(|failure| failure.code), Err("instance.default_seed_moved"));

    let none = Fake::new(&[
        ("query /1.0/instances/instance-12?", MISSING),
        ("query /1.0/network-acls/", Ok(input()["acl"]["rules"].clone())),
        ("query /1.0/instances/instance-2/snapshots", Ok(snapshots(&[]))),
    ]);
    assert_eq!(clone(&none, &input()).map_err(|failure| failure.code), Err("instance.default_seed_missing"));
}

#[test]
fn a_dir_pool_refuses_before_any_copy() {
    let fake = Fake::new(&[
        ("query /1.0/instances/instance-12?", MISSING),
        ("query /1.0/network-acls/", Ok(input()["acl"]["rules"].clone())),
        ("query /1.0/instances/instance-2/snapshots", Ok(snapshots(&[("seed-abc123", "")]))),
        ("query /1.0/storage-pools/", Ok(json!({"driver": "dir"}))),
    ]);

    assert_eq!(clone(&fake, &input()).map_err(|failure| failure.code), Err("instance.pool_driver_unsupported"));
    assert!(!fake.calls().iter().any(|call| call.contains(" copy ")));
}

#[test]
fn an_incus_failure_other_than_not_found_fails_the_clone() {
    let fake = Fake::new(&[("query /1.0/instances/instance-12?", Err("Error: connection refused"))]);

    assert_eq!(clone(&fake, &input()).map_err(|failure| failure.code), Err("instance.clone_failed"));
}

#[test]
fn values_that_could_carry_another_option_are_refused() {
    let cases: [(&str, Value); 7] = [
        ("/name", json!("instance-12 --force")),
        ("/snapshot", json!("next-abc")),
        ("/address", json!("10.253.0.1,eth1")),
        ("/config", json!({"limits.cpu": "4\nx"})),
        ("/filters", json!({"security.acls": "none"})),
        ("/proxy/listen", json!("tcp:0.0.0.0:22 --x")),
        ("/routes/0/comment", json!("--force")),
    ];
    for (pointer, value) in cases {
        let mut input = input();
        *input.pointer_mut(pointer).expect("field") = value;
        let fake = Fake::new(&[]);
        assert_eq!(clone(&fake, &input).map_err(|failure| failure.code), Err("instance.clone_input_invalid"), "{pointer}");
        assert_eq!(fake.calls(), Vec::<String>::new());
    }
}

fn stopped() -> Value {
    json!({"status": "Stopped", "config": {"volatile.uuid": "01234567-89ab-cdef-0123-456789abcdef"}})
}

fn prepare_input(files: &Value) -> Value {
    json!({"project": "orbit-instances", "name": "instance-12", "units": ["orbit-process-7.service"], "process_ids": ["7"], "files": files})
}

#[test]
fn a_running_copy_is_left_alone() {
    let fake = Fake::new(&[("query /1.0/instances/instance-12?", Ok(json!({"status": "Running"})))]);

    let output: Value = serde_json::from_str(&prepare(&fake, &prepare_input(&json!([]))).expect("prepare")).expect("json");
    assert_eq!(output, json!({"app_account_current": false, "changes": 0, "manifest": false, "workspace_clean": false, "fleet_tunnel": false}));
    assert_eq!(fake.calls().len(), 1);
}

#[test]
fn a_stopped_copy_loses_the_seeds_units_and_gets_its_machine_id_and_files() {
    let fake = Fake::new(&[
        ("query /1.0/instances/instance-12?", Ok(stopped())),
        // The nested fleet's tunnel boot unit is the seed's, for every copy: it stays.
        ("path=/etc/systemd/system/multi-user.target.wants", Ok(json!(["orbit-inner-fleet-tunnel.service", "orbit-process-3.service", "ssh.service"]))),
        ("path=/etc/systemd/system/sockets.target.wants", MISSING),
        ("path=/etc/systemd/system/timers.target.wants", MISSING),
        ("path=/etc/systemd/system", Ok(json!(["orbit-process-3.service", "orbit-process-7.service", "orbit-schedule-1.timer", "t3.service"]))),
        ("path=/etc/orbit/processes", Ok(json!(["3", "7"]))),
        ("query /1.0/instances/instance-12/files", MISSING),
    ]);
    let files = json!([
        {"file": {"path": "/etc/orbit/instance", "uid": 0, "gid": 0, "mode": "0644", "content": "ID=12\n"}},
        {"file": {"path": "/home/agent/.config/orbit/model.env", "uid": 2750, "gid": 2750, "mode": "0600", "blob": {"blob": {"sha256": "ab12"}}}},
        {"link": {"path": "/home/agent/.claude/CLAUDE.md", "target": "/home/agent/.config/orbit/agent/AGENTS.md"}},
    ]);

    let changes = prepare(&fake, &prepare_input(&files)).expect("prepare");

    let calls = fake.calls.borrow().clone();
    let named = |part: &str| calls.iter().find(|(call, _)| call.contains(part)).cloned().unwrap_or_else(|| panic!("no call {part}"));
    assert_eq!(named("file delete instance-12").0, "--project orbit-instances file delete instance-12/etc/systemd/system/multi-user.target.wants/orbit-process-3.service");
    assert_eq!(
        named("file delete --force").0,
        "--project orbit-instances file delete --force instance-12/etc/systemd/system/orbit-process-3.service \
         instance-12/etc/systemd/system/orbit-schedule-1.timer instance-12/etc/orbit/processes/3"
    );
    assert_eq!(named("/etc/machine-id").1.as_deref(), Some("0123456789abcdef0123456789abcdef\n"));
    assert_eq!(named("instance-12/etc/orbit/instance").0, "--project orbit-instances file push --create-dirs --uid 0 --gid 0 --mode 0644 - instance-12/etc/orbit/instance");
    assert_eq!(named("model.env").1.as_deref(), Some("blob ab12"));
    assert_eq!(
        named("--type=symlink").0,
        "--project orbit-instances file create --type=symlink --force instance-12/home/agent/.claude/CLAUDE.md /home/agent/.config/orbit/agent/AGENTS.md"
    );
    assert_eq!(serde_json::from_str::<Value>(&changes).expect("json")["changes"], json!(4 + 1 + 3));
}

#[test]
fn a_seed_with_its_manifest_skips_what_it_holds_and_names_the_units_to_remove() {
    let manifest = json!({
        "files": {"/usr/local/libexec/orbit-app-user": {"sha256": "c0b4aab4ac1e1efac0b0f2ee6e0b7ce8e1b5b1f0d36e1ad5c75f6c1fe9b3b4cd", "uid": 0, "gid": 0, "mode": "0755"},
            "/etc/gitconfig": {"sha256": sha256_hex("[safe]\n"), "uid": 0, "gid": 0, "mode": "0644"}},
        "links": {"/home/agent/.claude/CLAUDE.md": "/home/agent/.config/orbit/agent/AGENTS.md"},
        "folders": {"/home/agent/.config/orbit/agent": {"files": {"AGENTS.md": sha256_hex("# Note\n")}, "skills": true}},
        // A seed taken before the tunnel's boot unit stayed names it: it stays all the same.
        "units": ["/etc/systemd/system/multi-user.target.wants/orbit-inner-fleet-tunnel.service",
            "/etc/systemd/system/multi-user.target.wants/orbit-process-3.service", "/etc/systemd/system/orbit-process-3.service",
            "/etc/systemd/system/orbit-process-7.service", "/etc/orbit/processes/3"],
        "workspace_clean": true,
    });
    let fake = Fake::new(&[
        ("query /1.0/instances/instance-12?", Ok(stopped())),
        ("file pull instance-12/etc/orbit/seed-manifest.json", Ok(manifest)),
    ]);
    let files = json!([
        {"file": {"path": "/etc/gitconfig", "uid": 0, "gid": 0, "mode": "0644", "content": "[safe]\n"}},
        {"file": {"path": "/usr/local/libexec/orbit-app-user", "uid": 0, "gid": 0, "mode": "0755", "content": "#!/bin/sh\n"}},
        {"folder": {"path": "/home/agent/.config/orbit/agent", "files": [{"path": "AGENTS.md", "content": "# Note\n"}]}},
        {"link": {"path": "/home/agent/.claude/CLAUDE.md", "target": "/home/agent/.config/orbit/agent/AGENTS.md"}},
    ]);
    let mut input = prepare_input(&files);
    input["app_account"] = json!("/usr/local/libexec/orbit-app-user");

    let output: Value = serde_json::from_str(&prepare(&fake, &input).expect("prepare")).expect("json");

    let calls = fake.calls();
    // No folder listing: the manifest names the units, and the copy's own Process 7 stays.
    assert!(!calls.iter().any(|call| call.contains("/files?project=")), "{calls:?}");
    assert!(calls.iter().any(|call| call == "--project orbit-instances file delete instance-12/etc/systemd/system/multi-user.target.wants/orbit-process-3.service"));
    assert!(calls.iter().any(|call| call.ends_with("file delete --force instance-12/etc/systemd/system/orbit-process-3.service instance-12/etc/orbit/processes/3")));
    // Only the swap file differs from the seed's: it alone is placed, and so the swap runs again.
    let pushed: Vec<&String> = calls.iter().filter(|call| call.contains("file push") || call.contains("--type=symlink")).collect();
    assert_eq!(pushed.len(), 2, "{pushed:?}");
    assert!(pushed[1].ends_with("instance-12/usr/local/libexec/orbit-app-user"));
    assert_eq!(output, json!({"changes": 5, "manifest": true, "app_account_current": false, "workspace_clean": true, "fleet_tunnel": false}));
}

fn sha256_hex(text: &str) -> String {
    use sha2::Digest as _;
    sha2::Sha256::digest(text.as_bytes()).iter().fold(String::new(), |mut out, byte| {
        let _ = std::fmt::Write::write_fmt(&mut out, format_args!("{byte:02x}"));
        out
    })
}

#[test]
fn a_json_file_merges_over_the_copys_own_and_an_equal_one_stays() {
    let held = |content: Value| {
        Fake::new(&[
            ("query /1.0/instances/instance-12?", Ok(stopped())),
            ("query /1.0/instances/instance-12/files", MISSING),
            ("%{http_code} %header{x-incus-type} http://incus/1.0/instances/instance-12/files?project=orbit-instances&path=/home/agent/.t3/settings.json", Ok(json!("200 file"))),
            ("x-incus-uid", Ok(json!("2750 2750 600"))),
            ("&path=/home/agent/.t3", Ok(json!("200 directory"))),
            ("file pull instance-12/home/agent/.t3/settings.json", Ok(content)),
        ])
    };
    let files = json!([{"json": {"path": "/home/agent/.t3/settings.json", "uid": 2750, "gid": 2750, "mode": "0600", "json": {"linked": true}}}]);

    let merged = held(json!({"theme": "dark"}));
    prepare(&merged, &prepare_input(&files)).expect("prepare");
    let pushed = merged.calls.borrow().iter().find(|(call, _)| call.ends_with("settings.json") && call.contains("push")).and_then(|(_, stdin)| stdin.clone());
    let pushed: Value = serde_json::from_str(pushed.as_deref().expect("pushed")).expect("json");
    assert_eq!(pushed, json!({"linked": true, "theme": "dark"}));

    let same = held(json!({"linked": true, "theme": "dark"}));
    prepare(&same, &prepare_input(&files)).expect("prepare");
    assert!(!same.calls().iter().any(|call| call.contains("push") && call.ends_with("settings.json")));
}

#[test]
fn prepare_refuses_a_path_outside_the_copy_and_any_other_trust_source() {
    for item in [
        json!({"file": {"path": "/etc/../root/x", "uid": 0, "gid": 0, "mode": "0644", "content": ""}}),
        json!({"file": {"path": "etc/x", "uid": 0, "gid": 0, "mode": "0644", "content": ""}}),
        json!({"file": {"path": "/etc/x", "uid": 0, "gid": 0, "mode": "0644"}}),
        json!({"trust": {"source": "/etc/shadow", "path": "/usr/local/share/ca-certificates/x.crt", "hash_directory": "/etc/ssl/certs", "uid": 0, "gid": 0, "mode": "0644"}}),
    ] {
        let fake = Fake::new(&[]);
        assert_eq!(prepare(&fake, &prepare_input(&json!([item]))).map_err(|failure| failure.code), Err("instance.prepare_input_invalid"), "{item}");
        assert_eq!(fake.calls(), Vec::<String>::new());
    }
}

fn fetch_input() -> Value {
    json!({"mirror": "/var/lib/orbit/instance-repositories/12.git", "repository": "https://github.com/acme/app.git", "branch": "feature/x",
        "protocol": "https", "credential": {"blob": {"sha256": "cred"}}})
}

#[test]
fn a_branch_the_origin_lacks_fetches_nothing() {
    let fake = Fake::new(&[("ls-remote", Ok(json!("")))]);

    assert_eq!(parsed(&fetch_branch(&fake, &fetch_input()).expect("fetch")), parsed(r#"{"commit":null}"#));
    assert_eq!(fake.calls().len(), 1);
    assert!(fake.calls()[0].ends_with("ls-remote --heads -- https://github.com/acme/app.git refs/heads/feature/x"), "{}", fake.calls()[0]);
}

#[test]
fn a_branch_the_origin_has_lands_in_a_new_bare_mirror_and_the_credential_goes() {
    let fake = Fake::new(&[("ls-remote", Ok(json!("abc\trefs/heads/feature/x"))), ("rev-parse", Ok(json!("abc123\n")))]);

    assert_eq!(parsed(&fetch_branch(&fake, &fetch_input()).expect("fetch")), parsed(r#"{"commit":"abc123"}"#));
    let calls = fake.calls();
    assert!(calls[1].starts_with("init --quiet --bare "), "{}", calls[1]);
    assert!(calls[2].contains("protocol.allow=never") && calls[2].ends_with("fetch --quiet --no-tags -- https://github.com/acme/app.git +refs/heads/feature/x:refs/heads/feature/x"), "{}", calls[2]);
    let folder = fake.path("/var/lib/orbit/instance-repositories");
    assert_eq!(std::fs::read_dir(folder).expect("folder").count(), 0, "the credential file is gone");
}

#[test]
fn fetch_refuses_a_mirror_elsewhere_a_branch_that_reads_as_an_option_and_another_protocol() {
    for (pointer, value) in [
        ("/mirror", json!("/root/.ssh")),
        ("/mirror", json!("/var/lib/orbit/instance-repositories/../x.git")),
        ("/branch", json!("--upload-pack=x")),
        ("/branch", json!("a..b")),
        ("/repository", json!("ext::sh -c x")),
        ("/protocol", json!("file")),
    ] {
        let mut input = fetch_input();
        *input.pointer_mut(pointer).expect("field") = value;
        let fake = Fake::new(&[]);
        assert_eq!(fetch_branch(&fake, &input).map_err(|failure| failure.code), Err("instance.fetch_input_invalid"), "{pointer}");
        assert_eq!(fake.calls(), Vec::<String>::new());
    }
}

fn checkout_input() -> Value {
    json!({"project": "orbit-instances", "name": "instance-12", "instance_id": 12, "workspace": "/srv/workspace", "branch": "feature/x",
        "expected_commit": "a".repeat(40), "environment_file": "/srv/workspace/.env", "group": 2760,
        "environment": {"blob": {"sha256": "env"}}, "merge": {"own": ["APP_URL"], "drop": [], "guard": []}})
}

#[test]
fn a_checked_out_workspace_only_gets_its_env_merged() {
    let fake = Fake::new(&[
        ("file pull instance-12/var/lib/orbit/workspace-checkout", Ok(json!({"instance_id": 12, "commit": "a".repeat(40)}))),
        ("x-incus-uid", Ok(json!("2750 2760 640"))),
        ("&path=/srv/workspace/.env", Ok(json!("200 file"))),
        ("file pull instance-12/srv/workspace/.env", Ok(json!("APP_URL=old\nKEEP=1\n"))),
        ("&path=/srv/workspace/.git/info/exclude", Ok(json!("200 file"))),
        ("file pull instance-12/srv/workspace/.git/info/exclude", Ok(json!("/.env\n"))),
    ]);

    let output: Value = serde_json::from_str(&checkout(&fake, &checkout_input()).expect("checkout")).expect("json");

    assert_eq!(output["environment"]["copied"], json!(["KEEP"]));
    assert_eq!(output["commit"], json!("a".repeat(40)), "the commit the mark records");
    assert!(!fake.calls().iter().any(|call| call.contains("checkout --quiet") || call.contains("rev-parse")), "no checkout when the mark names this Instance");
    let pushed = fake.calls.borrow().iter().find(|(call, _)| call.ends_with("instance-12/srv/workspace/.env")).and_then(|(_, stdin)| stdin.clone());
    assert_eq!(pushed.as_deref(), Some("APP_URL=new\nKEEP=1\n"));
}

#[test]
fn a_fresh_copy_checks_its_branch_out_at_the_seeds_commit_and_marks_it() {
    let mut input = checkout_input();
    input["environment"] = json!({"blob": {"sha256": "env"}});
    let fake = Fake::new(&[
        ("file pull instance-12/var/lib/orbit/workspace-checkout", MISSING),
        ("symbolic-ref --quiet --short HEAD", Ok(json!("main\n"))),
        ("rev-parse HEAD", Ok(json!(format!("{}\n", "a".repeat(40))))),
        ("symbolic-ref --short HEAD", Ok(json!("feature/x\n"))),
        ("%{http_code}", Ok(json!("404 "))),
    ]);

    checkout(&fake, &input).expect("checkout");

    let calls = fake.calls();
    assert!(calls.iter().any(|call| call.contains("--user 2750 --group 2760 --cwd /srv/workspace") && call.ends_with("checkout --quiet -B feature/x aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")), "{calls:?}");
    let mark = fake.calls.borrow().iter().find(|(call, _)| call.ends_with("instance-12/var/lib/orbit/workspace-checkout")).and_then(|(_, stdin)| stdin.clone());
    assert_eq!(serde_json::from_str::<Value>(mark.as_deref().expect("mark")).expect("json"), json!({"instance_id": 12, "commit": "a".repeat(40)}));
}

#[test]
fn checkout_refuses_an_env_outside_the_workspace_and_a_commit_that_is_no_commit() {
    for (pointer, value) in [("/environment_file", json!("/etc/shadow")), ("/expected_commit", json!("HEAD")), ("/branch", json!("-x"))] {
        let mut input = checkout_input();
        *input.pointer_mut(pointer).expect("field") = value;
        let fake = Fake::new(&[]);
        assert_eq!(checkout(&fake, &input).map_err(|failure| failure.code), Err("instance.checkout_input_invalid"), "{pointer}");
    }
}

#[test]
fn an_agent_folder_is_made_again_with_exactly_its_files_and_refuses_a_path_outside_it() {
    let fake = Fake::new(&[("query /1.0/instances/instance-12?", Ok(stopped())), ("query /1.0/instances/instance-12/files", MISSING), ("%{http_code}", Ok(json!("200 directory")))]);
    let folder = json!([{"folder": {"path": "/home/agent/.config/orbit/agent", "files": [{"path": "AGENTS.md", "blob": {"blob": {"sha256": "rules"}}}, {"path": ".skills/x/SKILL.md", "blob": {"blob": {"sha256": "skill"}}}]}}]);

    prepare(&fake, &prepare_input(&folder)).expect("prepare");

    let calls = fake.calls();
    let delete = calls.iter().position(|call| call.ends_with("file delete --force instance-12/home/agent/.config/orbit/agent")).expect("folder goes first");
    let pushed = calls.iter().position(|call| call.ends_with("--uid 0 --gid 0 --mode 0644 - instance-12/home/agent/.config/orbit/agent/AGENTS.md")).expect("file");
    assert!(delete < pushed);
    assert!(calls.iter().any(|call| call.ends_with("--mode 0755 instance-12/home/agent/.config/orbit/agent/.skills")));

    let outside = json!([{"folder": {"path": "/home/agent/.config/orbit/agent", "files": [{"path": "../../.ssh/authorized_keys", "blob": {"blob": {"sha256": "k"}}}]}}]);
    assert_eq!(prepare(&Fake::new(&[]), &prepare_input(&outside)).map_err(|failure| failure.code), Err("instance.prepare_input_invalid"));
}

fn start_input(app_account: &str) -> Value {
    json!({"project": "orbit-instances", "name": "instance-12", "source": "instance-2", "network": "orbitinst0", "nic": "eth0",
        "hostname": "engine-proof.orbit", "app_account": app_account})
}

fn running() -> Value {
    json!({"status": "Running", "expanded_devices": {"eth0": {"type": "nic", "network": "orbitinst0", "ipv4.address": "10.253.0.12",
        "security.acls": "orbitinst0-proxy,instance-12", "security.acls.default.egress.action": "reject",
        "security.ipv4_filtering": "true", "security.mac_filtering": "true"}}})
}

#[test]
fn a_started_copy_runs_the_app_account_swap_as_root_after_its_names() {
    let swap = |said: &str| {
        let fake = Fake::new(&[("query /1.0/instances/instance-12?", Ok(running())), ("-- /usr/local/libexec/orbit-app-user", Ok(json!(said)))]);
        let changes = start(&fake, &start_input("/usr/local/libexec/orbit-app-user")).expect("started");
        (changes, fake.calls().last().cloned())
    };

    let (moved, last) = swap("orbit-app-user: moved to www-data (restarted: none)");
    let (kept, _) = swap("orbit-app-user: already www-data");

    assert_eq!(last.as_deref(), Some("--project orbit-instances exec instance-12 -- /usr/local/libexec/orbit-app-user"));
    assert_eq!(moved, kept + 1);
}

#[test]
fn start_refuses_an_app_account_swap_that_is_no_absolute_path() {
    let fake = Fake::new(&[]);

    for path in ["orbit-app-user", "/usr/local/../bin/sh"] {
        assert_eq!(start(&fake, &start_input(path)).map_err(|failure| failure.code), Err("instance.start_input_invalid"));
    }
    assert_eq!(fake.calls(), Vec::<String>::new());
}

#[test]
fn a_task_copys_first_checkout_cleans_its_tree_as_agent_before_the_mark() {
    let mut input = checkout_input();
    input["clean"] = json!(true);
    let fake = Fake::new(&[
        ("file pull instance-12/var/lib/orbit/workspace-checkout", MISSING),
        ("symbolic-ref --quiet --short HEAD", Ok(json!("main\n"))),
        ("rev-parse HEAD", Ok(json!(format!("{}\n", "a".repeat(40))))),
        ("symbolic-ref --short HEAD", Ok(json!("feature/x\n"))),
        ("ls-files -v -z", Ok(json!("H kept\0S skipped\0h assumed\0"))),
        ("diff --name-only --no-renames -z HEAD", Ok(json!("routes/web.php\0"))),
        ("clean -d -ff -n", Ok(json!("Would remove notes/\nWould remove scratch.txt\n"))),
        ("%{http_code}", Ok(json!("404 "))),
    ]);

    let output: Value = serde_json::from_str(&checkout(&fake, &input).expect("checkout")).expect("json");

    assert_eq!(output["cleaned"], json!({"reset_count": 1, "reset": ["routes/web.php"], "removed_count": 2, "removed": ["notes/", "scratch.txt"]}));
    let calls = fake.calls.borrow().clone();
    let git = |part: &str| calls.iter().position(|(call, _)| call.contains(part)).unwrap_or_else(|| panic!("{part} not run: {calls:?}"));
    for part in ["ls-files", "update-index", "reset --quiet --hard HEAD", "clean -d -ff --quiet", "status --porcelain"] {
        let (call, _) = &calls[git(part)];
        assert!(call.contains("--env GIT_CONFIG_GLOBAL=/dev/null --env GIT_CONFIG_NOSYSTEM=1 --env LC_ALL=C -- /usr/sbin/runuser -u agent -- /usr/bin/git"), "{call}");
        assert!(call.contains("-c safe.directory=/srv/workspace -c core.quotePath=false -c core.excludesFile=/home/agent/.config/git/ignore"), "{call}");
    }
    let stdin = |flag: &str| calls.iter().find(|(call, _)| call.contains(flag)).and_then(|(_, stdin)| stdin.clone());
    assert_eq!(stdin("--no-skip-worktree").as_deref(), Some("skipped\0"));
    assert_eq!(stdin("--no-assume-unchanged").as_deref(), Some("assumed\0"));
    assert_eq!(calls.iter().filter(|(call, _)| call.contains("clean -d -ff --quiet")).count(), 1, "a second dry run that finds nothing new ends the rounds");
    // The `.env` is excluded before the clean, and the mark comes after it.
    let exclude = calls.iter().position(|(call, _)| call.ends_with("instance-12/srv/workspace/.git/info/exclude")).expect("exclude");
    let mark = calls.iter().position(|(call, _)| call.ends_with("instance-12/var/lib/orbit/workspace-checkout") && call.contains("push")).expect("mark");
    assert!(exclude < git("reset --quiet --hard") && git("status --porcelain") < mark);
}

#[test]
fn a_task_copy_that_stays_dirty_fails_its_checkout_and_writes_no_mark() {
    let mut input = checkout_input();
    input["clean"] = json!(true);
    let fake = Fake::new(&[
        ("file pull instance-12/var/lib/orbit/workspace-checkout", MISSING),
        ("symbolic-ref --quiet --short HEAD", Ok(json!("feature/x\n"))),
        ("rev-parse HEAD", Ok(json!(format!("{}\n", "a".repeat(40))))),
        ("symbolic-ref --short HEAD", Ok(json!("feature/x\n"))),
        ("status --porcelain", Ok(json!(" M vendor-module\n"))),
        ("%{http_code}", Ok(json!("404 "))),
    ]);

    assert_eq!(checkout(&fake, &input).map_err(|failure| failure.code), Err("instance.container_checkout_failed"));
    assert!(!fake.calls().iter().any(|call| call.ends_with("var/lib/orbit/workspace-checkout") && call.contains("push")));
}

#[test]
fn a_copy_that_is_no_task_copy_keeps_the_defaults_tree() {
    let fake = Fake::new(&[
        ("file pull instance-12/var/lib/orbit/workspace-checkout", MISSING),
        ("symbolic-ref --quiet --short HEAD", Ok(json!("feature/x\n"))),
        ("rev-parse HEAD", Ok(json!(format!("{}\n", "a".repeat(40))))),
        ("symbolic-ref --short HEAD", Ok(json!("feature/x\n"))),
        ("%{http_code}", Ok(json!("404 "))),
    ]);

    let output: Value = serde_json::from_str(&checkout(&fake, &checkout_input()).expect("checkout")).expect("json");

    assert_eq!(output.get("cleaned"), None);
    assert!(!fake.calls().iter().any(|call| call.contains("reset --quiet --hard") || call.contains("clean -d")));
}

fn club_input() -> Value {
    json!({"project": "orbit-instances-nested", "name": "instance-12", "directory": "/run/orbit-club", "file": "swamp-club-api-key",
        "owner": 1000, "group": 1000, "tmpfiles": "/etc/tmpfiles.d/orbit-club.conf", "gateway": "nested-gateway",
        "serve_unit": "orbit-swamp-serve", "key": {"blob": {"sha256": "k"}}})
}

#[test]
fn a_new_club_key_lands_in_run_through_a_next_file_and_restarts_serve() {
    let fake = Fake::new(&[
        ("findmnt", Ok(json!("tmpfs\n"))),
        ("%{http_code}", Ok(json!("404 "))),
        ("list --format csv --columns s", Ok(json!("RUNNING\n"))),
    ]);

    let output: Value = serde_json::from_str(&club_key(&fake, &club_input()).expect("club key")).expect("json");

    assert_eq!(output, json!({"club_key": "changed"}));
    let calls = fake.calls.borrow().clone();
    let at = |part: &str| calls.iter().position(|(call, _)| call.contains(part)).unwrap_or_else(|| panic!("{part} not run: {calls:?}"));
    assert!(calls[0].0.ends_with("-- /usr/bin/findmnt --noheadings --output FSTYPE --target /run"));
    assert!(at("chown -- 0:1000 /run/orbit-club") < at("chmod 0750 -- /run/orbit-club"));
    let (_, rule) = &calls[at("instance-12/etc/tmpfiles.d/orbit-club.conf")];
    assert_eq!(rule.as_deref(), Some("d /run/orbit-club 0750 0 1000 -\n"));
    let (push, key) = &calls[at("instance-12/run/orbit-club/.swamp-club-api-key.next")];
    assert!(push.contains("file push --uid 1000 --gid 1000 --mode 0600 -"), "{push}");
    assert_eq!(key.as_deref(), Some("blob k"), "the key goes only on stdin");
    assert!(!calls.iter().any(|(call, _)| call.contains("blob k")), "the key is never an argument");
    assert!(at(".next") < at("/usr/bin/mv -f -- /run/orbit-club/.swamp-club-api-key.next /run/orbit-club/swamp-club-api-key"));
    assert!(calls.last().expect("calls").0.ends_with("exec nested-gateway -- systemctl restart --no-block -- orbit-swamp-serve"));
}

#[test]
fn a_club_key_refuses_a_run_that_is_no_tmpfs_and_keeps_an_equal_key() {
    let disk = Fake::new(&[("findmnt", Ok(json!("ext4\n")))]);
    assert_eq!(club_key(&disk, &club_input()).map_err(|failure| failure.code), Err("instance.nested_club_key_not_tmpfs"));
    assert!(!disk.calls().iter().any(|call| call.contains("file push")));

    let same = Fake::new(&[
        ("findmnt", Ok(json!("tmpfs\n"))),
        ("x-incus-uid", Ok(json!("1000 1000 600"))),
        ("&path=/etc/tmpfiles.d/orbit-club.conf", Ok(json!("200 file"))),
        ("file pull instance-12/etc/tmpfiles.d/orbit-club.conf", Ok(json!("d /run/orbit-club 0750 0 1000 -\n"))),
        ("&path=/run/orbit-club/swamp-club-api-key", Ok(json!("200 file"))),
        ("file pull instance-12/run/orbit-club/swamp-club-api-key", Ok(json!("blob k"))),
    ]);
    assert_eq!(parsed(&club_key(&same, &club_input()).expect("club key")), parsed(r#"{"club_key":"unchanged"}"#));
    assert!(!same.calls().iter().any(|call| call.contains("file push") || call.contains("restart")));

    let mut none = club_input();
    none.as_object_mut().expect("object").remove("key");
    let fake = Fake::new(&[]);
    assert_eq!(parsed(&club_key(&fake, &none).expect("no key")), parsed(r#"{"club_key":null}"#));
    assert_eq!(fake.calls(), Vec::<String>::new());
}

#[test]
fn a_club_key_leaves_serve_to_start_by_itself_when_the_inner_incus_does_not_answer() {
    let fake = Fake::new(&[("findmnt", Ok(json!("tmpfs\n"))), ("%{http_code}", Ok(json!("404 "))), ("is-active --quiet incus.service", Err("inactive"))]);

    assert_eq!(parsed(&club_key(&fake, &club_input()).expect("club key")), parsed(r#"{"club_key":"changed_serve_pending"}"#));
    assert!(!fake.calls().iter().any(|call| call.contains("restart")));
}

fn volume_input() -> Value {
    json!({"project": "orbit-instances-nested", "name": "instance-12", "source": "instance-2", "snapshot": "seed-abc123", "device": "inner-pool",
        "volume_source": "inner-2", "volume": "inner-12", "pool": "orbit-instances", "size": "30GiB"})
}

fn volumes(names: &[&str]) -> Value {
    Value::Array(names.iter().map(|name| json!(format!("/1.0/storage-pools/orbit-instances/volumes/custom/{name}?project=orbit-instances-nested"))).collect())
}

/// The take of the volume snapshot `seed-abc123` made at `at`, as the Node names it.
fn take(at: &str) -> String {
    sha256_hex(&format!("seed-abc123\n{at}"))[..12].to_owned()
}

fn volume_answers(volumes_held: &[&str], rename: Result<Value, &'static str>) -> Fake {
    Fake::new(&[
        ("query /1.0/instances/instance-12?", MISSING),
        ("query /1.0/instances/instance-2/snapshots", Ok(snapshots(&[("seed-abc123", "2026-11-19")]))),
        ("volumes/custom/inner-2/snapshots", Ok(json!([{"name": "inner-2/seed-abc123", "created_at": "2026-11-20T10:00:00Z"}]))),
        ("volumes/custom?", Ok(volumes(volumes_held))),
        ("storage volume rename", rename),
    ])
}

#[test]
fn a_spare_of_the_same_take_is_taken_by_a_rename_and_one_another_create_took_falls_back_to_a_copy() {
    let spare = format!("spare-inner-2-{}", take("2026-11-20T10:00:00Z"));
    let fake = volume_answers(&["inner-2", &spare], Ok(json!("")));
    assert_eq!(parsed(&copy_volume(&fake, &volume_input()).expect("take")), parsed(r#"{"seed_taken_at":"2026-11-19","snapshot":"seed-abc123","spare":true}"#));
    let calls = fake.calls();
    assert!(calls.iter().any(|call| call.ends_with(&format!("storage volume rename orbit-instances {spare} inner-12"))), "{calls:?}");
    assert!(!calls.iter().any(|call| call.contains("storage volume copy")));
    assert!(calls[calls.len() - 2].ends_with("storage volume set orbit-instances inner-12 size=30GiB"));
    // The volume records the take it was made from, for a retry to read back.
    assert!(calls.last().expect("calls").ends_with("storage volume set orbit-instances inner-12 --property description=2026-11-19"));

    let taken = volume_answers(&["inner-2", &spare], Err("Error: Storage volume not found"));
    assert_eq!(parsed(&copy_volume(&taken, &volume_input()).expect("copy")), parsed(r#"{"seed_taken_at":"2026-11-19","snapshot":"seed-abc123","spare":false}"#));
    assert!(taken.calls().iter().any(|call| call.contains("storage volume copy orbit-instances/inner-2/seed-abc123 orbit-instances/inner-12")));
}

#[test]
fn a_spare_of_an_older_take_of_the_same_seed_is_never_taken_and_goes() {
    // A deployment at the same commit retook `seed-abc123`: the spare holds the older take's inner fleet.
    let older = format!("spare-inner-2-{}", take("2026-11-19T08:00:00Z"));
    let fake = volume_answers(&["inner-2", "inner-12", &older], Ok(json!("")));

    assert_eq!(parsed(&copy_volume(&fake, &volume_input()).expect("copy")), parsed(r#"{"seed_taken_at":"2026-11-19","snapshot":"seed-abc123","spare":false}"#));

    let calls = fake.calls();
    let at = |part: &str| calls.iter().position(|call| call.ends_with(part)).unwrap_or_else(|| panic!("{part} not run: {calls:?}"));
    assert!(!calls.iter().any(|call| call.contains("storage volume rename")));
    // A volume of the copy's name that its disk does not name yet is left from a failed run: it is copied again.
    assert!(at("storage volume delete orbit-instances inner-12") < at("storage volume copy orbit-instances/inner-2/seed-abc123 orbit-instances/inner-12"));
    assert!(calls.iter().any(|call| call.ends_with(&format!("storage volume delete orbit-instances {older}"))));
}

#[test]
fn a_volume_copy_takes_only_the_admitted_seed_and_a_seed_without_its_volume_half_refuses() {
    let moved = Fake::new(&[
        ("query /1.0/instances/instance-12?", MISSING),
        ("query /1.0/instances/instance-2/snapshots", Ok(snapshots(&[("seed-old", "2026-11-18"), ("seed-new", "2026-11-19")]))),
    ]);
    assert_eq!(copy_volume(&moved, &volume_input()).map_err(|failure| failure.code), Err("instance.default_seed_moved"));

    let used = Fake::new(&[
        ("query /1.0/instances/instance-12?", Ok(json!({"devices": {"inner-pool": {"source": "inner-12"}}}))),
        ("volumes/custom/inner-12?", Ok(json!({"description": "2026-11-19"}))),
    ]);
    assert_eq!(parsed(&copy_volume(&used, &volume_input()).expect("repeat")), parsed(r#"{"seed_taken_at":"2026-11-19","snapshot":null,"spare":false}"#));
    assert_eq!(used.calls().len(), 2);

    let incomplete = Fake::new(&[
        ("query /1.0/instances/instance-12?", MISSING),
        ("query /1.0/instances/instance-2/snapshots", Ok(snapshots(&[("seed-abc123", "2026-11-19")]))),
        ("volumes/custom/inner-2/snapshots", Ok(json!([{"name": "inner-2/seed-old", "created_at": "x"}]))),
        ("volumes/custom?", Ok(volumes(&["inner-2"]))),
    ]);
    assert_eq!(copy_volume(&incomplete, &volume_input()).map_err(|failure| failure.code), Err("instance.default_seed_incomplete"));
    assert!(!incomplete.calls().iter().any(|call| call.contains("storage volume copy")));

    let mut same = volume_input();
    same["volume"] = json!("inner-2");
    assert_eq!(copy_volume(&Fake::new(&[]), &same).map_err(|failure| failure.code), Err("instance.copy_volume_input_invalid"));
}

#[test]
fn a_nested_clone_copies_only_the_admitted_seed() {
    let mut input = input();
    input["exact_seed"] = json!(true);
    let fake = Fake::new(&[
        ("query /1.0/instances/instance-12?", MISSING),
        ("query /1.0/network-acls/", MISSING),
        ("query /1.0/instances/instance-2/snapshots", Ok(snapshots(&[("seed-new", "2026-11-19")]))),
    ]);

    assert_eq!(clone(&fake, &input).map_err(|failure| failure.code), Err("instance.default_seed_moved"));
    assert!(!fake.calls().iter().any(|call| call.contains(" copy ")));
}

fn spare_input() -> Value {
    json!({"project": "orbit-instances-nested", "source": "instance-2", "snapshot": "seed-abc123", "volume_source": "inner-2", "pool": "orbit-instances"})
}

#[test]
fn a_spare_is_built_under_the_defaults_builder_name_and_renamed_into_place_once() {
    let spare = format!("spare-inner-2-{}", take("2026-11-20T10:00:00Z"));
    let older = format!("spare-inner-2-{}", take("2026-11-19T08:00:00Z"));
    let none = volume_answers(&["inner-2", "build-inner-2", &older], Ok(json!("")));
    assert_eq!(parsed(&spare_volume(&none, &spare_input()).expect("spare")), parsed(r#"{"spare":"made"}"#));
    let calls = none.calls();
    let at = |part: &str| calls.iter().position(|call| call.ends_with(part)).unwrap_or_else(|| panic!("{part} not run: {calls:?}"));
    // A spare of an older take goes, and so does a builder that an interrupted run left.
    assert!(at(&format!("storage volume delete orbit-instances {older}")) < at("storage volume delete orbit-instances build-inner-2"));
    assert!(at("storage volume delete orbit-instances build-inner-2") < at("storage volume copy orbit-instances/inner-2/seed-abc123 orbit-instances/build-inner-2"));
    assert!(at("storage volume copy orbit-instances/inner-2/seed-abc123 orbit-instances/build-inner-2") < at(&format!("storage volume rename orbit-instances build-inner-2 {spare}")));

    let present = volume_answers(&["inner-2", &spare], Ok(json!("")));
    assert_eq!(parsed(&spare_volume(&present, &spare_input()).expect("present")), parsed(r#"{"spare":"present"}"#));
    assert!(!present.calls().iter().any(|call| call.contains("storage volume copy")));

    let lost = volume_answers(&["inner-2"], Err("Error: Volume by that name already exists"));
    assert_eq!(parsed(&spare_volume(&lost, &spare_input()).expect("lost")), parsed(r#"{"spare":"present"}"#));
    assert!(lost.calls().last().expect("calls").ends_with("storage volume delete orbit-instances build-inner-2"));
}

#[test]
fn the_start_names_the_copys_own_volume_in_its_disk_before_the_guard() {
    let mut copy = running();
    copy["devices"] = json!({"inner-pool": {"source": "inner-2"}});
    let fake = Fake::new(&[("query /1.0/instances/instance-12?", Ok(copy))]);
    let mut input = start_input("/usr/local/libexec/orbit-app-user");
    input["volume"] = json!({"device": "inner-pool", "name": "inner-12"});

    start(&fake, &input).expect("started");

    assert_eq!(fake.calls()[1], "--project orbit-instances config device set instance-12 inner-pool source=inner-12");
}

#[test]
fn a_failed_creates_copy_stops_and_stays() {
    let running = Fake::new(&[("query /1.0/instances/instance-12?", Ok(json!({"status": "Running"})))]);
    assert_eq!(stop(&running, &json!({"project": "orbit-instances", "name": "instance-12"})), Ok(1));
    assert_eq!(running.calls().last().map(String::as_str), Some("--project orbit-instances stop --timeout 30 instance-12"));
    assert!(!running.calls().iter().any(|call| call.contains("delete")));

    for answer in [Ok(json!({"status": "Stopped"})), MISSING] {
        let fake = Fake::new(&[("query /1.0/instances/instance-12?", answer)]);
        assert_eq!(stop(&fake, &json!({"project": "orbit-instances", "name": "instance-12"})), Ok(0));
    }
}

fn nested_start(copy: &Value, volume: &Value) -> Fake {
    Fake::new(&[
        ("query /1.0/instances/instance-12?", Ok(copy.clone())),
        ("volumes/container/instance-12", Ok(json!({"config": {}}))),
        ("volumes/custom/inner-12", Ok(volume.clone())),
        ("query /1.0/storage-pools/orbit-instances", Ok(json!({"driver": "btrfs"}))),
    ])
}

fn nested_input() -> Value {
    let mut input = start_input("/usr/local/libexec/orbit-app-user");
    input["project"] = json!("orbit-instances-nested");
    input["allowed"] = json!({
        "config": {"security.nesting": "true", "environment.NO_PROXY": "x"},
        "devices": {"root": {"type": "disk", "path": "/", "pool": "orbit-instances", "size": "40GiB"},
            "eth0": running()["expanded_devices"]["eth0"].clone(),
            "inner-pool": {"type": "disk", "pool": "orbit-instances", "source": "inner-12", "path": "/srv/inner-pool"}},
        "volumes": {"inner-pool": {"block.filesystem": "btrfs", "size": "30GiB"}},
    });
    input
}

fn nested_copy() -> Value {
    let mut copy = running();
    copy["expanded_config"] = json!({"security.nesting": "true", "volatile.uuid": "u", "environment.NO_PROXY": "y"});
    copy["expanded_devices"]["root"] = json!({"type": "disk", "path": "/", "pool": "orbit-instances"});
    copy["expanded_devices"]["inner-pool"] = json!({"type": "disk", "pool": "orbit-instances", "source": "inner-12", "path": "/srv/inner-pool"});
    copy
}

#[test]
fn a_nested_copy_starts_only_with_exactly_its_config_devices_and_volumes() {
    let volume = json!({"config": {"block.filesystem": "btrfs", "size": "30GiB", "volatile.uuid": "v", "block.create_options": ""}});
    assert!(start(&nested_start(&nested_copy(), &volume), &nested_input()).is_ok(), "a btrfs root has no size, and Incus's own keys do not count");

    let refused = |copy: Value, volume: Value| start(&nested_start(&copy, &volume), &nested_input()).map_err(|failure| failure.code);
    let mut extra = nested_copy();
    extra["expanded_config"]["security.privileged"] = json!("true");
    assert_eq!(refused(extra, volume.clone()), Err("instance.container_network_unsafe"));
    let mut missing = nested_copy();
    missing["expanded_config"].as_object_mut().expect("config").remove("security.nesting");
    assert_eq!(refused(missing, volume.clone()), Err("instance.container_network_unsafe"));
    let mut device = nested_copy();
    device["expanded_devices"]["host"] = json!({"type": "disk", "source": "/", "path": "/host"});
    assert_eq!(refused(device, volume.clone()), Err("instance.container_network_unsafe"));
    let mut sized = nested_copy();
    sized["expanded_devices"]["root"]["size"] = json!("40GiB");
    assert_eq!(refused(sized, volume), Err("instance.container_network_unsafe"));
    assert_eq!(refused(nested_copy(), json!({"config": {"block.filesystem": "btrfs", "size": "30GiB", "zfs.delegate": "true"}})), Err("instance.container_network_unsafe"));
    assert_eq!(refused(nested_copy(), json!({"config": {"block.filesystem": "ext4", "size": "30GiB"}})), Err("instance.container_network_unsafe"));
}

fn settings(content: Value, json: &Value) -> Fake {
    let fake = Fake::new(&[
        ("query /1.0/instances/instance-12?", Ok(stopped())),
        ("query /1.0/instances/instance-12/files", MISSING),
        ("x-incus-uid", Ok(json!("2750 2750 600"))),
        ("&path=/home/agent/.t3/userdata/settings.json", Ok(json!("200 file"))),
        ("&path=/home/agent/.t3", Ok(json!("200 directory"))),
        ("file pull instance-12/home/agent/.t3/userdata/settings.json", Ok(content)),
    ]);
    let files = json!([{"json": {"path": "/home/agent/.t3/userdata/settings.json", "uid": 2750, "gid": 2750, "mode": "0600", "json": json,
        "key_value": "#orbit-model-key", "key": "model-key-1"}}]);
    let _ = prepare(&fake, &prepare_input(&files));
    fake
}

fn pushed_settings(fake: &Fake) -> Option<Value> {
    fake.calls.borrow().iter().find(|(call, _)| call.contains("push") && call.ends_with("settings.json")).and_then(|(_, stdin)| stdin.as_deref().map(|text| serde_json::from_str(text).expect("json")))
}

#[test]
fn settings_merge_object_by_object_keep_the_agents_own_instance_and_put_the_key_in_place() {
    let orbit = json!({"linkedEnvironmentsEnabled": true, "providerInstances": {"antigravity": {"driver": "antigravity", "config": {"apiKey": "#orbit-model-key"}}}});
    let held = json!({"theme": "dark", "providerInstances": {"mine": {"driver": "codex"}, "antigravity": {"enabled": false}}});

    let fake = settings(held, &orbit);

    assert_eq!(
        pushed_settings(&fake),
        Some(json!({"theme": "dark", "providerInstances": {"mine": {"driver": "codex"},
            "antigravity": {"enabled": false, "driver": "antigravity", "config": {"apiKey": "model-key-1"}}}, "linkedEnvironmentsEnabled": true}))
    );
}

#[test]
fn settings_whose_key_the_conn_server_moved_into_its_store_stay_as_they_are() {
    let orbit = json!({"providerInstances": {"antigravity": {"config": {"apiKey": "#orbit-model-key"}}}});
    let moved = json!({"providerInstances": {"antigravity": {"config": {"apiKey": "\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}"}}}});
    assert_eq!(pushed_settings(&settings(moved, &orbit)), None);

    let held = json!({"providerInstances": {"antigravity": {"config": {"apiKey": "model-key-1"}}}});
    assert_eq!(pushed_settings(&settings(held, &orbit)), None, "a file that already holds the key stays");
}

#[test]
fn settings_that_need_the_key_refuse_without_it() {
    let fake = Fake::new(&[
        ("query /1.0/instances/instance-12?", Ok(stopped())),
        ("query /1.0/instances/instance-12/files", MISSING),
        ("%{http_code}", Ok(json!("404 "))),
    ]);
    let files = json!([{"json": {"path": "/home/agent/.t3/userdata/settings.json", "uid": 2750, "gid": 2750, "mode": "0600",
        "json": {"apiKey": "#orbit-model-key"}, "key_value": "#orbit-model-key"}}]);

    assert_eq!(prepare(&fake, &prepare_input(&files)).map_err(|failure| failure.code), Err("instance.clone_t3_files_refused"));
}

#[test]
fn a_nested_clone_records_the_seed_take_it_read_and_copies_again_a_never_started_copy_of_another_take() {
    let mut input = input();
    input["exact_seed"] = json!(true);
    let new = Fake::new(&[
        ("query /1.0/instances/instance-12?", MISSING),
        ("query /1.0/network-acls/", MISSING),
        ("query /1.0/instances/instance-2/snapshots", Ok(snapshots(&[("seed-abc123", "2026-11-20T10:00:00Z")]))),
        ("query /1.0/storage-pools/", Ok(json!({"driver": "btrfs"}))),
    ]);
    assert_eq!(parsed(&clone(&new, &input).expect("clone")), parsed(r#"{"seed_taken_at":"2026-11-20T10:00:00Z","snapshot":"seed-abc123"}"#));
    let calls = new.calls.borrow().clone();
    let copied = calls.iter().position(|(call, _)| call.contains(" copy instance-2/seed-abc123 instance-12")).expect("copy");
    let (pushed, take) = calls.iter().enumerate().find(|(_, (call, _))| call.ends_with("- instance-12/etc/orbit/seed-take")).map(|(at, (_, stdin))| (at, stdin.clone())).expect("take file");
    assert!(copied < pushed);
    assert_eq!(take.as_deref(), Some("2026-11-20T10:00:00Z\n"));

    // An earlier run copied the seed's older take and never started it: it goes, and the current take is copied.
    let stale = Fake::new(&[
        ("query /1.0/instances/instance-12?", Ok(json!({"config": {}, "devices": {}}))),
        ("query /1.0/network-acls/", Ok(json!({}))),
        ("query /1.0/instances/instance-2/snapshots", Ok(snapshots(&[("seed-abc123", "2026-11-20T10:00:00Z")]))),
        ("file pull instance-12/etc/orbit/seed-take", Ok(json!("2026-11-19T08:00:00Z\n"))),
        ("query /1.0/storage-pools/", Ok(json!({"driver": "btrfs"}))),
    ]);
    assert_eq!(parsed(&clone(&stale, &input).expect("again")), parsed(r#"{"seed_taken_at":"2026-11-20T10:00:00Z","snapshot":"seed-abc123"}"#));
    let calls = stale.calls();
    let at = |part: &str| calls.iter().position(|call| call.contains(part)).unwrap_or_else(|| panic!("{part} not run: {calls:?}"));
    assert!(at("--project orbit-instances delete instance-12 --force") < at(" copy instance-2/seed-abc123 instance-12"));

    // A started copy stays: its take goes to the start, which compares it.
    let started = Fake::new(&[
        ("query /1.0/instances/instance-12?", Ok(json!({"config": {"volatile.last_state.power": "RUNNING"}, "devices": {}}))),
        ("query /1.0/network-acls/", Ok(json!({}))),
        ("query /1.0/instances/instance-2/snapshots", Ok(snapshots(&[("seed-abc123", "2026-11-20T10:00:00Z")]))),
        ("file pull instance-12/etc/orbit/seed-take", Ok(json!("2026-11-19T08:00:00Z\n"))),
    ]);
    assert_eq!(parsed(&clone(&started, &input).expect("kept")), parsed(r#"{"seed_taken_at":"2026-11-19T08:00:00Z","snapshot":null}"#));
    assert!(!started.calls().iter().any(|call| call.contains(" delete ") || call.contains(" copy ")));
}

/// A copy that never started, beside a seed taken at `at`.
fn seed_take_start(at: &str) -> Fake {
    let mut copy = running();
    copy["status"] = json!("Stopped");
    copy["config"] = json!({});
    Fake::new(&[
        ("query /1.0/instances/instance-2/snapshots", Ok(snapshots(&[("seed-abc123", at)]))),
        ("query /1.0/instances/instance-12?", Ok(copy)),
        ("query /1.0/instances/instance-2?", Ok(json!({"config": {}}))),
    ])
}

fn seed_take_input(clone: &str, volume: &str) -> Value {
    let mut input = start_input("/usr/local/libexec/orbit-app-user");
    input["seed_take"] = json!({"snapshot": "seed-abc123", "clone": clone, "volume": volume});
    input
}

#[test]
fn a_nested_copy_whose_container_and_volume_hold_different_takes_refuses_to_start() {
    let fake = seed_take_start("2026-11-20T10:00:00Z");

    let started = start(&fake, &seed_take_input("2026-11-20T10:00:00Z", "2026-11-19T08:00:00Z"));

    assert_eq!(started.map_err(|failure| failure.code), Err("instance.default_seed_moved"));
    assert!(!fake.calls().iter().any(|call| call.contains(" start ") || call.contains("config device")));
}

#[test]
fn a_copy_that_started_already_skips_the_seed_take_check() {
    // A late receipt after a retake resends the start of a good copy: it must not refuse, or the stop step stops it.
    let mut ran = running();
    ran["config"] = json!({});
    let mut stopped = running();
    stopped["status"] = json!("Stopped");
    stopped["config"] = json!({"volatile.last_state.power": "STOPPED"});
    for copy in [ran, stopped] {
        let fake = Fake::new(&[
            ("query /1.0/instances/instance-2/snapshots", Ok(snapshots(&[("seed-abc123", "2026-11-21T09:00:00Z")]))),
            ("query /1.0/instances/instance-12?", Ok(copy)),
            ("query /1.0/instances/instance-2?", Ok(json!({"config": {}}))),
        ]);
        assert!(start(&fake, &seed_take_input("2026-11-20T10:00:00Z", "2026-11-20T10:00:00Z")).is_ok());
        assert!(!fake.calls().iter().any(|call| call.contains("/snapshots")));
    }
}

#[test]
fn a_nested_copy_starts_only_when_its_container_and_its_volume_hold_the_seeds_current_take() {
    let input = seed_take_input;
    let now = "2026-11-20T10:00:00Z";
    let before = "2026-11-19T08:00:00Z";
    assert!(start(&seed_take_start(now), &input(now, now)).is_ok());

    // The seed was retaken while the two copies ran side by side: one of them, or both, hold another take.
    for (clone, volume) in [(before, now), (now, before), (before, before)] {
        let fake = seed_take_start(now);
        assert_eq!(start(&fake, &input(clone, volume)).map_err(|failure| failure.code), Err("instance.default_seed_moved"));
        assert_eq!(fake.calls(), vec![
            "query /1.0/instances/instance-12?project=orbit-instances".to_owned(),
            "query /1.0/instances/instance-2/snapshots?project=orbit-instances&recursion=1".to_owned(),
        ]);
    }
    assert_eq!(start(&seed_take_start(""), &input("", "")).map_err(|failure| failure.code), Err("instance.container_start_failed"));
}

#[test]
fn a_volume_snapshot_without_a_creation_time_has_no_take_and_refuses() {
    let fake = Fake::new(&[
        ("query /1.0/instances/instance-12?", MISSING),
        ("query /1.0/instances/instance-2/snapshots", Ok(snapshots(&[("seed-abc123", "2026-11-19")]))),
        ("volumes/custom/inner-2/snapshots", Ok(json!([{"name": "inner-2/seed-abc123"}]))),
        ("volumes/custom?", Ok(volumes(&["inner-2", "spare-inner-2-x"]))),
    ]);

    assert_eq!(copy_volume(&fake, &volume_input()).map_err(|failure| failure.code), Err("instance.copy_volume_failed"));
    assert!(!fake.calls().iter().any(|call| call.contains("storage volume copy") || call.contains("storage volume rename")));
}

/// A Node whose default retakes its inner volume's seed while the spare's copy runs.
struct Retaking {
    fake: Fake,
    reads: std::cell::Cell<u32>,
}

impl System for Retaking {
    fn run(&self, seconds: u32, argv: &[String], stdin: Option<&[u8]>) -> Result<String, String> {
        if argv.join(" ").contains("volumes/custom/inner-2/snapshots") {
            self.reads.set(self.reads.get() + 1);
            let at = if self.reads.get() == 1 { "2026-11-20T10:00:00Z" } else { "2026-11-20T11:00:00Z" };
            return Ok(json!([{"name": "inner-2/seed-abc123", "created_at": at}]).to_string());
        }
        self.fake.run(seconds, argv, stdin)
    }

    fn blob(&self, sha256: &str) -> Result<Vec<u8>, String> {
        self.fake.blob(sha256)
    }

    fn read(&self, path: &str) -> Result<Vec<u8>, String> {
        self.fake.read(path)
    }

    fn path(&self, value: &str) -> std::path::PathBuf {
        self.fake.path(value)
    }
}

#[test]
fn a_spare_whose_seed_was_retaken_during_its_copy_goes_and_is_never_placed() {
    let node = Retaking { fake: volume_answers(&["inner-2"], Ok(json!(""))), reads: std::cell::Cell::new(0) };

    assert_eq!(parsed(&spare_volume(&node, &spare_input()).expect("retaken")), parsed(r#"{"spare":"retaken"}"#));

    let calls = node.fake.calls();
    assert!(calls.iter().any(|call| call.ends_with("storage volume copy orbit-instances/inner-2/seed-abc123 orbit-instances/build-inner-2")));
    assert!(calls.last().expect("calls").ends_with("storage volume delete orbit-instances build-inner-2"));
    assert!(!calls.iter().any(|call| call.contains("storage volume rename")));
}

fn fleet_input() -> Value {
    json!({"project": "orbit-instances-nested", "name": "instance-12", "guests": ["nested-gateway", "nested-app-dev", "nested-app-prod"],
        "hub": "10.45.0.1", "host": "gateway.orbit", "nodes": ["gateway", "app-dev", "app-prod"], "seconds": 1})
}

const HEALTHY: &str = r#"{"data":{"healthy":true,"nodes":[]}}"#;

fn nodes(online: &[&str], operator: Option<&str>) -> Value {
    let mut rows: Vec<Value> = ["gateway", "app-dev", "app-prod"]
        .iter()
        .map(|name| json!({"name": name, "agent": {"state": if online.contains(name) { "online" } else { "offline" }}}))
        .collect();
    rows.push(json!({"name": "operator", "agent": null, "wireguard_public_key": operator}));
    json!({"data": rows})
}

fn fleet_node(listed: &str, doctor: Result<Value, &'static str>, read: Value) -> Fake {
    fleet_node_with_tunnel("active\n", listed, doctor, read)
}

fn fleet_node_with_tunnel(state: &'static str, listed: &str, doctor: Result<Value, &'static str>, read: Value) -> Fake {
    Fake::new(&[
        ("test -L /etc/systemd/system/multi-user.target.wants/orbit-inner-fleet-tunnel.service", Ok(json!(""))),
        ("grep -q -F -- # Managed by Orbit: the operator tunnel /etc/systemd/system/orbit-inner-fleet-tunnel.service", Ok(json!(""))),
        ("--property ActiveState --value orbit-inner-fleet-tunnel.service", Ok(json!(state))),
        ("incus list --format csv", Ok(json!(listed))),
        ("api/v1/doctor", doctor),
        ("api/v1/nodes", Ok(read)),
    ])
}

const RUNNING: &str = "nested-app-dev,RUNNING\nnested-app-prod,RUNNING\nnested-gateway,RUNNING\n";

#[test]
fn a_nested_copys_fleet_is_ready_when_doctor_is_healthy_its_agents_are_online_and_it_knows_the_operator() {
    let fake = fleet_node(RUNNING, Ok(json!(HEALTHY)), nodes(&["gateway", "app-dev", "app-prod"], Some("key=")));

    let output = fleet_ready(&fake, &fleet_input()).expect("ready");

    assert_eq!(parsed(&output), json!({"fleet": "ready", "started": []}));
    let calls = fake.calls();
    let exec = "--project orbit-instances-nested exec instance-12 -- ";
    // The fleet starts first: the unit is read only after the nested containers run.
    assert_eq!(calls[0], format!("{exec}/usr/bin/incus admin waitready --timeout 80"));
    assert!(!calls.iter().any(|call| call.contains("incus start")), "{calls:?}");
    // The proof's own reads, over the tunnel, never through the container's proxy.
    assert!(calls.contains(&format!("{exec}/usr/bin/curl -sSk --noproxy * -m 10 -H Host: gateway.orbit -H Accept: application/json -X POST \
        -H Content-Type: application/json -d {{}} https://10.45.0.1/api/v1/doctor")));
}

#[test]
fn a_nested_container_that_did_not_start_by_itself_is_started_with_the_others_in_one_call() {
    let fake = fleet_node("nested-app-dev,STOPPED\nnested-app-prod,RUNNING\nnested-gateway,STOPPED\n", Ok(json!(HEALTHY)),
        nodes(&["gateway", "app-dev", "app-prod"], Some("key=")));

    let output = fleet_ready(&fake, &fleet_input()).expect("ready");

    assert_eq!(parsed(&output), json!({"fleet": "ready", "started": ["nested-gateway", "nested-app-dev"]}));
    assert!(fake.calls().iter().any(|call| call.ends_with("-- /usr/bin/incus start nested-gateway nested-app-dev")));
}

#[test]
fn a_fleet_that_is_not_ready_in_its_bound_fails_and_names_the_check() {
    let code = |fake: &Fake| fleet_ready(fake, &fleet_input()).map_err(|failure| (failure.code, failure.detail));

    let (unhealthy, detail) = code(&fleet_node(RUNNING, Ok(json!(r#"{"data":{"healthy":false}}"#)), nodes(&[], Some("key=")))).expect_err("doctor");
    assert_eq!(unhealthy, "instance.container_fleet_resume_failed");
    assert!(detail.starts_with("Readiness check 'doctor' failed: after 1 s"), "{detail}");

    let (_, silent) = code(&fleet_node(RUNNING, Err("curl: (28) Connection timed out"), nodes(&[], Some("key=")))).expect_err("no answer");
    assert!(silent.contains("no response: curl: (28)"), "{silent}");

    let (_, offline) = code(&fleet_node(RUNNING, Ok(json!(HEALTHY)), nodes(&["gateway", "app-prod"], Some("key=")))).expect_err("agents");
    assert_eq!(offline, "Readiness check 'agents' failed: the agent of app-dev is not online");

    let (_, operator) = code(&fleet_node(RUNNING, Ok(json!(HEALTHY)), nodes(&["gateway", "app-dev", "app-prod"], None))).expect_err("operator");
    assert!(operator.starts_with("Readiness check 'operator' failed"), "{operator}");
}

#[test]
fn a_copy_without_the_tunnels_boot_unit_still_starts_its_fleet_and_reads_the_unit_again_before_it_gives_up() {
    let fake = Fake::new(&[
        ("incus list --format csv", Ok(json!("nested-app-dev,STOPPED\nnested-app-prod,RUNNING\nnested-gateway,RUNNING\n"))),
        ("test -L /etc/systemd/system/multi-user.target.wants/orbit-inner-fleet-tunnel.service", Err("Error: exec failed")),
    ]);

    assert_eq!(parsed(&fleet_ready(&fake, &fleet_input()).expect("skipped")), json!({"fleet": "seed_without_tunnel", "started": ["nested-app-dev"]}));
    let calls = fake.calls();
    assert!(calls.iter().any(|call| call.ends_with("-- /usr/bin/incus start nested-app-dev")), "{calls:?}");
    // A failed read, such as an `incus exec` right after the start, is read again until its bound.
    assert!(calls.iter().filter(|call| call.contains("test -L")).count() >= 2, "{calls:?}");

    let mut wrong = fleet_input();
    wrong["hub"] = json!("10.45.0.1; reboot");
    assert_eq!(fleet_ready(&Fake::new(&[]), &wrong).map_err(|failure| failure.code), Err("instance.fleet_ready_input_invalid"));
}

#[test]
fn the_fleet_waits_until_the_tunnels_whole_boot_script_ran_before_it_reads_doctor() {
    // The unit is still running its script: Doctor may answer over the hub's address before the resolver is set.
    let fake = fleet_node_with_tunnel("activating\n", RUNNING, Ok(json!(HEALTHY)), nodes(&["gateway", "app-dev", "app-prod"], Some("key=")));

    let failure = fleet_ready(&fake, &fleet_input()).expect_err("not active");

    assert_eq!(failure.code, "instance.container_fleet_resume_failed");
    assert!(failure.detail.starts_with("Readiness check 'tunnel' failed: after 1 s: orbit-inner-fleet-tunnel.service is activating"), "{}", failure.detail);
    assert!(!fake.calls().iter().any(|call| call.contains("api/v1/doctor")));
}

#[test]
fn a_tunnel_unit_that_failed_at_boot_is_restarted_once_and_a_second_failure_leaves_the_tunnel_to_the_gateway() {
    let fake = fleet_node_with_tunnel("failed\n", RUNNING, Ok(json!(HEALTHY)), nodes(&["gateway", "app-dev", "app-prod"], Some("key=")));

    assert_eq!(parsed(&fleet_ready(&fake, &fleet_input()).expect("skipped")), json!({"fleet": "seed_without_tunnel", "started": []}));
    let calls = fake.calls();
    assert_eq!(calls.iter().filter(|call| call.ends_with("/usr/bin/systemctl reset-failed orbit-inner-fleet-tunnel.service")).count(), 1, "{calls:?}");
    assert_eq!(calls.iter().filter(|call| call.ends_with("/usr/bin/systemctl restart --no-block orbit-inner-fleet-tunnel.service")).count(), 1, "{calls:?}");
    assert!(!calls.iter().any(|call| call.contains("api/v1/doctor")));
}

#[test]
fn a_hand_made_tunnel_unit_of_the_same_name_is_not_orbits_tunnel() {
    let fake = Fake::new(&[
        ("test -L /etc/systemd/system/multi-user.target.wants/orbit-inner-fleet-tunnel.service", Ok(json!(""))),
        ("grep -q -F -- # Managed by Orbit: the operator tunnel", Err("")),
    ]);

    assert_eq!(parsed(&fleet_ready(&fake, &fleet_input()).expect("skipped")), json!({"fleet": "seed_without_tunnel", "started": ["nested-gateway", "nested-app-dev", "nested-app-prod"]}));
    assert!(!fake.calls().iter().any(|call| call.contains("ActiveState")));
}

#[test]
fn prepare_says_whether_the_seed_holds_orbits_tunnel_boot_unit() {
    let unit = "file pull instance-12/etc/systemd/system/orbit-inner-fleet-tunnel.service";
    let manifest = ("file pull instance-12/etc/orbit/seed-manifest.json", Ok(json!({"units": [], "workspace_clean": false})));
    let read = |status: Value, content: Result<Value, &'static str>| {
        let fake = Fake::new(&[("query /1.0/instances/instance-12?", Ok(status)), manifest.clone(), (unit, content)]);
        parsed(&prepare(&fake, &prepare_input(&json!([]))).expect("prepare"))["fleet_tunnel"].clone()
    };
    let orbits = Ok(json!("# Managed by Orbit: the operator tunnel to this Instance's nested fleet, at each boot.\n[Unit]\n"));

    assert_eq!(read(stopped(), orbits.clone()), json!(true));
    // A hand-made unit of the same name, and no unit at all.
    assert_eq!(read(stopped(), Ok(json!("[Unit]\nDescription=tunnel\n"))), json!(false));
    assert_eq!(read(stopped(), MISSING), json!(false));
    // A running copy is a retry: its first boot is over, and the completion starts its fleet.
    assert_eq!(read(json!({"status": "Running"}), orbits), json!(false));
}
