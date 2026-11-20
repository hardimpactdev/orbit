//! `instance.start`: the copy's first start, then its names.

use std::path::Path;

use serde::Deserialize;
use serde_json::Value;

use crate::{Failure, Incus, System};

const HOSTS_BEGIN: &str = "# BEGIN Orbit private names";
const HOSTS_END: &str = "# END Orbit private names";
const HOSTS_LINE: &str = "127.0.1.1";

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct StartInput {
    project: String,
    name: String,
    /// The default the copy came from: a source whose files Incus shifted needs the copy's files shifted back.
    source: String,
    /// The presented machine name, `<instance>.<project slug>`.
    hostname: String,
    /// The access plan's private names: `<host> <name>` lines in Orbit's block of `/etc/hosts`.
    #[serde(default)]
    private_names: Vec<PrivateName>,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct PrivateName {
    host: String,
    name: String,
}

/// Starts a stopped copy, then gives the running copy its hostname and its `/etc/hosts`: the `127.0.1.1` line names the
/// hostname, its first label and the Incus name, and Orbit's block holds the private names. The copy's Incus templates
/// rewrite both files at the first start, so the names come after it. On a Node whose seed holds shifted files (a nested
/// Node without idmapped mounts), a stopped copy's files are shifted back first, so its first start can apply its own map.
/// Answers how many changes it made.
///
/// # Errors
///
/// `instance.start_input_invalid`, `instance.clone_idmap_unshift_failed`, `instance.clone_idmap_unshift_unsupported`,
/// `instance.container_start_failed` or `instance.clone_private_names_failed`.
pub fn start(system: &impl System, input: &Value) -> Result<u32, Failure> {
    let input: StartInput =
        serde_json::from_value(input.clone()).map_err(|error| Failure::new("instance.start_input_invalid", error.to_string()))?;
    check(&input).map_err(|detail| Failure::new("instance.start_input_invalid", detail))?;
    let incus = Incus { system, code: "instance.container_start_failed" };
    let copy = incus
        .query(&format!("/1.0/instances/{}?project={}", input.name, input.project))?
        .ok_or_else(|| Failure::new("instance.container_start_failed", "the copy does not exist"))?;
    let mut changes = 0;
    if copy.get("status").and_then(Value::as_str) == Some("Stopped") {
        if unshift(&incus, &input, &copy)? {
            changes += 1;
        }
        incus.change(&["--project", &input.project, "start", &input.name])?;
        changes += 1;
    }
    changes += names(&incus, &input).map_err(|failure| Failure::new("instance.clone_private_names_failed", failure.detail))?;
    Ok(changes)
}

fn check(input: &StartInput) -> Result<(), String> {
    for (field, value) in [("project", &input.project), ("name", &input.name), ("source", &input.source)] {
        if !crate::name(value) {
            return Err(format!("{field} is not a name"));
        }
    }
    let label = |value: &str| {
        !value.is_empty()
            && value.len() <= 63
            && value.bytes().all(|byte| byte.is_ascii_alphanumeric() || byte == b'-')
            && !value.starts_with('-')
            && !value.ends_with('-')
    };
    let host = |value: &str| value.len() <= 253 && value.split('.').all(label);
    if !host(&input.hostname) {
        return Err("hostname is not a host name".to_owned());
    }
    for name in &input.private_names {
        let address = name.host.parse::<std::net::IpAddr>().is_ok();
        if !address || !host(&name.name) {
            return Err(format!("{} {} is not a private name", name.host, name.name));
        }
    }
    Ok(())
}

/// The hostname and `/etc/hosts` of the running copy. Answers how many files changed.
fn names(incus: &Incus<'_, impl System>, input: &StartInput) -> Result<u32, Failure> {
    let target = |path: &str| format!("{}{path}", input.name);
    let mut changes = 0;
    let hostname = format!("{}\n", input.hostname);
    let held = pull(incus, input, "/etc/hostname")?;
    if held.as_deref() != Some(hostname.as_str()) {
        incus.write(&["--project", &input.project, "file", "push", "--uid", "0", "--gid", "0", "--mode", "0644", "-", &target("/etc/hostname")], hostname.as_bytes())?;
        changes += 1;
    }
    incus.change(&["--project", &input.project, "exec", &input.name, "--", "/usr/bin/hostname", &input.hostname])?;
    let current = pull(incus, input, "/etc/hosts")?.unwrap_or_default();
    let wanted = hosts(&private_names(&current, &input.private_names), &input.hostname, &input.name);
    if wanted.lines().ne(current.lines()) {
        incus.write(&["--project", &input.project, "file", "push", "--uid", "0", "--gid", "0", "--mode", "0644", "-", &target("/etc/hosts")], wanted.as_bytes())?;
        changes += 1;
    }
    Ok(changes)
}

fn pull(incus: &Incus<'_, impl System>, input: &StartInput, path: &str) -> Result<Option<String>, Failure> {
    let argv: Vec<String> =
        ["--project", &input.project, "file", "pull", &format!("{}{path}", input.name), "-"].iter().map(|part| (*part).to_owned()).collect();
    match incus.run(&argv, None) {
        Ok(text) => Ok(Some(text)),
        Err(failure) if failure.detail.contains("does not exist") || failure.detail.to_ascii_lowercase().contains("not found") => Ok(None),
        Err(failure) => Err(failure),
    }
}

/// `hosts` without Orbit's block, then the block with one line per private name; no name, no block.
fn private_names(hosts: &str, names: &[PrivateName]) -> String {
    let mut kept = Vec::new();
    let mut inside = false;
    for line in hosts.lines() {
        if line == HOSTS_BEGIN {
            inside = true;
        } else if line == HOSTS_END {
            inside = false;
        } else if !inside {
            kept.push(line.to_owned());
        }
    }
    if !names.is_empty() {
        kept.push(HOSTS_BEGIN.to_owned());
        kept.extend(names.iter().map(|name| format!("{} {}", name.host, name.name)));
        kept.push(HOSTS_END.to_owned());
    }
    if kept.is_empty() { String::new() } else { kept.join("\n") + "\n" }
}

/// `hosts` with its `127.0.1.1` line naming the hostname, its first label and the Incus name; every other line stays.
fn hosts(current: &str, hostname: &str, container: &str) -> String {
    let first = hostname.split('.').next().unwrap_or(hostname);
    let mut names: Vec<&str> = Vec::new();
    for name in [hostname, first, container] {
        if !names.contains(&name) {
            names.push(name);
        }
    }
    let line = format!("{HOSTS_LINE}\t{}", names.join(" "));
    let mut lines: Vec<String> = current.lines().map(str::to_owned).collect();
    if let Some(existing) = lines.iter_mut().find(|existing| existing.split_whitespace().next() == Some(HOSTS_LINE)) {
        *existing = line;
    } else {
        lines.insert(0, line);
    }
    lines.join("\n") + "\n"
}

/// One range of an Incus ID map.
#[derive(Debug, Deserialize)]
#[serde(rename_all = "PascalCase")]
struct Range {
    isuid: bool,
    isgid: bool,
    hostid: u32,
    nsid: u32,
    maprange: u32,
}

/// Shifts a stopped copy's files back when its source's files were shifted: Incus copies them as they are on disk but
/// strips the copy's record of the map, so its first start would shift them again from the wrong base. A source on
/// idmapped mounts records no map and needs nothing. Answers whether a file changed.
fn unshift(incus: &Incus<'_, impl System>, input: &StartInput, copy: &Value) -> Result<bool, Failure> {
    let failed = |detail: String| Failure::new("instance.clone_idmap_unshift_failed", detail);
    let map = |value: &Value| -> Result<Vec<Range>, Failure> {
        let text = value.pointer("/config/volatile.last_state.idmap").and_then(Value::as_str).unwrap_or("[]");
        serde_json::from_str(text).map_err(|error| failed(error.to_string()))
    };
    if !map(copy)?.is_empty() {
        return Ok(false);
    }
    let source = incus
        .query(&format!("/1.0/instances/{}?project={}", input.source, input.project))?
        .ok_or_else(|| failed(format!("{} not found", input.source)))?;
    let ranges = map(&source)?;
    if ranges.is_empty() {
        return Ok(false);
    }
    let pool_name = copy.pointer("/expanded_devices/root/pool").and_then(Value::as_str).unwrap_or_default();
    let pool = incus.query(&format!("/1.0/storage-pools/{pool_name}"))?.ok_or_else(|| failed(format!("pool {pool_name} not found")))?;
    if pool.get("driver").and_then(Value::as_str) != Some("btrfs") {
        return Err(Failure::new("instance.clone_idmap_unshift_unsupported", pool_name.to_owned()));
    }
    let base = pool.pointer("/config/source").and_then(Value::as_str).unwrap_or_default();
    let root = Path::new(base).join("containers").join(format!("{}_{}", input.project, input.name)).join("rootfs");
    let uids: Vec<&Range> = ranges.iter().filter(|range| range.isuid).collect();
    let gids: Vec<&Range> = ranges.iter().filter(|range| range.isgid).collect();
    let result = crate::shift::back(&root, &uids, &gids).map_err(|error| failed(format!("{}: {error}", root.display())));
    if result.is_err() {
        // A chown may clear modes or capabilities before their restore fails; a retry cannot tell what was lost, so the
        // copy goes and the next run copies the seed again.
        let _ = incus.change(&["--project", &input.project, "delete", &input.name, "--force"]);
    }
    result
}

impl crate::shift::Map for Range {
    fn back(&self, id: u32) -> Option<u32> {
        (self.hostid <= id && id - self.hostid < self.maprange).then(|| id - self.hostid + self.nsid)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn hosts_get_the_machine_line_and_the_private_names_block_and_keep_every_other_line() {
        let seed = "127.0.0.1 localhost\n127.0.1.1\tinstance-2\n# BEGIN Orbit private names\n10.0.0.9 old.test\n# END Orbit private names\n";
        let names = [PrivateName { host: "10.44.0.3".to_owned(), name: "db.orbit".to_owned() }];

        let written = hosts(&private_names(seed, &names), "engine-proof.orbit", "instance-99");

        assert_eq!(
            written,
            "127.0.0.1 localhost\n127.0.1.1\tengine-proof.orbit engine-proof instance-99\n# BEGIN Orbit private names\n10.44.0.3 db.orbit\n\
             # END Orbit private names\n"
        );
        assert_eq!(private_names(seed, &[]), "127.0.0.1 localhost\n127.0.1.1\tinstance-2\n");
    }
}
