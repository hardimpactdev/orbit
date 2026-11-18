//! SPIKE: primitives inside one Instance container. Each builds its own fixed command and runs it with `incus exec` as a
//! named user in a named folder; no order carries a command line. They are the guest part of a clone (instance_clone_guest.py)
//! split by resource: the checkout, a dotenv file, the agent's git config, a JSON file, a folder, a unit, T3's sessions and
//! project. Secrets that T3 issues come back as output (see the note on `t3_session_issue`).

use std::collections::{BTreeMap, HashSet};
use std::io::Write as _;
use std::process::{Command, Stdio};

use orbit_executor_core::journal::Outcome;
use serde::Deserialize;

const PROJECT: &str = "orbit-instances";
const AGENT: u32 = 2750;
const MARK: &str = "/var/lib/orbit/workspace-checkout";

fn ok_instance(name: &str) -> bool {
    name.strip_prefix("instance-").is_some_and(|id| !id.is_empty() && id.bytes().all(|b| b.is_ascii_digit()))
}

fn ok_path(path: &str) -> bool {
    path.starts_with('/') && !path.contains("..") && !path.contains('\0')
}

fn applied(changes: u32) -> Outcome {
    Outcome::Applied { changes }
}

fn failed(code: &str) -> Outcome {
    Outcome::Failed { code: code.to_owned() }
}

fn text(stdout: String) -> Outcome {
    Outcome::Output { stdout, encoding: "text".to_owned() }
}

fn input<T: for<'de> Deserialize<'de>>(value: &serde_json::Value) -> Result<T, Outcome> {
    serde_json::from_value(value.clone()).map_err(|_| failed("container.input_invalid"))
}

/// `incus exec` as `uid` (agent gets HOME=/home/agent), in `cwd`, with fixed argv.
fn exec(instance: &str, uid: u32, cwd: &str, argv: &[&str], stdin: Option<&[u8]>) -> Result<String, String> {
    let home = if uid == AGENT { "/home/agent" } else { "/root" };
    let (user, env_home) = (uid.to_string(), format!("HOME={home}"));
    let mut args = vec!["--project", PROJECT, "exec", instance, "--user", &user, "--group", &user, "--cwd", cwd, "--env", &env_home, "--"];
    args.extend_from_slice(argv);
    let mut child = Command::new("incus")
        .args(&args)
        .stdin(if stdin.is_some() { Stdio::piped() } else { Stdio::null() })
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .map_err(|e| e.to_string())?;
    if let (Some(bytes), Some(mut pipe)) = (stdin, child.stdin.take()) {
        pipe.write_all(bytes).map_err(|e| e.to_string())?;
    }
    let out = child.wait_with_output().map_err(|e| e.to_string())?;
    if out.status.success() {
        Ok(String::from_utf8_lossy(&out.stdout).into_owned())
    } else {
        Err(String::from_utf8_lossy(&out.stderr).trim().chars().take(300).collect())
    }
}

fn pull(instance: &str, path: &str) -> Option<Vec<u8>> {
    let out = Command::new("incus").args(["--project", PROJECT, "file", "pull", &format!("{instance}{path}"), "-"]).output().ok()?;
    out.status.success().then_some(out.stdout)
}

fn push(instance: &str, path: &str, bytes: &[u8], uid: u32, gid: u32, mode: &str) -> bool {
    let target = format!("{instance}{path}");
    let (uid, gid) = (uid.to_string(), gid.to_string());
    let Ok(mut child) = Command::new("incus")
        .args(["--project", PROJECT, "file", "push", "--create-dirs", "--uid", &uid, "--gid", &gid, "--mode", mode, "-", &target])
        .stdin(Stdio::piped())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .spawn()
    else {
        return false;
    };
    if let Some(mut pipe) = child.stdin.take() {
        if pipe.write_all(bytes).is_err() {
            return false;
        }
    }
    child.wait().is_ok_and(|s| s.success())
}

