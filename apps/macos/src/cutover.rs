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
fn normalized_mounts(v: &Value) -> Vec<(String, String, bool)> {
    let mut mounts = mounts(v)
        .into_iter()
        .filter_map(|m| {
            Some((
                m.get("Type")?.as_str()?.to_owned(),
                m.get("Source")?.as_str()?.to_owned(),
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
            let scalar_equal = [
                "/Config/Labels",
                "/Config/Env",
                "/Config/WorkingDir",
                "/Config/User",
                "/HostConfig/ExtraHosts",
                "/State/Running",
            ]
            .iter()
            .all(|path| actual.pointer(path) == expected.pointer(path));
            if !scalar_equal
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
    fn named_volume_is_rejected_before_plan_creation() {
        let mut c = runtime_fixture();
        let mut v = inspect(&c.inspect).unwrap();
        v["Mounts"]
            .as_array_mut()
            .unwrap()
            .push(json!({"Type":"volume","Source":"data","Destination":"/data"}));
        c.inspect = json!([v]).to_string();
        assert!(validate_container(&c)
            .unwrap_err()
            .to_string()
            .contains("named volumes"));
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

    fn test_dir(label: &str) -> PathBuf {
        let path = std::env::temp_dir().join(format!(
            "orbit-cutover-test-{}-{}",
            label,
            std::process::id()
        ));
        let _ = fs::remove_dir_all(&path);
        fs::create_dir_all(&path).unwrap();
        path
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
        let _guard = CUTOVER_TEST_LOCK.lock().unwrap();
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
        assert!(log_text.contains("rm orbit-caddy"));
        assert!(log_text.contains("rm orbit-runtime"));
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
        let _guard = CUTOVER_TEST_LOCK.lock().unwrap();
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
        std::env::remove_var("LOG");
        std::env::remove_var("FIXTURE");
    }
}
