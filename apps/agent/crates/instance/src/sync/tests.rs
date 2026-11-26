use std::cell::RefCell;

use serde_json::{json, Value};

use super::*;

const HEAD: &str = "1111111111111111111111111111111111111111";
const OLD: &str = "2222222222222222222222222222222222222222";
const BRANCH: &str = "3333333333333333333333333333333333333333";
const START: &str = "4444444444444444444444444444444444444444";

/// Whether the copy holds the pinned head already.
#[derive(Clone, Copy)]
enum Holds {
    Head,
    Older,
}

/// A copy and its Node mirror: the remote ref, whether the copy holds the head, HEAD and the local branches, with every call.
struct Copy {
    origin: RefCell<String>,
    holds: RefCell<bool>,
    head: RefCell<String>,
    calls: RefCell<Vec<String>>,
    root: tempfile::TempDir,
}

impl Copy {
    fn new(origin: &str, holds: Holds) -> Self {
        Self {
            origin: RefCell::new(origin.to_owned()),
            holds: RefCell::new(matches!(holds, Holds::Head)),
            head: RefCell::new(BRANCH.to_owned()),
            calls: RefCell::default(),
            root: tempfile::tempdir().expect("scratch"),
        }
    }

    fn calls(&self) -> Vec<String> {
        self.calls.borrow().clone()
    }

    fn writes(&self) -> Vec<String> {
        self.calls().into_iter().filter(|call| call.contains("update-ref") && call.contains("/usr/sbin/runuser")).collect()
    }
}

impl System for Copy {
    fn run(&self, _seconds: u32, argv: &[String], _stdin: Option<&[u8]>) -> Result<String, String> {
        let call = argv.join(" ");
        self.calls.borrow_mut().push(call.clone());
        let has = |part: &str| call.contains(part);
        if !has("/usr/sbin/runuser") {
            // The mirror and root in the copy.
            return Ok(String::new());
        }
        let answer = if has("--show-toplevel") {
            "/srv/workspace\n".to_owned()
        } else if has("symbolic-ref") {
            "task-42\n".to_owned()
        } else if has("rev-parse HEAD") {
            format!("{}\n", self.head.borrow())
        } else if has("refs/heads") && has("for-each-ref") {
            format!("refs/heads/main {OLD}\nrefs/heads/task-42 {}\n", self.head.borrow())
        } else if has("for-each-ref --format=%(objectname) refs/remotes/origin/main") {
            self.origin.borrow().clone()
        } else if has(&format!("cat-file -e {HEAD}")) {
            return if *self.holds.borrow() { Ok(String::new()) } else { Err("missing".to_owned()) };
        } else if has("update-ref refs/remotes/origin/main") {
            let old = argv.last().expect("old");
            if *old != *self.origin.borrow() {
                return Err("cannot lock ref: is at another value".to_owned());
            }
            *self.origin.borrow_mut() = argv[argv.len() - 2].clone();
            String::new()
        } else if has("rev-parse --verify refs/remotes/origin/main") {
            self.origin.borrow().clone()
        } else if has("fetch --quiet") {
            *self.holds.borrow_mut() = true;
            String::new()
        } else if has("rev-list --count") {
            "3\n".to_owned()
        } else if has("merge-base") && !has("--is-ancestor") {
            format!("{OLD}\n")
        } else if has("status --porcelain") {
            " M app.php\0?? notes.txt\0".to_owned()
        } else if has("/usr/bin/id -g") {
            "2750\n".to_owned()
        } else {
            String::new()
        };
        Ok(answer)
    }

    fn blob(&self, _sha256: &str) -> Result<Vec<u8>, String> {
        Err("no blob".to_owned())
    }

    fn read(&self, path: &str) -> Result<Vec<u8>, String> {
        Err(format!("{path} not in the double"))
    }

    fn path(&self, value: &str) -> std::path::PathBuf {
        self.root.path().join(value.trim_start_matches('/'))
    }
}

fn input() -> Value {
    json!({"project": "orbit-instances", "name": "instance-12", "instance_id": 12, "mirror": "/var/lib/orbit/instance-repositories/12.git",
        "default_branch": "main", "branch": "task-42", "head": HEAD, "start": START, "published": [], "approved": ""})
}

fn result(copy: &Copy) -> Value {
    serde_json::from_str(&sync_base(copy, &input()).expect("sync")).expect("json")
}