fn git<'a>(args: &[&'a str]) -> Vec<&'a str> {
    let mut argv = vec!["git", "-c", "core.hooksPath=/dev/null", "-c", "core.fsmonitor=false"];
    argv.extend_from_slice(args);
    argv
}

/// The workspace on `branch` at `commit` as this Instance's first checkout, `.env` excluded from Git, and the mark that makes a
/// retry leave the branch alone (the user's commits come after). Answers the checked-out commit.
pub(crate) fn git_checkout(value: &serde_json::Value) -> Outcome {
    #[derive(Deserialize)]
    #[serde(deny_unknown_fields)]
    struct Checkout {
        instance: String,
        workspace: String,
        branch: String,
        commit: String,
        instance_id: u64,
        exclude: String,
    }
    let c: Checkout = match input(value) {
        Ok(c) => c,
        Err(o) => return o,
    };
    let word = |t: &str| !t.is_empty() && !t.starts_with('-') && t.bytes().all(|b| b.is_ascii_alphanumeric() || b"._/-".contains(&b));
    if !ok_instance(&c.instance) || !ok_path(&c.workspace) || !word(&c.branch) || c.commit.len() != 40
        || !c.commit.bytes().all(|b| b.is_ascii_hexdigit()) || c.exclude.contains('\n') || c.exclude.is_empty()
    {
        return failed("container.input_invalid");
    }
    let held: serde_json::Value = pull(&c.instance, MARK).and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default();
    if held["instance_id"].as_u64() == Some(c.instance_id) {
        return text(held["commit"].as_str().unwrap_or_default().to_owned());
    }
    let run = |args: &[&str]| exec(&c.instance, AGENT, &c.workspace, &git(args), None);
    let branch = run(&["symbolic-ref", "--quiet", "--short", "HEAD"]).unwrap_or_default();
    let head = run(&["rev-parse", "HEAD"]).unwrap_or_default();
    if branch.trim() != c.branch || head.trim() != c.commit {
        if let Err(error) = run(&["checkout", "--quiet", "-B", &c.branch, &c.commit]) {
            eprintln!("container.git_checkout: {error}");
            return failed("container.checkout_failed");
        }
    }
    if run(&["symbolic-ref", "--short", "HEAD"]).map(|b| b.trim() == c.branch) != Ok(true)
        || run(&["rev-parse", "HEAD"]).map(|h| h.trim() == c.commit) != Ok(true)
    {
        return failed("container.checkout_mismatch");
    }
    // `.env` stays out of Git: one line in the checkout's own info/exclude, as agent, who owns the checkout.
    let Ok(exclude) = run(&["rev-parse", "--path-format=absolute", "--git-path", "info/exclude"]) else {
        return failed("container.exclude_failed");
    };
    let exclude = exclude.trim();
    let entry = format!("/{}", c.exclude);
    let data = pull(&c.instance, exclude).unwrap_or_default();
    if !String::from_utf8_lossy(&data).lines().any(|line| line == entry) {
        let mut next = data.clone();
        if !next.is_empty() && !next.ends_with(b"\n") {
            next.push(b'\n');
        }
        next.extend_from_slice(entry.as_bytes());
        next.push(b'\n');
        if !push(&c.instance, exclude, &next, AGENT, AGENT, "0644") {
            return failed("container.exclude_failed");
        }
    }
    let mark = serde_json::json!({"instance_id": c.instance_id, "commit": c.commit}).to_string() + "\n";
    if !push(&c.instance, MARK, mark.as_bytes(), 0, 0, "0600") {
        return failed("container.mark_failed");
    }
    text(c.commit)
}

