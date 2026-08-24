use serde_json::Value;
use std::collections::{BTreeMap, BTreeSet, HashMap, HashSet};
use std::fs::{self, File, OpenOptions};
use std::io;
use std::path::{Path, PathBuf};
use std::process::{Command, Output, Stdio};
use std::time::Duration;

pub const KNOWN_KINDS: [&str; 8] = [
    "caddy",
    "gateway",
    "runtime",
    "app-runtime",
    "workspace-runtime",
    "process-runtime",
    "websocket-runtime",
    "s3-runtime",
];
const START_RETRY_ATTEMPTS: usize = 20;
const START_RETRY_INTERVAL: Duration = Duration::from_millis(250);
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct LegacyContainer {
    pub id: String,
    pub name: String,
    pub image: String,
    pub running: bool,
    pub inspect: String,
}
#[derive(Debug)]
pub enum CutoverError {
    Command(String),
    Invalid(String, String),
    TargetConflict(String),
    Rollback(String),
    Io(io::Error),
}
impl std::fmt::Display for CutoverError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::Command(x) => write!(f, "docker command failed: {x}"),
            Self::Invalid(n, x) => write!(f, "invalid container {n}: {x}"),
            Self::TargetConflict(x) => write!(f, "target name already exists: {x}"),
            Self::Rollback(x) => write!(f, "cutover rollback failed: {x}"),
            Self::Io(x) => write!(f, "io: {x}"),
        }
    }
}
impl std::error::Error for CutoverError {}
impl From<io::Error> for CutoverError {
    fn from(x: io::Error) -> Self {
        Self::Io(x)
    }
}
fn docker(program: &Path, endpoint: &str, args: &[String]) -> Result<Output, CutoverError> {
    let out = Command::new(program)
        .args(args)
        .env("DOCKER_HOST", endpoint)
        .env_remove("DOCKER_CONTEXT")
        .output()?;
    if out.status.success() {
        Ok(out)
    } else {
        Err(CutoverError::Command(
            String::from_utf8_lossy(&out.stderr).trim().into(),
        ))
    }
}
fn a(xs: &[&str]) -> Vec<String> {
    xs.iter().map(|x| (*x).into()).collect()
}
fn inspect(s: &str) -> Result<Value, CutoverError> {
    serde_json::from_str::<Vec<Value>>(s)
        .map_err(|e| CutoverError::Command(e.to_string()))?
        .into_iter()
        .next()
        .ok_or_else(|| CutoverError::Command("empty inspect".into()))
}
fn strings(v: Option<&Value>) -> Vec<String> {
    v.and_then(Value::as_array)
        .map(|a| {
            a.iter()
                .filter_map(Value::as_str)
                .map(str::to_owned)
                .collect()
        })
        .unwrap_or_default()
}
fn normalized_env(v: &Value) -> Result<Vec<(String, String)>, String> {
    let entries = match v.pointer("/Config/Env") {
        None | Some(Value::Null) => return Ok(Vec::new()),
        Some(Value::Array(entries)) => entries,
        Some(_) => return Err("Config.Env must be an array when present".to_owned()),
    };
    let mut normalized = Vec::with_capacity(entries.len());
    let mut names = HashSet::with_capacity(entries.len());
    for entry in entries {
        let entry = entry
            .as_str()
            .ok_or_else(|| "Config.Env contains a non-string entry".to_owned())?;
        let (name, value) = entry
            .split_once('=')
            .ok_or_else(|| "Config.Env contains an entry without '='".to_owned())?;
        if name.is_empty() {
            return Err("Config.Env contains an empty variable name".to_owned());
        }
        if !names.insert(name.to_owned()) {
            return Err(format!("Config.Env contains duplicate variable: {name}"));
        }
        normalized.push((name.to_owned(), value.to_owned()));
    }
    normalized.sort();
    Ok(normalized)
}
fn env_matches(actual: &Value, expected: &Value) -> bool {
    match (normalized_env(actual), normalized_env(expected)) {
        (Ok(actual), Ok(expected)) => actual == expected,
        _ => false,
    }
}
fn socket_is_owned(path: &Path) -> Result<(), CutoverError> {
    use std::os::unix::fs::{FileTypeExt, MetadataExt};
    if !path.is_absolute() {
        return Err(CutoverError::Invalid(
            path.display().to_string(),
            "socket path must be absolute".into(),
        ));
    }
    let mut cur = PathBuf::from("/");
    for c in path.components().skip(1) {
        cur.push(c);
        let m = fs::symlink_metadata(&cur).map_err(|e| {
            CutoverError::Invalid(path.display().to_string(), format!("socket path: {e}"))
        })?;
        if m.file_type().is_symlink() {
            return Err(CutoverError::Invalid(
                path.display().to_string(),
                "socket path contains symlink".into(),
            ));
        }
    }
    let m = fs::symlink_metadata(path)?;
    if !m.file_type().is_socket() {
        return Err(CutoverError::Invalid(
            path.display().to_string(),
            "must be a Unix socket".into(),
        ));
    }
    let p = fs::symlink_metadata(path.parent().unwrap_or(Path::new("/")))?;
    if m.uid() != p.uid() {
        return Err(CutoverError::Invalid(
            path.display().to_string(),
            "socket owner mismatch".into(),
        ));
    }
    Ok(())
}
pub fn discover(program: &Path, source: &str) -> Result<Vec<LegacyContainer>, CutoverError> {
    let out = docker(program, source, &a(&["ps", "-aq"]))?;
    let mut r = Vec::new();
    for id in String::from_utf8_lossy(&out.stdout)
        .lines()
        .map(str::trim)
        .filter(|x| !x.is_empty())
    {
        let raw = docker(program, source, &a(&["inspect", id]))?;
        let text = String::from_utf8_lossy(&raw.stdout).into_owned();
        let v = inspect(&text)?;
        let name = v
            .get("Name")
            .and_then(Value::as_str)
            .unwrap_or(id)
            .trim_start_matches('/')
            .to_owned();
        if !classify_inspect(&v, &name)? {
            continue;
        }
        let image = v
            .pointer("/Config/Image")
            .and_then(Value::as_str)
            .unwrap_or_default();
        if image.is_empty() {
            return Err(CutoverError::Invalid(name, "missing image".into()));
        }
        r.push(LegacyContainer {
            id: v.get("Id").and_then(Value::as_str).unwrap_or(id).into(),
            name,
            image: image.into(),
            running: v
                .pointer("/State/Running")
                .and_then(Value::as_bool)
                .unwrap_or(false),
            inspect: text,
        })
    }
    Ok(r)
}

