use serde::Deserialize;
use std::fmt;
use std::fs::{self, OpenOptions};
use std::io;
use std::net::IpAddr;
use std::path::{Path, PathBuf};
use std::process::{Command, Output};
use std::time::Duration;

pub const PROFILE: &str = "orbit";
pub const MIN_COLIMA_VERSION: (u64, u64, u64) = (0, 8, 1);
#[derive(Debug)]
pub enum ColimaError {
    MissingExecutable(String),
    UnsupportedVersion(String),
    OwnershipConflict,
    InsufficientMemory,
    InvalidStatus(String),
    Command(String),
    DockerReadinessTimeout(String),
    Io(io::Error),
    Json(serde_json::Error),
}
impl fmt::Display for ColimaError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "Colima error: {:?}", self)
    }
}
impl std::error::Error for ColimaError {}
impl From<io::Error> for ColimaError {
    fn from(value: io::Error) -> Self {
        Self::Io(value)
    }
}
impl From<serde_json::Error> for ColimaError {
    fn from(value: serde_json::Error) -> Self {
        Self::Json(value)
    }
}
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ProviderReady {
    pub socket: String,
}
#[derive(Clone, Debug, PartialEq, Eq, serde::Serialize, Deserialize)]
pub struct OwnershipRecord {
    pub provider: String,
    pub profile: String,
    pub runtime: String,
    pub lifecycle: String,
    pub state: String,
}
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ColimaCommand {
    pub program: PathBuf,
    pub args: Vec<String>,
}

pub const DEFAULT_DOCKER_READY_TIMEOUT: Duration = Duration::from_secs(30);
pub const DEFAULT_DOCKER_READY_INTERVAL: Duration = Duration::from_millis(250);

/// Poll Docker through the owned endpoint. The closures make timeout behavior deterministic.
pub fn poll_docker_ready<F, S>(
    endpoint: &str,
    timeout: Duration,
    interval: Duration,
    mut now: impl FnMut() -> Duration,
    mut probe: F,
    mut sleep: S,
) -> Result<(), ColimaError>
where
    F: FnMut(&str, Option<&str>) -> bool,
    S: FnMut(Duration),
{
    let started = now();
    loop {
        if probe(endpoint, None) {
            return Ok(());
        }
        if now().saturating_sub(started) >= timeout {
            return Err(ColimaError::DockerReadinessTimeout(endpoint.into()));
        }
        sleep(interval);
    }
}

pub fn docker_info_command(docker: impl Into<PathBuf>, endpoint: &str) -> Command {
    let mut command = Command::new(docker.into());
    command
        .args(["info"])
        .env("DOCKER_HOST", endpoint)
        .env_remove("DOCKER_CONTEXT");
    command
}

pub fn stop_plan(colima: impl Into<PathBuf>) -> ColimaCommand {
    ColimaCommand {
        program: colima.into(),
        args: vec!["stop".into(), PROFILE.into()],
    }
}

pub fn homebrew_install_plan(brew: impl Into<PathBuf>) -> ColimaCommand {
    ColimaCommand {
        program: brew.into(),
        args: vec!["install".into(), "colima".into(), "docker".into()],
    }
}

pub fn install_local_runtime(brew: impl Into<PathBuf>) -> Result<(), ColimaError> {
    let brew = resolve_executable(&brew.into())?;
    run(&homebrew_install_plan(brew))?;
    Ok(())
}

pub fn reset_delete_plan(
    colima: impl Into<PathBuf>,
    version: &str,
) -> Result<ColimaCommand, ColimaError> {
    if !colima_version_supported(version) {
        return Err(ColimaError::UnsupportedVersion(version.into()));
    }
    let mut args = vec!["delete".into(), PROFILE.into(), "--force".into()];
    if version_at_least(version, (0, 9, 0)) {
        args.push("--data".into());
    }
    Ok(ColimaCommand {
        program: colima.into(),
        args,
    })
}

fn version_at_least(version: &str, minimum: (u64, u64, u64)) -> bool {
    let token = version
        .split_whitespace()
        .find(|x| x.trim_start_matches('v').split('.').count() >= 3)
        .unwrap_or("");
    let n: Vec<u64> = token
        .trim_start_matches('v')
        .split('.')
        .take(3)
        .map(|x| x.parse().unwrap_or(0))
        .collect();
    (
        n.first().copied().unwrap_or(0),
        n.get(1).copied().unwrap_or(0),
        n.get(2).copied().unwrap_or(0),
    ) >= minimum
}