/// The clone's `.env`: the default's file merged with the Gateway's render (dotenv.rs). Answers the key names only.
pub(crate) fn dotenv_merge(value: &serde_json::Value) -> Outcome {
    #[derive(Deserialize)]
    #[serde(deny_unknown_fields)]
    struct Blob {
        sha256: String,
    }
    #[derive(Deserialize)]
    #[serde(deny_unknown_fields)]
    struct Hosts {
        from: Vec<String>,
        to: String,
    }
    #[derive(Deserialize)]
    #[serde(deny_unknown_fields)]
    struct Merge {
        instance: String,
        path: String,
        blob: Blob,
        own: Vec<String>,
        drop: Vec<String>,
        guard: Vec<String>,
        hosts: Option<Hosts>,
        shared: Vec<String>,
        uid: u32,
        gid: u32,
        mode: String,
    }
    let m: Merge = match input(value) {
        Ok(m) => m,
        Err(o) => return o,
    };
    if !ok_instance(&m.instance) || !ok_path(&m.path) || !crate::prims::valid_sha(&m.blob.sha256) {
        return failed("container.input_invalid");
    }
    let Ok(rendered) = std::fs::read(std::path::Path::new(crate::prims::BLOBS).join(&m.blob.sha256)) else {
        return failed("blob.missing");
    };
    if crate::prims::sha_hex(&rendered) != m.blob.sha256 {
        return failed("blob.hash_mismatch");
    }
    let base = String::from_utf8_lossy(&pull(&m.instance, &m.path).unwrap_or_default()).into_owned();
    let merged = crate::dotenv::merge(&base, &String::from_utf8_lossy(&rendered), &m.own, &m.drop, &m.guard);
    let (mut text_out, mut rewritten) = (merged.text, Vec::new());
    if let Some(hosts) = &m.hosts {
        let keys: HashSet<String> = merged.copied.iter().cloned().collect();
        (text_out, rewritten) = crate::dotenv::rewrite_hosts(&text_out, &keys, &hosts.from, &hosts.to);
    }
    let written: HashSet<String> = crate::dotenv::blocks(&text_out).into_iter().filter_map(|b| b.0).collect();
    let mut shared: Vec<String> = m.shared.iter().filter(|k| written.contains(*k)).cloned().collect();
    shared.sort();
    if text_out != base && !push(&m.instance, &m.path, text_out.as_bytes(), m.uid, m.gid, &m.mode) {
        return failed("container.env_write_failed");
    }
    text(serde_json::json!({"copied": merged.copied, "dropped": merged.dropped, "rewritten": rewritten, "shared": shared}).to_string())
}

/// The agent's global Git settings: `set` only where missing, `replace_all` to exactly one value.
pub(crate) fn git_config(value: &serde_json::Value) -> Outcome {
    #[derive(Deserialize)]
    #[serde(deny_unknown_fields)]
    struct Config {
        instance: String,
        set: BTreeMap<String, String>,
        replace_all: BTreeMap<String, String>,
    }
    let c: Config = match input(value) {
        Ok(c) => c,
        Err(o) => return o,
    };
    let key_ok = |k: &str| !k.is_empty() && k.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'.');
    if !ok_instance(&c.instance) || !c.set.keys().chain(c.replace_all.keys()).all(|k| key_ok(k)) {
        return failed("container.input_invalid");
    }
    let run = |args: &[&str]| exec(&c.instance, AGENT, "/home/agent", &git(args), None);
    let mut changes = 0;
    for (key, value) in &c.replace_all {
        if run(&["config", "--global", "--get-all", key]).map(|out| out.lines().collect::<Vec<_>>() == [value.as_str()]) != Ok(true) {
            if run(&["config", "--global", "--replace-all", key, value]).is_err() {
                return failed("container.git_config_failed");
            }
            changes += 1;
        }
    }
    for (key, value) in &c.set {
        if run(&["config", "--global", "--get", key]).is_err() {
            if run(&["config", "--global", key, value]).is_err() {
                return failed("container.git_config_failed");
            }
            changes += 1;
        }
    }
    applied(changes)
}