fn classify_inspect(v: &Value, name: &str) -> Result<bool, CutoverError> {
    let labels = v.pointer("/Config/Labels").and_then(Value::as_object);
    if labels
        .and_then(|x| x.get("orbit.managed"))
        .and_then(Value::as_str)
        != Some("true")
    {
        return Ok(false);
    }
    let kind = labels
        .and_then(|x| x.get("orbit.container.kind"))
        .and_then(Value::as_str);
    if !kind.is_some_and(|k| KNOWN_KINDS.contains(&k)) {
        return Err(CutoverError::Invalid(
            name.into(),
            "unknown or missing orbit.container.kind".into(),
        ));
    }
    Ok(true)
}
fn mounts(v: &Value) -> Vec<&Value> {
    v.pointer("/Mounts")
        .and_then(Value::as_array)
        .map(|x| x.iter().collect())
        .unwrap_or_default()
}
fn mount_identity(m: &Value) -> Option<&str> {
    match m.get("Type").and_then(Value::as_str) {
        Some("volume") => m.get("Name").and_then(Value::as_str),
        Some("bind") => m.get("Source").and_then(Value::as_str),
        _ => None,
    }
}
fn normalized_mounts(v: &Value) -> Vec<(String, String, bool)> {
    let mut mounts = mounts(v)
        .into_iter()
        .filter_map(|m| {
            Some((
                m.get("Type")?.as_str()?.to_owned(),
                mount_identity(m)?.to_owned(),
                m.get("Destination")?.as_str()?.to_owned(),
                m.get("RW").and_then(Value::as_bool).unwrap_or(true),
            ))
        })
        .map(|(ty, source, destination, rw)| (format!("{ty}:{source}"), destination, rw))
        .collect::<Vec<_>>();
    mounts.sort();
    mounts
}
fn normalized_ports(v: &Value) -> Vec<(String, String, String)> {
    let mut ports = v
        .pointer("/HostConfig/PortBindings")
        .and_then(Value::as_object)
        .into_iter()
        .flat_map(|ports| ports.iter())
        .flat_map(|(container, bindings)| {
            bindings
                .as_array()
                .into_iter()
                .flatten()
                .map(move |binding| {
                    (
                        container.clone(),
                        binding
                            .get("HostIp")
                            .and_then(Value::as_str)
                            .unwrap_or_default()
                            .to_owned(),
                        binding
                            .get("HostPort")
                            .and_then(Value::as_str)
                            .unwrap_or_default()
                            .to_owned(),
                    )
                })
        })
        .collect::<Vec<_>>();
    ports.sort();
    ports
}
fn normalized_restart(v: &Value) -> (String, u64) {
    (
        v.pointer("/HostConfig/RestartPolicy/Name")
            .and_then(Value::as_str)
            .unwrap_or_default()
            .to_owned(),
        v.pointer("/HostConfig/RestartPolicy/MaximumRetryCount")
            .and_then(Value::as_u64)
            .unwrap_or_default(),
    )
}
fn effective_command(v: &Value) -> Vec<String> {
    let mut command = strings(v.pointer("/Config/Entrypoint"));
    command.extend(strings(v.pointer("/Config/Cmd")));
    command
}
fn cutover_fingerprint(v: &Value) -> String {
    let value = serde_json::json!({
        "mounts": normalized_mounts(v), "ports": normalized_ports(v),
        "restart": normalized_restart(v), "command": effective_command(v),
        "labels": v.pointer("/Config/Labels").cloned().unwrap_or(Value::Null),
        "env": v.pointer("/Config/Env").cloned().unwrap_or(Value::Null),
        "workdir": v.pointer("/Config/WorkingDir").cloned().unwrap_or(Value::Null),
        "user": v.pointer("/Config/User").cloned().unwrap_or(Value::Null),
        "extra_hosts": v.pointer("/HostConfig/ExtraHosts").cloned().unwrap_or(Value::Null),
    });
    crate::pending_update::sha256_hex(value.to_string().as_bytes())
}
fn normalized_aliases(v: &Value, c: &LegacyContainer) -> Vec<String> {
    let mut aliases = v
        .pointer("/NetworkSettings/Networks/orbit-network/Aliases")
        .and_then(Value::as_array)
        .into_iter()
        .flatten()
        .filter_map(Value::as_str)
        .filter(|alias| {
            *alias != c.name
                && *alias != c.id
                && !(alias.len() >= 12 && alias.chars().all(|ch| ch.is_ascii_hexdigit()))
        })
        .map(str::to_owned)
        .collect::<Vec<_>>();
    aliases.sort();
    aliases
}
fn validate_container(c: &LegacyContainer) -> Result<(), CutoverError> {
    let v = inspect(&c.inspect)?;
    normalized_env(&v).map_err(|reason| CutoverError::Invalid(c.name.clone(), reason))?;
    for m in mounts(&v) {
        match m.get("Type").and_then(Value::as_str) {
            Some("bind") => {
                if m.get("Source").and_then(Value::as_str).is_none()
                    || m.get("Destination").and_then(Value::as_str).is_none()
                {
                    return Err(CutoverError::Invalid(
                        c.name.clone(),
                        "bind mount lacks source or destination".into(),
                    ));
                }
            }
            Some("volume") => {
                if m.get("Name").and_then(Value::as_str).is_none()
                    || m.get("Destination").and_then(Value::as_str).is_none()
                {
                    return Err(CutoverError::Invalid(
                        c.name.clone(),
                        "named volume lacks name or destination".into(),
                    ));
                }
            }
            Some(x) => {
                return Err(CutoverError::Invalid(
                    c.name.clone(),
                    format!("unsupported mount type {x}"),
                ))
            }
            None => {
                return Err(CutoverError::Invalid(
                    c.name.clone(),
                    "mount lacks type".into(),
                ))
            }
        }
    }
    Ok(())
}
fn named_volumes(containers: &[LegacyContainer]) -> Result<Vec<String>, CutoverError> {
    let mut names = HashSet::new();
    for c in containers {
        let v = inspect(&c.inspect)?;
        for mount in mounts(&v) {
            if mount.get("Type").and_then(Value::as_str) == Some("volume") {
                let name = mount
                    .get("Name")
                    .and_then(Value::as_str)
                    .filter(|name| !name.is_empty())
                    .ok_or_else(|| {
                        CutoverError::Invalid(c.name.clone(), "named volume lacks name".into())
                    })?;
                names.insert(name.to_owned());
            }
        }
    }
    let mut names = names.into_iter().collect::<Vec<_>>();
    names.sort();
    Ok(names)
}
fn volume_args(volume: &str, read_only: bool) -> String {
    format!(
        "{volume}:/orbit-volume{}",
        if read_only { ":ro" } else { "" }
    )
}
fn tar_manifest(bytes: &[u8]) -> Result<Vec<u8>, CutoverError> {
    if bytes.len() < 1024 || !bytes.len().is_multiple_of(512) {
        return Err(CutoverError::Invalid(
            "volume".into(),
            "malformed tar stream".into(),
        ));
    }
    let mut offset = 0;
    #[derive(Clone)]
    struct Entry {
        kind: u8,
        metadata: Vec<u8>,
        payload: Vec<u8>,
        declared_size: u64,
        target: Option<Vec<u8>>,
    }
    let mut entries = BTreeMap::<Vec<u8>, Entry>::new();
    let mut root_seen = false;
    while offset + 512 <= bytes.len() {
        let header = &bytes[offset..offset + 512];
        if header.iter().all(|byte| *byte == 0) {
            if offset + 1024 > bytes.len() || bytes[offset + 512..].iter().any(|byte| *byte != 0) {
                return Err(CutoverError::Invalid(
                    "volume".into(),
                    "malformed tar trailer".into(),
                ));
            }
            return if root_seen {
                let mut output = Vec::new();
                let mut done = BTreeSet::new();
                for path in entries.keys() {
                    if done.contains(path) {
                        continue;
                    }
                    let mut members = BTreeSet::from([path.clone()]);
                    if entries[path]
                        .target
                        .as_ref()
                        .is_some_and(|target| !entries.contains_key(target))
                    {
                        return Err(CutoverError::Invalid(
                            "volume".into(),
                            "missing hardlink target".into(),
                        ));
                    }
                    let mut changed = true;
                    while changed {
                        changed = false;
                        for (name, entry) in &entries {
                            if entry.target.as_ref().is_some_and(|t| members.contains(t))
                                || members
                                    .iter()
                                    .any(|m| entries[m].target.as_ref() == Some(name))
                            {
                                changed |= members.insert(name.clone());
                            }
                        }
                    }
                    let anchors = members
                        .iter()
                        .filter(|p| entries[*p].kind == b'0')
                        .collect::<Vec<_>>();
                    if members.len() > 1 && anchors.len() != 1 {
                        return Err(CutoverError::Invalid(
                            "volume".into(),
                            "invalid hardlink component".into(),
                        ));
                    }
                    let anchor = anchors.first().copied();
                    for member in &members {
                        done.insert(member.clone());
                        output.extend_from_slice(&(member.len() as u64).to_le_bytes());
                        output.extend_from_slice(member);
                        let entry = &entries[member];
                        if let Some(anchor) = anchor {
                            let a = &entries[anchor];
                            output.push(b'H');
                            output.extend_from_slice(&(members.len() as u64).to_le_bytes());
                            for m in &members {
                                output.extend_from_slice(&(m.len() as u64).to_le_bytes());
                                output.extend_from_slice(m);
                            }
                            output.extend_from_slice(&a.metadata);
                            output.extend_from_slice(&a.declared_size.to_le_bytes());
                            output.extend_from_slice(&(a.payload.len() as u64).to_le_bytes());
                            output.extend_from_slice(&a.payload);
                        } else {
                            output.push(entry.kind);
                            output.extend_from_slice(&entry.metadata);
                            output.extend_from_slice(&entry.declared_size.to_le_bytes());
                            output.extend_from_slice(&(entry.payload.len() as u64).to_le_bytes());
                            output.extend_from_slice(&entry.payload);
                        }
                        output.extend_from_slice(&(0u64).to_le_bytes());
                    }
                }
                Ok(output)
            } else {
                Err(CutoverError::Invalid(
                    "volume".into(),
                    "missing root directory".into(),
                ))
            };
        }
        let stored = u64::from_str_radix(
            std::str::from_utf8(&header[148..156])
                .map_err(|_| CutoverError::Invalid("volume".into(), "invalid tar checksum".into()))?
                .trim()
                .trim_end_matches('\0'),
            8,
        )
        .map_err(|_| CutoverError::Invalid("volume".into(), "invalid tar checksum".into()))?;
        let checksum = header
            .iter()
            .enumerate()
            .map(|(i, byte)| {
                if (148..156).contains(&i) {
                    b' ' as u64
                } else {
                    *byte as u64
                }
            })
            .sum::<u64>();
        if stored != checksum {
            return Err(CutoverError::Invalid(
                "volume".into(),
                "invalid tar checksum".into(),
            ));
        }
        let size_text = std::str::from_utf8(&header[124..136])
            .map_err(|_| CutoverError::Invalid("volume".into(), "invalid tar size".into()))?
            .trim()
            .trim_end_matches('\0');
        let size = u64::from_str_radix(size_text, 8)
            .map_err(|_| CutoverError::Invalid("volume".into(), "invalid tar size".into()))?;
        let padded = size
            .checked_add(511)
            .and_then(|x| x.checked_div(512))
            .and_then(|x| x.checked_mul(512))
            .ok_or_else(|| CutoverError::Invalid("volume".into(), "tar size overflow".into()))?
            .try_into()
            .map_err(|_| CutoverError::Invalid("volume".into(), "tar size overflow".into()))?;
        let end = offset
            .checked_add(512)
            .and_then(|x| x.checked_add(padded))
            .ok_or_else(|| CutoverError::Invalid("volume".into(), "tar size overflow".into()))?;
        if end > bytes.len() {
            return Err(CutoverError::Invalid(
                "volume".into(),
                "truncated tar record".into(),
            ));
        }
        let name_end = header[0..100]
            .iter()
            .position(|byte| *byte == 0)
            .unwrap_or(100);
        let mut name = header[..name_end].to_vec();
        let prefix_end = header[345..500]
            .iter()
            .position(|byte| *byte == 0)
            .unwrap_or(155);
        if prefix_end > 0 {
            let prefix = &header[345..345 + prefix_end];
            name = [prefix, b"/", &name].concat();
        }
        let kind = match header[156] {
            0 => b'0',
            value => value,
        };
        let root = name == b"./" && kind == b'5';
        if root {
            if root_seen {
                return Err(CutoverError::Invalid(
                    "volume".into(),
                    "duplicate root directory".into(),
                ));
            }
            root_seen = true;
        } else {
            let mut canonical = name.strip_prefix(b"./").unwrap_or(&name);
            if canonical.is_empty() || canonical[0] == b'/' {
                return Err(CutoverError::Invalid(
                    "volume".into(),
                    "unsafe tar path".into(),
                ));
            }
            let trailing_slash = canonical.ends_with(b"/");
            if trailing_slash {
                canonical = &canonical[..canonical.len() - 1];
            }
            if canonical.is_empty()
                || canonical[0] == b'/'
                || canonical
                    .split(|byte| *byte == b'/')
                    .any(|part| part.is_empty() || part == b"." || part == b"..")
                || (trailing_slash && kind != b'5')
            {
                return Err(CutoverError::Invalid(
                    "volume".into(),
                    "unsafe tar path".into(),
                ));
            }
            if !matches!(kind, b'0' | b'1' | b'2' | b'5') {
                return Err(CutoverError::Invalid(
                    "volume".into(),
                    "unsupported tar entry".into(),
                ));
            }
            let mut metadata = Vec::new();
            let mut payload = Vec::new();
            let mut target = None;
            if kind == b'2' {
                // Symlink ownership, mode, and mtime are not portable across
                // archive extraction implementations. The target is semantic.
                metadata.extend_from_slice(&header[157..257]);
            } else {
                metadata.extend_from_slice(&header[100..124]);
                if kind != b'5' {
                    metadata.extend_from_slice(&header[136..148]);
                    if kind == b'1' {
                        let target_end = header[157..257]
                            .iter()
                            .position(|byte| *byte == 0)
                            .unwrap_or(100);
                        let link_target = header[157..157 + target_end]
                            .strip_prefix(b"./")
                            .unwrap_or(&header[157..157 + target_end]);
                        if link_target.is_empty()
                            || link_target[0] == b'/'
                            || link_target
                                .split(|byte| *byte == b'/')
                                .any(|part| part.is_empty() || part == b"." || part == b"..")
                        {
                            return Err(CutoverError::Invalid(
                                "volume".into(),
                                "unsafe tar path".into(),
                            ));
                        }
                        target = Some(link_target.to_vec());
                    } else {
                        metadata.extend_from_slice(&header[157..257]);
                    }
                    payload.extend_from_slice(&bytes[offset + 512..offset + 512 + size as usize]);
                }
            }
            let key = canonical.to_vec();
            if entries
                .insert(
                    key,
                    Entry {
                        kind,
                        metadata,
                        payload,
                        declared_size: size,
                        target,
                    },
                )
                .is_some()
            {
                return Err(CutoverError::Invalid(
                    "volume".into(),
                    "duplicate tar entry".into(),
                ));
            }
        }
        offset = end;
    }
    Err(CutoverError::Invalid(
        "volume".into(),
        "missing tar trailer".into(),
    ))
}
fn volume_manifest(program: &Path, endpoint: &str, volume: &str) -> Result<Vec<u8>, CutoverError> {
    Ok(docker(
        program,
        endpoint,
        &[
            "run".into(),
            "--rm".into(),
            "--volume".into(),
            volume_args(volume, true),
            "busybox:stable".into(),
            "tar".into(),
            "-C".into(),
            "/orbit-volume".into(),
            "-cf".into(),
            "-".into(),
            ".".into(),
        ],
    )?
    .stdout)
}
fn copy_named_volume(
    program: &Path,
    source: &str,
    target: &str,
    volume: &str,
    tmp: &Path,
) -> Result<(), CutoverError> {
    let archive = tmp.join(format!("volume-{}.tar", volume.replace('/', "_")));
    let mut export = Command::new(program)
        .args([
            "run",
            "--rm",
            "--volume",
            &volume_args(volume, true),
            "busybox:stable",
            "tar",
            "-C",
            "/orbit-volume",
            "-cf",
            "-",
            ".",
        ])
        .env("DOCKER_HOST", source)
        .env_remove("DOCKER_CONTEXT")
        .stdout(File::create(&archive)?)
        .spawn()?;
    if !export.wait()?.success() {
        return Err(CutoverError::Command(format!(
            "named volume export failed: {volume}"
        )));
    }
    let source_hash = crate::pending_update::file_sha256(&archive)
        .map_err(|e| CutoverError::Command(e.to_string()))?;
    docker(
        program,
        target,
        &[
            "volume".into(),
            "create".into(),
            "--label".into(),
            format!("orbit.cutover.volume_sha256={source_hash}"),
            volume.into(),
        ],
    )?;
    let mut import = Command::new(program)
        .args([
            "run",
            "--rm",
            "--interactive",
            "--volume",
            &volume_args(volume, false),
            "busybox:stable",
            "tar",
            "-C",
            "/orbit-volume",
            "-xf",
            "-",
        ])
        .env("DOCKER_HOST", target)
        .env_remove("DOCKER_CONTEXT")
        .stdin(File::open(&archive)?)
        .stdout(Stdio::null())
        .spawn()?;
    if !import.wait()?.success() {
        return Err(CutoverError::Command(format!(
            "named volume import failed: {volume}"
        )));
    }
    let target_archive = tmp.join(format!("target-volume-{}.tar", volume.replace('/', "_")));
    let mut verify = Command::new(program)
        .args([
            "run",
            "--rm",
            "--volume",
            &volume_args(volume, true),
            "busybox:stable",
            "tar",
            "-C",
            "/orbit-volume",
            "-cf",
            "-",
            ".",
        ])
        .env("DOCKER_HOST", target)
        .env_remove("DOCKER_CONTEXT")
        .stdout(File::create(&target_archive)?)
        .spawn()?;
    if !verify.wait()?.success() {
        return Err(CutoverError::Command(format!(
            "named volume verification failed: {volume}"
        )));
    }
    let source_manifest = tar_manifest(&fs::read(&archive)?)?;
    let target_manifest = tar_manifest(&fs::read(&target_archive)?)?;
    if source_manifest != target_manifest {
        return Err(CutoverError::Invalid(
            volume.into(),
            "named volume byte manifest mismatch".into(),
        ));
    }
    Ok(())
}
pub fn preflight(
    program: &Path,
    source: &str,
    target: &str,
    containers: &[LegacyContainer],
) -> Result<(), CutoverError> {
    docker(program, source, &a(&["info"]))?;
    docker(program, target, &a(&["info"]))?;
    let out = docker(program, target, &a(&["ps", "-aq"]))?;
    let mut names = HashSet::new();
    for id in String::from_utf8_lossy(&out.stdout)
        .lines()
        .map(str::trim)
        .filter(|x| !x.is_empty())
    {
        let v = inspect(&String::from_utf8_lossy(
            &docker(program, target, &a(&["inspect", id]))?.stdout,
        ))?;
        if let Some(n) = v.get("Name").and_then(Value::as_str) {
            names.insert(n.trim_start_matches('/').to_owned());
        }
    }
    for c in containers {
        validate_container(c)?;
        if names.contains(&c.name) {
            let actual = inspect(&String::from_utf8_lossy(
                &docker(program, target, &a(&["inspect", &c.name]))?.stdout,
            ))?;
            let expected = inspect(&c.inspect)?;
            let fingerprint = actual
                .pointer("/Config/Labels/orbit.cutover.fingerprint")
                .and_then(Value::as_str);
            if fingerprint != Some(cutover_fingerprint(&expected).as_str())
                || normalized_mounts(&actual) != normalized_mounts(&expected)
                || normalized_ports(&actual) != normalized_ports(&expected)
                || normalized_restart(&actual) != normalized_restart(&expected)
                || effective_command(&actual) != effective_command(&expected)
            {
                return Err(CutoverError::TargetConflict(c.name.clone()));
            }
        }
    }
    if !containers.is_empty() {
        let net = docker(
            program,
            source,
            &a(&["network", "inspect", "orbit-network"]),
        )?;
        if inspect(&String::from_utf8_lossy(&net.stdout))?
            .get("Name")
            .and_then(Value::as_str)
            != Some("orbit-network")
        {
            return Err(CutoverError::Invalid(
                "orbit-network".into(),
                "source network identity mismatch".into(),
            ));
        }
        let target_net = docker(
            program,
            target,
            &a(&["network", "inspect", "orbit-network"]),
        );
        if let Ok(target_net) = target_net {
            let target_net = inspect(&String::from_utf8_lossy(&target_net.stdout))?;
            let labels = target_net.pointer("/Labels").and_then(Value::as_object);
            if labels
                .and_then(|x| x.get("orbit.managed"))
                .and_then(Value::as_str)
                != Some("true")
                || labels
                    .and_then(|x| x.get("orbit.network.kind"))
                    .and_then(Value::as_str)
                    != Some("runtime")
            {
                return Err(CutoverError::Invalid(
                    "orbit-network".into(),
                    "target network is not Orbit-managed runtime network".into(),
                ));
            }
        }
    }
    Ok(())
}
fn create_args_with_image(c: &LegacyContainer, image: &str) -> Result<Vec<String>, CutoverError> {
    let v = inspect(&c.inspect)?;
    let cfg = v
        .get("Config")
        .ok_or_else(|| CutoverError::Invalid(c.name.clone(), "missing config".into()))?;
    let host = v.get("HostConfig").unwrap_or(&Value::Null);
    let mut x = vec![
        "create".into(),
        "--name".into(),
        c.name.clone(),
        "--network".into(),
        "orbit-network".into(),
    ];
    if let Some(m) = cfg.get("Labels").and_then(Value::as_object) {
        for (k, v) in m {
            if let Some(v) = v.as_str() {
                x.extend(["--label".into(), format!("{k}={v}")])
            }
        }
    }
    x.extend([
        "--label".into(),
        format!("orbit.cutover.fingerprint={}", cutover_fingerprint(&v)),
    ]);
    for e in strings(cfg.get("Env")) {
        x.extend(["--env".into(), e])
    }
    for (flag, key) in [("--workdir", "WorkingDir"), ("--user", "User")] {
        if let Some(s) = cfg
            .get(key)
            .and_then(Value::as_str)
            .filter(|s| !s.is_empty())
        {
            x.extend([flag.into(), s.into()])
        }
    }
    let ep = strings(cfg.get("Entrypoint"));
    if let Some(first) = ep.first() {
        x.extend(["--entrypoint".into(), first.clone()])
    }
    let mut cmd = strings(cfg.get("Cmd"));
    if ep.len() > 1 {
        cmd.splice(0..0, ep[1..].iter().cloned());
    }
    if let Some(r) = host
        .pointer("/RestartPolicy/Name")
        .and_then(Value::as_str)
        .filter(|s| !s.is_empty())
    {
        x.extend([
            "--restart".into(),
            if r == "on-failure" {
                format!(
                    "on-failure:{}",
                    host.pointer("/RestartPolicy/MaximumRetryCount")
                        .and_then(Value::as_u64)
                        .unwrap_or(0)
                )
            } else {
                r.into()
            },
        ])
    }
    for m in mounts(&v) {
        let source = mount_identity(m).unwrap_or("");
        let dest = m.get("Destination").and_then(Value::as_str).unwrap_or("");
        let mode = if m.get("RW").and_then(Value::as_bool) == Some(false) {
            ":ro"
        } else {
            ""
        };
        x.extend(["--volume".into(), format!("{source}:{dest}{mode}")])
    }
    if let Some(p) = host.get("PortBindings").and_then(Value::as_object) {
        for (container, bs) in p {
            for b in bs.as_array().into_iter().flatten() {
                let ip = b.get("HostIp").and_then(Value::as_str).unwrap_or("");
                let hp = b.get("HostPort").and_then(Value::as_str).unwrap_or("");
                let published = if ip.is_empty() {
                    format!("{hp}:{container}")
                } else {
                    format!("{ip}:{hp}:{container}")
                };
                x.extend(["--publish".into(), published])
            }
        }
    }
    if let Some(h) = host.get("ExtraHosts").and_then(Value::as_array) {
        for h in h.iter().filter_map(Value::as_str) {
            x.extend(["--add-host".into(), h.into()])
        }
    }
    if let Some(n) = v
        .pointer("/NetworkSettings/Networks/orbit-network/Aliases")
        .and_then(Value::as_array)
    {
        for alias in n.iter().filter_map(Value::as_str).filter(|alias| {
            *alias != c.name
                && *alias != c.id
                && !(alias.len() >= 12 && alias.chars().all(|ch| ch.is_ascii_hexdigit()))
        }) {
            x.extend(["--network-alias".into(), alias.into()])
        }
    }
    x.push(image.into());
    x.extend(cmd);
    Ok(x)
}
#[cfg(test)]
fn create_args(c: &LegacyContainer) -> Result<Vec<String>, CutoverError> {
    create_args_with_image(c, &c.image)
}
fn exact_retry_counterpart(
    program: &Path,
    target: &str,
    c: &LegacyContainer,
) -> Result<bool, CutoverError> {
    let actual = match docker(program, target, &a(&["inspect", &c.name])) {
        Ok(out) => inspect(&String::from_utf8_lossy(&out.stdout))?,
        Err(_) => return Ok(false),
    };
    let expected = inspect(&c.inspect)?;
    let mut actual_labels = actual
        .pointer("/Config/Labels")
        .cloned()
        .unwrap_or(Value::Null);
    let mut expected_labels = expected
        .pointer("/Config/Labels")
        .cloned()
        .unwrap_or(Value::Null);
    if let Some(labels) = actual_labels.as_object_mut() {
        labels.remove("orbit.cutover.fingerprint");
    }
    if let Some(labels) = expected_labels.as_object_mut() {
        labels.remove("orbit.cutover.fingerprint");
    }
    Ok(actual
        .pointer("/Config/Labels/orbit.cutover.fingerprint")
        .and_then(Value::as_str)
        == Some(cutover_fingerprint(&expected).as_str())
        && actual_labels == expected_labels
        && env_matches(&actual, &expected)
        && actual.pointer("/Config/WorkingDir") == expected.pointer("/Config/WorkingDir")
        && actual.pointer("/Config/User") == expected.pointer("/Config/User")
        && actual.pointer("/HostConfig/ExtraHosts") == expected.pointer("/HostConfig/ExtraHosts")
        && normalized_mounts(&actual) == normalized_mounts(&expected)
        && normalized_ports(&actual) == normalized_ports(&expected)
        && normalized_restart(&actual) == normalized_restart(&expected)
        && normalized_aliases(&actual, c) == normalized_aliases(&expected, c)
        && effective_command(&actual) == effective_command(&expected))
}
fn retry_volume_matches(program: &Path, target: &str, source: &str, volume: &str) -> bool {
    let source = volume_manifest(program, source, volume)
        .and_then(|bytes| tar_manifest(&bytes))
        .ok();
    let target = volume_manifest(program, target, volume)
        .and_then(|bytes| tar_manifest(&bytes))
        .ok();
    matches!((source, target), (Some(source), Some(target)) if source == target)
}
fn save_image(program: &Path, source: &str, image: &str, path: &Path) -> Result<(), CutoverError> {
    let mut child = Command::new(program)
        .args(["save", image])
        .env("DOCKER_HOST", source)
        .env_remove("DOCKER_CONTEXT")
        .stdout(File::create(path)?)
        .spawn()?;
    if !child.wait()?.success() {
        return Err(CutoverError::Command("docker save failed".into()));
    }
    Ok(())
}

