//! Swamp's worker runs directly under a VM-local systemd runtime unit.
use super::recipe::{self, store::Account, Files, Host, Invocation};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{collections::BTreeMap, fs, path::Path, time::Duration};

pub(super) const UNIT: &str = "orbit-swamp-worker.service";
pub(super) const UNIT_PATH: &str = "/run/systemd/system/orbit-swamp-worker.service";
const TOKEN: &str = "/run/orbit-swamp-worker-enrollment";
const SERVER_TOKEN: &str = "/run/orbit-swamp-worker-server";
pub const CA: &str = "/usr/local/share/ca-certificates/orbit-root.crt";
command_errors! {
    pub enum Error {
        Invalid {} => "swamp.worker_invalid", "The worker enrollment input is invalid.";
        Failed {} => "swamp.worker_start_failed", "The Swamp worker lifecycle could not complete.";
    }
}
fn failed() -> Error {
    Error::Failed {}
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    #[serde(default)]
    pub(super) node: bool,
    pub(super) expires_at: Option<String>,
    pub(super) enrollment_id: String,
    pub(super) workspace: String,
    pub(super) orchestrator_url: String,
    pub(super) labels: BTreeMap<String, String>,
    pub(super) enrollment_token: Option<String>,
    pub(super) server_token: Option<String>,
    #[serde(default)]
    pub(super) server_token_required: bool,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Output {
    pub(super) started: bool,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub(super) drained: Option<bool>,
}
fn label(s: &str, max: usize) -> bool {
    !s.is_empty()
        && s.len() <= max
        && s.bytes()
            .all(|b| b.is_ascii_alphanumeric() || b"_.-".contains(&b))
}
pub(super) fn validate(input: &Input) -> Result<(), Error> {
    let invalid = || Error::Invalid {};
    let url = reqwest::Url::parse(&input.orchestrator_url).map_err(|_| invalid())?;
    if !label(&input.enrollment_id, 128)
        || url.scheme() != "wss"
        || url.host_str().is_none()
        || !url.username().is_empty()
        || url.password().is_some()
        || url.query().is_some()
        || url.fragment().is_some()
        || input
            .orchestrator_url
            .chars()
            .any(|c| c.is_control() || c == '%' || c == '"' || c == '\\')
        || input.labels.len() > 32
        || input
            .labels
            .iter()
            .any(|(k, v)| !label(k, 64) || !label(v, 128))
        || input.workspace.len() > 4096
        || !Path::new(&input.workspace).is_absolute()
        || input.workspace.chars().any(|c| c.is_control())
        || (!input.server_token_required && input.server_token.is_some())
        || [&input.enrollment_token, &input.server_token]
            .into_iter()
            .flatten()
            .any(|t| {
                t.len() > 4096
                    || t.split_once('.')
                        .is_none_or(|(n, s)| !label(n, 128) || s.len() < 16 || !label(s, 4096))
            })
    {
        return Err(invalid());
    }
    Ok(())
}
fn quote(s: &str) -> String {
    format!(
        "\"{}\"",
        s.replace('\\', "\\\\")
            .replace('"', "\\\"")
            .replace('%', "%%")
    )
}
fn argument(s: &str) -> String {
    quote(s).replace('$', "$$")
}
fn unit(input: &Input, account: &Account) -> String {
    let labels = input
        .labels
        .iter()
        .map(|(k, v)| format!(" --label {}", argument(&format!("{k}={v}"))))
        .collect::<String>();
    // WorkingDirectory is a path, not an argv word: systemd keeps quotes literally here.
    let workspace = input.workspace.replace('%', "%%");
    let home = account.home.to_string_lossy();
    let environment = quote(&format!("HOME={home}"));
    let cache = argument(&format!("--cache-dir={home}/.cache/swamp-worker"));
    let server_credential = if input.server_token_required {
        format!("LoadCredential=server:{SERVER_TOKEN}\n")
    } else {
        String::new()
    };
    let server_argument = if input.server_token_required {
        " --server-token-file=%d/server"
    } else {
        ""
    };
    let node = account.name == super::node_worker::USER;
    let policy = if node {
        "StartLimitIntervalSec=300\nStartLimitBurst=3\n"
    } else {
        ""
    };
    let runtime = if node {
        "Environment=SWAMP_HOME=/var/lib/orbit-node-worker/home SWAMP_CONFIG_DIR=/var/lib/orbit-node-worker/home/config\nExitType=cgroup\nRestart=on-failure\nRestartSec=10\nKillMode=mixed\nSendSIGKILL=no\nTimeoutStopSec=infinity\nNoNewPrivileges=yes\nProtectSystem=strict\nProtectHome=yes\nPrivateTmp=yes\nReadWritePaths=/var/lib/orbit-node-worker\nStandardOutput=null\nStandardError=null\n"
    } else {
        "Restart=no\nKillMode=control-group\nKillSignal=SIGTERM\nTimeoutStopSec=30\n"
    };
    let extra = if node { " --concurrency=1" } else { "" };
    let user = &account.name;
    format!("[Unit]\n{policy}Description=Swamp task worker {}\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=exec\nUser={user}\nGroup={user}\nWorkingDirectory={workspace}\nEnvironment={environment}\nLoadCredential=enrollment:{TOKEN}\n{server_credential}ExecStart=/usr/local/bin/swamp worker connect {} --no-telemetry --token-file=%d/enrollment{server_argument} {cache} --ca-cert={CA}{labels}{extra}\n{runtime}UMask=0077\n", input.enrollment_id, argument(&input.orchestrator_url))
}
fn call(host: &mut impl Host, args: &[&str]) -> Result<(), Error> {
    // Discard diagnostics: systemd and worker output are not a secret-safe boundary.
    recipe::run(host, &Invocation::new(args), true).map_err(|_| failed())?;
    Ok(())
}
fn active(host: &mut impl Host) -> Result<bool, Error> {
    match recipe::run(
        host,
        &Invocation::new(&["systemctl", "is-active", "--quiet", UNIT]),
        false,
    )
    .map_err(|_| failed())?
    .code
    {
        0 => Ok(true),
        3 | 4 => Ok(false),
        _ => Err(failed()),
    }
}
fn remove(path: &Path) -> Result<(), Error> {
    match fs::remove_file(path) {
        Ok(()) => Ok(()),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(()),
        Err(_) => Err(failed()),
    }
}
pub(super) fn stop(files: &Files, host: &mut impl Host) -> Result<(), Error> {
    let path = files.path(UNIT_PATH);
    if path.exists() || active(host)? {
        // Confirm the process and its descendants have stopped before removing the unit.
        call(host, &["systemctl", "stop", UNIT])?;
        remove(&path)?;
        call(host, &["systemctl", "daemon-reload"])?;
    }
    let removed = remove(&files.path(TOKEN));
    remove(&files.path(SERVER_TOKEN))?;
    remove(&files.path(super::node_worker::EXPIRY))?;
    removed
}
pub(super) fn start(
    files: &Files,
    host: &mut impl Host,
    account: &Account,
    input: &Input,
) -> Result<Output, Error> {
    validate(input)?;
    let workspace = fs::canonicalize(&input.workspace).map_err(|_| failed())?;
    if workspace != Path::new(&input.workspace)
        || !workspace.is_dir()
        || !workspace.starts_with(&account.home)
    {
        return Err(Error::Invalid {});
    }
    let text = unit(input, account);
    let path = files.path(UNIT_PATH);
    let running = active(host)?;
    if running && fs::read_to_string(&path).ok().as_deref() == Some(&text) {
        if account.name != super::node_worker::USER {
            remove(&files.path(TOKEN))?;
            remove(&files.path(SERVER_TOKEN))?;
        }
        return Ok(Output {
            started: true,
            drained: None,
        });
    }
    let Some(token) = &input.enrollment_token else {
        return Ok(Output {
            started: false,
            drained: None,
        });
    };
    if input.server_token_required && input.server_token.is_none() {
        return Ok(Output {
            started: false,
            drained: None,
        });
    }
    if !files.path(CA).is_file() || !files.path("/usr/local/bin/swamp").is_file() {
        return Err(failed());
    }
    if running {
        stop(files, host)?;
    }
    for dir in [
        account.home.join(".cache"),
        account.home.join(".cache/swamp-worker"),
    ] {
        files
            .directory(&dir, account.uid, account.gid)
            .map_err(|_| failed())?;
    }
    let source = files.path(TOKEN);
    let server_source = files.path(SERVER_TOKEN);
    let result = (|| {
        files
            .publish(
                &source,
                token.as_bytes(),
                0o600,
                files.uid,
                files.gid,
                false,
            )
            .map_err(|_| failed())?;
        if let Some(server_token) = &input.server_token {
            files
                .publish(
                    &server_source,
                    server_token.as_bytes(),
                    0o600,
                    files.uid,
                    files.gid,
                    false,
                )
                .map_err(|_| failed())?;
        }
        files
            .publish_root(&path, text.as_bytes())
            .map_err(|_| failed())?;
        call(host, &["systemctl", "daemon-reload"])?;
        if account.name == super::node_worker::USER {
            call(host, &["systemctl", "reset-failed", UNIT])?;
        }
        call(host, &["systemctl", "start", UNIT])?;
        call(host, &["systemctl", "is-active", "--quiet", UNIT])?;
        Ok(Output {
            started: true,
            drained: None,
        })
    })();
    if account.name == super::node_worker::USER && result.is_ok() {
        return result;
    }
    let removed = remove(&source);
    let server_removed = remove(&server_source);
    if result.is_err() || removed.is_err() || server_removed.is_err() {
        let _ = stop(files, host);
    }
    removed?;
    server_removed?;
    result
}
pub struct Start;
impl Command for Start {
    const NAME: &'static str = "swamp.start-worker";
    const SUMMARY: &'static str =
        "Start or check a Swamp task worker or operator-selected Node worker.";
    const MUTATES: bool = true;
    const RESOURCES: &'static [&'static str] = &["swamp-worker"];
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(p: Platform) -> Option<Privilege> {
        super::linux_only(p, Privilege::Root)
    }
    fn run(input: Input, context: &Context) -> Result<Output, Error> {
        if input.node {
            if !crate::Config::load().map_err(|_| failed())?.swamp_worker
                || !super::node_worker::pinned(&Files::system())
            {
                return Err(Error::Invalid {});
            }
            return super::node_worker::lifecycle::converge(
                &Files::system(),
                &mut recipe::Native(context),
                &input,
                time::OffsetDateTime::now_utc().unix_timestamp(),
            )
            .map_err(|_| failed());
        }
        let account = Account::named("orbit").map_err(|_| failed())?;
        start(
            &Files::system(),
            &mut recipe::Native(context),
            &account,
            &input,
        )
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct StopInput {}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Stopped {
    stopped: bool,
}
command_errors! {
    pub enum StopError {
        Failed {} => "swamp.worker_stop_failed", "The task VM worker could not be stopped.";
    }
}
pub struct Stop;
impl Command for Stop {
    const NAME: &'static str = "swamp.stop-worker";
    const SUMMARY: &'static str =
        "Stop a Swamp worker and remove its runtime unit and credentials.";
    const MUTATES: bool = true;
    const RESOURCES: &'static [&'static str] = &["swamp-worker"];
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = StopInput;
    type Output = Stopped;
    type Error = StopError;
    fn privilege(p: Platform) -> Option<Privilege> {
        super::linux_only(p, Privilege::Root)
    }
    fn run(_: StopInput, context: &Context) -> Result<Stopped, StopError> {
        stop(&Files::system(), &mut recipe::Native(context)).map_err(|_| StopError::Failed {})?;
        Ok(Stopped { stopped: true })
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct TrustInput {
    certificate: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Ready {
    ready: bool,
}
command_errors! {
    pub enum TrustError {
        Failed {} => "task_vm.image_build_failed", "The Orbit root CA could not be installed in the image.";
    }
}
fn trust(files: &Files, host: &mut impl Host, input: &TrustInput) -> Result<Ready, TrustError> {
    let certificates = rustls_pemfile::certs(&mut input.certificate.as_bytes())
        .collect::<Result<Vec<_>, _>>()
        .unwrap_or_default();
    let mut roots = rustls::RootCertStore::empty();
    if input.certificate.len() > 65536
        || certificates.len() != 1
        || roots.add(certificates[0].clone()).is_err()
    {
        return Err(TrustError::Failed {});
    }
    let directory = files.path("/usr/local/share/ca-certificates");
    if !directory.is_dir() {
        return Err(TrustError::Failed {});
    }
    files
        .publish_root(&files.path(CA), input.certificate.as_bytes())
        .map_err(|_| TrustError::Failed {})?;
    call(host, &["update-ca-certificates"]).map_err(|_| TrustError::Failed {})?;
    Ok(Ready { ready: true })
}
pub struct Trust;
impl Command for Trust {
    const NAME: &'static str = "image.trust-ca";
    const SUMMARY: &'static str =
        "Install the public Orbit CA in the task image system trust store.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = TrustInput;
    type Output = Ready;
    type Error = TrustError;
    fn privilege(p: Platform) -> Option<Privilege> {
        super::linux_only(p, Privilege::Root)
    }
    fn run(input: TrustInput, context: &Context) -> Result<Ready, TrustError> {
        trust(&Files::system(), &mut recipe::Native(context), &input)
    }
}
#[cfg(test)]
mod tests;
