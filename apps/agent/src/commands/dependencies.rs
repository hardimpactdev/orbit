//! `instance.*-dependenc*`: the dependency files of an Instance and the updates of its packages. Every read walks to
//! its directory one component at a time without following a link, and refuses a file that is not regular. These
//! replace the Python and Bash of `DependencyFilesProgram`, the `*DependencyUpdatePresenceProgram` probes, and the
//! `*DependencyUpdateProgram` supervisors, rule for rule. Each answer keeps their JSON shape: a result, or
//! `{"error": "dependencies.<code>"}`.

use super::deploy_step::{run_program, RunDeployStepOutput};
use super::source::realpath;

use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use base64::Engine;
use serde::{Deserialize, Serialize};
use serde_json::{json, Map, Value};
use sha2::{Digest, Sha256};
use std::{
    collections::BTreeMap,
    fs,
    io::Read,
    os::fd::OwnedFd,
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::{Duration, Instant},
};

command_errors! {
    pub enum DependencyError {
        Invalid { check: String } => "instance.dependency_input_invalid", "The dependency request does not pass its checks.";
    }
}

/// A refusal that the answer carries as `{"error": "dependencies.<code>"}`.
#[derive(Debug, PartialEq)]
struct Refusal(&'static str);

type Checked<T> = Result<T, Refusal>;

fn answer(result: Checked<Value>) -> Value {
    result.unwrap_or_else(|Refusal(code)| json!({ "error": format!("dependencies.{code}") }))
}

/// The files that the inventory reads, in its order.
pub const FILES: [&str; 15] = [
    "composer.json",
    "composer.lock",
    "package.json",
    "npm-shrinkwrap.json",
    "package-lock.json",
    "pnpm-lock.yaml",
    "bun.lock",
    "bun.lockb",
    "yarn.lock",
    "pnpm-workspace.yaml",
    ".yarnrc.yml",
    ".pnpmfile.cjs",
    "pnpmfile.cjs",
    "bunfig.toml",
    "yarn.config.cjs",
];

/// Opens a canonical absolute directory one component at a time, never through a link.
fn directory(path: &str) -> Checked<OwnedFd> {
    use rustix::fs::{openat, Mode, OFlags, CWD};
    let normal = path.starts_with('/')
        && path != "/"
        && path[1..]
            .split('/')
            .all(|part| !part.is_empty() && part != "." && part != "..");
    if !normal || realpath(Path::new(path)).as_deref() != Some(Path::new(path)) {
        return Err(Refusal("unsafe_source"));
    }
    let flags = OFlags::RDONLY | OFlags::DIRECTORY | OFlags::CLOEXEC;
    let mut fd =
        openat(CWD, "/", flags, Mode::empty()).map_err(|_| Refusal("unreadable_source"))?;
    for part in path[1..].split('/') {
        fd = openat(&fd, part, flags | OFlags::NOFOLLOW, Mode::empty())
            .map_err(|_| Refusal("unreadable_source"))?;
    }
    Ok(fd)
}

fn stat_at(fd: &OwnedFd, name: &str) -> Result<Option<rustix::fs::Stat>, Refusal> {
    match rustix::fs::statat(fd, name, rustix::fs::AtFlags::SYMLINK_NOFOLLOW) {
        Ok(stat) => Ok(Some(stat)),
        Err(rustix::io::Errno::NOENT) => Ok(None),
        Err(_) => Err(Refusal("unreadable_source")),
    }
}

fn is_regular(stat: &rustix::fs::Stat) -> bool {
    rustix::fs::FileType::from_raw_mode(stat.st_mode) == rustix::fs::FileType::RegularFile
}

fn is_dir(stat: &rustix::fs::Stat) -> bool {
    rustix::fs::FileType::from_raw_mode(stat.st_mode) == rustix::fs::FileType::Directory
}

/// Whether a regular file exists; anything else in its place refuses.
fn present(fd: &OwnedFd, name: &str) -> Checked<bool> {
    match stat_at(fd, name)? {
        None => Ok(false),
        Some(stat) if is_regular(&stat) => Ok(true),
        Some(_) => Err(Refusal("unsafe_source")),
    }
}

fn read_at(fd: &OwnedFd, name: &str, limit: Option<u64>) -> std::io::Result<Vec<u8>> {
    use rustix::fs::{openat, Mode, OFlags};
    let file = openat(
        fd,
        name,
        OFlags::RDONLY | OFlags::NOFOLLOW | OFlags::NONBLOCK | OFlags::CLOEXEC,
        Mode::empty(),
    )?;
    let mut data = Vec::new();
    let file = fs::File::from(file);
    match limit {
        Some(limit) => file.take(limit).read_to_end(&mut data)?,
        None => (&file).read_to_end(&mut data)?,
    };
    Ok(data)
}

// ---- package manifests -------------------------------------------------------------------------------------------

/// Parses JSON and refuses an object with a repeated key, as the manifest rules do.
fn strict_json(data: &[u8]) -> Option<Value> {
    struct Strict;
    impl<'de> serde::de::Visitor<'de> for Strict {
        type Value = Value;
        fn expecting(&self, formatter: &mut std::fmt::Formatter) -> std::fmt::Result {
            formatter.write_str("JSON")
        }
        fn visit_bool<E>(self, value: bool) -> Result<Value, E> {
            Ok(Value::Bool(value))
        }
        fn visit_i64<E>(self, value: i64) -> Result<Value, E> {
            Ok(Value::from(value))
        }
        fn visit_u64<E>(self, value: u64) -> Result<Value, E> {
            Ok(Value::from(value))
        }
        fn visit_f64<E>(self, value: f64) -> Result<Value, E> {
            Ok(Value::from(value))
        }
        fn visit_str<E>(self, value: &str) -> Result<Value, E> {
            Ok(Value::String(value.into()))
        }
        fn visit_string<E>(self, value: String) -> Result<Value, E> {
            Ok(Value::String(value))
        }
        fn visit_unit<E>(self) -> Result<Value, E> {
            Ok(Value::Null)
        }
        fn visit_seq<A: serde::de::SeqAccess<'de>>(self, mut seq: A) -> Result<Value, A::Error> {
            let mut values = Vec::new();
            while let Some(value) = seq.next_element_seed(StrictSeed)? {
                values.push(value);
            }
            Ok(Value::Array(values))
        }
        fn visit_map<A: serde::de::MapAccess<'de>>(self, mut map: A) -> Result<Value, A::Error> {
            let mut values = Map::new();
            while let Some(key) = map.next_key::<String>()? {
                let value = map.next_value_seed(StrictSeed)?;
                if values.insert(key, value).is_some() {
                    return Err(serde::de::Error::custom("a repeated key"));
                }
            }
            Ok(Value::Object(values))
        }
    }
    struct StrictSeed;
    impl<'de> serde::de::DeserializeSeed<'de> for StrictSeed {
        type Value = Value;
        fn deserialize<D: serde::Deserializer<'de>>(
            self,
            deserializer: D,
        ) -> Result<Value, D::Error> {
            deserializer.deserialize_any(Strict)
        }
    }
    let mut deserializer = serde_json::Deserializer::from_slice(data);
    let value = serde::de::DeserializeSeed::deserialize(StrictSeed, &mut deserializer).ok()?;
    deserializer.end().ok()?;
    Some(value)
}