fn normalized_architecture(value: &str) -> Option<&'static str> {
    match value.trim().to_ascii_lowercase().as_str() {
        "amd64" | "x86_64" | "x86-64" => Some("amd64"),
        "arm64" | "aarch64" => Some("arm64"),
        "arm" | "arm32" | "armhf" => Some("arm"),
        "386" | "i386" | "i686" => Some("386"),
        "ppc64le" => Some("ppc64le"),
        "s390x" => Some("s390x"),
        _ => None,
    }
}

fn daemon_architecture(program: &Path, endpoint: &str) -> Result<&'static str, CutoverError> {
    let out = docker(
        program,
        endpoint,
        &a(&["info", "--format", "{{.Architecture}}"]),
    )?;
    let architecture = String::from_utf8_lossy(&out.stdout);
    if architecture.trim().is_empty() {
        return Ok("amd64");
    }
    normalized_architecture(&architecture).ok_or_else(|| {
        CutoverError::Invalid(
            endpoint.into(),
            "unsupported or missing daemon architecture".into(),
        )
    })
}

fn image_architecture(
    program: &Path,
    endpoint: &str,
    image: &str,
) -> Result<&'static str, CutoverError> {
    let out = docker(
        program,
        endpoint,
        &a(&["image", "inspect", "--format", "{{.Architecture}}", image]),
    )?;
    let architecture = String::from_utf8_lossy(&out.stdout);
    if architecture.trim().is_empty() {
        return Ok("amd64");
    }
    normalized_architecture(&architecture).ok_or_else(|| {
        CutoverError::Invalid(
            image.into(),
            "unsupported or missing image architecture".into(),
        )
    })
}

fn portable_tagged_reference(image: &str) -> bool {
    if image.is_empty() || image.starts_with("sha256:") || image.contains('@') {
        return false;
    }
    let last = image.rsplit('/').next().unwrap_or_default();
    last.rsplit_once(':')
        .is_some_and(|(_, tag)| !tag.is_empty())
}

fn target_image_reference(
    program: &Path,
    source: &str,
    target: &str,
    image: &str,
    target_arch: &'static str,
) -> Result<Option<String>, CutoverError> {
    let source_arch = image_architecture(program, source, image)?;
    if source_arch == target_arch {
        return Ok(None);
    }
    if !portable_tagged_reference(image) {
        return Err(CutoverError::Invalid(
            image.into(),
            "cross-architecture migration requires a portable tagged registry reference".into(),
        ));
    }
    docker(
        program,
        target,
        &a(&["pull", "--platform", &format!("linux/{target_arch}"), image]),
    )?;
    let target_image_arch = image_architecture(program, target, image)?;
    if target_image_arch != target_arch {
        return Err(CutoverError::Invalid(
            image.into(),
            format!(
                "target image architecture {target_image_arch} does not match target {target_arch}"
            ),
        ));
    }
    Ok(Some(image.into()))
}
fn start_and_verify_running<F>(
    program: &Path,
    target: &str,
    c: &LegacyContainer,
    mut sleep: F,
) -> Result<(), CutoverError>
where
    F: FnMut(Duration),
{
    for attempt in 1..=START_RETRY_ATTEMPTS {
        let started = docker(program, target, &a(&["start", &c.name])).is_ok();
        if started {
            let running = docker(program, target, &a(&["inspect", &c.name]))
                .ok()
                .and_then(|out| inspect(&String::from_utf8_lossy(&out.stdout)).ok())
                .and_then(|actual| actual.pointer("/State/Running").cloned())
                == Some(Value::Bool(true));
            if running {
                return Ok(());
            }
        }
        if attempt < START_RETRY_ATTEMPTS {
            sleep(START_RETRY_INTERVAL);
        }
    }
    Err(CutoverError::Invalid(
        c.name.clone(),
        "target failed to start".into(),
    ))
}
pub fn cutover(
    program: &Path,
    source: &str,
    target: &str,
) -> Result<Vec<LegacyContainer>, CutoverError> {
    cutover_with_sleep(program, source, target, std::thread::sleep)
}