#[test]
fn a_copy_that_lacks_the_head_imports_one_bundle_and_moves_only_the_remote_ref() {
    let copy = Copy::new(OLD, Holds::Older);

    let answer = result(&copy);

    assert_eq!(answer["default_commit"], HEAD);
    assert_eq!(answer["branch_commit"], BRANCH);
    assert_eq!(answer["behind_by"], 3);
    assert_eq!(answer["start"], START);
    assert_eq!(answer["bundled"], true);
    assert_eq!(answer["changed"], true);
    assert_eq!(answer["work"]["state"], "dirty");
    assert_eq!(answer["work"]["uncommitted"], 1);
    assert_eq!(answer["work"]["untracked"], 1);
    assert_eq!(answer["work"]["approved_held"], Value::Null);
    let calls = copy.calls();
    let bundle = calls.iter().find(|call| call.contains("bundle create")).expect("bundle");
    assert!(bundle.ends_with(&format!("refs/orbit/task-base-12 ^{OLD} ^{BRANCH}")), "{bundle}");
    assert!(calls.iter().any(|call| call.contains("file push --uid 0 --gid 2750 --mode 0640")));
    assert!(calls.iter().any(|call| call.contains("fetch --quiet --no-tags --no-write-fetch-head /tmp/orbit-task-base-12/base.bundle refs/orbit/task-base-12")));
    // Only the remote ref is written in the copy, compared with the value read before; no merge, checkout, reset or stash.
    assert_eq!(copy.writes(), vec![format!(
        "/usr/bin/incus --project orbit-instances exec --disable-stdin --cwd /srv/workspace instance-12 -- /usr/sbin/runuser -u agent -- /usr/bin/env -i \
HOME=/home/agent PATH=/usr/local/bin:/usr/bin:/bin GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 GIT_TERMINAL_PROMPT=0 /usr/bin/git --literal-pathspecs \
-c core.hooksPath=/dev/null -c core.fsmonitor=false -c core.untrackedCache=false -c core.quotePath=false -c safe.directory=/srv/workspace \
update-ref refs/remotes/origin/main {HEAD} {OLD}")]);
    for verb in [" merge", " checkout", " reset", " stash", " rebase", " commit", " add "] {
        assert!(!calls.iter().any(|call| call.contains("/usr/sbin/runuser") && call.contains(&format!("/usr/bin/git{verb}")) ), "{verb}");
    }
    // The scratch goes after the import: the Node folder, the mirror's pin and the copy's bundle folder.
    assert!(!copy.path("/var/tmp/orbit-task-base-12").exists());
    assert!(calls.iter().any(|call| call.ends_with("-- /bin/rm -rf -- /tmp/orbit-task-base-12")));
}

#[test]
fn a_replay_finds_the_ref_at_the_head_and_writes_nothing() {
    let copy = Copy::new(OLD, Holds::Older);
    let first = result(&copy);

    let replay = result(&copy);

    assert_eq!(copy.writes().len(), 1, "the second run writes no ref");
    assert_eq!(replay["changed"], false);
    assert_eq!(replay["bundled"], false);
    assert_eq!(replay["default_commit"], first["default_commit"]);
    assert_eq!(replay["branch_commit"], first["branch_commit"]);
}

#[test]
fn a_copy_that_holds_the_head_only_points_the_stale_ref_at_it() {
    let copy = Copy::new(OLD, Holds::Head);

    let answer = result(&copy);

    assert_eq!(answer["bundled"], false);
    assert_eq!(answer["changed"], true);
    assert!(!copy.calls().iter().any(|call| call.contains("bundle create")));
    assert_eq!(copy.writes().len(), 1);
}

#[test]
fn a_copy_without_the_remote_ref_creates_it_only_when_it_is_still_absent() {
    let copy = Copy::new("", Holds::Head);

    result(&copy);

    assert!(copy.writes()[0].ends_with(&format!("update-ref refs/remotes/origin/main {HEAD} ")), "{}", copy.writes()[0]);
}