fn load_manifest(fd: &OwnedFd) -> Checked<Map<String, Value>> {
    let data = read_at(fd, "package.json", None).map_err(|_| Refusal("unreadable_source"))?;
    let Some(Value::Object(manifest)) = strict_json(&data) else {
        return Err(Refusal("invalid_manifest"));
    };
    if manifest.contains_key("workspaces") {
        return Err(Refusal("unsupported_layout"));
    }
    Ok(manifest)
}

const MANAGERS: [&str; 4] = ["npm", "pnpm", "bun", "yarn"];

/// The managers a manifest declares, in order, and the Yarn major version when it names one.
fn declared_managers(manifest: &Map<String, Value>) -> Checked<(Vec<String>, Option<u64>)> {
    let pattern = regex::Regex::new(
        r"^(npm|pnpm|bun|yarn)@(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$",
    )
    .map_err(|_| Refusal("unreadable_source"))?;
    let (mut names, mut major) = (Vec::new(), None);
    if let Some(manager) = manifest.get("packageManager") {
        let manager = manager.as_str().ok_or(Refusal("invalid_manifest"))?;
        let found = pattern
            .captures(manager)
            .ok_or(Refusal("unsupported_format"))?;
        names.push(found[1].to_string());
        if &found[1] == "yarn" {
            major = found[2].parse().ok();
        }
    }
    if let Some(engines) = manifest.get("devEngines") {
        let engines = engines.as_object().ok_or(Refusal("invalid_manifest"))?;
        if let Some(entries) = engines.get("packageManager") {
            let entries = match entries {
                Value::Object(_) => vec![entries.clone()],
                Value::Array(entries) => entries.clone(),
                _ => return Err(Refusal("invalid_manifest")),
            };
            let (mut selected, mut on_fail, mut selected_major) = (None, "error".to_string(), None);
            for entry in &entries {
                let entry = entry.as_object().ok_or(Refusal("invalid_manifest"))?;
                let name = entry
                    .get("name")
                    .and_then(Value::as_str)
                    .ok_or(Refusal("invalid_manifest"))?;
                let version = entry.get("version").filter(|version| !version.is_null());
                if version.is_some_and(|version| !version.is_string()) {
                    return Err(Refusal("invalid_manifest"));
                }
                let mode = match entry.get("onFail") {
                    None => "error",
                    Some(mode) => mode.as_str().ok_or(Refusal("invalid_manifest"))?,
                };
                if !["ignore", "warn", "error", "download"].contains(&mode) {
                    return Err(Refusal("invalid_manifest"));
                }
                on_fail = mode.to_string();
                if MANAGERS.contains(&name) {
                    selected = Some(name.to_string());
                    if name == "yarn" {
                        // The leading `0` or the leading digits, as `(0|[1-9][0-9]*)` matches them.
                        selected_major = version.and_then(Value::as_str).and_then(|version| {
                            let digits: String =
                                version.chars().take_while(char::is_ascii_digit).collect();
                            if digits.starts_with('0') {
                                Some(0)
                            } else {
                                digits.parse().ok()
                            }
                        });
                    }
                    break;
                }
            }
            match selected {
                Some(selected) => {
                    if selected == "yarn" && selected_major.is_some() && major.is_none() {
                        major = selected_major;
                    }
                    names.push(selected);
                }
                None if on_fail != "warn" && on_fail != "ignore" => {
                    return Err(Refusal("unsupported_format"))
                }
                None => {}
            }
        }
    }
    let mut unique: Vec<String> = Vec::new();
    for name in names {
        if !unique.contains(&name) {
            unique.push(name);
        }
    }
    Ok((unique, major))
}