fn cutover_with_sleep<F>(
    program: &Path,
    source: &str,
    target: &str,
    mut sleep: F,
) -> Result<Vec<LegacyContainer>, CutoverError>
where
    F: FnMut(Duration),
{
    let path = source.strip_prefix("unix://").ok_or_else(|| {
        CutoverError::Invalid(source.into(), "source endpoint must be Unix socket".into())
    })?;
    socket_is_owned(Path::new(path))?;
    let cs = discover(program, source)?;
    preflight(program, source, target, &cs)?;
    if cs.is_empty() {
        return Ok(cs);
    }
    let volumes = named_volumes(&cs)?;
    let cleanup_retry = cs
        .iter()
        .all(|c| exact_retry_counterpart(program, target, c).unwrap_or(false))
        && volumes
            .iter()
            .all(|v| retry_volume_matches(program, target, source, v));
    if cleanup_retry {
        for c in &cs {
            docker(program, source, &a(&["rm", &c.name]))?;
        }
        return Ok(cs);
    }
    if volumes.iter().any(|volume| {
        docker(program, target, &a(&["volume", "inspect", volume])).is_ok()
            && !retry_volume_matches(program, target, source, volume)
    }) {
        return Err(CutoverError::TargetConflict(
            "named volume retry counterpart mismatch".into(),
        ));
    }
    let mut tmp = std::env::temp_dir();
    tmp.push(format!(
        "orbit-cutover-{}-{}",
        std::process::id(),
        std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap_or_default()
            .as_nanos()
    ));
    OpenOptions::new().write(true).create_new(true).open(&tmp)?;
    fs::remove_file(&tmp)?;
    fs::create_dir(&tmp)?;
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        fs::set_permissions(&tmp, fs::Permissions::from_mode(0o700))?
    }
    let mut created_network = false;
    let mut created_volumes = Vec::new();
    let mut verified = false;
    let result = (|| {
        let volumes = volumes.clone();
        // A target volume is a conflict unless it is an exact retry counterpart.
        // The normal path creates each volume below; an existing target is never
        // silently overwritten.
        for volume in &volumes {
            if docker(program, target, &a(&["volume", "inspect", volume])).is_ok() {
                return Err(CutoverError::TargetConflict(format!("volume {volume}")));
            }
        }
        // Verify the helper image and argv contract while both daemons are
        // still untouched. This also prevents an implicit context/pull path.
        for endpoint in [source, target] {
            if docker(
                program,
                endpoint,
                &a(&["image", "inspect", "busybox:stable"]),
            )
            .is_err()
            {
                docker(program, endpoint, &a(&["pull", "busybox:stable"]))?;
                docker(
                    program,
                    endpoint,
                    &a(&["image", "inspect", "busybox:stable"]),
                )?;
            }
        }
        let target_arch = daemon_architecture(program, target)?;
        let mut images = HashMap::new();
        for c in &cs {
            if !images.contains_key(&c.image) {
                let reference =
                    target_image_reference(program, source, target, &c.image, target_arch)?;
                let image = if reference.is_some() {
                    None
                } else {
                    let p = tmp.join(format!("image-{}.tar", images.len()));
                    save_image(program, source, &c.image, &p)?;
                    Some(p)
                };
                images.insert(c.image.clone(), (image, reference));
            }
        }
        let net = docker(
            program,
            target,
            &a(&["network", "inspect", "orbit-network"]),
        );
        if net.is_err() {
            docker(
                program,
                target,
                &a(&[
                    "network",
                    "create",
                    "--label",
                    "orbit.managed=true",
                    "--label",
                    "orbit.network.kind=runtime",
                    "orbit-network",
                ]),
            )?;
            created_network = true;
        } else if let Ok(net) = net {
            if inspect(&String::from_utf8_lossy(&net.stdout))?
                .get("Name")
                .and_then(Value::as_str)
                != Some("orbit-network")
            {
                return Err(CutoverError::Invalid(
                    "orbit-network".into(),
                    "target network identity mismatch".into(),
                ));
            }
        }
        for c in &cs {
            if c.running {
                docker(program, source, &a(&["stop", &c.name]))?;
            }
        }
        for volume in &volumes {
            created_volumes.push(volume.clone());
            copy_named_volume(program, source, target, volume, &tmp)?;
        }
        for c in &cs {
            let (archive, reference) = images.get(&c.image).unwrap();
            let image = if let Some(reference) = reference {
                reference.as_str()
            } else {
                let mut load = Command::new(program)
                    .args(["load"])
                    .env("DOCKER_HOST", target)
                    .env_remove("DOCKER_CONTEXT")
                    .stdin(File::open(archive.as_ref().unwrap())?)
                    .stdout(Stdio::null())
                    .spawn()?;
                if !load.wait()?.success() {
                    return Err(CutoverError::Command("docker load failed".into()));
                }
                &c.image
            };
            docker(program, target, &create_args_with_image(c, image)?)?;
        }
        for c in &cs {
            let actual = inspect(&String::from_utf8_lossy(
                &docker(program, target, &a(&["inspect", &c.name]))?.stdout,
            ))?;
            let expected = inspect(&c.inspect)?;
            let scalar_equal = [
                "/Config/WorkingDir",
                "/Config/User",
                "/HostConfig/ExtraHosts",
            ]
            .iter()
            .all(|path| actual.pointer(path) == expected.pointer(path));
            let env_equal = env_matches(&actual, &expected);
            let mut actual_labels = actual
                .pointer("/Config/Labels")
                .cloned()
                .unwrap_or(Value::Null);
            let mut expected_labels = expected
                .pointer("/Config/Labels")
                .cloned()
                .unwrap_or(Value::Null);
            if let Some(labels) = actual_labels.as_object_mut() {
                labels.remove("orbit.cutover.fingerprint");
            }
            if let Some(labels) = expected_labels.as_object_mut() {
                labels.remove("orbit.cutover.fingerprint");
            }
            if !env_equal
                || !scalar_equal
                || actual_labels != expected_labels
                || normalized_mounts(&actual) != normalized_mounts(&expected)
                || normalized_ports(&actual) != normalized_ports(&expected)
                || normalized_restart(&actual) != normalized_restart(&expected)
                || normalized_aliases(&actual, c) != normalized_aliases(&expected, c)
                || effective_command(&actual) != effective_command(&expected)
            {
                return Err(CutoverError::Invalid(
                    c.name.clone(),
                    "target configuration or state mismatch".into(),
                ));
            }
        }
        for c in &cs {
            if c.running {
                start_and_verify_running(program, target, c, &mut sleep)?;
            }
        }
        verified = true;
        for c in &cs {
            docker(program, source, &a(&["rm", &c.name]))?;
        }
        Ok(())
    })();
    let _ = fs::remove_dir_all(&tmp);
    if let Err(e) = result {
        if verified {
            let _ = fs::remove_dir_all(&tmp);
            return Err(e);
        }
        for c in &cs {
            let _ = docker(program, target, &a(&["rm", "-f", &c.name]));
            if c.running {
                let _ = docker(program, source, &a(&["start", &c.name]));
            }
        }
        if created_network {
            let _ = docker(program, target, &a(&["network", "rm", "orbit-network"]));
        }
        for volume in &created_volumes {
            let _ = docker(program, target, &a(&["volume", "rm", volume]));
        }
        return Err(e);
    }
    Ok(cs)
}
#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;
    static CUTOVER_TEST_LOCK: std::sync::Mutex<()> = std::sync::Mutex::new(());

    fn caddy_fixture() -> LegacyContainer {
        let mut v = json!({"Id":"caddy-id","Name":"/orbit-caddy","State":{"Running":true},"Config":{"Image":"caddy:2-alpine","Labels":{"orbit.managed":"true","orbit.container.kind":"caddy","orbit.caddy.spec_hash":"sha256:mini-caddy"},"Env":["PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin","CADDY_VERSION=2.10.0","XDG_CONFIG_HOME=/config","XDG_DATA_HOME=/data"],"Entrypoint":null,"Cmd":["caddy","run","--config","/etc/caddy/Caddyfile","--adapter","caddyfile"]},"HostConfig":{"RestartPolicy":{"Name":"always","MaximumRetryCount":0},"PortBindings":{"80/tcp":[{"HostIp":"","HostPort":"80"}],"443/tcp":[{"HostIp":"","HostPort":"443"}],"443/udp":[{"HostIp":"","HostPort":"443"}],"8081/tcp":[{"HostIp":"10.6.0.8","HostPort":"8081"}]},"ExtraHosts":["host.docker.internal:host-gateway"]},"Mounts":[],"NetworkSettings":{"Networks":{"orbit-network":{"Aliases":["orbit-caddy","caddy-id","caddy"]}}},"Config2":{"WorkingDir":""}});
        v["Mounts"] = json!([
            {"Type":"bind","Source":"/Users","Destination":"/Users","RW":false},
            {"Type":"bind","Source":"/Users/nckrtl/.local/share/orbit/caddy/config","Destination":"/config/caddy","RW":true},
            {"Type":"bind","Source":"/Users/nckrtl/.local/share/orbit/caddy/data","Destination":"/data/caddy","RW":true},
            {"Type":"bind","Source":"/Users/nckrtl/.config/orbit/caddy/Caddyfile","Destination":"/etc/caddy/Caddyfile","RW":false},
            {"Type":"bind","Source":"/Users/nckrtl/.config/orbit/caddy/orbit","Destination":"/etc/caddy/orbit","RW":false},
            {"Type":"bind","Source":"/Users/nckrtl/.config/orbit/caddy/sites","Destination":"/etc/caddy/sites","RW":false},
            {"Type":"bind","Source":"/Users/nckrtl/.config/orbit","Destination":"/etc/orbit","RW":false}
        ]);
        v["Config"]["Cmd"] = json!([
            "caddy",
            "run",
            "--config",
            "/etc/caddy/Caddyfile",
            "--adapter",
            "caddyfile"
        ]);
        LegacyContainer {
            id: "caddy-id".into(),
            name: "orbit-caddy".into(),
            image: "caddy:2-alpine".into(),
            running: true,
            inspect: json!([v]).to_string(),
        }
    }

    fn runtime_fixture() -> LegacyContainer {
        let mut v = json!({"Id":"runtime-id","Name":"/orbit-runtime","State":{"Running":false},"Config":{"Image":"orbit-runtime:current","Labels":{"orbit.managed":"true","orbit.container.kind":"runtime"},"Env":["ORBIT_HOST_PATH=/Users/nckrtl/orbit","ORBIT_SOURCE_PATH=/Users/nckrtl/orbit/src"],"Entrypoint":[],"Cmd":["sleep","infinity"]},"HostConfig":{"RestartPolicy":{"Name":"unless-stopped","MaximumRetryCount":0}},"Mounts":[],"NetworkSettings":{"Networks":{"orbit-network":{"Aliases":["orbit-runtime","runtime-id","runtime"]}}}});
        v["Mounts"] = json!([
            {"Type":"bind","Source":"/etc/caddy","Destination":"/etc/caddy","RW":true},
            {"Type":"bind","Source":"/etc/orbit","Destination":"/etc/orbit","RW":true},
            {"Type":"bind","Source":"/Users/nckrtl/orbit","Destination":"/opt/orbit","RW":true},
            {"Type":"bind","Source":"/var/run/docker.sock","Destination":"/var/run/docker.sock","RW":true}
        ]);
        v["Config"]["Entrypoint"] = json!([
            "/usr/bin/tini",
            "--",
            "/usr/local/bin/orbit-runtime-entrypoint"
        ]);
        v["Config"]["WorkingDir"] = json!("/opt/orbit");
        LegacyContainer {
            id: "runtime-id".into(),
            name: "orbit-runtime".into(),
            image: "orbit-runtime:current".into(),
            running: false,
            inspect: json!([v]).to_string(),
        }
    }

    #[test]
    fn exact_live_and_legacy_fixtures_preserve_create_and_semantics() {
        let caddy = caddy_fixture();
        let args = create_args(&caddy).unwrap();
        assert!(args.windows(2).any(|w| w == ["--publish", "80:80/tcp"]));
        assert!(args
            .windows(2)
            .any(|w| w == ["--publish", "10.6.0.8:8081:8081/tcp"]));
        assert!(!args.iter().any(|x| x.starts_with(':')));
        assert_eq!(
            normalized_mounts(&inspect(&caddy.inspect).unwrap()).len(),
            7
        );
        assert_eq!(
            effective_command(&inspect(&caddy.inspect).unwrap()),
            vec![
                "caddy",
                "run",
                "--config",
                "/etc/caddy/Caddyfile",
                "--adapter",
                "caddyfile"
            ]
        );
        let runtime = runtime_fixture();
        let args = create_args(&runtime).unwrap();
        assert!(args
            .windows(2)
            .any(|w| w == ["--restart", "unless-stopped"]));
        assert_eq!(
            effective_command(&inspect(&runtime.inspect).unwrap()),
            vec![
                "/usr/bin/tini",
                "--",
                "/usr/local/bin/orbit-runtime-entrypoint",
                "sleep",
                "infinity",
            ]
        );
        assert_eq!(
            normalized_mounts(&inspect(&runtime.inspect).unwrap()).len(),
            4
        );
        let source = inspect(&caddy_fixture().inspect).unwrap();
        let mut target = source.clone();
        target["Id"] = json!("target-daemon-id");
        target["Name"] = json!("/orbit-caddy");
        target["State"]["Running"] = json!(true);
        target["NetworkSettings"]["Networks"]["orbit-network"]["Aliases"] =
            json!(["orbit-caddy", "target-daemon-id", "caddy"]);
        assert_eq!(normalized_mounts(&source), normalized_mounts(&target));
        assert_eq!(normalized_ports(&source), normalized_ports(&target));
        assert_eq!(normalized_restart(&source), normalized_restart(&target));
        assert_eq!(effective_command(&source), effective_command(&target));
        let mut target_container = caddy.clone();
        target_container.id = "target-daemon-id".into();
        assert_eq!(
            normalized_aliases(&source, &caddy),
            normalized_aliases(&target, &target_container)
        );
        assert_eq!(source["Config"]["Labels"], target["Config"]["Labels"]);
        assert_eq!(source["State"], target["State"]);
    }

    #[test]
    fn classification_accepts_known_kinds_and_rejects_owned_unknowns() {
        for kind in KNOWN_KINDS {
            assert!(classify_inspect(
                &json!({"Config":{"Labels":{"orbit.managed":"true","orbit.container.kind":kind}}}),
                "x"
            )
            .unwrap());
        }
        assert!(
            !classify_inspect(&json!({"Config":{"Labels":{"orbit.managed":"false"}}}), "x")
                .unwrap()
        );
        assert!(classify_inspect(
            &json!({"Config":{"Labels":{"orbit.managed":"true","orbit.container.kind":"unknown"}}}),
            "x"
        )
        .is_err());
        assert!(
            classify_inspect(&json!({"Config":{"Labels":{"orbit.managed":"true"}}}), "x").is_err()
        );
    }

    #[test]
    fn environment_semantics_are_order_independent_and_preserve_full_values() {
        let source = json!({"Config":{"Env":["ALPHA= value\twith=equals", "BETA="]}});
        let mut target = source.clone();
        target["Config"]["Env"] = json!(["BETA=", "ALPHA= value\twith=equals"]);
        assert_eq!(
            normalized_env(&source).unwrap(),
            normalized_env(&target).unwrap()
        );
        assert!(env_matches(&target, &source));
    }

    #[test]
    fn missing_null_and_empty_environments_are_equivalent() {
        for source in [
            json!({}),
            json!({"Config":{}}),
            json!({"Config":{"Env":null}}),
            json!({"Config":{"Env":[]}}),
        ] {
            for target in [
                json!({}),
                json!({"Config":{}}),
                json!({"Config":{"Env":null}}),
                json!({"Config":{"Env":[]}}),
            ] {
                assert_eq!(
                    normalized_env(&source).unwrap(),
                    Vec::<(String, String)>::new()
                );
                assert_eq!(
                    normalized_env(&target).unwrap(),
                    Vec::<(String, String)>::new()
                );
                assert!(env_matches(&source, &target));
            }
        }
    }

    #[test]
    fn malformed_environment_never_matches_and_source_validation_rejects_it() {
        for env in [
            json!(true),
            json!([false]),
            json!(["NO_SEPARATOR"]),
            json!(["=empty-name"]),
            json!(["DUPLICATE=one", "DUPLICATE=two"]),
        ] {
            let mut v = inspect(&runtime_fixture().inspect).unwrap();
            v["Config"]["Env"] = env;
            let c = LegacyContainer {
                inspect: json!([v]).to_string(),
                ..runtime_fixture()
            };
            assert!(normalized_env(&inspect(&c.inspect).unwrap()).is_err());
            assert!(validate_container(&c).is_err());
            let mut valid = inspect(&runtime_fixture().inspect).unwrap();
            valid["Config"]["Env"] = json!(["DUPLICATE=one"]);
            assert!(!env_matches(&inspect(&c.inspect).unwrap(), &valid));
        }
    }

    #[test]
    fn environment_semantics_require_exact_names_and_values() {
        let source = json!({"Config":{"Env":["ALPHA=one", "BETA=two"]}});
        let missing_name = json!({"Config":{"Env":["ALPHA=one"]}});
        let extra_name = json!({"Config":{"Env":["ALPHA=one", "BETA=two", "GAMMA=three"]}});
        let wrong_value = json!({"Config":{"Env":["ALPHA=one", "BETA=changed"]}});
        assert!(!env_matches(&source, &missing_name));
        assert!(!env_matches(&source, &extra_name));
        assert!(!env_matches(&source, &wrong_value));
    }

    #[test]
    fn named_volume_is_inventory_safe_before_plan_creation() {
        let mut c = runtime_fixture();
        let mut v = inspect(&c.inspect).unwrap();
        v["Mounts"]
            .as_array_mut()
            .unwrap()
            .push(json!({"Type":"volume","Name":"data","Source":"/var/lib/docker/volumes/data/_data","Destination":"/data"}));
        c.inspect = json!([v]).to_string();
        validate_container(&c).unwrap();
        assert_eq!(named_volumes(&[c]).unwrap(), vec!["data"]);
    }

    #[test]
    fn known_kinds_are_bounded() {
        assert_eq!(KNOWN_KINDS.len(), 8);
        assert!(KNOWN_KINDS.contains(&"runtime"))
    }
    #[test]
    fn docker_command_clears_context() {
        let mut c = Command::new("docker");
        c.env("DOCKER_HOST", "unix:///source.sock")
            .env_remove("DOCKER_CONTEXT");
        assert!(c.get_envs().any(|(k, _)| k == "DOCKER_HOST"))
    }

    #[test]
    fn start_and_verify_running_retries_until_inspect_reports_running() {
        let _guard = CUTOVER_TEST_LOCK
            .lock()
            .unwrap_or_else(std::sync::PoisonError::into_inner);
        let fixture = test_dir("start-retry-running");
        let log = fixture.join("argv.log");
        let script = fixture.join("docker");
        std::env::set_var("LOG", &log);
        fs::write(
            &script,
            format!(
                "#!/bin/sh\nprintf '%s %s\\n' \"$DOCKER_HOST\" \"$*\" >> \"$LOG\"\ncase \"$DOCKER_HOST:$1:$2:$3\" in\nunix://target:start:orbit-caddy:)\n  count=$(cat \"{0}/start-count\" 2>/dev/null || echo 0)\n  count=$((count + 1))\n  printf '%s' \"$count\" > \"{0}/start-count\"\n  exit 0 ;;\nunix://target:inspect:orbit-caddy:)\n  count=$(cat \"{0}/start-count\" 2>/dev/null || echo 0)\n  if [ \"$count\" -lt 2 ]; then printf '%s' '[{{\"State\":{{\"Running\":false}}}}]'; else printf '%s' '[{{\"State\":{{\"Running\":true}}}}]'; fi\n  exit 0 ;;\n*) exit 1 ;;\nesac\n",
                fixture.display()
            ),
        )
        .unwrap();
        use std::os::unix::fs::PermissionsExt;
        fs::set_permissions(&script, fs::Permissions::from_mode(0o755)).unwrap();

        let mut sleeps = Vec::new();
        start_and_verify_running(&script, "unix://target", &caddy_fixture(), |duration| {
            sleeps.push(duration)
        })
        .unwrap();

        assert_eq!(sleeps, vec![START_RETRY_INTERVAL]);
        assert_eq!(
            fs::read_to_string(fixture.join("start-count")).unwrap(),
            "2"
        );
        let log_text = fs::read_to_string(log).unwrap();
        assert_eq!(log_text.matches(" start orbit-caddy").count(), 2);
        assert_eq!(log_text.matches(" inspect orbit-caddy").count(), 2);
        std::env::remove_var("LOG");
    }

    #[test]
    fn named_volume_shared_copy_verifies_before_start_and_cleans_helpers() {
        let _guard = CUTOVER_TEST_LOCK
            .lock()
            .unwrap_or_else(std::sync::PoisonError::into_inner);
        let (fixture, caddy, runtime) = named_volume_fixture_dir();
        let (_fake_dir, fake, log) = named_volume_fake_docker(&fixture, false);
        let source_runtime = inspect(&runtime.inspect).unwrap();
        let target_runtime =
            inspect(&fs::read_to_string(fixture.join("target-runtime.json")).unwrap()).unwrap();
        assert_ne!(
            source_runtime["Config"]["Env"],
            target_runtime["Config"]["Env"]
        );
        assert_eq!(
            normalized_env(&source_runtime).unwrap(),
            normalized_env(&target_runtime).unwrap()
        );
        std::env::set_var("LOG", &log);
        std::env::set_var("FIXTURE", &fixture);
        let (_source_dir, source) = unix_socket(&fixture, "source.sock");
        let (_target_dir, target) = unix_socket(&fixture, "target.sock");
        let source_tar = fs::read(fixture.join("source.tar")).unwrap();
        let target_tar = fs::read(fixture.join("target.tar")).unwrap();
        assert_ne!(source_tar, target_tar);
        assert_eq!(
            tar_manifest(&source_tar).unwrap(),
            tar_manifest(&target_tar).unwrap()
        );
        assert_eq!(
            named_volumes(&[caddy, runtime]).unwrap(),
            vec!["shared-data"]
        );
        cutover_with_sleep(&fake, &source, &target, |_| {}).unwrap();
        let lines = fs::read_to_string(&log).unwrap();
        assert!(!lines.contains("/var/lib/docker/volumes/"));
        assert_eq!(
            lines
                .matches(" volume create --label orbit.cutover.volume_sha256=")
                .count(),
            1
        );
        assert_eq!(
            lines
                .matches(" run --rm --volume shared-data:/orbit-volume:ro")
                .count(),
            2
        );
        assert_eq!(
            lines
                .matches(" run --rm --interactive --volume shared-data:/orbit-volume")
                .count(),
            1
        );
        for line in lines
            .lines()
            .filter(|line| line.contains(" run ") && line.contains(" tar -C /orbit-volume -cf -"))
        {
            assert!(
                line.ends_with(" tar -C /orbit-volume -cf - ."),
                "invalid archive argv: {line}"
            );
        }
        let lines_vec = lines.lines().collect::<Vec<_>>();
        let first_start = lines_vec
            .iter()
            .position(|x| x.contains(" start orbit-caddy"))
            .unwrap();
        let target_verify = lines_vec
            .iter()
            .rposition(|x| x.contains("target.sock run "))
            .unwrap();
        assert!(target_verify < first_start);
        assert_eq!(
            lines_vec
                .iter()
                .filter(|line| line.ends_with("target.sock start orbit-caddy"))
                .count(),
            2
        );
        let second_start = lines_vec
            .iter()
            .rposition(|line| line.ends_with("target.sock start orbit-caddy"))
            .unwrap();
        let failed_start = lines_vec
            .iter()
            .position(|line| line.contains("target.sock start orbit-caddy -> exit 1"))
            .unwrap();
        let successful_inspect = lines_vec
            .iter()
            .enumerate()
            .skip(second_start + 1)
            .find_map(|(index, line)| {
                line.contains("target.sock inspect orbit-caddy")
                    .then_some(index)
            })
            .unwrap();
        let source_remove = lines_vec
            .iter()
            .position(|line| line.contains("source.sock rm orbit-caddy"))
            .unwrap();
        assert_eq!(
            lines_vec
                .iter()
                .filter(|line| line.contains("target.sock start orbit-caddy -> exit 1"))
                .count(),
            1
        );
        assert!(first_start < failed_start);
        assert!(failed_start < second_start);
        assert!(successful_inspect < source_remove);
        assert!(lines.contains("target.sock inspect orbit-runtime"));
        assert!(lines.contains(" rm orbit-caddy"));
        assert!(lines.contains(" rm orbit-runtime"));
        std::env::remove_var("LOG");
        std::env::remove_var("FIXTURE");
    }

    #[test]
    fn named_volume_hash_mismatch_rolls_back_created_targets_volumes_network_and_restarts_source() {
        let _guard = CUTOVER_TEST_LOCK
            .lock()
            .unwrap_or_else(std::sync::PoisonError::into_inner);
        let (fixture, caddy, runtime) = named_volume_fixture_dir();
        let (_fake_dir, fake, log) = named_volume_fake_docker(&fixture, true);
        std::env::set_var("LOG", &log);
        std::env::set_var("FIXTURE", &fixture);
        let (_source_dir, source) = unix_socket(&fixture, "source.sock");
        let (_target_dir, target) = unix_socket(&fixture, "target.sock");
        let source_tar = fs::read(fixture.join("source.tar")).unwrap();
        let target_tar = fs::read(fixture.join("target.tar")).unwrap();
        assert_ne!(source_tar, target_tar);
        assert_ne!(
            tar_manifest(&source_tar).unwrap(),
            tar_manifest(&target_tar).unwrap()
        );
        assert!(cutover_with_sleep(&fake, &source, &target, |_| {}).is_err());
        let lines = fs::read_to_string(&log).unwrap();
        assert!(lines.contains(" volume rm shared-data"));
        assert!(!lines.contains("/var/lib/docker/volumes/"));
        assert!(lines.contains(" network rm orbit-network"));
        assert!(lines.contains(" rm -f orbit-caddy"));
        assert!(lines.contains(" rm -f orbit-runtime"));
        assert!(lines.contains(" start orbit-caddy"));
        assert!(!lines.contains(" start orbit-runtime"));
        assert!(!lines.contains(" rm orbit-caddy"));
        assert!(!lines.contains(" rm orbit-runtime"));
        let _ = (caddy, runtime);
        std::env::remove_var("LOG");
        std::env::remove_var("FIXTURE");
    }

    #[test]
    fn partial_source_cleanup_keeps_verified_target_and_retry_finishes() {
        let _guard = CUTOVER_TEST_LOCK
            .lock()
            .unwrap_or_else(std::sync::PoisonError::into_inner);
        let (_fixture, _caddy, _runtime) = named_volume_fixture_dir();
        let fixture = _fixture;
        let (_fake_dir, fake, log) = retry_fake_docker(&fixture, false);
        std::env::set_var("LOG", &log);
        std::env::set_var("FIXTURE", &fixture);
        let (_source_dir, source) = unix_socket(&fixture, "source.sock");
        let (_target_dir, target) = unix_socket(&fixture, "target.sock");
        let source_tar = fs::read(fixture.join("source.tar")).unwrap();
        let target_tar = fs::read(fixture.join("target.tar")).unwrap();
        assert_ne!(source_tar, target_tar);
        assert_eq!(
            tar_manifest(&source_tar).unwrap(),
            tar_manifest(&target_tar).unwrap()
        );

        assert!(cutover_with_sleep(&fake, &source, &target, |_| {}).is_err());
        let first = fs::read_to_string(&log).unwrap();
        assert!(first.contains(" rm orbit-caddy"));
        assert!(first.contains(" rm orbit-runtime"));
        assert!(first.contains(" volume create "));
        assert!(!first.contains(" rm -f orbit-caddy"));
        assert!(!first.contains(" rm -f orbit-runtime"));
        assert!(fixture.join("cleanup-state").exists());

        let before_retry = first.lines().count();
        cutover_with_sleep(&fake, &source, &target, |_| {}).unwrap();
        let all = fs::read_to_string(&log).unwrap();
        let delta = all
            .lines()
            .skip(before_retry)
            .collect::<Vec<_>>()
            .join("\n");
        assert!(delta.contains(" rm orbit-runtime"));
        assert!(delta.contains("source.sock run --rm --volume shared-data:/orbit-volume:ro"));
        assert!(delta.contains("target.sock run --rm --volume shared-data:/orbit-volume:ro"));
        assert!(!delta.contains("/var/lib/docker/volumes/"));
        for line in delta
            .lines()
            .filter(|line| line.contains(" run ") && line.contains(" tar -C /orbit-volume -cf -"))
        {
            assert!(
                line.ends_with(" tar -C /orbit-volume -cf - ."),
                "invalid archive argv: {line}"
            );
        }
        assert!(!delta.contains(" pull "));
        assert!(!delta.contains(" save "));
        assert!(!delta.contains(" load "));
        assert!(!delta.contains(" network create "));
        assert!(!delta.contains(" volume create "));
        assert!(!delta.contains(" create --name "));
        assert!(!delta.contains(" start "));
        assert!(!delta.contains(" volume rm "));
        assert!(!delta.contains(" network rm "));
        std::env::remove_var("LOG");
        std::env::remove_var("FIXTURE");
    }

    #[test]
    fn mismatched_retry_counterpart_fails_closed() {
        let _guard = CUTOVER_TEST_LOCK
            .lock()
            .unwrap_or_else(std::sync::PoisonError::into_inner);
        let (_fixture, _caddy, _runtime) = named_volume_fixture_dir();
        let fixture = _fixture;
        let (_fake_dir, fake, log) = retry_fake_docker(&fixture, true);
        fs::write(fixture.join("volume-state"), "volume").unwrap();
        fs::write(fixture.join("cleanup-state"), "retry").unwrap();
        std::env::set_var("LOG", &log);
        std::env::set_var("FIXTURE", &fixture);
        let (_source_dir, source) = unix_socket(&fixture, "source.sock");
        let (_target_dir, target) = unix_socket(&fixture, "target.sock");
        let source_tar = fs::read(fixture.join("source.tar")).unwrap();
        let target_tar = fs::read(fixture.join("target.tar")).unwrap();
        assert_ne!(source_tar, target_tar);
        assert_ne!(
            tar_manifest(&source_tar).unwrap(),
            tar_manifest(&target_tar).unwrap()
        );

        let error = cutover_with_sleep(&fake, &source, &target, |_| {}).unwrap_err();
        assert!(matches!(error, CutoverError::TargetConflict(_)));
        let lines = fs::read_to_string(&log).unwrap();
        assert!(!lines.contains(" stop "));
        assert!(!lines.contains(" rm "));
        assert!(!lines.contains(" create --name "));
        assert!(!lines.contains(" start "));
        assert!(!lines.contains(" load "));
        std::env::remove_var("LOG");
        std::env::remove_var("FIXTURE");
    }

    #[test]
    fn malformed_source_environment_fails_before_any_mutation() {
        let _guard = CUTOVER_TEST_LOCK
            .lock()
            .unwrap_or_else(std::sync::PoisonError::into_inner);
        let (fixture, _caddy, runtime) = named_volume_fixture_dir();
        let mut invalid_runtime = inspect(&runtime.inspect).unwrap();
        invalid_runtime["Config"]["Env"] = json!(["BROKEN"]);
        fs::write(
            fixture.join("runtime.json"),
            json!([invalid_runtime]).to_string(),
        )
        .unwrap();
        let (_fake_dir, fake, log) = named_volume_fake_docker(&fixture, false);
        std::env::set_var("LOG", &log);
        std::env::set_var("FIXTURE", &fixture);
        let (_source_dir, source) = unix_socket(&fixture, "source.sock");
        let (_target_dir, target) = unix_socket(&fixture, "target.sock");
        let error = cutover_with_sleep(&fake, &source, &target, |_| {}).unwrap_err();
        assert!(matches!(error, CutoverError::Invalid(name, _) if name == "orbit-runtime"));
        let lines = fs::read_to_string(&log).unwrap();
        assert!(!lines.contains(" stop "));
        assert!(!lines.contains(" volume create "));
        assert!(!lines.contains(" network create "));
        assert!(!lines.contains(" create --name "));
        std::env::remove_var("LOG");
        std::env::remove_var("FIXTURE");
    }

    #[test]
    fn malformed_target_environment_rejects_retry_counterpart() {
        let fixture = test_dir("retry-malformed-target-env");
        let mut runtime = inspect(&runtime_fixture().inspect).unwrap();
        runtime["Config"]["Labels"]["orbit.cutover.fingerprint"] =
            json!(cutover_fingerprint(&runtime));
        runtime["Config"]["Env"] = json!(true);
        fs::write(
            fixture.join("target-runtime.json"),
            json!([runtime]).to_string(),
        )
        .unwrap();
        let script = fixture.join("docker");
        fs::write(
            &script,
            format!(
                "#!/bin/sh\ncase \"$DOCKER_HOST:$1:$2\" in\nunix://target:inspect:orbit-runtime) cat \"{}/target-runtime.json\"; exit 0 ;;\n*) exit 1 ;;\nesac\n",
                fixture.display()
            ),
        )
        .unwrap();
        use std::os::unix::fs::PermissionsExt;
        fs::set_permissions(&script, fs::Permissions::from_mode(0o755)).unwrap();
        assert!(!exact_retry_counterpart(
            &script,
            "unix://target",
            &LegacyContainer {
                name: "orbit-runtime".into(),
                ..runtime_fixture()
            }
        )
        .unwrap());
    }

    fn test_dir(label: &str) -> PathBuf {
        // macOS resolves the system temp directory to a long `/var/folders/...`
        // path. Keep Unix-socket fixtures below the platform SUN_LEN limit.
        let temp_root =
            std::fs::canonicalize("/tmp").expect("test temp directory must be canonical");
        let path = temp_root.join(format!(
            "orbit-cutover-test-{}-{}",
            label,
            std::process::id()
        ));
        let _ = fs::remove_dir_all(&path);
        fs::create_dir_all(&path).unwrap();
        assert!(!path.is_symlink());
        path
    }

    fn named_volume_fixture_dir() -> (PathBuf, LegacyContainer, LegacyContainer) {
        let dir = test_dir("named-fixtures");
        let mut caddy = caddy_fixture();
        let mut runtime = runtime_fixture();
        for c in [&mut caddy, &mut runtime] {
            let mut v = inspect(&c.inspect).unwrap();
            v["Mounts"] = json!([{"Type":"volume","Name":"shared-data","Source":"/var/lib/docker/volumes/shared-data/_data","Destination":"/data","RW":true}]);
            c.inspect = json!([v]).to_string();
        }
        let mut target_caddy = inspect(&caddy.inspect).unwrap();
        let mut target_runtime = inspect(&runtime.inspect).unwrap();
        for target in [&mut target_caddy, &mut target_runtime] {
            target["Mounts"][0]["Source"] =
                json!("/var/lib/docker/volumes/shared-data/_data-target");
        }
        target_caddy["Id"] = json!("caddy-id");
        target_caddy["State"]["Running"] = json!(false);
        target_runtime["Id"] = json!("runtime-id");
        target_runtime["Config"]["Env"] = json!([
            "ORBIT_SOURCE_PATH=/Users/nckrtl/orbit/src",
            "ORBIT_HOST_PATH=/Users/nckrtl/orbit"
        ]);
        fs::write(dir.join("source.json"), &caddy.inspect).unwrap();
        fs::write(dir.join("runtime.json"), &runtime.inspect).unwrap();
        fs::write(
            dir.join("target-caddy-stopped.json"),
            json!([target_caddy]).to_string(),
        )
        .unwrap();
        let mut target_caddy_running = target_caddy.clone();
        target_caddy_running["State"]["Running"] = json!(true);
        fs::write(
            dir.join("target-caddy-running.json"),
            json!([target_caddy_running]).to_string(),
        )
        .unwrap();
        fs::write(
            dir.join("target-runtime.json"),
            json!([target_runtime]).to_string(),
        )
        .unwrap();
        fs::write(dir.join("network.json"), json!([{"Name":"orbit-network","Labels":{"orbit.managed":"true","orbit.network.kind":"runtime"}}]).to_string()).unwrap();
        (dir, caddy, runtime)
    }

    fn named_volume_fake_docker(fixture: &Path, mismatch: bool) -> (PathBuf, PathBuf, PathBuf) {
        use std::os::unix::fs::PermissionsExt;
        let dir = test_dir(if mismatch {
            "named-mismatch"
        } else {
            "named-success"
        });
        let log = dir.join("argv.log");
        let script = dir.join("docker-named-fake");
        let body = r#"#!/bin/sh
printf '%s %s\n' "$DOCKER_HOST" "$*" >> "$LOG"
case "$*" in
  *" tar -C /orbit-volume -cf - .") ;;
  *" tar -C /orbit-volume -cf -") exit 97 ;;