pub fn reset_owned_profile(home: &Path, colima: &Path) -> Result<(), ColimaError> {
    let record = load_record(home)?;
    validate_record(&record)?;
    if record.state != "ready" {
        return Err(ColimaError::OwnershipConflict);
    }
    let profile = home.join(".colima").join(PROFILE);
    let metadata = fs::symlink_metadata(&profile).map_err(|_| ColimaError::OwnershipConflict)?;
    if metadata.file_type().is_symlink() || !metadata.is_dir() {
        return Err(ColimaError::OwnershipConflict);
    }
    let executable = resolve_executable(colima)?;
    let version_output = run(&direct_command(executable.clone(), vec!["version".into()]))?;
    let detected = String::from_utf8_lossy(&version_output.stdout).into_owned();
    if !colima_version_supported(&detected) {
        return Err(ColimaError::UnsupportedVersion(detected));
    }
    run(&stop_plan(executable.clone()))?;
    run(&reset_delete_plan(executable, &detected)?)?;
    fs::remove_file(ownership_path(home))?;
    if let Some(parent) = ownership_path(home).parent() {
        fs::File::open(parent)?.sync_all()?;
    }
    Ok(())
}

pub fn has_owned_profile(home: &Path) -> bool {
    load_record(home).is_ok_and(|record| record.state == "ready")
        && fs::symlink_metadata(home.join(".colima").join(PROFILE))
            .is_ok_and(|m| m.is_dir() && !m.file_type().is_symlink())
}

fn profile_exists(home: &Path) -> bool {
    fs::symlink_metadata(home.join(".colima").join(PROFILE))
        .is_ok_and(|metadata| metadata.is_dir() && !metadata.file_type().is_symlink())
}

pub fn stop_owned_profile(home: &Path, colima: &Path) -> Result<(), ColimaError> {
    let record = load_record(home)?;
    validate_record(&record)?;
    if record.state != "ready" {
        return Err(ColimaError::OwnershipConflict);
    }
    let executable = resolve_executable(colima)?;
    run(&stop_plan(executable))?;
    Ok(())
}
#[derive(Deserialize)]
struct Status {
    #[serde(default)]
    status: Option<String>,
    runtime: String,
    kubernetes: bool,
    #[serde(rename = "docker_socket")]
    docker_socket: String,
}
pub fn first_creation_plan(
    logical_cpus: u32,
    physical_memory_bytes: u64,
) -> Result<Vec<String>, ColimaError> {
    let cpus = (logical_cpus / 2).max(1);
    let memory = physical_memory_bytes / (1024 * 1024 * 1024) / 2;
    if memory < 2 {
        return Err(ColimaError::InsufficientMemory);
    }
    Ok(vec![
        "start",
        PROFILE,
        "--runtime",
        "docker",
        "--kubernetes=false",
        "--activate=false",
        "--cpus",
        &cpus.to_string(),
        "--memory",
        &memory.to_string(),
    ]
    .into_iter()
    .map(String::from)
    .collect())
}
pub fn existing_start_plan() -> Vec<String> {
    vec![
        "start",
        PROFILE,
        "--runtime",
        "docker",
        "--kubernetes=false",
        "--activate=false",
    ]
    .into_iter()
    .map(String::from)
    .collect()
}