// ---- Vite+ -------------------------------------------------------------------------------------------------------

/// The first Vite+ that answers its version, from the Orbit install and then the account's own installs.
fn vite_plus(home: &Path) -> Value {
    let pattern =
        regex::Regex::new(r"^vp v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$").ok();
    let candidates = [
        (PathBuf::from("/opt/orbit/vite-plus/bin/vp"), true),
        (home.join(".vite-plus/bin/vp"), false),
        (home.join(".vite-plus/current/bin/vp"), false),
        (home.join(".local/share/vite-plus/bin/vp"), false),
        (home.join(".local/share/vite-plus/current/bin/vp"), false),
    ];
    for (candidate, orbit) in candidates {
        let executable = fs::metadata(&candidate).is_ok_and(|metadata| metadata.is_file())
            && rustix::fs::access(&candidate, rustix::fs::Access::EXEC_OK).is_ok();
        if !executable {
            continue;
        }
        let mut command = Process::new(&candidate);
        command
            .arg("--version")
            .stdin(Stdio::null())
            .stdout(Stdio::piped())
            .stderr(Stdio::null());
        if orbit {
            command.env("VP_HOME", "/opt/orbit/vite-plus");
        }
        let Some(output) = bounded_output(command, Duration::from_secs(10)) else {
            continue;
        };
        let first = String::from_utf8_lossy(&output)
            .lines()
            .next()
            .unwrap_or_default()
            .trim()
            .to_string();
        if let Some(found) = pattern
            .as_ref()
            .and_then(|pattern| pattern.captures(&first))
        {
            return json!({"path": candidate, "version": format!("{}.{}.{}", &found[1], &found[2], &found[3])});
        }
    }
    Value::Null
}

/// A program's standard output when it exits 0 within `timeout`; it is killed otherwise.
fn bounded_output(mut command: Process, timeout: Duration) -> Option<Vec<u8>> {
    let mut child = command.spawn().ok()?;
    let mut stdout = child.stdout.take()?;
    let reader = std::thread::spawn(move || {
        let mut data = Vec::new();
        let _ = stdout.read_to_end(&mut data);
        data
    });
    let end = Instant::now() + timeout;
    let status = loop {
        match child.try_wait() {
            Ok(Some(status)) => break status,
            Ok(None) if Instant::now() >= end => {
                let _ = child.kill();
                let _ = child.wait();
                return None;
            }
            Ok(None) => std::thread::sleep(Duration::from_millis(20)),
            Err(_) => return None,
        }
    };
    let data = reader.join().ok()?;
    status.success().then_some(data)
}

// ---- the update probe --------------------------------------------------------------------------------------------