esac
case "$DOCKER_HOST:$1:$2" in
unix://*/source.sock:info:*|unix://*/target.sock:info:*) exit 0 ;;
unix://*/source.sock:ps:-aq) printf 'caddy-id\nruntime-id\n'; exit 0 ;;
unix://*/target.sock:ps:-aq) exit 0 ;;
unix://*/source.sock:inspect:caddy-id) cat "$FIXTURE/source.json"; exit 0 ;;
unix://*/source.sock:inspect:runtime-id) cat "$FIXTURE/runtime.json"; exit 0 ;;
unix://*/target.sock:inspect:orbit-caddy)
  if [ -f "$FIXTURE/start-ready" ]; then
    touch "$FIXTURE/running-state"
    cat "$FIXTURE/target-caddy-running.json"
  else
    cat "$FIXTURE/target-caddy-stopped.json"
  fi
  exit 0 ;;
unix://*/target.sock:inspect:orbit-runtime) cat "$FIXTURE/target-runtime.json"; exit 0 ;;
unix://*/source.sock:network:inspect) cat "$FIXTURE/network.json"; exit 0 ;;
unix://*/target.sock:network:inspect) exit 1 ;;
unix://*/target.sock:network:create*) exit 0 ;;
unix://*/target.sock:network:rm*) exit 0 ;;
unix://*/target.sock:volume:inspect) exit 1 ;;
unix://*/target.sock:volume:create*) touch "$FIXTURE/retry-state"; exit 0 ;;
unix://*/target.sock:volume:rm*) exit 0 ;;
unix://*/source.sock:run:*) cat "$FIXTURE/source.tar"; exit 0 ;;
unix://*/target.sock:run:*) cat "$FIXTURE/target.tar"; exit 0 ;;
unix://*/source.sock:save:*) printf 'image'; exit 0 ;;
unix://*/target.sock:load:*|unix://*/target.sock:create:*|unix://*/target.sock:rm:*) exit 0 ;;
unix://*/target.sock:start:orbit-caddy)
  count=$(cat "$FIXTURE/start-count" 2>/dev/null || echo 0)
  count=$((count + 1))
  printf '%s' "$count" > "$FIXTURE/start-count"
  if [ "$count" -eq 1 ]; then
    printf '%s %s\n' "$DOCKER_HOST" "start orbit-caddy -> exit 1" >> "$LOG"
    exit 1
  fi
  touch "$FIXTURE/start-ready"
  exit 0 ;;