fn start_plan_for_profile(
    reserved: bool,
    profile_exists: bool,
    logical_cpus: u32,
    physical_memory_bytes: u64,
) -> Result<Vec<String>, ColimaError> {
    if reserved && !profile_exists {
        first_creation_plan(logical_cpus, physical_memory_bytes)
    } else {
        Ok(existing_start_plan())
    }
}
pub fn parse_status(json: &str) -> Result<ProviderReady, ColimaError> {
    let s: Status = serde_json::from_str(json)?;
    if s.status.as_deref().is_some_and(|x| x != "Running")
        || s.runtime != "docker"
        || s.kubernetes
        || !s.docker_socket.starts_with("unix://")
    {
        return Err(ColimaError::InvalidStatus(
            "profile is not a ready Docker runtime".into(),
        ));
    }
    Ok(ProviderReady {
        socket: s.docker_socket,
    })
}
pub fn colima_version_supported(version: &str) -> bool {
    let token = version
        .split_whitespace()
        .find(|x| x.chars().filter(|c| *c == '.').count() == 2)
        .unwrap_or("");
    let n: Vec<u64> = token
        .trim_start_matches('v')
        .split('.')
        .take(3)
        .map(|x| x.parse().unwrap_or(0))
        .collect();
    (
        n.first().copied().unwrap_or(0),
        n.get(1).copied().unwrap_or(0),
        n.get(2).copied().unwrap_or(0),
    ) >= MIN_COLIMA_VERSION
}
pub fn ownership_path(home: &Path) -> PathBuf {
    home.join("Library/Application Support/Orbit/colima-owner.json")
}
pub fn validate_record(r: &OwnershipRecord) -> Result<(), ColimaError> {
    if r.provider != "colima"
        || r.profile != PROFILE
        || r.runtime != "docker"
        || r.lifecycle != "orbit-desktop"
        || !matches!(r.state.as_str(), "reserved" | "ready")
    {
        return Err(ColimaError::OwnershipConflict);
    }
    Ok(())
}
pub fn persist_record(home: &Path, r: &OwnershipRecord) -> Result<(), ColimaError> {
    validate_record(r)?;
    let p = ownership_path(home);
    let d = p.parent().unwrap();
    validate_no_symlink(home)?;
    let directory_was_absent = !d.exists();
    fs::create_dir_all(d)?;
    if directory_was_absent {
        use std::os::unix::fs::PermissionsExt;
        fs::set_permissions(d, fs::Permissions::from_mode(0o700))?;
    }
    validate_directory(d, 0o700)?;
    if let Ok(metadata) = fs::symlink_metadata(&p) {
        if metadata.file_type().is_symlink() || !metadata.is_file() {
            return Err(ColimaError::OwnershipConflict);
        }
    }
    let t = p.with_extension("tmp");
    if let Ok(m) = fs::symlink_metadata(&t) {
        if m.file_type().is_symlink() {
            return Err(ColimaError::OwnershipConflict);
        }
        if m.is_file() {
            fs::remove_file(&t)?;
        } else {
            return Err(ColimaError::OwnershipConflict);
        }
    }
    use std::io::Write;
    use std::os::unix::fs::OpenOptionsExt;
    let mut f = OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o600)
        .open(&t)?;
    f.write_all(&serde_json::to_vec(r)?)?;
    f.sync_all()?;
    fs::rename(t, &p)?;
    validate_regular_file(&p, 0o600)?;
    fs::File::open(d)?.sync_all()?;
    Ok(())
}
pub fn validate_socket(home: &Path, url: &str) -> Result<String, ColimaError> {
    let p = PathBuf::from(
        url.strip_prefix("unix://")
            .ok_or_else(|| ColimaError::InvalidStatus("non-unix".into()))?,
    );
    let e = home.join(".colima").join(PROFILE).join("docker.sock");
    if p != e {
        return Err(ColimaError::InvalidStatus("socket outside profile".into()));
    }
    validate_no_symlink(home)?;
    validate_no_symlink(&home.join(".colima"))?;
    validate_no_symlink(&home.join(".colima").join(PROFILE))?;
    let m = fs::symlink_metadata(&p)
        .map_err(|_| ColimaError::InvalidStatus("socket missing".into()))?;
    if m.file_type().is_symlink() {
        return Err(ColimaError::InvalidStatus("symlink socket".into()));
    }
    use std::os::unix::fs::FileTypeExt;
    if !m.file_type().is_socket() {
        return Err(ColimaError::InvalidStatus(
            "Docker endpoint is not a socket".into(),
        ));
    }
    Ok(url.into())
}
pub fn reserve_ownership(home: &Path) -> Result<OwnershipRecord, ColimaError> {
    let path = ownership_path(home);
    let profile = home.join(".colima").join(PROFILE);
    validate_no_symlink(home)?;
    if path.parent().is_some_and(|parent| parent.exists()) {
        validate_directory(path.parent().unwrap(), 0o700)?;
    }
    let profile_exists = fs::symlink_metadata(&profile).is_ok();
    if profile_exists {
        let profile_metadata = fs::symlink_metadata(&profile).unwrap();
        if profile_metadata.file_type().is_symlink() || !profile_metadata.is_dir() {
            return Err(ColimaError::OwnershipConflict);
        }
    }
    if profile_exists && fs::symlink_metadata(&path).is_err() {
        return Err(ColimaError::OwnershipConflict);
    }
    if fs::symlink_metadata(&path).is_ok() {
        let record = load_record(home)?;
        if record.state == "ready" && !profile_exists {
            return Err(ColimaError::OwnershipConflict);
        }
        return Ok(record);
    }
    if let Some(p) = path.parent() {
        fs::create_dir_all(p)?;
        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            fs::set_permissions(p, fs::Permissions::from_mode(0o700))?;
        }
    }
    let r = OwnershipRecord {
        provider: "colima".into(),
        profile: PROFILE.into(),
        runtime: "docker".into(),
        lifecycle: "orbit-desktop".into(),
        state: "reserved".into(),
    };
    persist_record(home, &r)?;
    Ok(r)
}
pub fn mark_ready(home: &Path) -> Result<(), ColimaError> {
    let mut r = load_record(home)?;
    r.state = "ready".into();
    persist_record(home, &r)
}
fn load_record(home: &Path) -> Result<OwnershipRecord, ColimaError> {
    let path = ownership_path(home);
    let parent = path.parent().ok_or(ColimaError::OwnershipConflict)?;
    validate_no_symlink(home)?;
    validate_directory(parent, 0o700)?;
    validate_regular_file(&path, 0o600)?;
    let record: OwnershipRecord = serde_json::from_slice(&fs::read(&path)?)?;
    validate_record(&record)?;
    Ok(record)
}
pub fn direct_command(program: impl Into<PathBuf>, args: Vec<String>) -> ColimaCommand {
    ColimaCommand {
        program: program.into(),
        args,
    }
}
fn ssh_command(colima: &Path, args: Vec<String>) -> ColimaCommand {
    let mut command_args = vec!["ssh".into(), PROFILE.into(), "--".into()];
    command_args.extend(args);
    direct_command(colima.to_path_buf(), command_args)
}
fn normalize_wireguard_route(colima: &Path, address: IpAddr) -> Result<(), ColimaError> {
    let output = run(&ssh_command(
        colima,
        vec![
            "ip".into(),
            "-4".into(),
            "-o".into(),
            "addr".into(),
            "show".into(),
            "dev".into(),
            "lo".into(),
        ],
    ))?;
    let prefix = String::from_utf8_lossy(&output.stdout)
        .split_whitespace()
        .find_map(|token| {
            let (ip, prefix) = token.split_once('/')?;
            (ip.parse::<IpAddr>().ok()? == address)
                .then(|| prefix.parse::<u8>().ok())
                .flatten()
        })
        .ok_or_else(|| {
            ColimaError::InvalidStatus(format!(
                "WireGuard address {address} is not on Colima loopback"
            ))
        })?;
    if prefix == 32 {
        return Ok(());
    }
    let original = format!("{address}/{prefix}");
    let replace = |cidr: &str| {
        run(&ssh_command(
            colima,
            vec![
                "ip".into(),
                "addr".into(),
                "replace".into(),
                cidr.into(),
                "dev".into(),
                "lo".into(),
            ],
        ))
    };
    if replace(&format!("{address}/32")).is_ok() {
        return Ok(());
    }
    let rollback = replace(&original);
    Err(ColimaError::Command(format!(
        "failed to normalize loopback route for {address}; rollback={}",
        rollback.is_ok()
    )))
}
pub fn run(c: &ColimaCommand) -> Result<Output, ColimaError> {
    let o = Command::new(&c.program).args(&c.args).output()?;
    if o.status.success() {
        Ok(o)
    } else {
        Err(ColimaError::Command(
            String::from_utf8_lossy(&o.stderr).into(),
        ))
    }
}
pub fn ensure_ready(
    home: &Path,
    colima: &Path,
    docker: &Path,
    wireguard_address: IpAddr,
) -> Result<ProviderReady, ColimaError> {
    let colima = resolve_executable(colima)?;
    let docker = resolve_executable(docker)?;
    let version = run(&direct_command(colima.clone(), vec!["version".into()]))?;
    let version = String::from_utf8_lossy(&version.stdout);
    if !colima_version_supported(&version) {
        return Err(ColimaError::UnsupportedVersion(version.into()));
    }
    let record = reserve_ownership(home)?;
    let status = run(&direct_command(
        colima.clone(),
        vec!["status".into(), PROFILE.into(), "--json".into()],
    ));
    if status.is_err() {
        let args = if record.state == "reserved" && !profile_exists(home) {
            let cpus = std::thread::available_parallelism()
                .map(|x| x.get() as u32)
                .unwrap_or(1);
            let memory = host_memory_bytes().ok_or(ColimaError::InsufficientMemory)?;
            start_plan_for_profile(true, false, cpus, memory)?
        } else {
            start_plan_for_profile(record.state == "reserved", profile_exists(home), 1, 0)?
        };
        run(&direct_command(colima.clone(), args))?;
        normalize_wireguard_route(&colima, wireguard_address)?;
    }
    let status = run(&direct_command(
        colima.clone(),
        vec!["status".into(), PROFILE.into(), "--json".into()],
    ))?;
    let ready = parse_status(&String::from_utf8_lossy(&status.stdout))?;
    validate_socket(home, &ready.socket)?;
    let expected = home.join(".colima").join(PROFILE);
    let socket = ready.socket.trim_start_matches("unix://");
    let socket_path = fs::canonicalize(socket)
        .map_err(|_| ColimaError::InvalidStatus("Docker socket is not accessible".into()))?;
    if socket_path
        != fs::canonicalize(expected.join("docker.sock"))
            .map_err(|_| ColimaError::InvalidStatus("Docker socket identity mismatch".into()))?
    {
        return Err(ColimaError::InvalidStatus(
            "Docker socket is outside owned Colima profile".into(),
        ));
    }
    if fs::canonicalize(socket_path.parent().unwrap())? != fs::canonicalize(expected)? {
        return Err(ColimaError::InvalidStatus(
            "Docker socket parent is outside owned profile".into(),
        ));
    }
    let started = std::time::Instant::now();
    poll_docker_ready(
        &ready.socket,
        DEFAULT_DOCKER_READY_TIMEOUT,
        DEFAULT_DOCKER_READY_INTERVAL,
        || started.elapsed(),
        |endpoint, _| {
            docker_info_command(docker.clone(), endpoint)
                .output()
                .is_ok_and(|output| output.status.success())
        },
        std::thread::sleep,
    )?;
    mark_ready(home)?;
    Ok(ready)
}
fn resolve_executable(path: &Path) -> Result<PathBuf, ColimaError> {
    if path.components().count() > 1 {
        return validate_executable(path);
    }
    let Some(paths) = std::env::var_os("PATH") else {
        return Err(ColimaError::MissingExecutable(path.display().to_string()));
    };
    for dir in std::env::split_paths(&paths) {
        let candidate = dir.join(path);
        if let Ok(candidate) = validate_executable(&candidate) {
            return Ok(candidate);
        }
    }
    Err(ColimaError::MissingExecutable(path.display().to_string()))
}
fn validate_executable(path: &Path) -> Result<PathBuf, ColimaError> {
    let metadata = fs::symlink_metadata(path)
        .map_err(|_| ColimaError::MissingExecutable(path.display().to_string()))?;
    let target = if metadata.file_type().is_symlink() {
        fs::canonicalize(path)
            .map_err(|_| ColimaError::MissingExecutable(path.display().to_string()))?
    } else {
        path.to_path_buf()
    };
    let target_metadata = fs::symlink_metadata(&target)
        .map_err(|_| ColimaError::MissingExecutable(path.display().to_string()))?;
    if !target_metadata.file_type().is_file() {
        return Err(ColimaError::MissingExecutable(path.display().to_string()));
    }
    use std::os::unix::fs::PermissionsExt;
    if target_metadata.permissions().mode() & 0o111 == 0 {
        return Err(ColimaError::MissingExecutable(path.display().to_string()));
    }
    Ok(path.to_path_buf())
}
fn validate_no_symlink(path: &Path) -> Result<(), ColimaError> {
    let mut current = PathBuf::new();
    for component in path.components() {
        current.push(component);
        if let Ok(metadata) = fs::symlink_metadata(&current) {
            if metadata.file_type().is_symlink() {
                return Err(ColimaError::OwnershipConflict);
            }
        }
    }
    Ok(())
}
fn validate_directory(path: &Path, mode: u32) -> Result<(), ColimaError> {
    let metadata = fs::symlink_metadata(path).map_err(ColimaError::Io)?;
    if !metadata.is_dir() || metadata.file_type().is_symlink() {
        return Err(ColimaError::OwnershipConflict);
    }
    use std::os::unix::fs::PermissionsExt;
    if metadata.permissions().mode() & 0o777 != mode {
        return Err(ColimaError::OwnershipConflict);
    }
    Ok(())
}
fn validate_regular_file(path: &Path, mode: u32) -> Result<(), ColimaError> {
    let metadata = fs::symlink_metadata(path).map_err(ColimaError::Io)?;
    if !metadata.is_file() || metadata.file_type().is_symlink() {
        return Err(ColimaError::OwnershipConflict);
    }
    use std::os::unix::fs::PermissionsExt;
    if metadata.permissions().mode() & 0o777 != mode {
        return Err(ColimaError::OwnershipConflict);
    }
    Ok(())
}
fn host_memory_bytes() -> Option<u64> {
    let output = Command::new("sysctl")
        .args(["-n", "hw.memsize"])
        .output()
        .ok()?;
    String::from_utf8_lossy(&output.stdout)
        .trim()
        .parse()
        .ok()
        .filter(|x: &u64| x / (1024 * 1024 * 1024) / 2 >= 2)
}
#[cfg(test)]
mod tests {
    use super::*;
    use std::os::unix::fs::{symlink, PermissionsExt};
    use std::os::unix::net::UnixListener;
    use std::sync::atomic::{AtomicU64, Ordering};
    static FIXTURE_ID: AtomicU64 = AtomicU64::new(0);
    struct Fixture(PathBuf);
    impl Fixture {
        fn new() -> Self {
            let id = FIXTURE_ID.fetch_add(1, Ordering::Relaxed);
            let path =
                std::env::temp_dir().join(format!("orbit-colima-{}-{}", std::process::id(), id));
            fs::create_dir_all(&path).unwrap();
            Self(fs::canonicalize(path).unwrap())
        }
        fn home(&self) -> &Path {
            &self.0
        }
    }
    impl Drop for Fixture {
        fn drop(&mut self) {
            let _ = fs::remove_dir_all(&self.0);
        }
    }
    #[test]
    fn first_creation_uses_safe_resource_defaults() {
        assert_eq!(
            first_creation_plan(8, 16 * 1024 * 1024 * 1024).unwrap(),
            vec![
                "start",
                "orbit",
                "--runtime",
                "docker",
                "--kubernetes=false",
                "--activate=false",
                "--cpus",
                "4",
                "--memory",
                "8"
            ]
        )
    }

