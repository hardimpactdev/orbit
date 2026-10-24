//! Production Doctor observations share the runtime's paths, pool selection, and receipt rules.
use super::*;
use crate::commands::source;

command_errors! {
    pub enum Error {
        Invalid {} => "doctor.production_input_invalid", "The production Instance observation input is invalid.";
        Unavailable {} => "doctor.production_inspection_failed", "The production Instance could not be observed.";
    }
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub user: String,
    pub home: PathBuf,
    pub root: String,
    pub application_suffix: String,
    pub environment: String,
    pub caddy_sites: Vec<String>,
    pub association: bool,
    pub runtime: Option<ExpectedRuntime>,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ExpectedRuntime {
    pub version: String,
    pub marker: String,
    pub configuration: Configuration,
    pub application: PathBuf,
    pub initial_application: PathBuf,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct Output {
    pub home: bool,
    pub selection: bool,
    pub root: bool,
    pub environment: bool,
    pub php: Option<bool>,
    pub caddy: Option<bool>,
}
fn exact(path: &Path, bytes: &[u8], uid: u32, gid: u32, mode: u32) -> bool {
    is_regular_file(path)
        && owned(path, uid, gid, mode)
        && fs::read(path).is_ok_and(|value| value == bytes)
}
fn selected(input: &Input, uid: u32, gid: u32) -> Option<PathBuf> {
    let current = input.home.join("current");
    let target = fs::read_link(&current).ok()?;
    let name = target.strip_prefix("releases").ok()?.to_str()?;
    if !valid_name(name) || !owned_identity(&current, uid, gid) {
        return None;
    }
    let selected = realpath(&current)?;
    (selected.starts_with(input.home.join("releases"))
        && is_real_directory(&selected)
        && owned_identity(&selected, uid, gid))
    .then_some(selected)
}
fn owned_identity(path: &Path, uid: u32, gid: u32) -> bool {
    fs::symlink_metadata(path).is_ok_and(|m| m.uid() == uid && m.gid() == gid)
}
pub(super) fn inspect(input: Input, host: &Host, caddy: &Path) -> Result<Output, Error> {
    if !valid_user(&input.user)
        || !input.home.is_absolute()
        || (!input.application_suffix.is_empty()
            && (!input.application_suffix.starts_with('/')
                || !super::super::production::relative_path(&input.application_suffix[1..])))
    {
        return Err(Error::Invalid {});
    }
    let account = identity::passwd(&input.user);
    let selected = account
        .as_ref()
        .and_then(|a| selected(&input, a.uid, a.gid));
    let has_current = exists(&input.home.join("current"));
    let home = account.as_ref().is_some_and(|a| {
        input.home == host.homes.join(&input.user)
            && source::canonical(&input.home)
            && is_real_directory(&input.home)
            && owned_identity(&input.home, a.uid, a.gid)
    });
    let root = !has_current
        || selected.as_ref().is_some_and(|release| {
            super::super::production::relative_path(&input.root)
                && realpath(&release.join(&input.root))
                    .is_some_and(|p| p.starts_with(release) && p.is_dir())
        });
    let environment = account.as_ref().is_some_and(|a| {
        exact(
            &input.home.join(".env"),
            input.environment.as_bytes(),
            a.uid,
            a.gid,
            0o600,
        )
    }) && (!has_current
        || selected.as_ref().is_some_and(|release| {
            let env = PathBuf::from(format!("{}{}", release.display(), input.application_suffix))
                .join(".env");
            is_symlink(&env) && realpath(&env) == Some(input.home.join(".env"))
        }));
    let caddy = match realpath(caddy) {
        None => Some(false),
        Some(path) if !path.is_file() => Some(false),
        Some(path) => fs::read_to_string(path).ok().map(|content| {
            input
                .caddy_sites
                .iter()
                .all(|site| content.contains(site.trim_end_matches('\n')))
        }),
    };
    let php = if !input.association {
        Some(false)
    } else if let Some(expected) = input.runtime {
        let convergence = ConvergeInput {
            mode: Mode::Converge,
            user: input.user,
            home: input.home,
            version: expected.version,
            marker: expected.marker,
            configuration: expected.configuration,
            application: expected.application,
            initial_application: expected.initial_application,
        };
        runtime_matches(&convergence, host).ok()
    } else {
        Some(true)
    };
    Ok(Output {
        home,
        selection: !has_current || selected.is_some(),
        root,
        environment,
        php,
        caddy,
    })
}
/// A failed read is unavailable; a complete observation that differs is drift.
fn show(host: &Host, service: &str, property: &str) -> Result<String, ()> {
    let output = Process::new("/usr/bin/timeout")
        .args(["--kill-after=1s", "5s"])
        .arg(&host.systemctl)
        .args([
            "show",
            &format!("--property={property}"),
            "--value",
            service,
        ])
        .stdin(Stdio::null())
        .stderr(Stdio::null())
        .output()
        .map_err(|_| ())?;
    if !output.status.success() {
        return Err(());
    }
    String::from_utf8(output.stdout)
        .map(|s| s.trim_end_matches('\n').into())
        .map_err(|_| ())
}
fn runtime_matches(input: &ConvergeInput, host: &Host) -> Result<bool, ()> {
    let Ok(runtime) = Runtime::new(host, &input.user, &input.version, &input.marker) else {
        return Ok(false);
    };
    let Some(account) = identity::passwd(&input.user) else {
        return Ok(false);
    };
    let Ok(pool) = select_application_pool(input) else {
        return Ok(false);
    };
    if ![&runtime.directory, &runtime.generated]
        .iter()
        .all(|p| is_real_directory(p) && host.root_owned(p, 0o755))
        || ![
            (
                runtime.generated.join("php-fpm.conf"),
                input.configuration.main.as_bytes(),
            ),
            (runtime.generated.join("pool.conf"), pool.as_slice()),
            (
                runtime.generated.join("master.ini"),
                input.configuration.master_ini.as_bytes(),
            ),
            (runtime.unit.clone(), input.configuration.unit.as_bytes()),
            (runtime.marker.clone(), runtime.expected_marker.as_slice()),
        ]
        .iter()
        .all(|(p, bytes)| exact(p, bytes, host.root_uid, host.root_gid, 0o644))
        || !is_regular_file(&runtime.local)
        || !host.root_owned(&runtime.local, 0o644)
        || !fs::read_to_string(&runtime.local).is_ok_and(|local| {
            local_tuning_allowed(&local, &format!("orbit-{}", input.user), false)
        })
    {
        return Ok(false);
    }
    match show(host, &runtime.service, "ActiveState")?.as_str() {
        "active" => {}
        "inactive" | "failed" | "activating" | "deactivating" | "reloading" => return Ok(false),
        _ => return Err(()),
    }
    if !show(host, &runtime.service, "User")?.is_empty() {
        return Ok(false);
    }
    let pid = show(host, &runtime.service, "MainPID")?
        .parse::<u32>()
        .ok()
        .filter(|p| *p > 1)
        .ok_or(())?;
    let executable = host.fpm(&input.version);
    let expected_exec = format!(
        "{{ path={} ; argv[]={0} --nodaemonize --fpm-config {} ; ",
        executable.display(),
        runtime.generated.join("php-fpm.conf").display()
    );
    let exec = show(host, &runtime.service, "ExecStart")?;
    if !exec.starts_with(&expected_exec) {
        return if exec.starts_with("{ path=") && exec.contains(" ; argv[]=") {
            Ok(false)
        } else {
            Err(())
        };
    }
    if show(host, &runtime.service, "Environment")?
        != format!(
            "PHP_INI_SCAN_DIR={}/fpm/conf.d:{}",
            host.php_conf.join(&input.version).display(),
            runtime.generated.display()
        )
    {
        return Ok(false);
    }
    if !master_matches(host, pid, &executable)?
        || !workers_match(host, pid, account.uid, account.gid)?
        || !socket_matches(host, pid, &runtime.socket, account.uid)?
    {
        return Ok(false);
    }
    if show(host, &runtime.service, "MainPID")? != pid.to_string() {
        return Err(());
    }
    Ok(generation_applied(host, &runtime, &pool))
}
fn ids(status: &str, key: &str) -> Result<[u32; 4], ()> {
    let line = status
        .lines()
        .find_map(|line| line.strip_prefix(key))
        .ok_or(())?;
    let values = line
        .split_whitespace()
        .map(|v| v.parse::<u32>().map_err(|_| ()))
        .collect::<Result<Vec<_>, _>>()?;
    values.try_into().map_err(|_| ())
}
fn stable_process(
    host: &Host,
    pid: u32,
    inspect: impl FnOnce() -> Result<bool, ()>,
) -> Result<bool, ()> {
    let before = host.start_time(pid).ok_or(())?;
    if !inspect()? {
        return Ok(false);
    }
    if host.start_time(pid).ok_or(())? != before {
        return Err(());
    }
    Ok(true)
}
fn master_matches(host: &Host, pid: u32, executable: &Path) -> Result<bool, ()> {
    stable_process(host, pid, || {
        let process = host.proc_root.join(pid.to_string());
        if fs::canonicalize(process.join("exe")).map_err(|_| ())? != executable {
            return Ok(false);
        }
        if ids(
            &fs::read_to_string(process.join("status")).map_err(|_| ())?,
            "Uid:",
        )? != [0; 4]
        {
            return Ok(false);
        }
        Ok(true)
    })
}
fn worker_exited(host: &Host, pid: u32) -> bool {
    let process = host.proc_root.join(pid.to_string());
    if !process.is_dir() {
        return true;
    }
    if fs::read_to_string(process.join("status")).is_ok_and(|s| {
        s.lines().any(|line| {
            line.strip_prefix("State:")
                .is_some_and(|value| matches!(value.split_whitespace().next(), Some("Z" | "X")))
        })
    }) {
        return true;
    }
    fs::read_to_string(process.join("stat"))
        .ok()
        .and_then(|s| {
            s.rsplit_once(") ")
                .and_then(|(_, fields)| fields.split_whitespace().nth(6))
                .and_then(|flags| flags.parse::<u64>().ok())
        })
        .is_some_and(|flags| flags & 4 != 0)
        || !process.is_dir()
}
fn worker_matches(host: &Host, pid: u32, parent: u32, uid: u32, gid: u32) -> Result<bool, ()> {
    stable_process(host, pid, || {
        let process = host.proc_root.join(pid.to_string());
        let status = fs::read_to_string(process.join("status")).map_err(|_| ())?;
        let observed_parent = status
            .lines()
            .find_map(|l| l.strip_prefix("PPid:"))
            .and_then(|p| p.trim().parse::<u32>().ok())
            .ok_or(())?;
        if observed_parent != parent {
            return Err(());
        }
        if ids(&status, "Uid:")? != [uid; 4] || ids(&status, "Gid:")? != [gid; 4] {
            return Ok(false);
        }
        if fs::canonicalize(process.join("root")).map_err(|_| ())? != Path::new("/") {
            return Ok(false);
        }
        Ok(true)
    })
}
fn workers_match(host: &Host, pid: u32, uid: u32, gid: u32) -> Result<bool, ()> {
    let children = fs::read_to_string(host.proc_root.join(format!("{pid}/task/{pid}/children")))
        .map_err(|_| ())?;
    for child in children.split_whitespace() {
        let child = child.parse::<u32>().map_err(|_| ())?;
        match worker_matches(host, child, pid, uid, gid) {
            Ok(true) => {}
            _ if worker_exited(host, child) => {}
            result => return result,
        }
    }
    Ok(true)
}
fn socket_matches(host: &Host, pid: u32, socket: &Path, uid: u32) -> Result<bool, ()> {
    if !host.served_socket(socket, uid) {
        return Ok(false);
    }
    let sockets = fs::read_to_string(host.proc_root.join("net/unix")).map_err(|_| ())?;
    let matches = sockets
        .lines()
        .filter_map(|line| {
            let fields = line.split_whitespace().collect::<Vec<_>>();
            (fields.len() >= 8
                && fields[3] == "00010000"
                && fields[5] == "01"
                && fields[7] == socket.to_string_lossy())
            .then(|| fields[6])
        })
        .collect::<Vec<_>>();
    if matches.is_empty() {
        return Ok(false);
    }
    if matches.len() != 1 || matches[0].parse::<u64>().is_err() {
        return Err(());
    }
    let descriptors = fs::read_dir(host.proc_root.join(format!("{pid}/fd"))).map_err(|_| ())?;
    for entry in descriptors.flatten() {
        if fs::read_link(entry.path())
            .is_ok_and(|value| value == Path::new(&format!("socket:[{}]", matches[0])))
        {
            return Ok(true);
        }
    }
    if !host.proc_root.join(pid.to_string()).is_dir() {
        return Err(());
    }
    Ok(false)
}
pub struct InspectProduction;
impl Command for InspectProduction {
    const NAME: &'static str = "doctor.inspect-production-instance";
    const SUMMARY: &'static str =
        "Observe production release, environment, Caddy, and dedicated PHP-FPM projections.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        (platform == Platform::Linux).then_some(Privilege::Root)
    }
    fn run(input: Input, _: &Context) -> Result<Output, Error> {
        let host = Host::system().map_err(|_| Error::Unavailable {})?;
        inspect(input, &host, Path::new("/etc/caddy/Caddyfile"))
    }
}

#[cfg(test)]
#[path = "php_doctor_tests.rs"]
mod tests;