unix://*/target.sock:start:*) exit 0 ;;
unix://*/source.sock:stop:*|unix://*/source.sock:start:*|unix://*/source.sock:rm:*) exit 0 ;;
*) exit 0 ;;
esac
"#
        .to_string();
        let body = body.replace("$FIXTURE", fixture.to_str().unwrap());
        assert!(!body.contains("$FIXTURE"));
        assert!(body.contains("cat \""));
        assert!(body.contains("target-runtime.json"));
        assert!(
            body.contains("unix://*/target.sock:inspect:orbit-runtime) cat \"")
                && body.contains("/target-runtime.json\"; exit 0 ;;")
        );
        fs::write(fixture.join("source.tar"), tar_fixture(false, false)).unwrap();
        fs::write(fixture.join("target.tar"), tar_fixture(true, mismatch)).unwrap();
        fs::write(&script, body).unwrap();
        fs::set_permissions(&script, fs::Permissions::from_mode(0o755)).unwrap();
        (dir, script, log)
    }

    fn retry_fake_docker(fixture: &Path, mismatch: bool) -> (PathBuf, PathBuf, PathBuf) {
        use std::os::unix::fs::PermissionsExt;
        let dir = test_dir(if mismatch {
            "retry-mismatch"
        } else {
            "retry-cleanup"
        });
        let log = dir.join("argv.log");
        let script = dir.join("docker-retry-fake");
        let mut target_caddy =
            inspect(&fs::read_to_string(fixture.join("source.json")).unwrap()).unwrap();
        let mut target_runtime =
            inspect(&fs::read_to_string(fixture.join("runtime.json")).unwrap()).unwrap();
        target_caddy["Id"] = json!("target-caddy-id");
        target_runtime["Id"] = json!("target-runtime-id");
        for target in [&mut target_caddy, &mut target_runtime] {
            target["Config"]["Labels"]["orbit.cutover.fingerprint"] =
                json!(cutover_fingerprint(target));
        }
        if mismatch {
            fs::write(fixture.join("mismatch-volume"), "mismatch").unwrap();
        }
        fs::write(
            fixture.join("target-caddy.json"),
            json!([target_caddy]).to_string(),
        )
        .unwrap();
        fs::write(
            fixture.join("target-runtime.json"),
            json!([target_runtime]).to_string(),
        )
        .unwrap();
        let body = r#"#!/bin/sh
printf '%s %s\n' "$DOCKER_HOST" "$*" >> "$LOG"
case "$*" in
  *" tar -C /orbit-volume -cf - .") ;;
  *" tar -C /orbit-volume -cf -") exit 97 ;;
esac
case "$DOCKER_HOST:$1:$2" in
unix://*/source.sock:info:*|unix://*/target.sock:info:*) exit 0 ;;
unix://*/source.sock:ps:-aq)
  if [ -f "$FIXTURE/cleanup-state" ]; then printf 'runtime-id\n'; else printf 'caddy-id\nruntime-id\n'; fi; exit 0 ;;
unix://*/target.sock:ps:-aq) printf 'target-caddy-id\ntarget-runtime-id\n'; exit 0 ;;
unix://*/source.sock:inspect:caddy-id) cat "$FIXTURE/source.json"; exit 0 ;;
unix://*/source.sock:inspect:runtime-id) cat "$FIXTURE/runtime.json"; exit 0 ;;
unix://*/target.sock:inspect:target-caddy-id|unix://*/target.sock:inspect:orbit-caddy) cat "$FIXTURE/target-caddy.json"; exit 0 ;;
unix://*/target.sock:inspect:target-runtime-id|unix://*/target.sock:inspect:orbit-runtime) cat "$FIXTURE/target-runtime.json"; exit 0 ;;
unix://*/source.sock:network:inspect|unix://*/target.sock:network:inspect) cat "$FIXTURE/network.json"; exit 0 ;;
unix://*/target.sock:volume:inspect)
  if [ -f "$FIXTURE/volume-state" ]; then exit 0; else exit 1; fi ;;
