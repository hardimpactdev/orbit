//! Detached topology preparation, with the existing run/unit/result lifecycle.
use super::recipe::{self, Host, Invocation};
use crate::{
    command::{identity, Command, Context, Platform, Privilege},
    command_errors,
};
use orbit_agent_contract::Service;
use serde::{Deserialize, Serialize};
use std::{
    collections::BTreeMap,
    fs,
    io::{self, Read, Seek, SeekFrom, Write},
    net::Ipv4Addr,
    os::unix::fs::{DirBuilderExt, MetadataExt, OpenOptionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::{Duration, Instant},
};

const PREFIX: &str = "orbit-task-topology-";
const SECONDS: u64 = 4500;
const LOG_LIMIT: u64 = 16384;
command_errors! {
    pub enum Error {
        Invalid {} => "task_vm.topology_input_invalid", "The topology run, checkout, issue, nodes, or VPN subnet is invalid.";
        Failed { stage: String } => "task_vm.topology_failed", "The topology preparation could not be started or read.";
    }
}
fn failed(stage: &str) -> Error {
    Error::Failed {
        stage: stage.into(),
    }
}
#[derive(schemars::JsonSchema, Clone, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct StartInput {
    run: String,
    checkout: PathBuf,
    issue: String,
    nodes: Vec<String>,
    vpn_subnet: String,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct StatusInput {
    run: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Started {}
#[derive(schemars::JsonSchema, Debug, Deserialize, Serialize, PartialEq)]
#[serde(rename_all = "lowercase")]
enum State {
    Ready,
    Failed,
    Running,
    Lost,
    Stale,
}
#[derive(schemars::JsonSchema, Debug, Deserialize, Serialize, PartialEq)]
pub struct Status {
    state: State,
    #[serde(skip_serializing_if = "Option::is_none")]
    step: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    seconds: Option<u64>,
    #[serde(skip_serializing_if = "Option::is_none")]
    boot_id: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    log: Option<String>,
}
impl Status {
    fn state(state: State) -> Self {
        Self {
            state,
            step: None,
            seconds: None,
            boot_id: None,
            log: None,
        }
    }
}
fn valid_run(run: &str) -> bool {
    (8..=40).contains(&run.len())
        && run
            .bytes()
            .all(|c| c.is_ascii_lowercase() || c.is_ascii_digit())
}
fn validate(input: &StartInput) -> Result<(), Error> {
    let issue = input.issue.strip_prefix("TASK-").unwrap_or("");
    let subnet = input
        .vpn_subnet
        .split_once('/')
        .is_some_and(|(ip, prefix)| {
            ip.parse::<Ipv4Addr>().is_ok() && prefix.parse::<u8>().is_ok_and(|v| v <= 32)
        });
    if !valid_run(&input.run)
        || !super::doctor_route::absolute(&input.checkout)
        || !input.checkout.is_dir()
        || issue.is_empty()
        || issue.len() > 9
        || issue.starts_with('0')
        || !issue.bytes().all(|c| c.is_ascii_digit())
        || !subnet
        || input.nodes.len() > 32
        || input.nodes.iter().any(|n| {
            n.is_empty()
                || n.len() > 128
                || !n
                    .bytes()
                    .all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || b"+-".contains(&c))
        })
    {
        return Err(Error::Invalid {});
    }
    Ok(())
}
struct Files {
    home: PathBuf,
    uid: u32,
    gid: u32,
    boot: PathBuf,
    cgroups: PathBuf,
}
impl Files {
    fn system() -> Result<Self, Error> {
        let uid = identity::effective_uid();
        if uid == 0 {
            return Err(failed("account"));
        }
        let name = identity::user_name(uid).ok_or_else(|| failed("account"))?;
        let account = identity::passwd(&name).ok_or_else(|| failed("account"))?;
        Ok(Self {
            home: account.home.into(),
            uid,
            gid: account.gid,
            boot: "/proc/sys/kernel/random/boot_id".into(),
            cgroups: "/sys/fs/cgroup".into(),
        })
    }
    fn directory(&self) -> PathBuf {
        self.home.join(".local/state/orbit/task-topology")
    }
    fn path(&self, run: &str, extension: &str) -> PathBuf {
        self.directory().join(format!("{run}.{extension}"))
    }
    fn prepare(&self) -> Result<(), Error> {
        // All writes run as the account. Refuse a redirected state directory rather than following it.
        let mut path = self.home.clone();
        for part in [".local", "state", "orbit", "task-topology"] {
            path.push(part);
            match fs::DirBuilder::new().mode(0o700).create(&path) {
                Ok(()) => {}
                Err(e) if e.kind() == io::ErrorKind::AlreadyExists => {}
                Err(_) => return Err(failed("state-directory")),
            }
            let meta = fs::symlink_metadata(&path).map_err(|_| failed("state-directory"))?;
            if !meta.is_dir() || meta.uid() != self.uid || meta.mode() & 0o022 != 0 {
                return Err(failed("state-directory"));
            }
        }
        Ok(())
    }
    fn boot(&self) -> Result<String, Error> {
        let boot = fs::read_to_string(&self.boot).map_err(|_| failed("boot"))?;
        let boot = boot.trim();
        if boot.is_empty() || boot.len() > 64 {
            return Err(failed("boot"));
        }
        Ok(boot.into())
    }
}
fn unit(run: &str) -> String {
    format!("{PREFIX}{run}.service")
}
fn call(host: &mut impl Host, args: &[&str]) -> Result<recipe::Output, Error> {
    let mut call = Invocation::new(args);
    call.timeout = Duration::from_secs(30);
    host.execute(&call).map_err(|_| failed("systemd"))
}
/// Missing evidence is running. Only a vanished unit or an empty terminal cgroup proves completion.
fn running(host: &mut impl Host, files: &Files, name: &str) -> bool {
    let Ok(output) = call(
        host,
        &[
            "/usr/bin/systemctl",
            "show",
            name,
            "--no-pager",
            "--property=LoadState,ActiveState,SubState,ControlGroup,Job",
        ],
    ) else {
        return true;
    };
    if output.code != 0 || output.truncated {
        return true;
    }
    let mut fields = BTreeMap::new();
    for line in output.stdout.lines() {
        let Some((key, value)) = line.split_once('=') else {
            return true;
        };
        if fields.insert(key, value).is_some() {
            return true;
        }
    }
    let get = |key| fields.get(key).copied();
    if get("LoadState") == Some("not-found")
        && get("ActiveState") == Some("inactive")
        && get("ControlGroup") == Some("")
        && get("Job") == Some("")
    {
        return false;
    }
    if get("LoadState") != Some("loaded")
        || get("Job") != Some("")
        || !(matches!(get("ActiveState"), Some("inactive" | "failed"))
            || get("ActiveState") == Some("active") && get("SubState") == Some("exited"))
    {
        return true;
    }
    let Some(group) = get("ControlGroup") else {
        return true;
    };
    if group.is_empty() {
        return false;
    }
    if !group.starts_with('/') || group.split('/').any(|p| p == ".." || p == ".") {
        return true;
    }
    match fs::read_to_string(files.cgroups.join(&group[1..]).join("cgroup.events")) {
        Ok(events) => {
            let mut populated = None;
            for line in events.lines() {
                let fields = line.split_whitespace().collect::<Vec<_>>();
                if fields.len() != 2 {
                    return true;
                }
                if fields[0] == "populated" && populated.replace(fields[1]).is_some() {
                    return true;
                }
            }
            populated != Some("0")
        }
        Err(e) if e.kind() == io::ErrorKind::NotFound => false,
        Err(_) => true,
    }
}
fn start(input: &StartInput, files: &Files, host: &mut impl Host) -> Result<Started, Error> {
    validate(input)?;
    if running(host, files, &unit(&input.run)) {
        return Ok(Started {});
    }
    let listed = call(
        host,
        &[
            "/usr/bin/systemctl",
            "list-units",
            "--all",
            "--plain",
            "--no-legend",
            "orbit-task-topology-*",
        ],
    )?;
    if listed.code != 0 || listed.truncated {
        return Err(failed("list-units"));
    }
    for line in listed.stdout.lines() {
        let name = line
            .split_whitespace()
            .next()
            .ok_or_else(|| failed("list-units"))?;
        if !name
            .strip_prefix(PREFIX)
            .and_then(|v| v.strip_suffix(".service"))
            .is_some_and(valid_run)
        {
            return Err(failed("list-units"));
        }
        if running(host, files, name) {
            let stopped = call(
                host,
                &[
                    "/usr/bin/sudo",
                    "-n",
                    "/usr/bin/systemctl",
                    "stop",
                    "--",
                    name,
                ],
            )?;
            if stopped.code != 0 || running(host, files, name) {
                return Err(failed("stop"));
            }
        }
    }
    files.prepare()?;
    for extension in ["json", "log"] {
        match fs::remove_file(files.path(&input.run, extension)) {
            Ok(()) => {}
            Err(e) if e.kind() == io::ErrorKind::NotFound => {}
            Err(_) => return Err(failed("clear-result")),
        }
    }
    let output = call(
        host,
        &[
            "/usr/bin/sudo",
            "-n",
            "/usr/bin/systemd-run",
            "--quiet",
            "--collect",
            &format!("--unit={}", unit(&input.run)),
            &format!("--uid={}", files.uid),
            &format!("--gid={}", files.gid),
            &format!("--working-directory={}", input.checkout.display()),
            &format!("--setenv=HOME={}", files.home.display()),
            &format!("--property=RuntimeMaxSec={SECONDS}"),
            "--property=KillMode=control-group",
            "--property=UMask=0077",
            "--",
            "/usr/local/bin/orbit-agent",
            "service",
            "task-topology",
            &input.run,
            input.checkout.to_str().ok_or(Error::Invalid {})?,
            &input.issue,
            &input.nodes.join(","),
            &input.vpn_subnet,
        ],
    )?;
    if output.code != 0 {
        return Err(failed("start"));
    }
    Ok(Started {})
}
fn status(run: &str, files: &Files, host: &mut impl Host) -> Result<Status, Error> {
    if !valid_run(run) {
        return Err(Error::Invalid {});
    }
    match fs::OpenOptions::new()
        .read(true)
        .custom_flags(libc::O_NOFOLLOW | libc::O_NONBLOCK)
        .open(files.path(run, "json"))
    {
        Ok(file) => {
            let meta = file.metadata().map_err(|_| failed("result"))?;
            if !meta.is_file() || meta.uid() != files.uid || meta.len() > 128 * 1024 {
                return Err(failed("result"));
            }
            let mut bytes = Vec::new();
            file.take(128 * 1024 + 1)
                .read_to_end(&mut bytes)
                .map_err(|_| failed("result"))?;
            if bytes.len() > 128 * 1024 {
                return Err(failed("result"));
            }
            let result: Status = serde_json::from_slice(&bytes).map_err(|_| failed("result"))?;
            if result.state == State::Ready
                && result
                    .boot_id
                    .as_ref()
                    .is_some_and(|v| files.boot().as_ref() != Ok(v))
            {
                // A missing boot read is an error, never a reason to start another topology.
                files.boot()?;
                return Ok(Status::state(State::Stale));
            }
            Ok(result)
        }
        Err(e) if e.kind() == io::ErrorKind::NotFound => {
            Ok(Status::state(if running(host, files, &unit(run)) {
                State::Running
            } else {
                State::Lost
            }))
        }
        Err(_) => Err(failed("result")),
    }
}
fn log_tail(path: &Path) -> io::Result<String> {
    let mut file = fs::File::open(path)?;
    let length = file.metadata()?.len();
    file.seek(SeekFrom::Start(length.saturating_sub(LOG_LIMIT)))?;
    let mut bytes = Vec::new();
    file.take(LOG_LIMIT).read_to_end(&mut bytes)?;
    let text = String::from_utf8_lossy(&bytes);
    let lines = text.lines().collect::<Vec<_>>();
    Ok(recipe::diagnostic(
        &lines[lines.len().saturating_sub(40)..].join("\n"),
    ))
}
fn save(files: &Files, run: &str, result: &Status) -> Result<(), Error> {
    let candidate = files.path(run, &format!("json.{:016x}", rand::random::<u64>()));
    let write = (|| -> io::Result<()> {
        let mut file = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(&candidate)?;
        file.write_all(&serde_json::to_vec(result)?)?;
        fs::rename(&candidate, files.path(run, "json"))
    })();
    if write.is_err() {
        let _ = fs::remove_file(candidate);
    }
    write.map_err(|_| failed("save-result"))
}
trait Harness {
    fn execute(&mut self, input: &StartInput, step: &str, log: &fs::File) -> io::Result<bool>;
}
struct NativeHarness;
impl Harness for NativeHarness {
    fn execute(&mut self, input: &StartInput, step: &str, log: &fs::File) -> io::Result<bool> {
        let mut process = if step == "host-setup" {
            let mut p = Process::new("/usr/bin/sudo");
            p.args([
                "-n",
                "bin/e2e-topology-host-setup",
                &format!("--wireguard-subnet={}", input.vpn_subnet),
            ]);
            p
        } else {
            let mut p = Process::new(input.checkout.join("bin/e2e-topology"));
            p.args([
                "acquire",
                &input.issue,
                input.checkout.to_str().unwrap(),
                &format!("--nodes={}", input.nodes.join(",")),
                "--replace",
            ]);
            p
        };
        Ok(process
            .current_dir(&input.checkout)
            .stdin(Stdio::null())
            .stdout(log.try_clone()?)
            .stderr(log.try_clone()?)
            .status()?
            .success())
    }
}
fn work(input: &StartInput, files: &Files, harness: &mut impl Harness) -> Result<bool, Error> {
    validate(input)?;
    files.prepare()?;
    let boot = files.boot()?;
    let started = Instant::now();
    let log_path = files.path(&input.run, "log");
    let mut log = fs::OpenOptions::new()
        .append(true)
        .create(true)
        .mode(0o600)
        .custom_flags(libc::O_NOFOLLOW)
        .open(&log_path)
        .map_err(|_| failed("log"))?;
    writeln!(
        log,
        "topology: preparing {} with nodes [{}]",
        input.issue,
        input.nodes.join(",")
    )
    .map_err(|_| failed("log"))?;
    let mut step = "host-setup";
    let mut success = harness.execute(input, step, &log).unwrap_or(false);
    if success {
        step = "acquire";
        success = harness.execute(input, step, &log).unwrap_or(false);
        if !success {
            writeln!(log, "topology: warning: acquire failed; retry 1 of 1.")
                .map_err(|_| failed("log"))?;
            success = harness.execute(input, step, &log).unwrap_or(false);
        }
    }
    save(
        files,
        &input.run,
        &Status {
            state: if success { State::Ready } else { State::Failed },
            step: Some(step.into()),
            seconds: Some(started.elapsed().as_secs()),
            boot_id: Some(boot),
            log: Some(log_tail(&log_path).map_err(|_| failed("log"))?),
        },
    )?;
    Ok(success)
}
pub struct Worker;
impl Service for Worker {
    const NAME: &'static str = "task-topology";
    fn supported(platform: Platform) -> bool {
        platform == Platform::Linux
    }
    fn run(args: &[String]) -> i32 {
        let [run, checkout, issue, nodes, vpn_subnet] = args else {
            return 64;
        };
        let input = StartInput {
            run: run.clone(),
            checkout: checkout.into(),
            issue: issue.clone(),
            nodes: if nodes.is_empty() {
                vec![]
            } else {
                nodes.split(',').map(str::to_owned).collect()
            },
            vpn_subnet: vpn_subnet.clone(),
        };
        match Files::system().and_then(|files| work(&input, &files, &mut NativeHarness)) {
            Ok(true) => 0,
            _ => 1,
        }
    }
}
pub struct Start;
impl Command for Start {
    const NAME: &'static str = "task-vm.start-topology";
    const SUMMARY: &'static str = "Start one bounded topology preparation as the managed account.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = StartInput;
    type Output = Started;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn target(_: &StartInput) -> Option<String> {
        Some("task-topology".into())
    }
    fn run(input: StartInput, context: &Context) -> Result<Started, Error> {
        validate(&input)?;
        start(&input, &Files::system()?, &mut recipe::Native(context))
    }
}
pub struct Inspect;
impl Command for Inspect {
    const NAME: &'static str = "task-vm.topology-status";
    const SUMMARY: &'static str =
        "Read a topology result, boot identity, or conservative systemd state.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = StatusInput;
    type Output = Status;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(input: StatusInput, context: &Context) -> Result<Status, Error> {
        status(&input.run, &Files::system()?, &mut recipe::Native(context))
    }
}
#[cfg(test)]
#[path = "task_topology_tests.rs"]
mod tests;
