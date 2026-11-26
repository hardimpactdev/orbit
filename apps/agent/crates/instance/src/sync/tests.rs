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
        } else if has("symbolic-ref --quiet refs/remotes") {
            return Err("not a symbolic ref".to_owned());
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
        } else if has("update-ref --no-deref refs/remotes/origin/main") {
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

    fn blob(&self, sha256: &str) -> Result<Vec<u8>, String> {
        if sha256 == "published" { Ok(b"[]".to_vec()) } else { Err("no blob".to_owned()) }
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
        "default_branch": "main", "branch": "task-42", "head": HEAD, "start": START, "published": {"blob": {"sha256": "published"}}, "approved": ""})
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
update-ref --no-deref refs/remotes/origin/main {HEAD} {OLD}")]);
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

    assert!(copy.writes()[0].ends_with(&format!("update-ref --no-deref refs/remotes/origin/main {HEAD} ")), "{}", copy.writes()[0]);
}

#[test]
fn a_ref_that_moved_after_the_read_refuses_rather_than_overwriting() {
    struct Moving(Copy);
    impl System for Moving {
        fn run(&self, seconds: u32, argv: &[String], stdin: Option<&[u8]>) -> Result<String, String> {
            if argv.join(" ").contains("update-ref --no-deref refs/remotes/origin/main") {
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
    assert!(input["published"]["blob"]["sha256"].is_string(), "the publish history rides in a blob");
    input["published"] = json!({"blob": {"sha256": "published"}});

    assert!(sync_base(&Copy::new(OLD, Holds::Head), &input).is_ok());
    assert!(clear_base(&Copy::new(OLD, Holds::Head), &clear["fields"]["input"]).is_ok());
}

/// A real copy and Node mirror in scratch folders: `incus exec` runs the command here, with the copy's paths moved into the
/// scratch and moved back in what it answers, and `incus file push` copies the file.
struct Real {
    root: tempfile::TempDir,
    workspace: std::path::PathBuf,
    guest: std::path::PathBuf,
}

impl Real {
    fn new() -> Self {
        let root = tempfile::tempdir().expect("scratch");
        let (workspace, guest) = (root.path().join("copy/srv/workspace"), root.path().join("copy/tmp/orbit-task-base-12"));
        std::fs::create_dir_all(&workspace).and_then(|()| std::fs::create_dir_all(root.path().join("copy/tmp"))).expect("workspace");
        Self { root, workspace, guest }
    }

    fn inside(&self, value: &str) -> String {
        match value.strip_prefix("/tmp/") {
            Some(rest) if value.starts_with(GUEST_SCRATCH) => format!("{}/copy/tmp/{rest}", self.root.path().display()),
            _ => value.replace(ROOT, &self.workspace.display().to_string()),
        }
    }

    fn sh(cwd: &std::path::Path, args: &[&str]) -> String {
        let out = std::process::Command::new(GIT).args(["-c", "user.name=T", "-c", "user.email=t@t", "-c", "init.defaultBranch=main"]).args(args).current_dir(cwd)
            .env("GIT_CONFIG_GLOBAL", "/dev/null").env("GIT_CONFIG_NOSYSTEM", "1").output().expect("git");
        assert!(out.status.success(), "{args:?}: {}", String::from_utf8_lossy(&out.stderr));
        String::from_utf8_lossy(&out.stdout).trim().to_owned()
    }
}

impl System for Real {
    fn run(&self, _seconds: u32, argv: &[String], _stdin: Option<&[u8]>) -> Result<String, String> {
        let argv: Vec<String> = argv.iter().map(|part| self.inside(part)).collect();
        let command: Vec<String> = if argv[0] == INCUS && argv[3] == "file" {
            let (from, to) = (&argv[argv.len() - 2], &argv[argv.len() - 1]);
            let to = self.inside(&format!("/{}", to.split_once('/').map_or("", |(_, path)| path)));
            return std::fs::copy(from, to).map(drop).map(|()| String::new()).map_err(|error| error.to_string());
        } else if argv[0] == INCUS {
            let rest = &argv[argv.iter().position(|part| part == "--").expect("--") + 1..];
            rest.iter().position(|part| part == "/usr/bin/env").map_or_else(|| rest.to_vec(), |at| rest[at..].to_vec())
        } else {
            argv
        };
        let out = std::process::Command::new(&command[0]).args(&command[1..]).current_dir(&self.workspace).output().map_err(|error| error.to_string())?;
        let text = String::from_utf8_lossy(&out.stdout).replace(&self.workspace.display().to_string(), ROOT);
        if out.status.success() { Ok(text) } else { Err(String::from_utf8_lossy(&out.stderr).into_owned()) }
    }

    fn blob(&self, sha256: &str) -> Result<Vec<u8>, String> {
        if sha256 == "published" { Ok(b"[]".to_vec()) } else { Err("no blob".to_owned()) }
    }

    fn read(&self, path: &str) -> Result<Vec<u8>, String> {
        Err(format!("{path} not in the double"))
    }

    fn path(&self, value: &str) -> std::path::PathBuf {
        self.root.path().join(value.trim_start_matches('/'))
    }
}

#[test]
fn a_real_sync_moves_only_the_remote_ref_and_keeps_head_branches_index_untracked_and_stashes_across_a_replay() {
    let real = Real::new();
    let origin = real.root.path().join("origin");
    std::fs::create_dir_all(&origin).expect("origin");
    Real::sh(&origin, &["init", "--quiet"]);
    std::fs::write(origin.join("app.php"), "one\n").expect("file");
    Real::sh(&origin, &["add", "app.php"]);
    Real::sh(&origin, &["commit", "--quiet", "-m", "start"]);
    let start = Real::sh(&origin, &["rev-parse", "HEAD"]);
    // The copy: task-42 from the start with a commit of its own, origin/main at the start, staged, modified, untracked and stashed work.
    Real::sh(&real.workspace, &["clone", "--quiet", &origin.display().to_string(), "."]);
    Real::sh(&real.workspace, &["checkout", "--quiet", "-b", "task-42"]);
    std::fs::write(real.workspace.join("mine.txt"), "mine\n").expect("file");
    Real::sh(&real.workspace, &["add", "mine.txt"]);
    Real::sh(&real.workspace, &["commit", "--quiet", "-m", "mine"]);
    std::fs::write(real.workspace.join("stash.txt"), "s\n").expect("file");
    Real::sh(&real.workspace, &["add", "stash.txt"]);
    Real::sh(&real.workspace, &["stash", "--quiet"]);
    std::fs::write(real.workspace.join("staged.txt"), "staged\n").expect("file");
    Real::sh(&real.workspace, &["add", "staged.txt"]);
    std::fs::write(real.workspace.join("app.php"), "edited\n").expect("file");
    std::fs::write(real.workspace.join("notes.txt"), "untracked\n").expect("file");
    // Origin moves on; the Node mirror fetches it, as instance.fetch_branch does.
    std::fs::write(origin.join("other.php"), "two\n").expect("file");
    Real::sh(&origin, &["add", "other.php"]);
    Real::sh(&origin, &["commit", "--quiet", "-m", "main moves"]);
    let head = Real::sh(&origin, &["rev-parse", "HEAD"]);
    let mirror = real.path("/var/lib/orbit/instance-repositories/12.git");
    std::fs::create_dir_all(&mirror).expect("mirror");
    Real::sh(&mirror, &["init", "--quiet", "--bare"]);
    Real::sh(&mirror, &["fetch", "--quiet", &origin.display().to_string(), "+refs/heads/main:refs/heads/main"]);
    let state = |real: &Real| {
        [
            Real::sh(&real.workspace, &["symbolic-ref", "HEAD"]),
            Real::sh(&real.workspace, &["for-each-ref", "refs/heads", "refs/stash"]),
            Real::sh(&real.workspace, &["status", "--porcelain=v1", "--untracked-files=all"]),
            Real::sh(&real.workspace, &["write-tree"]),
            std::fs::read_to_string(real.workspace.join("app.php")).expect("file"),
        ]
    };
    let before = state(&real);
    let mut input = input();
    input["head"] = json!(head);
    input["start"] = json!(start);

    let first: Value = serde_json::from_str(&sync_base(&real, &input).expect("sync")).expect("json");
    let replay: Value = serde_json::from_str(&sync_base(&real, &input).expect("replay")).expect("json");

    assert_eq!(Real::sh(&real.workspace, &["rev-parse", "refs/remotes/origin/main"]), head);
    assert_eq!(state(&real), before, "HEAD, the branches, the stash, the index and the working tree are as they were");
    assert_eq!((first["bundled"].clone(), first["changed"].clone(), first["behind_by"].clone()), (json!(true), json!(true), json!(1)));
    assert_eq!((replay["bundled"].clone(), replay["changed"].clone()), (json!(false), json!(false)));
    assert_eq!((first["work"]["uncommitted"].clone(), first["work"]["untracked"].clone(), first["work"]["stashes"].clone()), (json!(2), json!(1), json!(1)));
    assert_eq!(first["work"]["paths"], json!({"count": 1, "names": ["mine.txt"]}), "the branch's own unpublished commit");
    assert_eq!(first["work"]["state"], "dirty");
    assert!(!real.guest.exists() && !real.path("/var/tmp/orbit-task-base-12").exists(), "the scratch is gone");
    assert_eq!(Real::sh(&mirror, &["for-each-ref", "refs/orbit"]), "", "the mirror's pin is gone");
}

/// A real copy on `task-42` cloned from an origin that then moved, and a mirror of the moved origin: the copy, the start and the head.
fn real_copy(paths: &[&[u8]]) -> (Real, String, String) {
    use std::os::unix::ffi::OsStrExt as _;
    let real = Real::new();
    let origin = real.root.path().join("origin");
    std::fs::create_dir_all(&origin).expect("origin");
    Real::sh(&origin, &["init", "--quiet"]);
    std::fs::write(origin.join("app.php"), "one\n").expect("file");
    Real::sh(&origin, &["add", "app.php"]);
    Real::sh(&origin, &["commit", "--quiet", "-m", "start"]);
    let start = Real::sh(&origin, &["rev-parse", "HEAD"]);
    Real::sh(&real.workspace, &["clone", "--quiet", &origin.display().to_string(), "."]);
    Real::sh(&real.workspace, &["checkout", "--quiet", "-b", "task-42"]);
    for path in paths {
        std::fs::write(real.workspace.join(std::ffi::OsStr::from_bytes(path)), "mine\n").expect("path");
    }
    if !paths.is_empty() {
        Real::sh(&real.workspace, &["add", "--all"]);
        Real::sh(&real.workspace, &["commit", "--quiet", "-m", "mine"]);
    }
    std::fs::write(origin.join("other.php"), "two\n").expect("file");
    Real::sh(&origin, &["add", "other.php"]);
    Real::sh(&origin, &["commit", "--quiet", "-m", "main moves"]);
    let head = Real::sh(&origin, &["rev-parse", "HEAD"]);
    let mirror = real.path("/var/lib/orbit/instance-repositories/12.git");
    std::fs::create_dir_all(&mirror).expect("mirror");
    Real::sh(&mirror, &["init", "--quiet", "--bare"]);
    Real::sh(&mirror, &["fetch", "--quiet", &origin.display().to_string(), "+refs/heads/main:refs/heads/main"]);
    (real, start, head)
}

fn real_input(start: &str, head: &str) -> Value {
    let mut input = input();
    input["head"] = json!(head);
    input["start"] = json!(start);
    input
}

#[test]
fn a_symbolic_remote_ref_refuses_and_its_local_branch_survives_the_sync_and_its_retry() {
    let (real, start, head) = real_copy(&[]);
    Real::sh(&real.workspace, &["symbolic-ref", "refs/remotes/origin/main", "refs/heads/main"]);
    let main = Real::sh(&real.workspace, &["rev-parse", "refs/heads/main"]);

    for _ in 0..2 {
        assert_eq!(sync_base(&real, &real_input(&start, &head)).map_err(|failure| failure.code), Err("instance.sync_base_failed"));
        assert_eq!(Real::sh(&real.workspace, &["rev-parse", "refs/heads/main"]), main, "the local branch is untouched");
        assert_eq!(Real::sh(&real.workspace, &["symbolic-ref", "refs/remotes/origin/main"]), "refs/heads/main");
    }
}

#[test]
fn distinct_paths_that_decode_alike_count_apart_as_in_swamp() {
    let (real, start, head) = real_copy(&[b"a\xff", b"a\xfe", "é.txt".as_bytes()]);

    let answer: Value = serde_json::from_str(&sync_base(&real, &real_input(&start, &head)).expect("sync")).expect("json");

    assert_eq!(answer["work"]["paths"]["count"], 3);
    assert_eq!(answer["work"]["paths"]["names"], json!(["a\u{fffd}", "a\u{fffd}", "é.txt"]));
}

#[test]
fn clear_fails_while_it_cannot_read_the_mirror_pin() {
    struct Unreadable(Copy);
    impl System for Unreadable {
        fn run(&self, seconds: u32, argv: &[String], stdin: Option<&[u8]>) -> Result<String, String> {
            if argv.join(" ").contains("for-each-ref --format=%(refname) refs/orbit/task-base-12") {
                return Err("fatal: cannot lock".to_owned());
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
    let copy = Unreadable(Copy::new(OLD, Holds::Older));
    let mirror = copy.path("/var/lib/orbit/instance-repositories/12.git");
    std::fs::create_dir_all(&mirror).and_then(|()| std::fs::write(mirror.join("HEAD"), "ref: refs/heads/main\n")).expect("mirror");
    let clear = json!({"project": "orbit-instances", "name": "instance-12", "instance_id": 12, "mirror": "/var/lib/orbit/instance-repositories/12.git"});

    assert_eq!(clear_base(&copy, &clear).map_err(|failure| failure.code), Err("instance.sync_base_failed"));
}

#[test]
fn a_failed_update_of_a_ref_that_did_not_move_keeps_the_ordinary_code() {
    struct Locked(Copy);
    impl System for Locked {
        fn run(&self, seconds: u32, argv: &[String], stdin: Option<&[u8]>) -> Result<String, String> {
            if argv.join(" ").contains("update-ref --no-deref") {
                return Err("fatal: Unable to create 'packed-refs.lock': Permission denied".to_owned());
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

    let failure = sync_base(&Locked(Copy::new(OLD, Holds::Head)), &input()).expect_err("refused");
    assert_eq!(failure.code, "instance.sync_base_failed");
    assert!(failure.detail.contains("Permission denied"), "{}", failure.detail);
}
