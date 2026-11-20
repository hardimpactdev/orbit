//! `instance.checkout`: the copy's workspace on the Instance's branch, its marked checkout and its merged `.env`.

use serde::Deserialize;
use serde_json::Value;

use crate::env::{self, Merge};
use crate::prepare::{Files, Folders};
use crate::{Failure, Incus, System};

/// Which Instance the copy's workspace was checked out for.
const MARK: &str = "/var/lib/orbit/workspace-checkout";
/// The workspace's writer inside the copy.
const AGENT: u32 = 2750;
const GIT: &str = "/usr/bin/git";

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct CheckoutInput {
    project: String,
    name: String,
    instance_id: u64,
    workspace: String,
    branch: String,
    /// The commit the branch must hold: the mirror's branch after a fetch, or the seed's commit.
    expected_commit: String,
    /// The Node's mirror when it holds the branch; without it the branch starts at the seed's commit.
    #[serde(default)]
    mirror: Option<String>,
    environment_file: String,
    /// The workspace's group in the copy.
    group: u32,
    /// The Gateway's render of the `.env`: a secret, so a blob.
    environment: crate::BlobRef,
    #[serde(default)]
    merge: Option<Merge>,
}

/// Checks the workspace out on the branch once per Instance (a mark records it), from a bundle of the Node's mirror when it
/// holds the branch, or at the seed's commit. Then the `.env` is the default's merged with the render, and Git never takes
/// it. Answers `{"commit", "environment": <key names>}`.
///
/// # Errors
///
/// `instance.checkout_input_invalid`, `instance.checkout_content_missing` or `instance.container_checkout_failed`.
pub fn checkout(system: &impl System, input: &Value) -> Result<String, Failure> {
    let input: CheckoutInput =
        serde_json::from_value(input.clone()).map_err(|error| Failure::new("instance.checkout_input_invalid", error.to_string()))?;
    check(&input).map_err(|detail| Failure::new("instance.checkout_input_invalid", detail))?;
    let rendered = system.blob(&input.environment.blob.sha256).map_err(|detail| Failure::new("instance.checkout_content_missing", detail))?;
    let incus = Incus { system, code: "instance.container_checkout_failed" };
    let files = Files { incus: &incus, project: &input.project, name: &input.name };
    identity(&incus, &input)?;
    let mark: Value = files.pull(MARK)?.and_then(|bytes| serde_json::from_slice(&bytes).ok()).unwrap_or(Value::Null);
    // A checkout done for this Instance reports the commit it checked out, so a retry never reports a later one.
    let mut commit = mark.get("commit").and_then(Value::as_str).unwrap_or_default().to_owned();
    if mark.get("instance_id").and_then(Value::as_u64) != Some(input.instance_id) {
        commit.clone_from(&input.expected_commit);
        branch(system, &incus, &files, &input)?;
        let mut written = serde_json::json!({"instance_id": input.instance_id, "commit": input.expected_commit}).to_string();
        written.push('\n');
        files.push(MARK, 0, 0, "0600", written.as_bytes(), Folders::Root)?;
    }
    let held = files.read(&input.environment_file, "instance.container_checkout_failed")?;
    let base = held.as_ref().map(|held| String::from_utf8_lossy(&held.content).into_owned()).unwrap_or_default();
    let (updated, keys) = env::merge(&base, &String::from_utf8_lossy(&rendered), input.merge.as_ref());
    if updated != base || held.as_ref().is_some_and(|held| (held.uid, held.gid, held.mode.as_str()) != (AGENT, input.group, "0640")) {
        files.push(&input.environment_file, AGENT, input.group, "0640", updated.as_bytes(), Folders::Present)?;
    }
    exclude(&files, &input)?;
    Ok(serde_json::json!({"commit": commit, "environment": keys}).to_string())
}

fn check(input: &CheckoutInput) -> Result<(), String> {
    for (field, value) in [("project", &input.project), ("name", &input.name)] {
        if !crate::name(value) {
            return Err(format!("{field} is not a name"));
        }
    }
    let path = |value: &str| value.starts_with('/') && !value.split('/').any(|part| part == ".." || part == ".") && !value.bytes().any(|b| b.is_ascii_control());
    if !path(&input.workspace) || !path(&input.environment_file) || !input.environment_file.starts_with(&format!("{}/", input.workspace)) {
        return Err("workspace or environment_file is not a path in the workspace".to_owned());
    }
    if input.expected_commit.len() != 40 || !input.expected_commit.bytes().all(|byte| byte.is_ascii_hexdigit()) {
        return Err("expected_commit is not a commit".to_owned());
    }
    crate::fetch::branch_name(&input.branch).then_some(()).ok_or("branch is not a branch name")?;
    if let Some(mirror) = &input.mirror {
        crate::fetch::mirror_path(mirror).then_some(()).ok_or("mirror is not an Orbit mirror")?;
    }
    Ok(())
}