/// A JSON object file that holds these keys: merged into the file's own object, written only when that changes it.
pub(crate) fn json_merge(value: &serde_json::Value) -> Outcome {
    #[derive(Deserialize)]
    #[serde(deny_unknown_fields)]
    struct Merge {
        instance: String,
        path: String,
        json: serde_json::Map<String, serde_json::Value>,
        uid: u32,
        gid: u32,
        mode: String,
    }
    let m: Merge = match input(value) {
        Ok(m) => m,
        Err(o) => return o,
    };
    if !ok_instance(&m.instance) || !ok_path(&m.path) {
        return failed("container.input_invalid");
    }
    let held: serde_json::Map<String, serde_json::Value> =
        pull(&m.instance, &m.path).and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default();
    let mut merged = held.clone();
    merged.extend(m.json);
    if merged == held && !held.is_empty() {
        return applied(0);
    }
    let body = serde_json::Value::Object(merged).to_string() + "\n";
    if push(&m.instance, &m.path, body.as_bytes(), m.uid, m.gid, &m.mode) { applied(1) } else { failed("container.file_write_failed") }
}

/// A folder made as its owner (`mkdir -p -m`), so the agent's folders belong to the agent.
pub(crate) fn dir(value: &serde_json::Value) -> Outcome {
    #[derive(Deserialize)]
    #[serde(deny_unknown_fields)]
    struct Dir {
        instance: String,
        path: String,
        uid: u32,
        mode: String,
    }
    let d: Dir = match input(value) {
        Ok(d) => d,
        Err(o) => return o,
    };
    if !ok_instance(&d.instance) || !ok_path(&d.path) || d.mode.len() != 4 || !d.mode.bytes().all(|b| (b'0'..=b'7').contains(&b)) {
        return failed("container.input_invalid");
    }
    if exec(&d.instance, d.uid, "/", &["test", "-d", &d.path], None).is_ok() {
        return applied(0);
    }
    match exec(&d.instance, d.uid, "/", &["mkdir", "-p", "-m", &d.mode, &d.path], None) {
        Ok(_) => applied(1),
        Err(_) => failed("container.dir_failed"),
    }
}

/// A root-owned folder with exactly these files (the agent instructions): made again from the list, folders 0755, `.skills` kept.
pub(crate) fn dir_sync(value: &serde_json::Value) -> Outcome {
    #[derive(Deserialize)]
    #[serde(deny_unknown_fields)]
    struct Blob {
        sha256: String,
    }
    #[derive(Deserialize)]
    #[serde(deny_unknown_fields)]
    struct File {
        path: String,
        blob: Blob,
    }
    #[derive(Deserialize)]
    #[serde(deny_unknown_fields)]
    struct Sync {
        instance: String,
        folder: String,
        files: Vec<File>,
    }
    let s: Sync = match input(value) {
        Ok(s) => s,
        Err(o) => return o,
    };
    if !ok_instance(&s.instance) || !ok_path(&s.folder) || !s.folder.starts_with("/home/agent/") || s.files.iter().any(|f| f.path.contains(".."))
    {
        return failed("container.input_invalid");
    }
    if exec(&s.instance, 0, "/", &["rm", "-rf", "--one-file-system", &s.folder], None).is_err() {
        return failed("container.dir_sync_failed");
    }
    for file in &s.files {
        let Ok(bytes) = std::fs::read(std::path::Path::new(crate::prims::BLOBS).join(&file.blob.sha256)) else {
            return failed("blob.missing");
        };
        if crate::prims::sha_hex(&bytes) != file.blob.sha256 {
            return failed("blob.hash_mismatch");
        }
        let target = format!("{}{}/{}", s.instance, s.folder, file.path.trim_start_matches('/'));
        let mut child = match Command::new("incus")
            .args(["--project", PROJECT, "file", "push", "--create-dirs", "--uid", "0", "--gid", "0", "--mode", "0644", "-", &target])
            .stdin(Stdio::piped())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .spawn()
        {
            Ok(child) => child,
            Err(_) => return failed("container.dir_sync_failed"),
        };
        if let Some(mut pipe) = child.stdin.take() {
            let _ = pipe.write_all(&bytes);
        }
        if !child.wait().is_ok_and(|status| status.success()) {
            return failed("container.dir_sync_failed");
        }
    }
    let skills = format!("{}/.skills", s.folder);
    if exec(&s.instance, 0, "/", &["mkdir", "-p", &skills], None).is_err()
        || exec(&s.instance, 0, "/", &["find", &s.folder, "-type", "d", "-exec", "chmod", "0755", "{}", "+"], None).is_err()
    {
        return failed("container.dir_sync_failed");
    }
    applied(1)
}

