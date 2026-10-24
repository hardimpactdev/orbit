use super::*;
use crate::command::{identity, run::file_sha256};
use sha2::{Digest, Sha256};
use std::path::PathBuf;

const PROPERTIES: &[&str] = &[
    "Id",
    "LoadState",
    "ActiveState",
    "SubState",
    "MainPID",
    "ControlGroup",
    "InvocationID",
    "User",
    "WorkingDirectory",
    "ExecStart",
    "FragmentPath",
    "DropInPaths",
    "NeedDaemonReload",
    "Transient",
];
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ProcessInput {
    pub checkout: String,
    pub orbit_home: PathBuf,
    pub target_sha: String,
    pub process_id: u64,
    pub unit: String,
    pub user: String,
    pub directory: PathBuf,
    pub command: Vec<String>,
    pub rendered: String,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct ProcessIdentity {
    pub source_sha: String,
    pub unit_sha256: String,
    pub runtime_sha256: String,
}
fn gateway_path(path: &Path, layout: &Layout, home: &Path) -> bool {
    [
        layout.current.as_path(),
        &layout.base.join("releases"),
        &layout.shared(),
        home,
    ]
    .into_iter()
    .any(|root| {
        path.starts_with(root)
            || fs::canonicalize(root).is_ok_and(|resolved| path.starts_with(resolved))
    })
}
fn start_time(path: &Path) -> Result<String, Error> {
    let stat = text(&path.join("stat"), 65536, "runtime")?;
    let fields = stat.rsplit_once(") ").ok_or_else(|| refuse("runtime"))?.1;
    let value = fields.split(' ').nth(19).ok_or_else(|| refuse("runtime"))?;
    if value.is_empty() || !value.bytes().all(|b| b.is_ascii_digit()) {
        return Err(refuse("runtime"));
    }
    Ok(value.into())
}
fn sha256(bytes: impl AsRef<[u8]>) -> String {
    format!("{:x}", Sha256::digest(bytes.as_ref()))
}
fn runtime_hash(
    properties: &BTreeMap<String, String>,
    started: &str,
    binary: &str,
    environment: &str,
) -> Result<String, Error> {
    let json = serde_json::to_string(&(properties, started, binary, environment))
        .map_err(|_| refuse("runtime"))?;
    // Preserve JSON_UNESCAPED_SLASHES hashes, including PHP's UTF-16 escapes for non-ASCII characters.
    let mut canonical = String::new();
    for character in json.chars() {
        if character.is_ascii() {
            canonical.push(character);
        } else {
            for code in character.encode_utf16(&mut [0; 2]) {
                canonical.push_str(&format!("\\u{code:04x}"));
            }
        }
    }
    Ok(sha256(canonical))
}
pub(super) fn inspect(
    files: &Files,
    host: &mut impl recipe::Host,
    input: &ProcessInput,
) -> Result<ProcessIdentity, Error> {
    if !hex(&input.target_sha, 40)
        || input.process_id == 0
        || input.process_id > i64::MAX as u64
        || processes::check_name(processes::Runtime::Systemd, input.process_id, &input.unit)
            .is_err()
        || !input.orbit_home.is_absolute()
        || input
            .orbit_home
            .components()
            .any(|c| matches!(c, std::path::Component::ParentDir))
        || input.command.is_empty()
        || input.command.iter().any(|s| s.contains('\0'))
        || !processes::marked(&input.rendered, input.process_id)
    {
        return Err(refuse("identity"));
    }
    let (layout, source_sha) = source(&input.checkout)?;
    let directory = fs::canonicalize(&input.directory).map_err(|_| refuse("path"))?;
    let executable = fs::canonicalize(&input.command[0]).map_err(|_| refuse("path"))?;
    if !input.directory.is_absolute()
        || !Path::new(&input.command[0]).is_absolute()
        || gateway_path(&directory, &layout, &input.orbit_home)
        || gateway_path(&executable, &layout, &input.orbit_home)
        || executable
            .file_name()
            .and_then(|n| n.to_str())
            .is_none_or(php)
        || input.command.iter().any(|arg| {
            [
                "artisan",
                "horizon",
                "schedule:work",
                "schedule:run",
                "queue:work",
                "queue:listen",
                "tasks:tick",
            ]
            .contains(&arg.as_str())
        })
    {
        return Err(refuse("consumer"));
    }
    for argument in &input.command {
        let path = if Path::new(argument).is_absolute() {
            PathBuf::from(argument)
        } else {
            directory.join(argument)
        };
        if fs::canonicalize(path)
            .is_ok_and(|resolved| gateway_path(&resolved, &layout, &input.orbit_home))
        {
            return Err(refuse("consumer"));
        }
    }
    let p = properties(host, &input.unit, PROPERTIES)?;
    if p.len() != PROPERTIES.len() {
        return Err(refuse("properties"));
    }
    let pid = &p["MainPID"];
    let group = format!("/system.slice/{}", input.unit);
    if p["Id"] != input.unit
        || p["LoadState"] != "loaded"
        || p["ActiveState"] != "active"
        || p["SubState"] != "running"
        || pid.starts_with('0')
        || pid.is_empty()
        || !pid.bytes().all(|b| b.is_ascii_digit())
        || pid.parse::<u32>().is_err()
        || !hex(&p["InvocationID"], 32)
        || p["ControlGroup"] != group
        || p["User"] != input.user
        || p["WorkingDirectory"] != input.directory.to_string_lossy()
        || p["ExecStart"].is_empty()
        || !p["DropInPaths"].is_empty()
        || p["NeedDaemonReload"] != "no"
        || p["Transient"] != "no"
    {
        return Err(refuse("runtime"));
    }
    let fragment = &p["FragmentPath"];
    if ![
        "/etc/systemd/system",
        "/usr/lib/systemd/system",
        "/lib/systemd/system",
    ]
    .iter()
    .any(|root| *fragment == format!("{root}/{}", input.unit))
    {
        return Err(refuse("unit"));
    }
    let path = files.path(fragment);
    let meta = fs::symlink_metadata(&path).map_err(|_| refuse("unit"))?;
    if !meta.is_file()
        || meta.uid() != files.uid
        || meta.mode() & 0o022 != 0
        || read(&path, 1024 * 1024, "unit")? != input.rendered.as_bytes()
    {
        return Err(refuse("unit"));
    }
    let cgroup = files.path(&format!("/sys/fs/cgroup{group}"));
    let members = text(&cgroup.join("cgroup.procs"), 65536, "cgroup")?;
    if members.split_whitespace().collect::<Vec<_>>() != [pid.as_str()]
        || !text(&cgroup.join("cgroup.events"), 65536, "cgroup")?
            .lines()
            .any(|line| line == "populated 1")
        || !text(&cgroup.join("cgroup.stat"), 65536, "cgroup")?
            .lines()
            .any(|line| line == "nr_descendants 0")
    {
        return Err(refuse("cgroup"));
    }
    let account = identity::passwd(&input.user).ok_or_else(|| refuse("account"))?;
    let process = files.path(&format!("/proc/{pid}"));
    let started = start_time(&process)?;
    let argv = read(&process.join("cmdline"), 1024 * 1024, "runtime")?;
    let environment = read(&process.join("environ"), 1024 * 1024, "runtime")?;
    let expected = format!("{}\0", input.command.join("\0"));
    if account.uid == 0
        || fs::metadata(&process).map_err(|_| refuse("account"))?.uid() != account.uid
        || argv != expected.as_bytes()
        || fs::canonicalize(process.join("cwd")).ok().as_ref() != Some(&directory)
        || fs::canonicalize(process.join("exe")).ok().as_ref() != Some(&executable)
    {
        return Err(refuse("runtime"));
    }
    let binary = file_sha256(&process.join("exe")).map_err(|_| refuse("runtime"))?;
    if start_time(&process)? != started || properties(host, &input.unit, PROPERTIES)? != p {
        return Err(refuse("changed"));
    }
    Ok(ProcessIdentity {
        source_sha,
        unit_sha256: sha256(input.rendered.as_bytes()),
        runtime_sha256: runtime_hash(&p, &started, &binary, &sha256(environment))?,
    })
}
pub struct InspectProcess;
impl Command for InspectProcess {
    const NAME: &'static str = "gateway.inspect-release-process";
    const SUMMARY: &'static str =
        "Observe the exact native identity of a declared unrelated Gateway Node Process.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(90);
    type Input = ProcessInput;
    type Output = ProcessIdentity;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::super::linux_only(platform, Privilege::Account)
    }
    fn run(input: ProcessInput, context: &Context) -> Result<ProcessIdentity, Error> {
        inspect(&Files::system(), &mut recipe::Native(context), &input)
    }
}
#[cfg(test)]
mod tests;