/// The branch at the expected commit in the copy's workspace.
fn branch(system: &impl System, incus: &Incus<'_, impl System>, files: &Files<'_, impl System>, input: &CheckoutInput) -> Result<(), Failure> {
    let current = git(incus, input, &["symbolic-ref", "--quiet", "--short", "HEAD"]).unwrap_or_default();
    let head = git(incus, input, &["rev-parse", "HEAD"])?;
    if current.trim() != input.branch || head.trim() != input.expected_commit {
        let remote = format!("refs/remotes/origin/{}", input.branch);
        let start = if let Some(mirror) = &input.mirror {
            let bundle = format!("/tmp/orbit-workspace-{}.bundle", input.instance_id);
            let local = system.path(&format!("{mirror}.workspace-{}.bundle", input.instance_id));
            let made = system
                .run(60, &[GIT.to_owned(), "-C".to_owned(), system.path(mirror).display().to_string(), "bundle".to_owned(), "create".to_owned(), "--quiet".to_owned(),
                    local.display().to_string(), format!("refs/heads/{}", input.branch)], None)
                .map_err(|detail| Failure::new("instance.container_checkout_failed", format!("bundle: {detail}")));
            let pushed = made.and_then(|_| std::fs::read(&local).map_err(|error| Failure::new("instance.container_checkout_failed", error.to_string())));
            let _ = std::fs::remove_file(&local);
            files.push(&bundle, AGENT, input.group, "0640", &pushed?, Folders::Present)?;
            let fetched = git(incus, input, &["fetch", "--quiet", &bundle, &format!("+refs/heads/{}:{remote}", input.branch)]);
            let _ = incus.change(&["--project", &input.project, "file", "delete", &format!("{}{bundle}", input.name)]);
            fetched?;
            remote
        } else {
            input.expected_commit.clone()
        };
        git(incus, input, &["checkout", "--quiet", "-B", &input.branch, &start])?;
    }
    let now = git(incus, input, &["symbolic-ref", "--short", "HEAD"])?;
    let commit = git(incus, input, &["rev-parse", "HEAD"])?;
    if now.trim() != input.branch || commit.trim() != input.expected_commit {
        return Err(Failure::new("instance.container_checkout_failed", format!("the workspace holds {} at {}", now.trim(), commit.trim())));
    }
    Ok(())
}

/// The writer's Git identity: the workspace is its one safe directory, and a name and an e-mail when it has none.
fn identity(incus: &Incus<'_, impl System>, input: &CheckoutInput) -> Result<(), Failure> {
    let safe = git(incus, input, &["config", "--global", "--get-all", "safe.directory"]).unwrap_or_default();
    if safe.lines().collect::<Vec<_>>() != [input.workspace.as_str()] {
        git(incus, input, &["config", "--global", "--replace-all", "safe.directory", &input.workspace])?;
    }
    for (key, value) in [("user.name", "Orbit Agent"), ("user.email", "agent@orbit.local")] {
        if git(incus, input, &["config", "--global", "--get", key]).is_err() {
            git(incus, input, &["config", "--global", key, value])?;
        }
    }
    Ok(())
}

/// Git in the copy's workspace as its writer, with no hooks and no file system monitor.
fn git(incus: &Incus<'_, impl System>, input: &CheckoutInput, args: &[&str]) -> Result<String, Failure> {
    let (writer, group) = (AGENT.to_string(), input.group.to_string());
    let mut command: Vec<String> = [
        "--project", &input.project, "exec", &input.name, "--user", &writer, "--group", &group, "--cwd", &input.workspace, "--env", "HOME=/home/agent", "--",
        GIT, "-c", "core.hooksPath=/dev/null", "-c", "core.fsmonitor=false",
    ]
    .iter()
    .map(|part| (*part).to_owned())
    .collect();
    command.extend(args.iter().map(|part| (*part).to_owned()));
    incus.run(&command, None)
}

/// Git never takes the `.env`: one line in the checkout's own `info/exclude`, written once with the file's owner.
fn exclude(files: &Files<'_, impl System>, input: &CheckoutInput) -> Result<(), Failure> {
    let relative = &input.environment_file[input.workspace.len() + 1..];
    let escaped: String = relative.chars().flat_map(|c| if "\\*?[".contains(c) { vec!['\\', c] } else { vec![c] }).collect();
    let entry = format!("/{escaped}");
    let path = format!("{}/.git/info/exclude", input.workspace);
    let held = files.read(&path, "instance.container_checkout_failed")?;
    let text = held.as_ref().map(|held| String::from_utf8_lossy(&held.content).into_owned()).unwrap_or_default();
    if text.lines().any(|line| line == entry) {
        return Ok(());
    }
    let mut updated = text;
    if !updated.is_empty() && !updated.ends_with('\n') {
        updated.push('\n');
    }
    updated.push_str(&entry);
    updated.push('\n');
    let (uid, gid, mode) = held.map_or((AGENT, input.group, "0644".to_owned()), |held| (held.uid, held.gid, held.mode));
    files.parents(&path, uid, gid)?;
    files.push(&path, uid, gid, &mode, updated.as_bytes(), Folders::Present)
}