/// A unit running inside the container: started (after a reset when it failed) only when it is not active.
pub(crate) fn unit(value: &serde_json::Value) -> Outcome {
    #[derive(Deserialize)]
    #[serde(deny_unknown_fields)]
    struct Unit {
        instance: String,
        unit: String,
    }
    let u: Unit = match input(value) {
        Ok(u) => u,
        Err(o) => return o,
    };
    if !ok_instance(&u.instance) || !u.unit.ends_with(".service") || !u.unit.bytes().all(|b| b.is_ascii_alphanumeric() || b"@._-".contains(&b)) {
        return failed("container.input_invalid");
    }
    if exec(&u.instance, 0, "/", &["systemctl", "is-active", "--quiet", &u.unit], None).is_ok() {
        return applied(0);
    }
    let _ = exec(&u.instance, 0, "/", &["systemctl", "daemon-reload"], None);
    let _ = exec(&u.instance, 0, "/", &["systemctl", "reset-failed", &u.unit], None);
    match exec(&u.instance, 0, "/", &["systemctl", "start", &u.unit], None) {
        Ok(_) => applied(1),
        Err(_) => failed("container.unit_start_failed"),
    }
}

/// One T3 session, issued by T3's own CLI as the agent. SPIKE NOTE: the token comes back as output, so it sits in the
/// receipt, the journal's done record and the engine state. A real build seals such output to the Gateway's key.
pub(crate) fn t3_session_issue(value: &serde_json::Value) -> Outcome {
    #[derive(Deserialize)]
    #[serde(deny_unknown_fields)]
    struct Issue {
        instance: String,
        t3: String,
        label: String,
    }
    let i: Issue = match input(value) {
        Ok(i) => i,
        Err(o) => return o,
    };
    if !ok_instance(&i.instance) || !i.t3.starts_with("/opt/orbit-agent/") || !i.label.starts_with("Orbit ") {
        return failed("container.input_invalid");
    }
    match exec(&i.instance, AGENT, "/home/agent", &[&i.t3, "auth", "session", "issue", "--label", &i.label, "--token-only"], None) {
        Ok(token) if !token.trim().is_empty() => text(token.trim().to_owned()),
        Ok(_) => failed("t3.session_issue_empty"),
        Err(error) => {
            eprintln!("t3.session_issue: {error}");
            failed("t3.session_issue_failed")
        }
    }
}

/// The workspace as T3's project, once: the mark holds T3's answer, so a repeat adds nothing.
pub(crate) fn t3_project_add(value: &serde_json::Value) -> Outcome {
    #[derive(Deserialize)]
    #[serde(deny_unknown_fields)]
    struct Add {
        instance: String,
        t3: String,
        workspace: String,
        title: String,
        mark: String,
    }
    let a: Add = match input(value) {
        Ok(a) => a,
        Err(o) => return o,
    };
    if !ok_instance(&a.instance) || !a.t3.starts_with("/opt/orbit-agent/") || !ok_path(&a.workspace) || !ok_path(&a.mark) {
        return failed("container.input_invalid");
    }
    if pull(&a.instance, &a.mark).is_some() {
        return applied(0);
    }
    match exec(&a.instance, AGENT, "/home/agent", &[&a.t3, "project", "add", &a.workspace, "--title", &a.title], None) {
        Ok(out) if push(&a.instance, &a.mark, format!("{}\n", out.trim()).as_bytes(), AGENT, AGENT, "0600") => applied(1),
        Ok(_) => failed("t3.project_mark_failed"),
        Err(error) => {
            eprintln!("t3.project_add: {error}");
            failed("t3.project_add_failed")
        }
    }
}