    #[test]
    fn install_and_reset_plans_use_exact_non_shell_argv() {
        assert_eq!(
            homebrew_install_plan("brew").args,
            ["install", "colima", "docker"]
        );
        assert_eq!(
            reset_delete_plan("colima", "Colima Version 0.8.9")
                .unwrap()
                .args,
            ["delete", "orbit", "--force"]
        );
        assert_eq!(
            reset_delete_plan("colima", "0.9.0").unwrap().args,
            ["delete", "orbit", "--force", "--data"]
        );
        assert!(reset_delete_plan("colima", "0.8.0").is_err());
    }

    fn fake_script(fixture: &Fixture, name: &str, body: &str) -> (PathBuf, PathBuf) {
        let bin = fixture.home().join(name);
        let log = fixture.home().join(format!("{name}.log"));
        fs::write(
            &bin,
            format!(
                "#!/bin/sh\nprintf '%s\\n' \"$*\" >> '{}'\n{}\n",
                log.display(),
                body
            ),
        )
        .unwrap();
        fs::set_permissions(&bin, fs::Permissions::from_mode(0o755)).unwrap();
        (bin, log)
    }

    fn ready_fixture(fixture: &Fixture) {
        fs::create_dir_all(fixture.home().join(".colima/orbit")).unwrap();
        persist_record(
            fixture.home(),
            &OwnershipRecord {
                provider: "colima".into(),
                profile: "orbit".into(),
                runtime: "docker".into(),
                lifecycle: "orbit-desktop".into(),
                state: "ready".into(),
            },
        )
        .unwrap();
    }