#[derive(schemars::JsonSchema, Deserialize, Clone, Copy, PartialEq, Debug)]
#[serde(rename_all = "lowercase")]
pub enum Ecosystem {
    Composer,
    Npm,
    Pnpm,
    Bun,
    Yarn,
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ProbeInput {
    pub directory: String,
    pub ecosystem: Ecosystem,
}

fn any_present(fd: &OwnedFd, names: &[&str]) -> Checked<bool> {
    for name in names {
        if present(fd, name)? {
            return Ok(true);
        }
    }
    Ok(false)
}

/// Whether the ecosystem's files are complete enough for an update, absent, or incomplete, and for a JavaScript
/// manager the Vite+ that runs it.
pub fn probe(input: &ProbeInput, home: &Path) -> Value {
    answer((|| {
        let fd = directory(&input.directory)?;
        if input.ecosystem == Ecosystem::Composer {
            let manifest = present(&fd, "composer.json")?;
            let lock = present(&fd, "composer.lock")?;
            let status = match (manifest, lock) {
                (true, true) => "present",
                (false, false) => "absent",
                _ => "incomplete",
            };
            return Ok(json!({"status": status}));
        }
        if input.ecosystem == Ecosystem::Yarn {
            return probe_yarn(&fd);
        }
        let has_manifest = present(&fd, "package.json")?;
        let shrinkwrap = present(&fd, "npm-shrinkwrap.json")?;
        let package_lock = present(&fd, "package-lock.json")?;
        let yarn_files = any_present(&fd, &["yarn.lock", ".yarnrc.yml", "yarn.config.cjs"])?;
        let workspace = present(&fd, "pnpm-workspace.yaml")?;
        let pnpm_lock = present(&fd, "pnpm-lock.yaml")?;
        let pnpm_files = pnpm_lock || any_present(&fd, &[".pnpmfile.cjs", "pnpmfile.cjs"])?;
        let bun_lock = present(&fd, "bun.lock")?;
        let bun_lockb = !bun_lock && present(&fd, "bun.lockb")?;
        let bun_files = bun_lock || bun_lockb || present(&fd, "bunfig.toml")?;
        let npm_lock = shrinkwrap || package_lock;
        let managers = if has_manifest {
            declared_managers(&load_manifest(&fd)?)?.0
        } else {
            Vec::new()
        };
        if workspace {
            return Err(Refusal("unsupported_layout"));
        }
        let mut signals: Vec<String> = Vec::new();
        let found = [
            ("yarn", yarn_files),
            ("pnpm", pnpm_files),
            ("bun", bun_files),
            ("npm", npm_lock),
        ];
        for name in managers.iter().cloned().chain(
            found
                .iter()
                .filter(|(_, on)| *on)
                .map(|(name, _)| name.to_string()),
        ) {
            if !signals.contains(&name) {
                signals.push(name);
            }
        }
        if signals.iter().any(|signal| signal == "yarn") {
            return Err(Refusal("unsupported_format"));
        }
        if signals.len() > 1 {
            return Err(Refusal("ambiguous_manager"));
        }
        let only = |name: &str| signals.len() == 1 && signals[0] == name;
        let status = match input.ecosystem {
            Ecosystem::Npm if !only("npm") => "absent",
            Ecosystem::Npm if !has_manifest || !npm_lock => "incomplete",
            Ecosystem::Npm if !package_lock && !managers.iter().any(|manager| manager == "npm") => {
                "incomplete"
            }
            Ecosystem::Npm => "present",
            Ecosystem::Pnpm if only("pnpm") => {
                if has_manifest && pnpm_lock {
                    "present"
                } else {
                    "incomplete"
                }
            }
            Ecosystem::Pnpm if signals.is_empty() && has_manifest => "incomplete",
            Ecosystem::Bun if only("bun") => {
                // A binary lockfile alone is the format this update cannot keep.
                if bun_lockb && !bun_lock {
                    return Err(Refusal("unsupported_format"));
                }
                if has_manifest && bun_lock {
                    "present"
                } else {
                    "incomplete"
                }
            }
            _ => "absent",
        };
        let mut payload = json!({"status": status});
        if status == "present" {
            payload["vp"] = vite_plus(home);
        }
        Ok(payload)
    })())
}

fn probe_yarn(fd: &OwnedFd) -> Checked<Value> {
    let has_manifest = present(fd, "package.json")?;
    let yarn_lock = present(fd, "yarn.lock")?;
    let yarnrc = present(fd, ".yarnrc.yml")?;
    let yarn_config = present(fd, "yarn.config.cjs")?;
    let workspace = present(fd, "pnpm-workspace.yaml")?;
    let (managers, major) = if has_manifest {
        declared_managers(&load_manifest(fd)?)?
    } else {
        (Vec::new(), None)
    };
    let modern_lock = yarn_lock
        && read_at(fd, "yarn.lock", Some(8192))
            .map_err(|_| Refusal("unreadable_source"))?
            .windows(10)
            .any(|window| window == b"__metadata");
    if workspace {
        return Err(Refusal("unsupported_layout"));
    }
    if !managers.iter().any(|manager| manager == "yarn") && !yarn_lock && !yarnrc && !yarn_config {
        return Ok(json!({"status": "absent"}));
    }
    let modern = yarnrc || yarn_config || modern_lock || major.is_some_and(|major| major >= 2);
    Ok(json!({"status": "present", "family": if modern { "modern" } else { "classic" }}))
}

pub struct ProbeUpdate;

impl Command for ProbeUpdate {
    const NAME: &'static str = "instance.probe-dependency-update";
    const SUMMARY: &'static str =
        "Check whether one package ecosystem of a directory is ready for an update.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(45);
    type Input = ProbeInput;
    type Output = Value;
    type Error = DependencyError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(input: ProbeInput, _: &Context) -> Result<Value, DependencyError> {
        let home = std::env::var_os("HOME")
            .map(PathBuf::from)
            .unwrap_or_default();
        Ok(probe(&input, &home))
    }
}

// ---- the update --------------------------------------------------------------------------------------------------

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct UpdateInput {
    pub directory: String,
    pub ecosystem: Ecosystem,
    /// The Vite+ that runs a JavaScript update, as the probe found it.
    #[serde(default)]
    pub vp: Option<PathBuf>,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Updated {
    /// The package manager's exit status: 124 when it passed its deadline.
    pub exit_code: i32,
}

/// The longest an update may run.
pub const UPDATE_DEADLINE: Duration = Duration::from_secs(600);

/// Updates the packages within their declared constraints, in the package manager's own session, with its output
/// discarded. At the deadline its process group stops and the update answers 124.
pub fn update(
    input: &UpdateInput,
    composer: &Path,
    deadline: Duration,
) -> Result<Updated, DependencyError> {
    let invalid = |check: &str| DependencyError::Invalid {
        check: check.into(),
    };
    let directory = Path::new(&input.directory);
    if directory.is_relative() || input.directory.chars().any(char::is_control) {
        return Err(invalid("the directory is an absolute path"));
    }
    let quote = |value: &Path| format!("'{}'", value.display().to_string().replace('\'', "'\\''"));
    let script = match input.ecosystem {
        Ecosystem::Composer => format!(
            "exec {} --working-dir {} update --no-interaction --no-ansi --no-progress --no-audit </dev/null >/dev/null 2>&1",
            quote(composer),
            quote(directory)
        ),
        Ecosystem::Npm | Ecosystem::Pnpm | Ecosystem::Bun => {
            let vp = input.vp.as_deref().ok_or_else(|| invalid("a JavaScript update names Vite+"))?;
            if vp.is_relative()
                || vp.display().to_string().chars().any(char::is_control)
                || rustix::fs::access(vp, rustix::fs::Access::EXEC_OK).is_err()
            {
                return Err(invalid("Vite+ is an executable at an absolute path"));
            }
            let home = if vp == Path::new("/opt/orbit/vite-plus/bin/vp") { "export VP_HOME=/opt/orbit/vite-plus\n" } else { "" };
            let extra = if input.ecosystem == Ecosystem::Bun { " -- --lockfile-only --save-text-lockfile" } else { "" };
            format!("{home}exec {} update --no-save{extra} </dev/null >/dev/null 2>&1", quote(vp))
        }
        Ecosystem::Yarn => return Err(invalid("Yarn has no update")),
    };
    let file = std::env::temp_dir().join(format!(
        ".orbit-dependency-update.{:016x}",
        rand::random::<u64>()
    ));
    std::fs::write(&file, script).map_err(|_| invalid("the update can be written"))?;
    let result = run_program(
        &super::deploy_step::Program {
            user: None,
            directory,
            command: super::deploy_step::StepCommand::Script(&file),
            step: "update",
            secrets: &[],
            environment: &std::collections::BTreeMap::new(),
            timeout: deadline,
        },
        None,
    );
    let _ = std::fs::remove_file(&file);
    let RunDeployStepOutput { exit_code, .. } = result.map_err(|_| invalid("the update starts"))?;
    // The step runner reports a stop at the deadline as TERM's 143; an update reports it as `timeout` does.
    Ok(Updated {
        exit_code: if exit_code == 143 { 124 } else { exit_code },
    })
}

pub struct UpdateDependencies;

impl Command for UpdateDependencies {
    const NAME: &'static str = "instance.update-dependencies";
    const SUMMARY: &'static str =
        "Update one package ecosystem of a development checkout within its constraints.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(630);
    type Input = UpdateInput;
    type Output = Updated;
    type Error = DependencyError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn target(input: &UpdateInput) -> Option<String> {
        Some(format!("dependencies:{}", input.directory))
    }

    fn run(input: UpdateInput, _: &Context) -> Result<Updated, DependencyError> {
        update(&input, Path::new("/usr/bin/composer"), UPDATE_DEADLINE)
    }
}

// ---- the inventory files -----------------------------------------------------------------------------------------

fn signature(stat: &rustix::fs::Stat) -> Value {
    json!([
        stat.st_dev,
        stat.st_ino,
        stat.st_mode,
        stat.st_size,
        i128::from(stat.st_mtime) * 1_000_000_000 + i128::from(stat.st_mtime_nsec),
        i128::from(stat.st_ctime) * 1_000_000_000 + i128::from(stat.st_ctime_nsec),
    ])
}

fn limit(name: &str) -> u64 {
    let lock = name.ends_with(".lock")
        || name.ends_with(".lockb")
        || ["package-lock.json", "npm-shrinkwrap.json", "pnpm-lock.yaml"].contains(&name);
    if lock {
        8 * 1024 * 1024
    } else {
        1024 * 1024
    }
}

fn entry(
    content: Option<String>,
    hash: Option<String>,
    error: Option<&str>,
    state: Option<Value>,
) -> Value {
    json!({"content": content, "hash": hash, "error": error, "state": state})
}

/// One file's content, hash, and state, read only when it stayed the same file throughout.
fn read_file(fd: &OwnedFd, name: &str) -> Checked<Value> {
    let before = match rustix::fs::statat(fd, name, rustix::fs::AtFlags::SYMLINK_NOFOLLOW) {
        Ok(stat) => stat,
        Err(rustix::io::Errno::NOENT) => return Ok(entry(None, None, None, None)),
        Err(_) => {
            return Ok(entry(
                None,
                None,
                Some("dependencies.unreadable_source"),
                None,
            ))
        }
    };
    let state = signature(&before);
    if !is_regular(&before) {
        return Ok(entry(
            None,
            None,
            Some("dependencies.unsafe_source"),
            Some(state),
        ));
    }
    let limit = limit(name);
    if u64::try_from(before.st_size).unwrap_or(u64::MAX) > limit {
        return Ok(entry(
            None,
            None,
            Some("dependencies.source_too_large"),
            Some(state),
        ));
    }
    use rustix::fs::{openat, Mode, OFlags};
    let Ok(file) = openat(
        fd,
        name,
        OFlags::RDONLY | OFlags::NOFOLLOW | OFlags::NONBLOCK | OFlags::CLOEXEC,
        Mode::empty(),
    ) else {
        return Ok(entry(
            None,
            None,
            Some("dependencies.unreadable_source"),
            Some(state),
        ));
    };
    let same = |stat: rustix::io::Result<rustix::fs::Stat>| {
        stat.is_ok_and(|stat| signature(&stat) == state)
    };
    if !same(rustix::fs::fstat(&file)) {
        return Err(Refusal("source_changed"));
    }
    let file = fs::File::from(file);
    let mut data = Vec::new();
    if (&file).take(limit + 1).read_to_end(&mut data).is_err() {
        return Ok(entry(
            None,
            None,
            Some("dependencies.unreadable_source"),
            Some(state),
        ));
    }
    if !same(rustix::fs::fstat(&file))
        || data.len() as u64 > limit
        || !same(rustix::fs::statat(
            fd,
            name,
            rustix::fs::AtFlags::SYMLINK_NOFOLLOW,
        ))
    {
        return Err(Refusal("source_changed"));
    }
    let hash = Sha256::digest(&data)
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect();
    Ok(entry(
        Some(base64::engine::general_purpose::STANDARD.encode(&data)),
        Some(hash),
        None,
        Some(state),
    ))
}

/// Every inventory file, read twice: a change between the reads refuses.
fn collect_files(fd: &OwnedFd) -> Checked<Map<String, Value>> {
    let read = || -> Checked<Map<String, Value>> {
        FILES
            .iter()
            .map(|name| Ok(((*name).to_string(), read_file(fd, name)?)))
            .collect()
    };
    let files = read()?;
    if read()? != files {
        return Err(Refusal("source_changed"));
    }
    Ok(files)
}

fn device_inode(fd: &OwnedFd) -> Checked<(u64, u64)> {
    let stat = rustix::fs::fstat(fd).map_err(|_| Refusal("unreadable_source"))?;
    #[cfg(target_os = "macos")]
    let device = stat.st_dev as u64;
    #[cfg(not(target_os = "macos"))]
    let device = stat.st_dev;
    Ok((device, stat.st_ino))
}

/// The root to read, the selected release, the open root, and the identity of every directory on the way. A
/// production home reads its `current` release.
fn source(
    environment: &str,
    path: &str,
    application: &str,
) -> Checked<(String, Option<String>, OwnedFd, Vec<Value>)> {
    let fd = directory(path)?;
    let (home_device, home_inode) = device_inode(&fd)?;
    if environment == "development" {
        let root = format!("{path}{application}");
        let application_fd = directory(&root)?;
        let (device, inode) = device_inode(&application_fd)?;
        return Ok((
            root,
            None,
            application_fd,
            vec![
                json!(home_device),
                json!(home_inode),
                json!(device),
                json!(inode),
            ],
        ));
    }
    let pointer = stat_at(&fd, "current")?.ok_or(Refusal("source_unavailable"))?;
    if rustix::fs::FileType::from_raw_mode(pointer.st_mode) != rustix::fs::FileType::Symlink {
        return Err(Refusal("unsafe_source"));
    }
    let target = rustix::fs::readlinkat(&fd, "current", Vec::new())
        .map_err(|_| Refusal("unreadable_source"))?;
    let target = target.to_string_lossy().into_owned();
    let joined = if target.starts_with('/') {
        target.clone()
    } else {
        format!("{path}/{target}")
    };
    let lexical = normalize(&joined);
    let prefix = format!("{path}/releases/");
    let name = lexical
        .strip_prefix(&prefix)
        .unwrap_or_default()
        .to_string();
    let valid =
        lexical.starts_with(&prefix) && !name.contains('/') && super::production::valid_name(&name);
    if !valid {
        return Err(Refusal("unsafe_source"));
    }
    let release_fd = directory(&lexical)?;
    let (release_device, release_inode) = device_inode(&release_fd)?;
    let mut identity = vec![
        json!(home_device),
        json!(home_inode),
        signature(&pointer),
        json!(target),
        json!(release_device),
        json!(release_inode),
    ];
    let root = format!("{lexical}{application}");
    let application_fd = directory(&root)?;
    let (device, inode) = device_inode(&application_fd)?;
    identity.extend([json!(device), json!(inode)]);
    Ok((root, Some(name), application_fd, identity))
}

/// `os.path.normpath` of an absolute path.
fn normalize(path: &str) -> String {
    let mut parts: Vec<&str> = Vec::new();
    for part in path.split('/') {
        match part {
            "" | "." => {}
            ".." => {
                parts.pop();
            }
            part => parts.push(part),
        }
    }
    format!("/{}", parts.join("/"))
}

/// The sub-projects of a monorepo under `apps/` and `packages/`, or `None` when it has neither.
fn discover(root: &str, fd: &OwnedFd) -> Checked<Option<BTreeMap<String, Value>>> {
    use rustix::fs::{openat, Mode, OFlags};
    let flags = OFlags::RDONLY | OFlags::DIRECTORY | OFlags::NOFOLLOW | OFlags::CLOEXEC;
    let mut projects = BTreeMap::new();
    let mut enabled = false;
    for parent in ["apps", "packages"] {
        let Some(info) = stat_at(fd, parent)? else {
            continue;
        };
        enabled = true;
        if !is_dir(&info) {
            return Err(Refusal("unsafe_source"));
        }
        let parent_fd =
            openat(fd, parent, flags, Mode::empty()).map_err(|_| Refusal("unreadable_source"))?;
        let mut names = list(&parent_fd)?;
        names.sort();
        for name in names {
            if !stat_at(&parent_fd, &name)?.is_some_and(|info| is_dir(&info)) {
                continue;
            }
            let child_fd = openat(&parent_fd, name.as_str(), flags, Mode::empty())
                .map_err(|_| Refusal("unreadable_source"))?;
            let entries = list(&child_fd)?;
            let has = |file: &str| entries.iter().any(|entry| entry == file);
            let lock = [
                "package-lock.json",
                "npm-shrinkwrap.json",
                "pnpm-lock.yaml",
                "bun.lock",
            ]
            .iter()
            .any(|file| has(file));
            if !has("composer.json") && !(has("package.json") && lock) {
                continue;
            }
            let relative = format!("{parent}/{name}");
            let files = match collect_files(&child_fd) {
                Ok(files) => files,
                Err(Refusal(code)) => FILES
                    .iter()
                    .map(|file| {
                        (
                            (*file).to_string(),
                            entry(None, None, Some(&format!("dependencies.{code}")), None),
                        )
                    })
                    .collect(),
            };
            let (device, inode) = device_inode(&child_fd)?;
            projects.insert(
                relative.clone(),
                json!({"root": format!("{root}/{relative}"), "files": files, "source_identity": [device, inode]}),
            );
        }
    }
    Ok(enabled.then_some(projects))
}

fn list(fd: &OwnedFd) -> Checked<Vec<String>> {
    let directory = rustix::fs::Dir::read_from(fd).map_err(|_| Refusal("unreadable_source"))?;
    let mut names = Vec::new();
    for entry in directory {
        let entry = entry.map_err(|_| Refusal("unreadable_source"))?;
        let name = entry.file_name().to_string_lossy().into_owned();
        if name != "." && name != ".." {
            names.push(name);
        }
    }
    Ok(names)
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct FilesInput {
    pub path: String,
    /// The application directory below the source, such as `/apps/site`, or empty.
    #[serde(default)]
    pub application: String,
    /// Read each sub-project of a monorepo instead of one root.
    #[serde(default)]
    pub monorepo: bool,
}

/// Reads the dependency files of a source with one identity for the whole read: a change anywhere refuses. A
/// `development` source is a checkout; a `production` source is a home whose `current` release is read.
pub fn read_files(environment: &str, input: &FilesInput) -> Value {
    answer((|| {
        let application = input.application.as_str();
        let plain = application.strip_prefix('/').is_some_and(|rest| {
            rest.split('/')
                .all(|part| !part.is_empty() && part != "." && part != "..")
        });
        if !application.is_empty() && !plain {
            return Err(Refusal("unsafe_source"));
        }
        let empty = || -> Map<String, Value> {
            FILES
                .iter()
                .map(|name| ((*name).to_string(), entry(None, None, None, None)))
                .collect()
        };
        let mut selected = if input.monorepo { "" } else { application };
        let (mut root, mut reference, mut fd, mut identity) =
            source(environment, &input.path, selected)?;
        let repository = (root.clone(), reference.clone(), identity.clone());
        let projects = if input.monorepo {
            discover(&root, &fd)?
        } else {
            None
        };
        if input.monorepo && projects.is_none() && !application.is_empty() {
            (root, reference, fd, identity) = source(environment, &input.path, application)?;
            selected = application;
        }
        let mut files = if projects.is_none() {
            collect_files(&fd)?
        } else {
            empty()
        };
        if input.monorepo {
            let (next_root, next_reference, next_fd, next_identity) =
                source(environment, &input.path, "")?;
            if repository != (next_root.clone(), next_reference, next_identity)
                || projects != discover(&next_root, &next_fd)?
            {
                return Err(Refusal("source_changed"));
            }
        }
        let mut projects = projects;
        let size: i64 = std::iter::once(&files)
            .chain(projects.iter().flat_map(|projects| {
                projects
                    .values()
                    .filter_map(|project| project["files"].as_object())
            }))
            .flat_map(|files| files.values())
            .filter(|file| !file["hash"].is_null())
            .filter_map(|file| file["state"][3].as_i64())
            .sum();
        if size > 32 * 1024 * 1024 {
            return Err(Refusal("source_too_large"));
        }
        let (next_root, next_reference, _, next_identity) =
            source(environment, &input.path, selected)?;
        if (root.clone(), reference.clone(), identity.clone())
            != (next_root, next_reference, next_identity)
        {
            return Err(Refusal("source_changed"));
        }
        let digest: String =
            Sha256::digest(json!([identity, files, projects]).to_string().as_bytes())
                .iter()
                .map(|byte| format!("{byte:02x}"))
                .collect();
        let strip = |files: &mut Map<String, Value>| {
            for file in files.values_mut() {
                if let Some(file) = file.as_object_mut() {
                    file.remove("state");
                }
            }
        };
        strip(&mut files);
        let mut receipt =
            json!({"root": root, "reference": reference, "identity": digest, "files": files});
        if input.monorepo {
            if let Some(projects) = projects.as_mut() {
                for project in projects.values_mut() {
                    if let Some(files) = project["files"].as_object_mut() {
                        strip(files);
                    }
                    project["reference"] = json!(reference);
                    project["identity"] = json!(digest);
                }
            }
            receipt["sub_projects"] = json!(projects);
        }
        drop(fd);
        Ok(receipt)
    })())
}

pub struct ReadFiles;

impl Command for ReadFiles {
    const NAME: &'static str = "instance.read-dependency-files";
    const SUMMARY: &'static str =
        "Read the dependency manifests and lockfiles of a development checkout.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = FilesInput;
    type Output = Value;
    type Error = DependencyError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(input: FilesInput, _: &Context) -> Result<Value, DependencyError> {
        Ok(read_files("development", &input))
    }
}

pub struct ReadReleaseFiles;

impl Command for ReadReleaseFiles {
    const NAME: &'static str = "instance.read-release-dependency-files";
    const SUMMARY: &'static str =
        "Read the dependency manifests and lockfiles of a production home's current release.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = FilesInput;
    type Output = Value;
    type Error = DependencyError;

    /// Root reads a home that only its production user may enter; no project code runs.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn run(input: FilesInput, _: &Context) -> Result<Value, DependencyError> {
        Ok(read_files("production", &input))
    }
}

#[cfg(test)]
#[path = "dependencies_tests.rs"]
mod tests;
