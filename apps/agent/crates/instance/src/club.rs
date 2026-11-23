//! `instance.club_key`: a nested-fleet copy's Club key, into its `/run`, a tmpfs that no snapshot holds.
//!
//! The steps of `instance_nested_club_key.sh` (docs/reference/development-containers.md#the-club-key-of-the-nested-gateway),
//! each as one fixed command in the running copy, with the key only in a pushed file.

use serde::Deserialize;
use serde_json::Value;

use crate::prepare::{Files, Folders};
use crate::{Failure, Incus, System};

const FINDMNT: &str = "/usr/bin/findmnt";
const MKDIR: &str = "/usr/bin/mkdir";
const CHOWN: &str = "/usr/bin/chown";
const CHMOD: &str = "/usr/bin/chmod";
const RM: &str = "/usr/bin/rm";
const MV: &str = "/usr/bin/mv";
const TIMEOUT: &str = "/usr/bin/timeout";
const SYSTEMCTL: &str = "/usr/bin/systemctl";
const INNER_INCUS: &str = "/usr/bin/incus";

const NOT_TMPFS: &str = "instance.nested_club_key_not_tmpfs";
const FAILED: &str = "instance.nested_club_key_delivery_failed";

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct ClubKeyInput {
    project: String,
    name: String,
    /// The folder in `/run` that holds the key.
    directory: String,
    file: String,
    /// The key's owner and group in the copy: the nested Gateway's `orbit`.
    owner: u32,
    group: u32,
    /// The tmpfiles rule that makes the empty folder at each boot.
    tmpfiles: String,
    /// The nested Gateway container and its Swamp serve unit, which reads the key when it starts.
    gateway: String,
    serve_unit: String,
    /// The key, or none when the Gateway holds no value: then nothing is written.
    #[serde(default)]
    key: Option<crate::BlobRef>,
}

/// Writes the key into the running copy's `/run` folder, owned by root with the nested Gateway's group, and the tmpfiles
/// rule that makes that folder at each boot. A new key restarts the nested Gateway's serve when the inner Incus answers.
/// Answers `{"club_key": "changed" | "changed_serve_pending" | "unchanged" | null}`, null when there was no key.
///
/// # Errors
///
/// `instance.club_key_input_invalid`, `instance.nested_club_key_not_tmpfs` or `instance.nested_club_key_delivery_failed`.
pub fn club_key(system: &impl System, input: &Value) -> Result<String, Failure> {
    let input: ClubKeyInput = serde_json::from_value(input.clone()).map_err(|error| Failure::new("instance.club_key_input_invalid", error.to_string()))?;
    check(&input).map_err(|detail| Failure::new("instance.club_key_input_invalid", detail))?;
    let Some(key) = &input.key else {
        return Ok(serde_json::json!({"club_key": null}).to_string());
    };
    let key = system.blob(&key.blob.sha256).map_err(|detail| Failure::new(FAILED, detail))?;
    if key.is_empty() {
        return Err(Failure::new(FAILED, "the key is empty"));
    }
    let incus = Incus { system, code: FAILED };
    let exec = |argv: &[&str]| -> Result<String, Failure> {
        let mut command: Vec<String> = ["--project", &input.project, "exec", &input.name, "--"].iter().map(|part| (*part).to_owned()).collect();
        command.extend(argv.iter().map(|part| (*part).to_owned()));
        incus.run(&command, None)
    };
    let tmpfs = |path: &str| -> Result<(), Failure> {
        let kind = exec(&[FINDMNT, "--noheadings", "--output", "FSTYPE", "--target", path])?;
        if kind.trim() == "tmpfs" { Ok(()) } else { Err(Failure::new(NOT_TMPFS, format!("{path} is on {}", kind.trim()))) }
    };
    tmpfs("/run")?;
    exec(&[MKDIR, "-p", "-m", "0700", "--", &input.directory])?;
    tmpfs(&input.directory)?;
    // Root owns the folder, so its group can read the key but plant no link to the disk in it.
    exec(&[CHOWN, "--", &format!("0:{}", input.group), &input.directory])?;
    exec(&[CHMOD, "0750", "--", &input.directory])?;
    let files = Files { incus: &incus, project: &input.project, name: &input.name };
    let rule = format!("d {} 0750 0 {} -", input.directory, input.group);
    let held = files.read(&input.tmpfiles, FAILED)?;
    if held.as_ref().map(|held| String::from_utf8_lossy(&held.content).trim_end_matches('\n').to_owned()).as_deref() != Some(rule.as_str()) {
        let (uid, gid, mode) = held.map_or((0, 0, "0600".to_owned()), |held| (held.uid, held.gid, held.mode));
        files.push(&input.tmpfiles, uid, gid, &mode, format!("{rule}\n").as_bytes(), Folders::Present)?;
    }
    let path = format!("{}/{}", input.directory, input.file);
    if files.read(&path, FAILED)?.is_some_and(|held| held.content == key) {
        return Ok(serde_json::json!({"club_key": "unchanged"}).to_string());
    }
    let next = format!("{}/.{}.next", input.directory, input.file);
    exec(&[RM, "-f", "--", &next])?;
    let moved = files.push(&next, input.owner, input.group, "0600", &key, Folders::Present).and_then(|()| exec(&[MV, "-f", "--", &next, &path]));
    if moved.is_err() {
        let _ = exec(&[RM, "-f", "--", &next]);
    }
    moved?;
    Ok(serde_json::json!({"club_key": restart(&exec, &input)}).to_string())
}