    #[test]
    fn fake_colima_08_deletes_without_data() {
        let f = Fixture::new();
        ready_fixture(&f);
        let (bin, log) = fake_script(
            &f,
            "colima08",
            "if [ \"$1\" = version ]; then echo 'Colima Version 0.8.9'; fi",
        );
        reset_owned_profile(f.home(), &bin).unwrap();
        assert_eq!(
            fs::read_to_string(log).unwrap(),
            "version\nstop orbit\ndelete orbit --force\n"
        );
        assert!(!ownership_path(f.home()).exists());
    }

    #[test]
    fn fake_colima_09_deletes_with_data() {
        let f = Fixture::new();
        ready_fixture(&f);
        let (bin, log) = fake_script(
            &f,
            "colima09",
            "if [ \"$1\" = version ]; then echo 'Colima Version 0.9.0'; fi",
        );
        reset_owned_profile(f.home(), &bin).unwrap();
        assert!(fs::read_to_string(log)
            .unwrap()
            .contains("delete orbit --force --data"));
    }

    #[test]
    fn reset_failures_preserve_ownership_and_short_circuit() {
        for (name, body, expected) in [("bad-version", "if [ \"$1\" = version ]; then echo bad; fi", "version\n"), ("stop-fail", "if [ \"$1\" = version ]; then echo 0.9.0; elif [ \"$1\" = stop ]; then echo not running >&2; exit 1; fi", "version\nstop orbit\n"), ("delete-fail", "if [ \"$1\" = version ]; then echo 0.9.0; elif [ \"$1\" = delete ]; then exit 1; fi", "version\nstop orbit\ndelete orbit --force --data\n")] {
            let f = Fixture::new(); ready_fixture(&f); let (bin, log) = fake_script(&f, name, body);
            assert!(reset_owned_profile(f.home(), &bin).is_err());
            assert!(ownership_path(f.home()).exists());
            assert_eq!(fs::read_to_string(log).unwrap(), expected);
        }
    }