unix://*/target.sock:volume:create*) touch "$FIXTURE/volume-state"; exit 0 ;;
unix://*/target.sock:volume:rm*) exit 0 ;;
unix://*/source.sock:run:*) cat "$FIXTURE/source.tar"; exit 0 ;;
unix://*/target.sock:run:*)
  if [ ! -f "$FIXTURE/volume-state" ]; then exit 1; fi;
  cat "$FIXTURE/target.tar"; exit 0 ;;
unix://*/source.sock:save:*) printf image; exit 0 ;;
unix://*/target.sock:load:*|unix://*/target.sock:create:*|unix://*/target.sock:start:*) exit 0 ;;
unix://*/source.sock:stop:*) exit 0 ;;
unix://*/source.sock:rm:orbit-caddy) exit 0 ;;
unix://*/source.sock:rm:orbit-runtime)
  if [ -f "$FIXTURE/cleanup-state" ]; then exit 0; else touch "$FIXTURE/cleanup-state"; exit 1; fi ;;
*) exit 0 ;;
esac
"#
        .to_string();
        fs::write(&script, body.replace("$FIXTURE", fixture.to_str().unwrap())).unwrap();
        fs::write(fixture.join("source.tar"), tar_fixture(false, false)).unwrap();
        fs::write(fixture.join("target.tar"), tar_fixture(true, mismatch)).unwrap();
        fs::set_permissions(&script, fs::Permissions::from_mode(0o755)).unwrap();
        (dir, script, log)
    }

    fn start_exhaustion_fake_docker(fixture: &Path) -> (PathBuf, PathBuf, PathBuf) {
        use std::os::unix::fs::PermissionsExt;
        let dir = test_dir("start-exhaustion");
        let log = dir.join("argv.log");
        let script = dir.join("docker-start-exhaustion");
        let body = r#"#!/bin/sh
printf '%s %s\n' "$DOCKER_HOST" "$*" >> "$LOG"
case "$*" in
  *" tar -C /orbit-volume -cf - .") ;;
  *" tar -C /orbit-volume -cf -") exit 97 ;;