/// Serve reads the key when it starts. Right after the copy boots the inner Incus may not answer yet: then serve starts
/// later by itself and reads the key, and the word says so.
fn restart(exec: &impl Fn(&[&str]) -> Result<String, Failure>, input: &ClubKeyInput) -> &'static str {
    let pending = "changed_serve_pending";
    if exec(&[TIMEOUT, "-k", "2", "5", SYSTEMCTL, "is-active", "--quiet", "incus.service"]).is_err() {
        return pending;
    }
    let pattern = format!("^{}$", input.gateway);
    let Ok(status) = exec(&[TIMEOUT, "-k", "2", "5", INNER_INCUS, "list", "--format", "csv", "--columns", "s", "--", &pattern]) else {
        return pending;
    };
    if status.trim() == "RUNNING" {
        let _ = exec(&[TIMEOUT, "-k", "2", "10", INNER_INCUS, "exec", &input.gateway, "--", "systemctl", "reset-failed", "--", &input.serve_unit]);
        if exec(&[TIMEOUT, "-k", "2", "10", INNER_INCUS, "exec", &input.gateway, "--", "systemctl", "restart", "--no-block", "--", &input.serve_unit]).is_err() {
            return pending;
        }
    }
    "changed"
}

fn check(input: &ClubKeyInput) -> Result<(), String> {
    for (field, value) in [("project", &input.project), ("name", &input.name), ("gateway", &input.gateway)] {
        if !crate::name(value) {
            return Err(format!("{field} is not a name"));
        }
    }
    let path = |value: &str| value.starts_with('/') && !value.split('/').any(|part| part == ".." || part == ".") && !value.bytes().any(|b| b.is_ascii_control() || b == b' ');
    if !input.directory.starts_with("/run/") || !path(&input.directory) || !path(&input.tmpfiles) {
        return Err("directory must be a folder in /run and tmpfiles a path".to_owned());
    }
    let word = |value: &str| !value.is_empty() && !value.starts_with(['-', '.']) && value.bytes().all(|b| b.is_ascii_alphanumeric() || b"._@-".contains(&b));
    if !word(&input.file) || !word(&input.serve_unit) {
        return Err("file and serve_unit must be plain names".to_owned());
    }
    Ok(())
}
