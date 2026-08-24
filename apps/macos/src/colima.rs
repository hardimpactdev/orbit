use serde::Deserialize;
use std::fmt;
use std::fs::{self, OpenOptions};
use std::io;
use std::path::{Path, PathBuf};
use std::process::{Command, Output};

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
        let args = if record.state == "reserved" {
            let cpus = std::thread::available_parallelism()
                .map(|x| x.get() as u32)
                .unwrap_or(1);
            let memory = host_memory_bytes().ok_or(ColimaError::InsufficientMemory)?;
            first_creation_plan(cpus, memory)?
        } else {
            existing_start_plan()
        };
        run(&direct_command(colima.clone(), args))?;
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
    let info = Command::new(docker)
        .args(["info"])
        .env("DOCKER_HOST", &ready.socket)
        .env_remove("DOCKER_CONTEXT")
        .output()?;
    if !info.status.success() {
        return Err(ColimaError::Command("docker info failed".into()));
    }
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
            Self(path)
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
}