    #[test]
    fn reset_refuses_unowned_reserved_mismatched_and_symlinked_state_without_execution() {
        for kind in ["unowned", "reserved", "mismatched", "symlinked"] {
            let f = Fixture::new();
            let (bin, log) = fake_script(&f, kind, "exit 0");
            fs::create_dir_all(f.home().join(".colima/orbit")).unwrap();
            if kind == "unowned" {
            } else if kind == "symlinked" {
                let real = f.home().join("real");
                fs::create_dir_all(&real).unwrap();
                fs::remove_dir(f.home().join(".colima/orbit")).unwrap();
                symlink(real, f.home().join(".colima/orbit")).unwrap();
            } else {
                let record = OwnershipRecord {
                    provider: "colima".into(),
                    profile: "orbit".into(),
                    runtime: "docker".into(),
                    lifecycle: "orbit-desktop".into(),
                    state: if kind == "reserved" {
                        "reserved".into()
                    } else {
                        "ready".into()
                    },
                };
                persist_record(f.home(), &record).unwrap();
                if kind == "mismatched" {
                    fs::write(
                        ownership_path(f.home()),
                        serde_json::to_vec(&OwnershipRecord {
                            provider: "other".into(),
                            ..record
                        })
                        .unwrap(),
                    )
                    .unwrap();
                }
            }
            assert!(reset_owned_profile(f.home(), &bin).is_err());
            assert!(!log.exists() || fs::read_to_string(log).unwrap().is_empty());
        }
    }

    #[test]
    fn fake_brew_install_executes_exact_argv_and_classifies_failures() {
        let f = Fixture::new();
        let (bin, log) = fake_script(&f, "brew", "exit 0");
        install_local_runtime(&bin).unwrap();
        assert_eq!(fs::read_to_string(log).unwrap(), "install colima docker\n");
        let missing = f.home().join("missing-brew");
        assert!(matches!(
            install_local_runtime(&missing),
            Err(ColimaError::MissingExecutable(_))
        ));
        let (fail, _) = fake_script(&f, "brew-fail", "exit 3");
        assert!(matches!(
            install_local_runtime(&fail),
            Err(ColimaError::Command(_))
        ));
    }