#[test]
fn a_ref_that_moved_after_the_read_refuses_rather_than_overwriting() {
    struct Moving(Copy);
    impl System for Moving {
        fn run(&self, seconds: u32, argv: &[String], stdin: Option<&[u8]>) -> Result<String, String> {
            if argv.join(" ").contains("update-ref refs/remotes/origin/main") {
                *self.0.origin.borrow_mut() = START.to_owned();
            }
            self.0.run(seconds, argv, stdin)
        }
        fn blob(&self, sha256: &str) -> Result<Vec<u8>, String> {
            self.0.blob(sha256)
        }
        fn read(&self, path: &str) -> Result<Vec<u8>, String> {
            self.0.read(path)
        }
        fn path(&self, value: &str) -> std::path::PathBuf {
            self.0.path(value)
        }
    }
    let copy = Moving(Copy::new(OLD, Holds::Head));

    assert_eq!(sync_base(&copy, &input()).map_err(|failure| failure.code), Err("tasks.base_ref_mismatch"));
    assert_eq!(*copy.0.origin.borrow(), START, "the moved ref stays");
}

#[test]
fn a_head_that_moves_during_the_import_fails_the_read_back() {
    struct Committing(Copy);
    impl System for Committing {
        fn run(&self, seconds: u32, argv: &[String], stdin: Option<&[u8]>) -> Result<String, String> {
            if argv.join(" ").contains("fetch --quiet") {
                *self.0.head.borrow_mut() = START.to_owned();
            }
            self.0.run(seconds, argv, stdin)
        }
        fn blob(&self, sha256: &str) -> Result<Vec<u8>, String> {
            self.0.blob(sha256)
        }
        fn read(&self, path: &str) -> Result<Vec<u8>, String> {
            self.0.read(path)
        }
        fn path(&self, value: &str) -> std::path::PathBuf {
            self.0.path(value)
        }
    }
    let copy = Committing(Copy::new(OLD, Holds::Older));

    assert_eq!(sync_base(&copy, &input()).map_err(|failure| failure.code), Err("instance.sync_base_failed"));
}

#[test]
fn an_origin_without_the_default_branch_fails_before_any_call() {
    let copy = Copy::new(OLD, Holds::Older);
    let mut missing = input();
    missing["head"] = Value::Null;

    assert_eq!(sync_base(&copy, &missing).map_err(|failure| failure.code), Err("tasks.default_branch_missing"));
    assert_eq!(copy.calls(), Vec::<String>::new());
}

#[test]
fn sync_refuses_a_shape_outside_the_contract() {
    for (pointer, value) in [
        ("/mirror", json!("/root/x.git")),
        ("/branch", json!("main")),
        ("/default_branch", json!("--upload-pack=x")),
        ("/head", json!("HEAD")),
        ("/name", json!("../x")),
        ("/approved", json!("abc")),
    ] {
        let copy = Copy::new(OLD, Holds::Older);
        let mut wrong = input();
        *wrong.pointer_mut(pointer).expect("field") = value;
        assert_eq!(sync_base(&copy, &wrong).map_err(|failure| failure.code), Err("instance.sync_input_invalid"), "{pointer}");
        assert_eq!(copy.calls(), Vec::<String>::new());
    }
}

#[test]
fn clear_removes_the_node_scratch_and_the_bundle_folder_and_repeats() {
    let copy = Copy::new(OLD, Holds::Older);
    std::fs::create_dir_all(copy.path("/var/tmp/orbit-task-base-12")).expect("scratch");
    let clear = json!({"project": "orbit-instances", "name": "instance-12", "instance_id": 12, "mirror": "/var/lib/orbit/instance-repositories/12.git"});

    assert_eq!(clear_base(&copy, &clear).expect("clear"), r#"{"cleaned":true}"#);
    assert_eq!(clear_base(&copy, &clear).expect("repeat"), r#"{"cleaned":true}"#);
    assert!(!copy.path("/var/tmp/orbit-task-base-12").exists());
    assert_eq!(copy.calls().iter().filter(|call| call.ends_with("exec --disable-stdin instance-12 -- /bin/rm -rf -- /tmp/orbit-task-base-12")).count(), 2);
}

#[test]
fn the_gateway_inputs_of_the_contract_fixtures_pass_the_primitives() {
    let sync: Value = serde_json::from_str(include_str!("../../../../contract/fixtures/executor-instance-sync-base.json")).expect("fixture");
    let clear: Value = serde_json::from_str(include_str!("../../../../contract/fixtures/executor-instance-clear-base.json")).expect("fixture");
    let mut input = sync["fields"]["input"].clone();
    input["head"] = json!(HEAD);
    input["start"] = json!(START);

    assert!(sync_base(&Copy::new(OLD, Holds::Head), &input).is_ok());
    assert!(clear_base(&Copy::new(OLD, Holds::Head), &clear["fields"]["input"]).is_ok());
}
