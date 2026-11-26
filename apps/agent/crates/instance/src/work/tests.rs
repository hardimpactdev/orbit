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
    serde_json::from_str(&work_read(copy, "12", input).expect("an answer")).expect("JSON")
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
            work_read(&copy, "12", &bad).expect_err("refused").code,
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
        )
        .expect("read"),
    )
    .expect("JSON");
    assert_eq!(answer, json!({"state": "unknown", "reason": "stopped"}));
}
