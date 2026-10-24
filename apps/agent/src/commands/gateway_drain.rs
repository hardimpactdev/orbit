//! Read-only release hold and Process identity observations. Redis and record policy stay in PHP.
use super::{
    gateway_adoption::{hex, Layout},
    gateway_releases, processes,
    recipe::{self, Files, Invocation},
};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    collections::{BTreeMap, BTreeSet},
    fs,
    io::Read,
    os::unix::fs::MetadataExt,
    path::Path,
    time::Duration,
};

mod process;
pub use process::InspectProcess;

command_errors! {
    pub enum Error {
        NotDrained { stage: String } => "gateway.release_not_drained", "A release drain or Process identity observation failed. Keep the hold and inspect locally.";
    }
}
fn refuse(stage: &str) -> Error {
    Error::NotDrained {
        stage: stage.into(),
    }
}
fn read(path: &Path, limit: u64, stage: &str) -> Result<Vec<u8>, Error> {
    let mut bytes = Vec::new();
    fs::File::open(path)
        .and_then(|file| file.take(limit + 1).read_to_end(&mut bytes))
        .map_err(|_| refuse(stage))?;
    if bytes.len() as u64 > limit {
        return Err(refuse(stage));
    }
    Ok(bytes)
}
fn text(path: &Path, limit: u64, stage: &str) -> Result<String, Error> {
    String::from_utf8(read(path, limit, stage)?).map_err(|_| refuse(stage))
}
fn observe(host: &mut impl recipe::Host, argv: &[&str]) -> Result<String, Error> {
    let mut call = Invocation::new(argv);
    call.timeout = Duration::from_secs(30);
    let output = recipe::run(host, &call, true).map_err(|_| refuse("inventory"))?;
    if output.stdout.trim().is_empty() || output.stdout.len() > 65_536 {
        return Err(refuse("inventory"));
    }
    Ok(output.stdout)
}
fn properties(
    host: &mut impl recipe::Host,
    unit: &str,
    names: &[&str],
) -> Result<BTreeMap<String, String>, Error> {
    let value = observe(
        host,
        &[
            "/usr/bin/systemctl",
            "show",
            unit,
            &format!("--property={}", names.join(",")),
        ],
    )?;
    let mut result = BTreeMap::new();
    for line in value.trim().lines() {
        let (key, value) = line.split_once('=').ok_or_else(|| refuse("properties"))?;
        if !names.contains(&key) || result.insert(key.into(), value.into()).is_some() {
            return Err(refuse("properties"));
        }
    }
    Ok(result)
}
fn source(checkout: &str) -> Result<(Layout, String), Error> {
    let layout = Layout::new(checkout).map_err(|_| refuse("source"))?;
    let id = gateway_releases::release_id(&layout, &layout.current)
        .map_err(|_| refuse("source"))?
        .ok_or_else(|| refuse("source"))?;
    let sha = gateway_releases::prepared(&layout, &id)
        .map_err(|_| refuse("source"))?
        .ok_or_else(|| refuse("source"))?;
    Ok((layout, sha))
}
fn php(name: &str) -> bool {
    name.strip_prefix("php").is_some_and(|tail| {
        tail.is_empty()
            || tail.starts_with("-fpm")
            || tail.starts_with(|c: char| c.is_ascii_digit() || c == '.')
    })
}
fn writer(unit: &str) -> bool {
    static PATTERN: std::sync::LazyLock<regex::Regex> = std::sync::LazyLock::new(|| {
        regex::Regex::new(r"\Aorbit-(?:process-[a-zA-Z0-9_.@-]+|schedule-[a-zA-Z0-9_.@-]+|gateway-release(?:-run@[^\s/]*)?|fleet-converge|runtime-hibernator|agent-view)\.(?:service|timer)\z").unwrap()
    });
    unit == "php8.5-fpm.service" || PATTERN.is_match(unit)
}
fn plain_group(group: &str) -> bool {
    group.starts_with('/')
        && !group.contains("..")
        && group.split('/').skip(1).all(|part| {
            !part.is_empty() && part != "." && !part.bytes().any(|b| b.is_ascii_control())
        })
}
#[derive(Debug, Clone, Deserialize, Serialize, PartialEq)]
#[serde(deny_unknown_fields)]
pub struct Declaration {
    process_id: u64,
    node_id: u64,
    unit: String,
    source_sha: String,
    target_sha: String,
    spec_sha256: String,
    unit_sha256: String,
    runtime_sha256: String,
}
#[derive(Deserialize)]
struct Manifest {
    target_sha: String,
    source_sha: String,
    units: Vec<String>,
    #[serde(default)]
    unrelated_processes: Vec<Declaration>,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct HoldInput {
    checkout: String,
    target_sha: String,
    caller_pid: u32,
}
#[derive(Debug, Serialize)]
pub struct Hold {
    unrelated_processes: Vec<Declaration>,
}
fn hold(files: &Files, host: &mut impl recipe::Host, input: &HoldInput) -> Result<Hold, Error> {
    if !hex(&input.target_sha, 40) || input.caller_pid == 0 {
        return Err(refuse("identity"));
    }
    let path = files.path("/run/orbit-gateway-drain/hold.json");
    for (at, directory) in [(path.parent().unwrap(), true), (path.as_path(), false)] {
        let meta = fs::symlink_metadata(at).map_err(|_| refuse("manifest"))?;
        if meta.uid() != files.uid
            || meta.mode() & 0o022 != 0
            || if directory {
                !meta.is_dir()
            } else {
                !meta.is_file()
            }
        {
            return Err(refuse("manifest"));
        }
    }
    let manifest: Manifest = serde_json::from_slice(&read(&path, 1024 * 1024, "manifest")?)
        .map_err(|_| refuse("manifest"))?;
    if manifest.target_sha != input.target_sha || !hex(&manifest.source_sha, 40) {
        return Err(refuse("manifest"));
    }
    if source(&input.checkout)?.1 != manifest.source_sha {
        return Err(refuse("source"));
    }
    let mut expected = BTreeSet::new();
    for unit in &manifest.units {
        if !writer(unit) || !expected.insert(unit.clone()) {
            return Err(refuse("manifest"));
        }
    }
    let mut units = BTreeSet::new();
    let mut installed = BTreeMap::new();
    for verb in ["list-unit-files", "list-units"] {
        let output = observe(
            host,
            &[
                "/usr/bin/systemctl",
                verb,
                "--type=service,timer",
                "--all",
                "--full",
                "--plain",
                "--no-legend",
                "--no-pager",
            ],
        )?;
        for line in output.trim().lines() {
            let columns: Vec<_> = line.split_whitespace().collect();
            if columns.len() < 2 {
                return Err(refuse("inventory"));
            }
            if verb == "list-unit-files" {
                installed.insert(columns[0].to_owned(), columns[1].to_owned());
            }
            if writer(columns[0]) {
                units.insert(columns[0].to_owned());
            }
        }
    }
    let mut unrelated = BTreeSet::new();
    for declaration in &manifest.unrelated_processes {
        if declaration.process_id == 0
            || declaration.process_id > i64::MAX as u64
            || declaration.node_id == 0
            || declaration.node_id > i64::MAX as u64
            || processes::check_name(
                processes::Runtime::Systemd,
                declaration.process_id,
                &declaration.unit,
            )
            .is_err()
            || declaration.source_sha != manifest.source_sha
            || declaration.target_sha != input.target_sha
            || [
                &declaration.spec_sha256,
                &declaration.unit_sha256,
                &declaration.runtime_sha256,
            ]
            .iter()
            .any(|hash| !hex(hash, 64))
            || !units.contains(&declaration.unit)
            || !unrelated.insert(declaration.unit.clone())
        {
            return Err(refuse("declaration"));
        }
    }
    units.retain(|unit| !unrelated.contains(unit));
    expected.retain(|unit| !unrelated.contains(unit));
    if units != expected
        || !units.contains("php8.5-fpm.service")
        || !units.contains("orbit-gateway-release.timer")
    {
        return Err(refuse("inventory"));
    }
    for unit in units {
        if unit.contains("@.") {
            if installed.get(&unit).map(String::as_str) != Some("masked") {
                return Err(refuse("writer"));
            }
            continue;
        }
        let p = properties(
            host,
            &unit,
            &["LoadState", "ActiveState", "MainPID", "ControlGroup"],
        )?;
        if p.get("LoadState").map(String::as_str) != Some("masked")
            || !matches!(
                p.get("ActiveState").map(String::as_str),
                Some("inactive" | "failed")
            )
            || (unit.ends_with(".service")
                && (p.get("MainPID").map(String::as_str) != Some("0")
                    || !p.contains_key("ControlGroup")))
        {
            return Err(refuse("writer"));
        }
        if let Some(group) = p.get("ControlGroup").filter(|v| !v.is_empty()) {
            if !plain_group(group) {
                return Err(refuse("cgroup"));
            }
            let events = text(
                &files.path(&format!("/sys/fs/cgroup{group}/cgroup.events")),
                65536,
                "cgroup",
            )?;
            if !events.lines().any(|line| line == "populated 0") {
                return Err(refuse("cgroup"));
            }
        }
    }
    let mounts = text(&files.path("/proc/mounts"), 1024 * 1024, "visibility")?;
    let options = mounts
        .lines()
        .find_map(|line| {
            let parts: Vec<_> = line.split_whitespace().collect();
            (parts.len() >= 4 && parts[1] == "/proc" && parts[2] == "proc")
                .then(|| parts[3].to_owned())
        })
        .ok_or_else(|| refuse("visibility"))?;
    if options
        .split(',')
        .any(|o| o.strip_prefix("hidepid=").is_some_and(|v| v != "0"))
    {
        return Err(refuse("visibility"));
    }
    let processes = observe(host, &["/usr/bin/ps", "-eo", "pid=,comm="])?;
    for line in processes.trim().lines() {
        let line = line.trim();
        let (pid, name) = line
            .split_once(char::is_whitespace)
            .ok_or_else(|| refuse("processes"))?;
        if !pid.bytes().all(|b| b.is_ascii_digit()) {
            return Err(refuse("processes"));
        }
        let pid = pid.parse::<u32>().map_err(|_| refuse("processes"))?;
        if pid == 0 || name.trim().is_empty() || (pid != input.caller_pid && php(name.trim())) {
            return Err(refuse("processes"));
        }
    }
    Ok(Hold {
        unrelated_processes: manifest.unrelated_processes,
    })
}
pub struct InspectHold;
impl Command for InspectHold {
    const NAME: &'static str = "gateway.inspect-release-hold";
    const SUMMARY: &'static str =
        "Verify the operator's release hold against Node writer and process observations.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = HoldInput;
    type Output = Hold;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(input: HoldInput, context: &Context) -> Result<Hold, Error> {
        hold(&Files::system(), &mut recipe::Native(context), &input)
    }
}
#[cfg(test)]
mod tests;