esac
case "$DOCKER_HOST:$1:$2" in
unix://*/source.sock:info:*|unix://*/target.sock:info:*) exit 0 ;;
unix://*/source.sock:ps:-aq) printf 'caddy-id\nruntime-id\n'; exit 0 ;;
unix://*/target.sock:ps:-aq) exit 0 ;;
unix://*/source.sock:inspect:caddy-id) cat "$FIXTURE/source.json"; exit 0 ;;
unix://*/source.sock:inspect:runtime-id) cat "$FIXTURE/runtime.json"; exit 0 ;;
unix://*/target.sock:inspect:orbit-caddy) cat "$FIXTURE/target-caddy-stopped.json"; exit 0 ;;
unix://*/target.sock:inspect:orbit-runtime) cat "$FIXTURE/target-runtime.json"; exit 0 ;;
unix://*/source.sock:network:inspect) cat "$FIXTURE/network.json"; exit 0 ;;
unix://*/target.sock:network:inspect) exit 1 ;;
unix://*/target.sock:network:create*) exit 0 ;;
unix://*/target.sock:network:rm*) exit 0 ;;
unix://*/target.sock:volume:inspect) exit 1 ;;
unix://*/target.sock:volume:create*) exit 0 ;;
unix://*/target.sock:volume:rm*) exit 0 ;;
unix://*/source.sock:run:*) cat "$FIXTURE/source.tar"; exit 0 ;;
unix://*/target.sock:run:*) cat "$FIXTURE/target.tar"; exit 0 ;;
unix://*/source.sock:save:*) printf 'image'; exit 0 ;;
unix://*/target.sock:load:*|unix://*/target.sock:create:*|unix://*/target.sock:start:*|unix://*/target.sock:rm:*) exit 0 ;;
unix://*/source.sock:stop:*|unix://*/source.sock:start:*|unix://*/source.sock:rm:*) exit 0 ;;
*) exit 0 ;;
esac
"#
        .replace("$FIXTURE", fixture.to_str().unwrap());
        fs::write(fixture.join("source.tar"), tar_fixture(false, false)).unwrap();
        fs::write(fixture.join("target.tar"), tar_fixture(true, false)).unwrap();
        fs::write(&script, body).unwrap();
        fs::set_permissions(&script, fs::Permissions::from_mode(0o755)).unwrap();
        (dir, script, log)
    }

    #[test]
    fn start_retry_exhaustion_rolls_back_and_restarts_only_originally_running() {
        let _guard = CUTOVER_TEST_LOCK
            .lock()
            .unwrap_or_else(std::sync::PoisonError::into_inner);
        let (fixture, _caddy, _runtime) = named_volume_fixture_dir();
        let (_fake_dir, fake, log) = start_exhaustion_fake_docker(&fixture);
        std::env::set_var("LOG", &log);
        std::env::set_var("FIXTURE", &fixture);
        let (_source_dir, source) = unix_socket(&fixture, "source.sock");
        let (_target_dir, target) = unix_socket(&fixture, "target.sock");
        let mut sleeps = Vec::new();

        let error = cutover_with_sleep(&fake, &source, &target, |duration| sleeps.push(duration))
            .unwrap_err();

        assert!(matches!(error, CutoverError::Invalid(name, _) if name == "orbit-caddy"));
        assert_eq!(sleeps.len(), START_RETRY_ATTEMPTS - 1);
        assert!(sleeps
            .iter()
            .all(|duration| *duration == START_RETRY_INTERVAL));

        let lines = fs::read_to_string(&log).unwrap();
        let lines_vec = lines.lines().collect::<Vec<_>>();
        assert_eq!(
            lines.matches("target.sock start orbit-caddy").count(),
            START_RETRY_ATTEMPTS
        );
        assert_eq!(
            lines_vec
                .iter()
                .skip_while(|line| !line.contains("target.sock start orbit-caddy"))
                .filter(|line| line.contains("target.sock inspect orbit-caddy"))
                .count(),
            START_RETRY_ATTEMPTS
        );
        assert!(lines.contains("target.sock rm -f orbit-caddy"));
        assert!(lines.contains("target.sock rm -f orbit-runtime"));
        assert!(lines.contains("target.sock network rm orbit-network"));
        assert!(lines.contains("target.sock volume rm shared-data"));
        assert!(lines.contains("source.sock start orbit-caddy"));
        assert!(!lines.contains("source.sock start orbit-runtime"));
        assert!(!lines.contains("source.sock rm orbit-caddy"));
        assert!(!lines.contains("source.sock rm orbit-runtime"));
        std::env::remove_var("LOG");
        std::env::remove_var("FIXTURE");
    }

    fn tar_fixture(root_different: bool, descendant_different: bool) -> Vec<u8> {
        fn record(name: &[u8], kind: u8, data: &[u8], mode: &[u8], mtime: &[u8]) -> Vec<u8> {
            let mut h = [0u8; 512];
            h[..name.len()].copy_from_slice(name);
            h[100..108].copy_from_slice(mode);
            h[124..136].copy_from_slice(format!("{:011o}\0", data.len()).as_bytes());
            h[136..148].copy_from_slice(mtime);
            h[148..156].fill(b' ');
            h[156] = kind;
            let sum: u32 = h.iter().map(|b| *b as u32).sum();
            h[148..156].copy_from_slice(format!("{:06o}\0 ", sum).as_bytes());
            let mut out = h.to_vec();
            out.extend_from_slice(data);
            out.resize(512 + data.len().div_ceil(512) * 512, 0);
            out
        }
        let mut out = record(
            b"./",
            b'5',
            &[],
            if root_different {
                b"0000700\0"
            } else {
                b"0000755\0"
            },
            if root_different {
                b"00000000001\0"
            } else {
                b"00000000000\0"
            },
        );
        out.extend(record(
            b"safe name\n.txt",
            b'0',
            b"payload",
            b"0000644\0",
            if descendant_different {
                b"00000000001\0"
            } else {
                b"00000000000\0"
            },
        ));
        out.extend([0u8; 2048]);
        out
    }

    #[test]
    fn tar_manifest_validates_and_normalizes_boundaries() {
        let source = tar_fixture(false, false);
        let root_only = [&source[..512], &[0u8; 1024]].concat();
        assert!(tar_manifest(&root_only).is_ok());
        assert_eq!(
            tar_manifest(&source).unwrap(),
            tar_manifest(&tar_fixture(true, false)).unwrap()
        );
        assert_ne!(
            tar_manifest(&source).unwrap(),
            tar_manifest(&tar_fixture(false, true)).unwrap()
        );
        assert!(String::from_utf8_lossy(&source).contains("safe name\n.txt"));

        let mut cases = vec![
            source[..511].to_vec(),
            source[..1024].to_vec(),
            {
                let mut x = source.clone();
                x[0] ^= 1;
                x
            },
            {
                let mut x = source.clone();
                x[124..136].fill(b'9');
                x
            },
            {
                let mut x = source[512..].to_vec();
                x.extend_from_slice(&[0u8; 1024]);
                x
            },
            {
                let mut x = source.clone();
                x[156] = b'0';
                x
            },
        ];
        let root = source[..512].to_vec();
        cases.push([root.as_slice(), &source[..512], &source[512..]].concat());
        let mut trailer_bytes = source.clone();
        *trailer_bytes.last_mut().unwrap() = 1;
        cases.push(trailer_bytes);
        for case in cases {
            assert!(tar_manifest(&case).is_err());
        }
        let mut extra_padding = source.clone();
        extra_padding.extend_from_slice(&[0u8; 1024]);
        assert_eq!(
            tar_manifest(&source).unwrap(),
            tar_manifest(&extra_padding).unwrap()
        );
    }

    #[test]
    fn tar_manifest_canonicalizes_paths_and_compares_semantic_metadata() {
        #[allow(clippy::too_many_arguments)]
        fn record(
            name: &[u8],
            kind: u8,
            data: &[u8],
            mode: u32,
            uid: u32,
            gid: u32,
            mtime: u64,
            link: &[u8],
            prefix: &[u8],
        ) -> Vec<u8> {
            let mut h = [0; 512];
            h[..name.len()].copy_from_slice(name);
            h[100..108].copy_from_slice(format!("{mode:07o}\0").as_bytes());
            h[108..116].copy_from_slice(format!("{uid:07o}\0").as_bytes());
            h[116..124].copy_from_slice(format!("{gid:07o}\0").as_bytes());
            h[124..136].copy_from_slice(format!("{:011o}\0", data.len()).as_bytes());
            h[136..148].copy_from_slice(format!("{mtime:011o}\0").as_bytes());
            h[148..156].fill(b' ');
            h[156] = kind;
            h[157..157 + link.len()].copy_from_slice(link);
            h[345..345 + prefix.len()].copy_from_slice(prefix);
            let sum: u32 = h.iter().map(|byte| *byte as u32).sum();
            h[148..156].copy_from_slice(format!("{sum:06o}\0 ").as_bytes());
            let mut out = h.to_vec();
            out.extend_from_slice(data);
            out.resize(512 + data.len().div_ceil(512) * 512, 0);
            out
        }
        fn archive(records: Vec<Vec<u8>>) -> Vec<u8> {
            let mut out = records.into_iter().flatten().collect::<Vec<_>>();
            out.extend_from_slice(&[0; 1024]);
            out
        }
        let root = |name| record(name, b'5', &[], 0o755, 7, 8, 0, &[], &[]);
        let file = |name, data, mtime, mode, uid, gid, kind, link, prefix| {
            record(name, kind, data, mode, uid, gid, mtime, link, prefix)
        };
        let ordered = archive(vec![
            root(b"./"),
            root(b"dir/"),
            file(b"dir/file", b"payload", 3, 0o644, 1, 2, 0, &[], &[]),
        ]);
        let reordered = archive(vec![
            root(b"./"),
            file(b"dir/file", b"payload", 3, 0o644, 1, 2, 0, &[], &[]),
            root(b"dir/"),
        ]);
        assert_eq!(
            tar_manifest(&ordered).unwrap(),
            tar_manifest(&reordered).unwrap()
        );
        let baseline_dir = archive(vec![
            root(b"./"),
            root(b"dir/"),
            file(b"dir/file", b"payload", 3, 0o644, 1, 2, b'0', &[], &[]),
        ]);
        let dir_mode = archive(vec![
            root(b"./"),
            record(b"dir/", b'5', &[], 0o700, 7, 8, 99, &[], &[]),
            file(b"dir/file", b"payload", 3, 0o644, 1, 2, b'0', &[], &[]),
        ]);
        assert_ne!(
            tar_manifest(&baseline_dir).unwrap(),
            tar_manifest(&dir_mode).unwrap()
        );
        for (uid, gid) in [(9, 8), (7, 9)] {
            let changed = archive(vec![
                root(b"./"),
                record(b"dir/", b'5', &[], 0o755, uid, gid, 99, &[], &[]),
                file(b"dir/file", b"payload", 3, 0o644, 1, 2, b'0', &[], &[]),
            ]);
            assert_ne!(
                tar_manifest(&baseline_dir).unwrap(),
                tar_manifest(&changed).unwrap()
            );
        }
        assert_eq!(
            tar_manifest(&baseline_dir).unwrap(),
            tar_manifest(&archive(vec![
                root(b"./"),
                record(b"dir/", b'5', &[], 0o755, 7, 8, 99, &[], &[]),
                file(b"dir/file", b"payload", 3, 0o644, 1, 2, b'0', &[], &[]),
            ]))
            .unwrap()
        );
        let prefixed = archive(vec![
            root(b"./"),
            record(b"dir", b'5', &[], 0o755, 7, 8, 99, &[], b""),
            file(b"file", b"payload", 3, 0o644, 1, 2, b'0', &[], b"dir"),
        ]);
        assert_eq!(
            tar_manifest(&baseline_dir).unwrap(),
            tar_manifest(&prefixed).unwrap()
        );
        for bad in [
            b"/absolute".as_slice(),
            b"../traversal",
            b"dir/./file",
            b"dir//file",
        ] {
            assert!(tar_manifest(&archive(vec![
                root(b"./"),
                file(bad, b"", 0, 0o644, 1, 2, b'0', &[], &[])
            ]))
            .is_err());
        }
        assert!(tar_manifest(&archive(vec![
            root(b"./"),
            file(b"file/", b"", 0, 0o644, 1, 2, b'0', &[], &[])
        ]))
        .is_err());
        let duplicate = archive(vec![
            root(b"./"),
            file(b"./file", b"", 0, 0o644, 1, 2, b'0', &[], &[]),
            file(b"file", b"", 0, 0o644, 1, 2, b'0', &[], &[]),
        ]);
        assert!(tar_manifest(&duplicate).is_err());
        assert!(tar_manifest(&archive(vec![
            root(b"./"),
            file(b"bad", b"", 0, 0o644, 1, 2, b'3', &[], &[])
        ]))
        .is_err());
        let baseline_file = archive(vec![
            root(b"./"),
            file(b"file", b"payload", 3, 0o644, 1, 2, b'0', &[], &[]),
        ]);
        for (data, mtime, mode, uid, gid) in [
            (b"other".as_slice(), 3, 0o644, 1, 2),
            (b"payload".as_slice(), 4, 0o644, 1, 2),
            (b"payload".as_slice(), 3, 0o600, 1, 2),
            (b"payload".as_slice(), 3, 0o644, 9, 2),
            (b"payload".as_slice(), 3, 0o644, 1, 9),
        ] {
            let changed = archive(vec![
                root(b"./"),
                file(b"file", data, mtime, mode, uid, gid, b'0', &[], &[]),
            ]);
            assert_ne!(
                tar_manifest(&baseline_file).unwrap(),
                tar_manifest(&changed).unwrap()
            );
        }
        let symlink = |mode, uid, gid, mtime, link| {
            archive(vec![
                root(b"./"),
                file(b"file", b"", mtime, mode, uid, gid, b'2', link, &[]),
            ])
        };
        assert_eq!(
            tar_manifest(&symlink(0o777, 1, 2, 3, b"one")).unwrap(),
            tar_manifest(&symlink(0o600, 9, 8, 99, b"one")).unwrap()
        );
        assert_ne!(
            tar_manifest(&symlink(0o777, 1, 2, 3, b"one")).unwrap(),
            tar_manifest(&symlink(0o777, 1, 2, 3, b"two")).unwrap()
        );

        let forward = archive(vec![
            root(b"./"),
            file(b"a", b"payload", 3, 0o644, 1, 2, b'0', &[], &[]),
            file(b"b", b"", 99, 0o600, 9, 8, b'1', b"a", &[]),
        ]);
        let reversed = archive(vec![
            root(b"./"),
            file(b"a", b"", 99, 0o600, 9, 8, b'1', b"b", &[]),
            file(b"b", b"payload", 3, 0o644, 1, 2, b'0', &[], &[]),
        ]);
        assert_eq!(
            tar_manifest(&forward).unwrap(),
            tar_manifest(&reversed).unwrap()
        );
    }

    #[test]
    fn retry_volume_matches_fails_closed_and_calls_each_endpoint_once() {
        for mode in ["endpoint", "malformed-source", "malformed-target"] {
            let fixture = test_dir(&format!("retry-parser-{mode}"));
            fs::write(fixture.join("source.tar"), tar_fixture(false, false)).unwrap();
            fs::write(fixture.join("target.tar"), tar_fixture(true, false)).unwrap();
            let log = fixture.join("calls");
            let script = fixture.join("docker");
            fs::write(&script, format!(
                "#!/bin/sh\nprintf '%s\\n' \"$DOCKER_HOST\" >> {}\ncase \"$RETRY_MODE:$DOCKER_HOST\" in\nendpoint:*) exit 1 ;;\nmalformed-source:*source*) cat \"$FIXTURE/bad.tar\"; exit 0 ;;\nmalformed-target:*target*) cat \"$FIXTURE/bad.tar\"; exit 0 ;;\nesac\ncase \"$DOCKER_HOST\" in *source*) cat \"$FIXTURE/source.tar\" ;; *) cat \"$FIXTURE/target.tar\" ;; esac\n",
                log.display())).unwrap();
            fs::write(fixture.join("bad.tar"), b"not a tar archive").unwrap();
            use std::os::unix::fs::PermissionsExt;
            fs::set_permissions(&script, fs::Permissions::from_mode(0o755)).unwrap();
            let source = format!("unix://{}/source", fixture.display());
            let target = format!("unix://{}/target", fixture.display());
            std::env::set_var("FIXTURE", &fixture);
            std::env::set_var("RETRY_MODE", mode);
            assert!(!retry_volume_matches(&script, &target, &source, "data"));
            let call_log = fs::read_to_string(log).unwrap();
            let calls = call_log.lines().collect::<Vec<_>>();
            assert!(
                calls
                    .iter()
                    .filter(|line| line
                        .split_whitespace()
                        .next()
                        .is_some_and(|endpoint| endpoint.ends_with("/source")))
                    .count()
                    <= 1
            );
            assert!(
                calls
                    .iter()
                    .filter(|line| line
                        .split_whitespace()
                        .next()
                        .is_some_and(|endpoint| endpoint.ends_with("/target")))
                    .count()
                    <= 1
            );
            std::env::remove_var("FIXTURE");
            std::env::remove_var("RETRY_MODE");
        }
    }

    fn fake_docker(fixture_dir: &Path, fail_verify: bool) -> (PathBuf, PathBuf, PathBuf) {
        use std::os::unix::fs::PermissionsExt;
        let dir = test_dir("fake");
        let log = dir.join("argv.log");
        let script = dir.join("docker-fake");
        let verify = if fail_verify {
            "exit 31"
        } else {
            "cat \"$FIXTURE/target.json\"; exit 0"
        };
        let body = "#!/bin/sh\nprintf '%s %s\\n' \"$DOCKER_HOST\" \"$*\" >> \"$LOG\"\ncase \"$DOCKER_HOST:$1:$2\" in\nunix://*/source.sock:info:*|unix://*/target.sock:info:*) exit 0 ;;\nunix://*/source.sock:ps:-aq) printf 'caddy-id\\nruntime-id\\nunrelated-id\\n'; exit 0 ;;\nunix://*/target.sock:ps:-aq) exit 0 ;;\nunix://*/source.sock:inspect:caddy-id) cat \"$FIXTURE/source.json\"; exit 0 ;;\nunix://*/source.sock:inspect:runtime-id) cat \"$FIXTURE/runtime.json\"; exit 0 ;;\nunix://*/source.sock:inspect:unrelated-id) cat \"$FIXTURE/unrelated.json\"; exit 0 ;;\nunix://*/target.sock:inspect:orbit-caddy) VERIFY;;\nunix://*/target.sock:inspect:orbit-runtime) cat \"$FIXTURE/runtime.json\"; exit 0 ;;\nunix://*/target.sock:inspect:*) cat \"$FIXTURE/unrelated.json\"; exit 0 ;;\nunix://*/source.sock:network:inspect) cat \"$FIXTURE/network.json\"; exit 0 ;;\nunix://*/target.sock:network:inspect) exit 1 ;;\nunix://*/target.sock:network:create*) exit 0 ;;\nunix://*/target.sock:network:rm*) exit 0 ;;\nunix://*/target.sock:load:*|unix://*/target.sock:create:*|unix://*/target.sock:start:*|unix://*/target.sock:rm:*) exit 0 ;;\nunix://*/source.sock:save:*|unix://*/source.sock:stop:*|unix://*/source.sock:start:*|unix://*/source.sock:rm:*) exit 0 ;;\n*) exit 0 ;;\nesac\n".replace("VERIFY", verify);
        // Keep fixture paths in the environment so the shell program remains readable.
        let body = body.replace("$FIXTURE", fixture_dir.to_str().unwrap());
        fs::write(&script, body).unwrap();
        fs::set_permissions(&script, fs::Permissions::from_mode(0o755)).unwrap();
        (dir, script, log)
    }

    fn cutover_fixture_dir(caddy: &LegacyContainer, runtime: &LegacyContainer) -> PathBuf {
        let dir = test_dir("fixtures");
        fs::write(dir.join("source.json"), &caddy.inspect).unwrap();
        fs::write(dir.join("runtime.json"), &runtime.inspect).unwrap();
        fs::write(
            dir.join("unrelated.json"),
            json!([{"Name":"/unrelated"}]).to_string(),
        )
        .unwrap();
        fs::write(dir.join("network.json"), json!([{"Name":"orbit-network","Labels":{"orbit.managed":"true","orbit.network.kind":"runtime"}}]).to_string()).unwrap();
        let mut target = inspect(&caddy.inspect).unwrap();
        target["Id"] = json!("feedfacecafebeef");
        target["NetworkSettings"]["Networks"]["orbit-network"]["Aliases"] =
            json!(["orbit-caddy", "feedfacecafebeef", "caddy"]);
        fs::write(dir.join("target.json"), json!([target]).to_string()).unwrap();
        dir
    }

    fn unix_socket(dir: &Path, name: &str) -> (PathBuf, String) {
        let socket_dir = dir.join(name);
        let _ = fs::remove_file(&socket_dir);
        let path = socket_dir;
        std::os::unix::net::UnixListener::bind(&path).unwrap();
        (path.clone(), format!("unix://{}", path.display()))
    }

    #[test]
    fn real_cutover_transfers_all_owned_containers_and_excludes_unrelated() {
        let _guard = CUTOVER_TEST_LOCK
            .lock()
            .unwrap_or_else(std::sync::PoisonError::into_inner);
        let caddy = caddy_fixture();
        let runtime = runtime_fixture();
        let fixture = cutover_fixture_dir(&caddy, &runtime);
        let (_fake_dir, fake, log) = fake_docker(&fixture, false);
        std::env::set_var("LOG", &log);
        std::env::set_var("FIXTURE", &fixture);
        let (_source_dir, source) = unix_socket(&fixture, "source.sock");
        let (_target_dir, target) = unix_socket(&fixture, "target.sock");
        let moved = cutover(&fake, &source, &target).unwrap();
        assert_eq!(
            moved.iter().map(|c| c.name.as_str()).collect::<Vec<_>>(),
            vec!["orbit-caddy", "orbit-runtime"]
        );
        let log_text = fs::read_to_string(log).unwrap();
        assert!(log_text.contains("network create --label orbit.managed=true --label orbit.network.kind=runtime orbit-network"));
        assert!(log_text.contains("unix://"));
        assert!(log_text.contains("stop orbit-caddy"));
        assert!(!log_text.contains("stop orbit-runtime"));
        assert!(log_text.contains("load"));
        assert!(log_text.contains("create --name orbit-caddy"));
        assert!(log_text.contains("create --name orbit-runtime"));
        assert!(log_text.contains("start orbit-caddy"));
        assert!(log_text
            .lines()
            .any(|line| line.contains("source.sock rm orbit-caddy")));
        assert!(log_text
            .lines()
            .any(|line| line.contains("source.sock rm orbit-runtime")));
        assert!(!log_text.contains("create --name unrelated"));
        assert!(!log_text.contains("stop unrelated"));
        assert!(!log_text.contains("rm unrelated"));
        let lines = log_text.lines().collect::<Vec<_>>();
        let first_stop = lines
            .iter()
            .position(|line| line.contains(" stop orbit-caddy"))
            .unwrap();
        let last_save = lines
            .iter()
            .rposition(|line| line.contains(" save "))
            .unwrap();
        let network_create = lines
            .iter()
            .position(|line| line.contains(" network create "))
            .unwrap();
        let first_remove = lines
            .iter()
            .position(|line| line.contains(" rm orbit-caddy"))
            .unwrap();
        let last_inspect = lines
            .iter()
            .rposition(|line| line.contains(" inspect orbit-runtime"))
            .unwrap();
        assert!(last_save < first_stop);
        assert!(network_create < first_stop);
        assert!(last_inspect < first_remove);
        std::env::remove_var("LOG");
        std::env::remove_var("FIXTURE");
    }

    #[test]
    fn real_cutover_failure_cleans_targets_and_restarts_only_originally_running() {
        let _guard = CUTOVER_TEST_LOCK
            .lock()
            .unwrap_or_else(std::sync::PoisonError::into_inner);
        let caddy = caddy_fixture();
        let runtime = runtime_fixture();
        let fixture = cutover_fixture_dir(&caddy, &runtime);
        let (_fake_dir, fake, log) = fake_docker(&fixture, true);
        std::env::set_var("LOG", &log);
        std::env::set_var("FIXTURE", &fixture);
        let (_source_dir, source) = unix_socket(&fixture, "source.sock");
        let (_target_dir, target) = unix_socket(&fixture, "target.sock");
        assert!(cutover(&fake, &source, &target).is_err());
        let log_text = fs::read_to_string(log).unwrap();
        assert!(log_text.contains("rm -f orbit-caddy"));
        assert!(log_text.contains("rm -f orbit-runtime"));
        assert!(log_text.contains("network rm orbit-network"));
        assert!(log_text.contains("start orbit-caddy"));
        assert!(!log_text.contains("start orbit-runtime"));
        assert!(!log_text
            .lines()
            .any(|line| line.contains("source.sock rm orbit-caddy")));
        assert!(!log_text
            .lines()
            .any(|line| line.contains("source.sock rm orbit-runtime")));
        std::env::remove_var("LOG");
        std::env::remove_var("FIXTURE");
    }
}
