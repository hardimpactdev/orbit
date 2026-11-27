use std::cell::RefCell;

use serde_json::{json, Value};

use super::*;

const A: &str = "1111111111111111111111111111111111111111";
const B: &str = "2222222222222222222222222222222222222222";
const S: &str = "3333333333333333333333333333333333333333";

/// Answers each command by the Git arguments it ends with (or the whole `incus query`), and records what ran.
struct Copy {
    answers: Vec<(String, Result<String, String>)>,
    ran: RefCell<Vec<String>>,
    blob: Option<Vec<u8>>,
    root: tempfile::TempDir,
    pause: Option<Duration>,
}

impl Copy {
    fn new(answers: &[(&str, Result<&str, &str>)]) -> Self {
        Self {
            answers: answers
                .iter()
                .map(|(key, value)| {
                    (
                        (*key).to_owned(),
                        value.map(str::to_owned).map_err(str::to_owned),
                    )
                })
                .collect(),
            ran: RefCell::new(Vec::new()),
            blob: None,
            root: tempfile::tempdir().expect("scratch"),
            pause: None,
        }
    }
}

impl System for Copy {
    #[expect(
        clippy::panic_in_result_fn,
        reason = "a test double fails the test on a call it does not expect"
    )]
    fn run(&self, _seconds: u32, argv: &[String], stdin: Option<&[u8]>) -> Result<String, String> {
        assert!(stdin.is_none());
        let line = argv.join(" ");
        self.ran.borrow_mut().push(line.clone());
        if let Some(pause) = self.pause {
            std::thread::sleep(pause);
        }
        self.answers
            .iter()
            .find(|(key, _)| line.ends_with(key.as_str()))
            .map_or_else(
                || panic!("unexpected call: {line}"),
                |(_, answer)| answer.clone(),
            )
    }

    fn blob(&self, _sha256: &str) -> Result<Vec<u8>, String> {
        self.blob.clone().ok_or_else(|| "not cached".to_owned())
    }

    fn read(&self, _path: &str) -> Result<Vec<u8>, String> {
        Err("the read reads no file".to_owned())
    }

    fn path(&self, value: &str) -> std::path::PathBuf {
        self.root.path().join(value.trim_start_matches('/'))
    }
}

