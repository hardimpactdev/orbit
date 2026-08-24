use serde_json::Value;
use std::collections::{HashMap, HashSet};
use std::fs::{self, File, OpenOptions};
use std::io;
use std::path::{Path, PathBuf};
use std::process::{Command, Output, Stdio};

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
        let labels = v.pointer("/Config/Labels").and_then(Value::as_object);
        if labels
            .and_then(|x| x.get("orbit.managed"))
            .and_then(Value::as_str)
            != Some("true")
        {
            continue;
        }
        let name = v
            .get("Name")
            .and_then(Value::as_str)
            .unwrap_or(id)
            .trim_start_matches('/')
            .to_owned();
        let kind = labels
            .and_then(|x| x.get("orbit.container.kind"))
            .and_then(Value::as_str);
        if !kind.is_some_and(|k| KNOWN_KINDS.contains(&k)) {
            return Err(CutoverError::Invalid(
                name,
                "unknown or missing orbit.container.kind".into(),
            ));
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
fn mounts(v: &Value) -> Vec<&Value> {
    v.pointer("/Mounts")
        .and_then(Value::as_array)
        .map(|x| x.iter().collect())
        .unwrap_or_default()
}
fn validate_container(c: &LegacyContainer) -> Result<(), CutoverError> {
    let v = inspect(&c.inspect)?;
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
                return Err(CutoverError::Invalid(
                    c.name.clone(),
                    "named volumes are not supported by this cutover".into(),
                ))
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
            return Err(CutoverError::TargetConflict(c.name.clone()));
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
fn create_args(c: &LegacyContainer) -> Result<Vec<String>, CutoverError> {
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
        let source = m.get("Source").and_then(Value::as_str).unwrap_or("");
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
                x.extend(["--publish".into(), format!("{ip}:{hp}:{container}")])
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
        for alias in n.iter().filter_map(Value::as_str) {
            x.extend(["--network-alias".into(), alias.into()])
        }
    }
    x.push(c.image.clone());
    x.extend(cmd);
    Ok(x)
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
pub fn cutover(
    program: &Path,
    source: &str,
    target: &str,
) -> Result<Vec<LegacyContainer>, CutoverError> {
    let path = source.strip_prefix("unix://").ok_or_else(|| {
        CutoverError::Invalid(source.into(), "source endpoint must be Unix socket".into())
    })?;
    socket_is_owned(Path::new(path))?;
    let cs = discover(program, source)?;
    preflight(program, source, target, &cs)?;
    if cs.is_empty() {
        return Ok(cs);
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
    let result = (|| {
        let mut images = HashMap::new();
        for c in &cs {
            if !images.contains_key(&c.image) {
                let p = tmp.join(format!("image-{}.tar", images.len()));
                save_image(program, source, &c.image, &p)?;
                images.insert(c.image.clone(), p);
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
        for c in &cs {
            let p = images.get(&c.image).unwrap();
            let mut load = Command::new(program)
                .args(["load"])
                .env("DOCKER_HOST", target)
                .env_remove("DOCKER_CONTEXT")
                .stdin(File::open(p)?)
                .stdout(Stdio::null())
                .spawn()?;
            if !load.wait()?.success() {
                return Err(CutoverError::Command("docker load failed".into()));
            }
            docker(program, target, &create_args(c)?)?;
            if c.running {
                docker(program, target, &a(&["start", &c.name]))?;
            }
        }
        for c in &cs {
            let actual = inspect(&String::from_utf8_lossy(
                &docker(program, target, &a(&["inspect", &c.name]))?.stdout,
            ))?;
            let expected = inspect(&c.inspect)?;
            let selected = [
                "/Config/Labels",
                "/Config/Env",
                "/Config/WorkingDir",
                "/Config/User",
                "/Mounts",
                "/HostConfig/PortBindings",
                "/HostConfig/RestartPolicy",
                "/HostConfig/ExtraHosts",
                "/NetworkSettings/Networks/orbit-network/Aliases",
                "/State/Running",
            ];
            let effective = |v: &Value| {
                let mut command = strings(v.pointer("/Config/Entrypoint"));
                command.extend(strings(v.pointer("/Config/Cmd")));
                command
            };
            if selected
                .iter()
                .any(|path| actual.pointer(path) != expected.pointer(path))
                || effective(&actual) != effective(&expected)
            {
                return Err(CutoverError::Invalid(
                    c.name.clone(),
                    "target configuration or state mismatch".into(),
                ));
            }
        }
        for c in &cs {
            docker(program, source, &a(&["rm", &c.name]))?;
        }
        Ok(())
    })();
    let _ = fs::remove_dir_all(&tmp);
    if let Err(e) = result {
        for c in &cs {
            let _ = docker(program, target, &a(&["rm", "-f", &c.name]));
            if c.running {
                let _ = docker(program, source, &a(&["start", &c.name]));
            }
        }
        if created_network {
            let _ = docker(program, target, &a(&["network", "rm", "orbit-network"]));
        }
        return Err(e);
    }
    Ok(cs)
}
#[cfg(test)]
mod tests {
    use super::*;
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
}