    #[test]
    fn owned_profile_predicate_validates_record_identity_and_profile() {
        let f = Fixture::new();
        assert!(!has_owned_profile(f.home()));
        ready_fixture(&f);
        assert!(has_owned_profile(f.home()));
        let mut bad: OwnershipRecord = load_record(f.home()).unwrap();
        bad.provider = "other".into();
        persist_record(
            f.home(),
            &OwnershipRecord {
                provider: "colima".into(),
                profile: "orbit".into(),
                runtime: "docker".into(),
                lifecycle: "orbit-desktop".into(),
                state: "ready".into(),
            },
        )
        .unwrap();
        fs::write(ownership_path(f.home()), serde_json::to_vec(&bad).unwrap()).unwrap();
        assert!(!has_owned_profile(f.home()));
    }
    #[test]
    fn rejects_old_colima() {
        assert!(!colima_version_supported(
            "colima version 0.8.0\ngit commit abc"
        ));
        assert!(colima_version_supported(
            "colima version 0.8.1\ngit commit abc"
        ));
        assert!(colima_version_supported(
            "colima version 0.10.3\ngit commit abc"
        ));
        assert!(!colima_version_supported("colima version unknown"));
    }
    #[test]
    fn low_memory_is_rejected() {
        assert!(matches!(
            first_creation_plan(3, 3 * 1024 * 1024 * 1024),
            Err(ColimaError::InsufficientMemory)
        ));
    }
    #[test]
    fn existing_plan_preserves_resources() {
        let p = existing_start_plan();
        assert!(!p
            .iter()
            .any(|x| matches!(x.as_str(), "--cpus" | "--memory" | "--disk")));
    }

    #[test]
    fn reserved_record_with_existing_profile_uses_existing_start_plan() {
        let plan = start_plan_for_profile(true, true, 8, 16 * 1024 * 1024 * 1024).unwrap();
        assert_eq!(plan, existing_start_plan());
        assert!(!plan.iter().any(|arg| arg == "--cpus" || arg == "--memory"));
    }
    #[test]
    fn unowned_profile_is_rejected_without_record() {
        let fixture = Fixture::new();
        fs::create_dir_all(fixture.home().join(".colima/orbit")).unwrap();
        assert!(reserve_ownership(fixture.home()).is_err());
    }
    #[test]
    fn mismatched_record_is_rejected() {
        assert!(validate_record(&OwnershipRecord {
            provider: "x".into(),
            profile: PROFILE.into(),
            runtime: "docker".into(),
            lifecycle: "orbit-desktop".into(),
            state: "ready".into()
        })
        .is_err());
    }
    #[test]
    fn symlinked_record_is_rejected() {
        let fixture = Fixture::new();
        let real = fixture.home().join("real.json");
        fs::write(&real, b"{}").unwrap();
        let owner = ownership_path(fixture.home());
        fs::create_dir_all(owner.parent().unwrap()).unwrap();
        fs::set_permissions(owner.parent().unwrap(), fs::Permissions::from_mode(0o700)).unwrap();
        symlink(&real, &owner).unwrap();
        assert!(reserve_ownership(fixture.home()).is_err());
    }
    #[test]
    fn symlinked_profile_is_rejected() {
        let fixture = Fixture::new();
        fs::create_dir_all(fixture.home().join(".colima")).unwrap();
        symlink(fixture.home(), fixture.home().join(".colima/orbit")).unwrap();
        assert!(reserve_ownership(fixture.home()).is_err());
    }
    #[test]
    fn ready_transition_is_atomic_and_owner_only() {
        let fixture = Fixture::new();
        reserve_ownership(fixture.home()).unwrap();
        mark_ready(fixture.home()).unwrap();
        let record = load_record(fixture.home()).unwrap();
        assert_eq!(record.state, "ready");
        assert_eq!(
            fs::metadata(ownership_path(fixture.home()))
                .unwrap()
                .permissions()
                .mode()
                & 0o777,
            0o600
        );
        assert_eq!(
            fs::metadata(ownership_path(fixture.home()).parent().unwrap())
                .unwrap()
                .permissions()
                .mode()
                & 0o777,
            0o700
        );
        assert!(!ownership_path(fixture.home())
            .with_extension("tmp")
            .exists());
    }
    #[test]
    fn socket_outside_profile_is_rejected() {
        assert!(validate_socket(Path::new("/tmp"), "unix:///tmp/orbit.sock").is_err());
    }
    #[test]
    fn symlinked_socket_is_rejected() {
        let fixture = Fixture::new();
        let profile = fixture.home().join(".colima/orbit");
        fs::create_dir_all(&profile).unwrap();
        let real = fixture.home().join("real.sock");
        let _listener = UnixListener::bind(&real).unwrap();
        symlink(&real, profile.join("docker.sock")).unwrap();
        let url = format!("unix://{}", profile.join("docker.sock").display());
        assert!(validate_socket(fixture.home(), &url).is_err());
    }
    #[test]
    fn exact_owned_socket_is_accepted() {
        let fixture = Fixture::new();
        let profile = fixture.home().join(".colima/orbit");
        fs::create_dir_all(&profile).unwrap();
        let socket = profile.join("docker.sock");
        let _listener = UnixListener::bind(&socket).unwrap();
        let url = format!("unix://{}", socket.display());
        assert_eq!(validate_socket(fixture.home(), &url).unwrap(), url);
    }