const STATE: &str = "/1.0/instances/instance-12/state?project=orbit-instances";
const RUNNING: Result<&str, &str> = Ok(r#"{"status":"Running"}"#);
const STOPPED: Result<&str, &str> = Ok(r#"{"status":"Stopped"}"#);

fn input() -> Value {
    json!({"project": "orbit-instances", "name": "instance-12", "budget_ms": 2000})
}

fn read(copy: &Copy, input: &Value) -> Value {
    serde_json::from_str(&work_read(copy, "12", input, u64::MAX).expect("an answer")).expect("JSON")
}

fn clean_repository<'a>(
    status: &'a str,
    refs: &'a str,
) -> Vec<(&'static str, Result<&'a str, &'a str>)> {
    vec![
        (STATE, RUNNING),
        ("rev-parse --is-inside-work-tree", Ok("true\n")),
        (
            "for-each-ref --format=%(objectname) %(refname) refs/heads refs/stash",
            Ok(refs),
        ),
        (
            "status --porcelain=v1 -z --untracked-files=all --no-renames",
            Ok(status),
        ),
        ("symbolic-ref -q --short HEAD", Ok("main\n")),
    ]
}

#[test]
fn a_clean_workspace_reads_clean_as_agent_without_hooks_or_locks() {
    let refs = format!("{A} refs/heads/main\n");
    let mut answers = clean_repository("?? .env\0", &refs);
    answers.push((
        "rev-list --ignore-missing 1111111111111111111111111111111111111111 --not --remotes",
        Ok(""),
    ));
    let copy = Copy::new(&answers);
    let answer = read(&copy, &input());
    assert_eq!(
        answer,
        json!({"state": "clean", "reason": null, "baseline": "none", "head": "main", "head_commit": A,
            "modified": {"count": 0, "names": []}, "untracked": {"count": 0, "names": []},
            "commits": {"count": 0, "branch_count": 0, "branches": [], "head": 0}, "stashes": {"count": 0, "names": []}})
    );
    let ran = copy.ran.borrow();
    let git = ran
        .iter()
        .find(|line| line.contains("status --porcelain"))
        .expect("status ran");
    assert!(git.starts_with("/usr/bin/incus exec --project orbit-instances --mode=non-interactive instance-12 -- /usr/bin/timeout 2 /usr/sbin/runuser -u agent -- /usr/bin/env -i HOME=/home/agent GIT_CONFIG_NOSYSTEM=1 GIT_TERMINAL_PROMPT=0 GIT_OPTIONAL_LOCKS=0 /usr/bin/git -c safe.directory=/srv/workspace -c core.fsmonitor=false -c core.hooksPath=/dev/null -c core.untrackedCache=false --no-optional-locks -C /srv/workspace "));
}

#[test]
fn changes_stashes_and_commits_beyond_the_baseline_read_dirty() {
    let refs = format!("{A} refs/heads/main\n{B} refs/heads/feature\n{S} refs/stash\n");
    let mut answers = clean_repository(" M app.php\0?? new.txt\0?? known.txt\0", &refs);
    let stash = format!("{S} stash@{{0}}: WIP on main\0");
    answers.extend([
        ("log -g -z --format=%H %gd: %gs refs/stash", Ok(stash.as_str())),
        ("rev-list --ignore-missing 1111111111111111111111111111111111111111 2222222222222222222222222222222222222222 --not --remotes 1111111111111111111111111111111111111111 4444444444444444444444444444444444444444", Ok("2222222222222222222222222222222222222222\n")),
        ("rev-list --ignore-missing 2222222222222222222222222222222222222222 --not --remotes 1111111111111111111111111111111111111111 4444444444444444444444444444444444444444", Ok("2222222222222222222222222222222222222222\n")),
    ]);
    let mut copy = Copy::new(&answers);
    copy.blob = Some(
        json!({"baseline": {"heads": [A], "stashes": [], "untracked": ["known.txt"]}, "published": ["4444444444444444444444444444444444444444"]})
            .to_string()
            .into_bytes(),
    );
    let mut order = input();
    order["state"] = json!({"blob": {"sha256": "0".repeat(64)}});
    assert_eq!(
        read(&copy, &order),
        json!({"state": "dirty", "reason": null, "baseline": "recorded", "head": "main", "head_commit": A,
            "modified": {"count": 1, "names": ["app.php"]}, "untracked": {"count": 1, "names": ["new.txt"]},
            "commits": {"count": 1, "branch_count": 1, "branches": [{"name": "feature", "count": 1}], "head": 0},
            "stashes": {"count": 1, "names": ["stash@{0}: WIP on main"]}})
    );
}

#[test]
fn a_copy_that_does_not_run_is_asleep_by_its_marker_or_stopped_and_never_woken() {
    let copy = Copy::new(&[(STATE, STOPPED)]);
    assert_eq!(
        read(&copy, &input()),
        json!({"state": "unknown", "reason": "stopped"})
    );
    let marker = copy.path("/data/caddy/orbit/hibernation/app-instance-12.asleep");
    std::fs::create_dir_all(marker.parent().expect("parent")).expect("markers");
    std::fs::write(&marker, "").expect("marker");
    assert_eq!(
        read(&copy, &input()),
        json!({"state": "unknown", "reason": "asleep"})
    );
    assert!(
        copy.ran
            .borrow()
            .iter()
            .all(|line| line.contains("incus query")),
        "only the state is read"
    );
}

#[test]
fn a_running_copy_is_read_even_with_an_asleep_marker() {
    let copy = Copy::new(&[
        (STATE, RUNNING),
        (
            "rev-parse --is-inside-work-tree",
            Err("fatal: not a git repository"),
        ),
    ]);
    let marker = copy.path("/data/caddy/orbit/hibernation/app-instance-12.asleep");
    std::fs::create_dir_all(marker.parent().expect("parent")).expect("markers");
    std::fs::write(&marker, "").expect("marker");
    assert_eq!(
        read(&copy, &input()),
        json!({"state": "unknown", "reason": "not_a_repository"})
    );
}

#[test]
fn a_failed_state_read_is_unreachable_and_a_spent_budget_is_timeout() {
    let copy = Copy::new(&[(STATE, Err("Error: connection refused"))]);
    assert_eq!(
        read(&copy, &input()),
        json!({"state": "unknown", "reason": "unreachable"})
    );
    let mut slow = Copy::new(&[(STATE, RUNNING)]);
    slow.pause = Some(Duration::from_millis(20));
    let mut order = input();
    order["budget_ms"] = json!(5);
    assert_eq!(
        read(&slow, &order),
        json!({"state": "unknown", "reason": "timeout"})
    );
}

#[test]
fn the_default_gives_the_baseline_of_a_copy_without_one() {
    let refs = format!("{B} refs/heads/main\n");
    let mut answers = clean_repository("", &refs);
    answers.extend([
        (
            "/1.0/instances/instance-3/state?project=orbit-instances",
            RUNNING,
        ),
        // The default's refs and the copy's refs share one command; the default answers first here by its own name.
    ]);
    let default_refs = format!("{A} refs/heads/main\n");
    let answers: Vec<(String, Result<String, String>)> = answers
        .into_iter()
        .map(|(key, value)| (key.to_owned(), value.map(str::to_owned).map_err(str::to_owned)))
        .chain([
            (format!("instance-3 -- /usr/bin/timeout 2 /usr/sbin/runuser -u agent -- /usr/bin/env -i HOME=/home/agent GIT_CONFIG_NOSYSTEM=1 GIT_TERMINAL_PROMPT=0 GIT_OPTIONAL_LOCKS=0 {} for-each-ref --format=%(objectname) %(refname) refs/heads refs/stash", GIT.join(" ")), Ok(default_refs)),
            (format!("rev-list --ignore-missing {B} --not --remotes {A}"), Ok(format!("{B}\n"))),
            (format!("rev-list --no-walk --ignore-missing {B}"), Ok(format!("{B}\n"))),
            (format!("rev-list --ignore-missing {B} --not {A}"), Ok(String::new())),
        ])
        .collect();
    let mut copy = Copy::new(&[]);
    // The more specific default key must win over the copy's own `for-each-ref`.
    copy.answers = answers.into_iter().rev().collect();
    let mut order = input();
    order["default"] = json!({"project": "orbit-instances", "name": "instance-3"});
    let answer = read(&copy, &order);
    assert_eq!(answer["state"], "clean", "{answer}");
    assert_eq!(answer["baseline"], "default_now");
    assert_eq!(answer["commits"]["count"], 0);
}

#[test]
fn an_input_that_is_not_the_targets_container_refuses_before_any_command() {
    let copy = Copy::new(&[]);
    for bad in [
        json!({"project": "orbit-instances", "name": "instance-13", "budget_ms": 2000}),
        json!({"project": "default", "name": "instance-12", "budget_ms": 2000}),
        json!({"project": "orbit-instances", "name": "instance-12", "budget_ms": 0}),
        json!({"project": "orbit-instances", "name": "instance-12", "budget_ms": 2000, "marker": "/etc/passwd"}),
        json!({"project": "orbit-instances", "name": "instance-12", "budget_ms": 2000, "state": {"blob": {"sha256": "0".repeat(64)}}}),
    ] {
        assert_eq!(
            work_read(&copy, "12", &bad, u64::MAX)
                .expect_err("refused")
                .code,
            "instance.work_read_input_invalid"
        );
    }
    assert!(copy.ran.borrow().is_empty());
}

#[test]
fn a_long_name_is_cut_to_two_hundred_characters() {
    let cut = text(&"é".repeat(250));
    assert_eq!(cut.chars().count(), 200);
    assert!(cut.ends_with('…'));
}

#[test]
fn the_contract_fixture_input_is_a_valid_read() {
    let fixture: Value = serde_json::from_str(include_str!(
        "../../../../contract/fixtures/executor-instance-work-read.json"
    ))
    .expect("fixture");
    let copy = Copy::new(&[(STATE, STOPPED)]);
    let fields = &fixture["fields"];
    assert_eq!(fields["action"], "instance.work_read");
    let answer: Value = serde_json::from_str(
        &work_read(
            &copy,
            fields["target"].as_str().expect("target"),
            &fields["input"],
            u64::MAX,
        )
        .expect("read"),
    )
    .expect("JSON");
    assert_eq!(answer, json!({"state": "unknown", "reason": "stopped"}));
}

/// Real Git on a scratch repository: the container's `incus exec … runuser … env -i` prefix is dropped and `/srv/workspace`
/// names the scratch work tree; the state query answers Running. It counts every `rev-list` it runs.
struct LocalGit {
    tree: tempfile::TempDir,
    revlists: RefCell<usize>,
}

impl LocalGit {
    fn new() -> Self {
        let local = Self {
            tree: tempfile::tempdir().expect("scratch"),
            revlists: RefCell::new(0),
        };
        local.git(&["init", "-q", "-b", "main"]);
        local
    }

    fn git(&self, arguments: &[&str]) -> String {
        let output = std::process::Command::new("/usr/bin/git")
            .args([
                "-c",
                "user.name=Fixture",
                "-c",
                "user.email=fixture@example.test",
            ])
            .args(arguments)
            .current_dir(self.tree.path())
            .env("HOME", self.tree.path())
            .env("GIT_CONFIG_NOSYSTEM", "1")
            .output()
            .expect("git runs");
        assert!(
            output.status.success(),
            "git {arguments:?}: {}",
            String::from_utf8_lossy(&output.stderr)
        );
        String::from_utf8_lossy(&output.stdout).into_owned()
    }

    fn commit(&self, file: &str, content: &str) {
        std::fs::write(self.tree.path().join(file), content).expect("file");
        self.git(&["add", "--", file]);
        self.git(&["commit", "-q", "-m", file]);
    }
}

impl System for LocalGit {
    fn run(&self, _seconds: u32, argv: &[String], _stdin: Option<&[u8]>) -> Result<String, String> {
        if argv.get(1).map(String::as_str) == Some("query") {
            return Ok(r#"{"status":"Running"}"#.to_owned());
        }
        let start = argv
            .iter()
            .position(|part| part == "/usr/bin/git")
            .expect("a Git call");
        let tree = self.tree.path().to_str().expect("UTF-8 path");
        let arguments: Vec<String> = argv[start + 1..]
            .iter()
            .map(|part| part.replace(WORKSPACE, tree))
            .collect();
        if arguments.iter().any(|part| part == "rev-list") {
            *self.revlists.borrow_mut() += 1;
        }
        let output = std::process::Command::new("/usr/bin/git")
            .args(&arguments)
            .env("HOME", self.tree.path())
            .env("GIT_CONFIG_NOSYSTEM", "1")
            .env("GIT_TERMINAL_PROMPT", "0")
            .env("GIT_OPTIONAL_LOCKS", "0")
            .output()
            .map_err(|error| error.to_string())?;
        if output.status.success() {
            Ok(String::from_utf8_lossy(&output.stdout).into_owned())
        } else {
            Err(String::from_utf8_lossy(&output.stderr).trim().to_owned())
        }
    }

    fn blob(&self, _sha256: &str) -> Result<Vec<u8>, String> {
        Err("no blob".to_owned())
    }

    fn read(&self, _path: &str) -> Result<Vec<u8>, String> {
        Err("the read reads no file".to_owned())
    }

    fn path(&self, value: &str) -> std::path::PathBuf {
        self.tree
            .path()
            .join(".markers")
            .join(value.trim_start_matches('/'))
    }
}

fn local_read(local: &LocalGit) -> Value {
    serde_json::from_str(&work_read(local, "12", &input(), u64::MAX).expect("an answer"))
        .expect("JSON")
}

/// Today's SSH reader (`apps/gateway/resources/instances/workspace-git-read.py`) on the same work tree, through an Incus
/// stand-in that runs its Git calls exactly as `LocalGit` runs the signed reader's.
fn python_read(local: &LocalGit) -> Value {
    let bin = tempfile::tempdir().expect("stand-in folder");
    let incus = bin.path().join("incus");
    std::fs::write(
        &incus,
        r#"#!/usr/bin/python3
import json, os, subprocess, sys
a = sys.argv[1:]
if a[0] == "query":
    print(json.dumps({"status": "Running"}))
    sys.exit(0)
tree = os.environ["FIXTURE_TREE"]
command = [part.replace("/srv/workspace", tree) for part in a[a.index("/usr/bin/git"):]]
environment = dict(os.environ, HOME=tree, GIT_CONFIG_NOSYSTEM="1", GIT_TERMINAL_PROMPT="0", GIT_OPTIONAL_LOCKS="0")
result = subprocess.run(command, env=environment, stdin=subprocess.DEVNULL, capture_output=True, check=False)
sys.stdout.buffer.write(result.stdout)
sys.exit(result.returncode)
"#,
    )
    .expect("stand-in");
    std::process::Command::new("/bin/chmod")
        .args(["0700"])
        .arg(&incus)
        .status()
        .expect("chmod");
    let reader = std::fs::read_to_string(concat!(
        env!("CARGO_MANIFEST_DIR"),
        "/../../../gateway/resources/instances/workspace-git-read.py"
    ))
    .expect("the SSH reader");
    let prelude = "INSTANCE_PROJECTS = [\"orbit-instances\", \"orbit-instances-nested\"]\nBASELINE = None\nPUBLISHED = []\nBUDGET = 10\n";
    let search = format!(
        "{}:{}",
        bin.path().display(),
        std::env::var("PATH").unwrap_or_default()
    );
    let mut python = std::process::Command::new("/usr/bin/python3")
        .args([
            "-",
            "read",
            "orbit-instances",
            "instance-12",
            "/nonexistent/app-instance-12.asleep",
        ])
        .env("PATH", search)
        .env("FIXTURE_TREE", local.tree.path())
        .stdin(std::process::Stdio::piped())
        .stdout(std::process::Stdio::piped())
        .spawn()
        .expect("python3");
    std::io::Write::write_all(
        &mut python.stdin.take().expect("stdin"),
        format!("{prelude}{reader}").as_bytes(),
    )
    .expect("reader source");
    let output = python.wait_with_output().expect("the SSH reader ran");
    serde_json::from_slice(&output.stdout).expect("the SSH reader's JSON")
}

/// Both readers on one work tree give one answer, whose tracked changes are `modified`. Every commit here is unpushed, so
/// both read `dirty`: the changed files are what differ between the cases.
fn same_answer(local: &LocalGit, modified: &Value) {
    let signed = local_read(local);
    assert_eq!(
        signed,
        python_read(local),
        "the signed and the SSH reader differ"
    );
    assert_eq!(signed["modified"]["names"], *modified, "{signed}");
}

#[test]
fn a_normalizing_filter_reads_as_the_ssh_reader_reads_it() {
    let local = LocalGit::new();
    local.git(&["config", "filter.norm.clean", "tr -d '\\r'"]);
    local.git(&["config", "filter.norm.smudge", "cat"]);
    local.commit(".gitattributes", "*.txt filter=norm\n");
    local.commit("notes.txt", "one\r\n");
    // Another stat for the same content: Git runs the filter to compare, and the cleaned bytes are the committed ones.
    std::fs::write(local.tree.path().join("notes.txt"), "one\r\n").expect("rewrite");
    let later = std::time::SystemTime::now() + Duration::from_secs(5);
    std::fs::File::options()
        .write(true)
        .open(local.tree.path().join("notes.txt"))
        .expect("open")
        .set_modified(later)
        .expect("mtime");
    // A driver no path uses, whose name holds `=`, changes nothing.
    local.git(&["config", "filter.a=b.clean", "cat"]);
    same_answer(&local, &json!([]));
    std::fs::write(local.tree.path().join("notes.txt"), "two\r\n").expect("change");
    same_answer(&local, &json!(["notes.txt"]));
}

/// A work tree with a submodule `sub`, both committed.
fn with_submodule() -> (LocalGit, LocalGit) {
    let inner = LocalGit::new();
    inner.commit("inner.txt", "inner\n");
    let local = LocalGit::new();
    local.commit("a.txt", "a\n");
    let source = inner.tree.path().to_str().expect("UTF-8 path").to_owned();
    local.git(&[
        "-c",
        "protocol.file.allow=always",
        "submodule",
        "add",
        "-q",
        &source,
        "sub",
    ]);
    local.git(&["commit", "-q", "-m", "sub"]);
    (local, inner)
}

#[test]
fn work_inside_a_submodule_reads_as_the_ssh_reader_reads_it() {
    for change in ["unstaged", "staged", "untracked"] {
        let (local, _inner) = with_submodule();
        same_answer(&local, &json!([]));
        let sub = local.tree.path().join("sub");
        match change {
            "unstaged" => std::fs::write(sub.join("inner.txt"), "edited\n").expect("edit"),
            "staged" => {
                std::fs::write(sub.join("inner.txt"), "staged\n").expect("edit");
                let staged = std::process::Command::new("/usr/bin/git")
                    .args(["add", "inner.txt"])
                    .current_dir(&sub)
                    .status()
                    .expect("git add");
                assert!(staged.success());
            }
            _ => std::fs::write(sub.join("loose.txt"), "loose\n").expect("untracked"),
        }
        same_answer(&local, &json!(["sub"]));
    }
}

#[test]
fn a_submodule_ignored_by_its_configuration_reads_as_the_ssh_reader_reads_it() {
    let (local, _inner) = with_submodule();
    local.git(&["config", "-f", ".gitmodules", "submodule.sub.ignore", "all"]);
    local.git(&["commit", "-q", "-am", "ignore sub"]);
    let sub = local.tree.path().join("sub");
    let moved = std::process::Command::new("/usr/bin/git")
        .args([
            "-c",
            "user.name=Fixture",
            "-c",
            "user.email=fixture@example.test",
            "commit",
            "-q",
            "--allow-empty",
            "-m",
            "moved",
        ])
        .current_dir(&sub)
        .status()
        .expect("git commit");
    assert!(moved.success());
    let answer = local_read(&local);
    assert_eq!(answer["modified"]["count"], 0, "{answer}");
    assert_eq!(answer, python_read(&local));
}

#[test]
fn a_branch_named_by_a_non_ascii_space_keeps_its_name_commit_and_count() {
    let local = LocalGit::new();
    local.git(&["checkout", "-q", "-b", "\u{a0}"]);
    local.commit("a.txt", "a\n");
    let head = local.git(&["rev-parse", "HEAD"]).trim().to_owned();
    let answer = local_read(&local);
    assert_eq!(answer["head"], "\u{a0}", "{answer}");
    assert_eq!(answer["head_commit"], head.as_str());
    assert_eq!(answer["commits"]["head"], 1);
    assert_eq!(answer["commits"]["count"], 1);
}

#[test]
fn branches_at_one_unpushed_tip_count_that_tip_once_per_read() {
    let local = LocalGit::new();
    local.commit("a.txt", "a\n");
    for branch in ["b1", "b2", "b3", "b4"] {
        local.git(&["branch", branch]);
    }
    let answer = local_read(&local);
    assert_eq!(answer["state"], "dirty", "{answer}");
    assert_eq!(answer["commits"]["branch_count"], 5);
    assert_eq!(answer["commits"]["head"], 1);
    assert!(answer["commits"]["branches"]
        .as_array()
        .expect("branches")
        .iter()
        .all(|branch| branch["count"] == 1));
    // One pass for the lost set and one for the shared tip: as the Python reader's `own`.
    assert_eq!(*local.revlists.borrow(), 2);
    // A second read counts afresh: nothing is kept across reads.
    local_read(&local);
    assert_eq!(*local.revlists.borrow(), 4);
}

#[test]
fn the_node_stops_a_command_at_the_deadline_to_the_millisecond() {
    let system = crate::NodeSystem::new("/nonexistent");
    // A command that ignores TERM and would clean up for seconds still ends at the bound.
    for argv in [
        vec!["/bin/sleep", "3"],
        vec!["/bin/sh", "-c", "trap 'sleep 2' TERM; sleep 3"],
        vec!["/bin/sh", "-c", "trap 'sleep 2' TERM; sleep 3 & wait"],
    ] {
        let argv: Vec<String> = argv.into_iter().map(str::to_owned).collect();
        let started = Instant::now();
        let stopped = system.run_for(Duration::from_millis(200), &argv, None);
        assert!(stopped.is_err());
        assert!(
            started.elapsed() < Duration::from_millis(400),
            "{argv:?}: {:?}",
            started.elapsed()
        );
    }
}

#[test]
fn the_node_answers_a_command_that_ends_within_its_bound() {
    let system = crate::NodeSystem::new("/nonexistent");
    let echo = ["/bin/echo".to_owned(), "read".to_owned()];
    assert_eq!(
        system.run_for(Duration::from_secs(2), &echo, None),
        Ok("read\n".to_owned())
    );
}

/// The bytes and modification time of every file under `.git` that a write would change: the index, `HEAD`, refs, reflogs
/// and any lock.
fn git_state(local: &LocalGit) -> Vec<(String, Vec<u8>, std::time::SystemTime)> {
    fn walk(path: &std::path::Path, out: &mut Vec<(String, Vec<u8>, std::time::SystemTime)>) {
        for entry in std::fs::read_dir(path).expect("folder").flatten() {
            let path = entry.path();
            if path.is_dir() {
                walk(&path, out);
            } else {
                let modified = entry
                    .metadata()
                    .and_then(|meta| meta.modified())
                    .expect("mtime");
                out.push((
                    path.display().to_string(),
                    std::fs::read(&path).expect("bytes"),
                    modified,
                ));
            }
        }
    }
    let git = local.tree.path().join(".git");
    let mut state = Vec::new();
    for name in ["index", "HEAD", "packed-refs", "ORIG_HEAD"] {
        let path = git.join(name);
        if path.exists() {
            let modified = std::fs::metadata(&path)
                .and_then(|meta| meta.modified())
                .expect("mtime");
            state.push((
                path.display().to_string(),
                std::fs::read(&path).expect("bytes"),
                modified,
            ));
        }
    }
    for folder in ["refs", "logs"] {
        if git.join(folder).exists() {
            walk(&git.join(folder), &mut state);
        }
    }
    state.sort();
    state
}

#[test]
fn a_read_writes_no_index_lock_or_ref_even_when_git_would_refresh_the_index() {
    let local = LocalGit::new();
    local.commit("a.txt", "a\n");
    std::fs::write(local.tree.path().join("a.txt"), "b\n").expect("change");
    local.git(&["stash", "-q"]);
    // The same content with a new time: a plain status refreshes the index for it.
    std::fs::write(local.tree.path().join("a.txt"), "a\n").expect("same content");
    let later = std::time::SystemTime::now() + Duration::from_secs(5);
    std::fs::File::options()
        .write(true)
        .open(local.tree.path().join("a.txt"))
        .expect("open")
        .set_modified(later)
        .expect("mtime");
    std::thread::sleep(Duration::from_millis(20));
    let before = git_state(&local);
    let answer = local_read(&local);
    assert_eq!(answer["stashes"]["count"], 1, "{answer}");
    assert_eq!(
        git_state(&local),
        before,
        "the read changed the index, a ref or a reflog"
    );
    let locks: Vec<_> = before
        .iter()
        .map(|(path, _, _)| path.clone())
        .filter(|path| std::path::Path::new(path).extension().is_some_and(|extension| extension == "lock"))
        .collect();
    assert!(locks.is_empty() && !local.tree.path().join(".git/index.lock").exists());
    // A plain status without the read's settings does rewrite the index here: the probe is real.
    local.git(&["status", "--porcelain"]);
    assert_ne!(
        git_state(&local),
        before,
        "a plain status left the index alone, so the probe proves nothing"
    );
}