    #[test]
    fn executable_candidates_require_regular_executable_non_symlinks() {
        let fixture = Fixture::new();
        let candidate = fixture.home().join("tool");
        fs::write(&candidate, b"#!/bin/sh\n").unwrap();
        assert!(matches!(
            resolve_executable(&candidate),
            Err(ColimaError::MissingExecutable(_))
        ));
        fs::set_permissions(&candidate, fs::Permissions::from_mode(0o755)).unwrap();
        assert_eq!(resolve_executable(&candidate).unwrap(), candidate);
        let link = fixture.home().join("link");
        symlink(&candidate, &link).unwrap();
        assert_eq!(resolve_executable(&link).unwrap(), link);
        let bad = fixture.home().join("bad");
        fs::write(&bad, b"x").unwrap();
        fs::set_permissions(&bad, fs::Permissions::from_mode(0o644)).unwrap();
        let bad_link = fixture.home().join("bad-link");
        symlink(&bad, &bad_link).unwrap();
        assert!(matches!(
            resolve_executable(&bad_link),
            Err(ColimaError::MissingExecutable(_))
        ));
        assert!(matches!(
            resolve_executable(&fixture.home().join("broken")),
            Err(ColimaError::MissingExecutable(_))
        ));
    }
    #[test]
    fn status_requires_owned_docker_socket() {
        let j = r#"{"runtime":"docker","kubernetes":false,"docker_socket":"unix:///Users/test/.colima/orbit/docker.sock"}"#;
        assert_eq!(
            parse_status(j).unwrap().socket,
            "unix:///Users/test/.colima/orbit/docker.sock"
        )
    }

    #[test]
    fn readiness_retries_with_explicit_endpoint_and_no_context() {
        let elapsed = std::cell::Cell::new(Duration::ZERO);
        let mut probes = 0;
        let result = poll_docker_ready(
            "unix:///owned/orbit/docker.sock",
            Duration::from_secs(3),
            Duration::from_secs(1),
            || elapsed.get(),
            |host, context| {
                probes += 1;
                assert_eq!(host, "unix:///owned/orbit/docker.sock");
                assert!(context.is_none());
                probes >= 3
            },
            |delay| elapsed.set(elapsed.get() + delay),
        );
        assert!(result.is_ok());
        assert_eq!(probes, 3);
    }

    #[test]
    fn readiness_times_out_without_success() {
        let elapsed = std::cell::Cell::new(Duration::ZERO);
        let result = poll_docker_ready(
            "unix://owned",
            Duration::from_secs(2),
            Duration::from_secs(1),
            || elapsed.get(),
            |_, _| false,
            |delay| elapsed.set(elapsed.get() + delay),
        );
        assert!(
            matches!(result, Err(ColimaError::DockerReadinessTimeout(endpoint)) if endpoint == "unix://owned")
        );
    }

    #[test]
    fn stop_plan_targets_only_orbit_profile() {
        assert_eq!(
            stop_plan("/usr/local/bin/colima").args,
            vec!["stop", "orbit"]
        );
    }

    #[test]
    fn stop_owned_profile_executes_only_owned_ready_profile() {
        let fixture = Fixture::new();
        let profile = fixture.home().join(".colima/orbit");
        fs::create_dir_all(&profile).unwrap();
        persist_record(
            fixture.home(),
            &OwnershipRecord {
                provider: "colima".into(),
                profile: "orbit".into(),
                runtime: "docker".into(),
                lifecycle: "orbit-desktop".into(),
                state: "ready".into(),
            },
        )
        .unwrap();
        let capture = fixture.home().join("argv");
        let fake = fixture.home().join("colima");
        fs::write(
            &fake,
            format!(
                "#!/bin/sh\nprintf '%s %s' \"$1\" \"$2\" > {}\n",
                capture.display()
            ),
        )
        .unwrap();
        fs::set_permissions(&fake, fs::Permissions::from_mode(0o755)).unwrap();
        stop_owned_profile(fixture.home(), &fake).unwrap();
        assert_eq!(fs::read_to_string(capture).unwrap(), "stop orbit");
        persist_record(
            fixture.home(),
            &OwnershipRecord {
                provider: "colima".into(),
                profile: "orbit".into(),
                runtime: "docker".into(),
                lifecycle: "orbit-desktop".into(),
                state: "reserved".into(),
            },
        )
        .unwrap();
        let _ = fs::remove_file(fixture.home().join("argv"));
        assert!(stop_owned_profile(fixture.home(), &fake).is_err());
        assert!(!fixture.home().join("argv").exists());
    }
}
