// No unwrap() outside tests (ADR 0267 P9): an expect() states why the value is always there.
#![cfg_attr(not(test), deny(clippy::unwrap_used))]
pub use orbit_agent_contract::command_errors;
use rand::Rng;
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::{
    collections::BTreeMap,
    fs::File,
    io::BufReader,
    net::{IpAddr, SocketAddr},
};
pub mod checkouts;
pub mod command;
pub(crate) mod commands;
pub use commands::gateway_start_hooks::service as start_hook_service;
pub use commands::schedules::service as schedule_service;
pub mod environment;
pub mod facts;
pub mod logs;
pub mod metrics;
pub mod packages;
pub mod realtime;
#[cfg(test)]
#[path = "../tests/support/executable.rs"]
mod test_support;
pub mod workspace;
use workspace::WorkspaceState;

/// The version the observer reports in presence: the Cargo version plus the first 16 hexadecimal digits of the
/// running binary's SHA-256, as semver build metadata. The Gateway derives the same string from its delivery
/// manifest, so presence proves the exact pinned binary, not only its release number (ADR 0225).
pub fn reported_version() -> &'static str {
    static REPORTED: std::sync::OnceLock<String> = std::sync::OnceLock::new();
    REPORTED.get_or_init(|| {
        let digest =
            command::run::file_sha256(std::path::Path::new("/proc/self/exe")).unwrap_or_default();
        version_with_digest(env!("CARGO_PKG_VERSION"), &digest)
    })
}

pub fn version_with_digest(version: &str, sha256: &str) -> String {
    match sha256.get(..16) {
        Some(prefix) => format!("{version}+{prefix}"),
        None => version.to_string(),
    }
}

pub const CONFIG_PATH: &str = "/etc/orbit/agent/config.toml";
pub const CA_PATH: &str = "/etc/orbit/agent/ca.pem";
/// The agent's secret, written by the Gateway as `root:root` mode `0600` (ADR 0155).
pub const SECRET_PATH: &str = "/etc/orbit/agent/secret";
/// The agent holds an exclusive lock on this directory, so one Node runs one agent.
pub const LOCK_PATH: &str = "/etc/orbit/agent";
pub const PUSHER_FRAME_LIMIT: usize = 9_900;
pub const CHANGE_MERGE_WINDOW: std::time::Duration = std::time::Duration::from_millis(250);
/// A complete snapshot also goes out this long after the last one, so a lost one heals.
pub const SNAPSHOT_INTERVAL: std::time::Duration = std::time::Duration::from_secs(60);
/// The agent pings Reverb after this long without a message from it.
pub const PING_AFTER: std::time::Duration = std::time::Duration::from_secs(15);
/// The agent reconnects when Reverb sends nothing for this long after its ping.
pub const PONG_TIMEOUT: std::time::Duration = std::time::Duration::from_secs(10);
/// Every step from the TCP connect to `pusher_internal:subscription_succeeded` fits in this time.
pub const JOIN_TIMEOUT: std::time::Duration = std::time::Duration::from_secs(30);
/// A session that stayed joined this long starts the backoff again.
pub const HEALTHY_SESSION: std::time::Duration = std::time::Duration::from_secs(60);
/// While the Gateway or Reverb has not started yet, the agent asks again this often, ±20%, so it joins right after they start.
pub const EARLY_RETRY: std::time::Duration = std::time::Duration::from_millis(500);
/// The early retries end this long after the agent started, or after `EARLY_RETRY_LIMIT` of them; then the backoff runs.
pub const EARLY_RETRY_WINDOW: std::time::Duration = std::time::Duration::from_secs(60);
pub const EARLY_RETRY_LIMIT: u32 = 120;

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct NodeWorkerConfig {
    pub node_id: u64,
    pub user: String,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Config {
    pub gateway_url: String,
    pub gateway_address: IpAddr,
    #[serde(default)]
    pub swamp_worker: Option<NodeWorkerConfig>,
}
impl Config {
    pub fn load() -> Result<Self, Box<dyn std::error::Error + Send + Sync>> {
        let config: Self = toml::from_str(&std::fs::read_to_string(CONFIG_PATH)?)?;
        let url = reqwest::Url::parse(&config.gateway_url)?;
        if url.scheme() != "https"
            || url.host_str() != Some("gateway.orbit")
            || url.port_or_known_default() != Some(443)
        {
            return Err("gateway_url must be https://gateway.orbit".into());
        }
        Ok(config)
    }
}

/// The secret the agent sends on every Gateway request, so the Gateway can tell the agent apart from
/// any other process on the Node (ADR 0155). Neither `Debug` nor an error ever prints it.
pub struct AgentSecret(String);
impl std::fmt::Debug for AgentSecret {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str("AgentSecret(redacted)")
    }
}
impl AgentSecret {
    pub fn load(path: &str) -> Result<Self, Box<dyn std::error::Error + Send + Sync>> {
        let contents = std::fs::read_to_string(path)
            .map_err(|error| format!("cannot read the agent secret at {path}: {error}"))?;
        Self::parse(&contents)
    }
    pub fn parse(contents: &str) -> Result<Self, Box<dyn std::error::Error + Send + Sync>> {
        let secret = contents.trim();
        if secret.len() != 64
            || !secret
                .bytes()
                .all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte))
        {
            return Err("the agent secret must be 64 lowercase hexadecimal characters".into());
        }
        Ok(Self(secret.to_owned()))
    }
    /// The `Authorization` header for every Gateway request, marked sensitive so it is never logged.
    pub fn headers(&self) -> reqwest::header::HeaderMap {
        let mut value = reqwest::header::HeaderValue::from_str(&format!("Bearer {}", self.0))
            .expect("a hexadecimal secret is a valid header value");
        value.set_sensitive(true);
        let mut headers = reqwest::header::HeaderMap::new();
        headers.insert(reqwest::header::AUTHORIZATION, value);
        headers
    }
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq, PartialOrd, Ord)]
pub struct Unit {
    pub name: String,
    pub runtime: String,
    pub runtime_status: String,
}
pub fn process_unit_name(name: &str) -> Option<String> {
    let name = name.strip_suffix(".service").unwrap_or(name);
    (name.starts_with("orbit-process-") && name.len() > "orbit-process-".len())
        .then(|| name.to_owned())
}
pub fn docker_container_name(names: &[String]) -> Option<String> {
    names
        .iter()
        .map(|name| name.trim_start_matches('/'))
        .find_map(process_unit_name)
}
pub fn docker_status(state: &str) -> &'static str {
    match state {
        "running" => "running",
        "exited" => "exited",
        "restarting" => "restarting",
        "paused" => "paused",
        "created" => "created",
        "dead" => "dead",
        _ => "exited",
    }
}
#[derive(Debug, Clone, Serialize)]
pub struct Envelope<T> {
    pub sequence: u64,
    pub at: String,
    #[serde(flatten)]
    pub data: T,
}
#[derive(Debug, Clone, Serialize)]
pub struct ProcessData {
    pub unit: Unit,
}
#[derive(Debug, Clone, Serialize)]
pub struct SnapshotData {
    pub docker: &'static str,
    pub part: usize,
    pub parts: usize,
    pub units: Vec<Unit>,
}
#[derive(Debug, Clone, Serialize)]
pub struct HeartbeatData {}
#[derive(Debug, Clone, Serialize)]
pub struct WorkspacesData {
    pub part: usize,
    pub parts: usize,
    pub workspaces: Vec<WorkspaceState>,
}
#[derive(Debug, Clone, Serialize)]
pub struct WorkspaceData {
    pub workspace: WorkspaceState,
}
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct ClientFrame {
    pub event: String,
    pub channel: String,
    pub data: Value,
}

#[derive(Default)]
pub struct Sequencer(u64);
impl Sequencer {
    pub fn advance(&mut self) -> u64 {
        self.0 += 1;
        self.0
    }
    pub fn current(&self) -> u64 {
        self.0
    }
}

pub fn coalesce(changes: impl IntoIterator<Item = Unit>) -> Vec<Unit> {
    let mut latest = BTreeMap::new();
    for unit in changes {
        latest.insert((unit.runtime.clone(), unit.name.clone()), unit);
    }
    latest.into_values().collect()
}

#[derive(Default)]
pub struct ChangeBatch {
    pending: BTreeMap<(String, String), Unit>,
    first_change: Option<tokio::time::Instant>,
}
impl ChangeBatch {
    /// Returns true only when this push starts an empty batch and sets its fixed deadline.
    pub fn push(&mut self, unit: Unit, now: tokio::time::Instant) -> bool {
        let starts_batch = self.pending.is_empty();
        if starts_batch {
            self.first_change = Some(now);
        }
        self.pending
            .insert((unit.runtime.clone(), unit.name.clone()), unit);
        starts_batch
    }
    pub fn due(&self, now: tokio::time::Instant) -> bool {
        self.first_change
            .is_some_and(|start| now >= start + CHANGE_MERGE_WINDOW)
    }
    pub fn is_empty(&self) -> bool {
        self.pending.is_empty()
    }
    pub fn take(&mut self) -> Vec<Unit> {
        self.first_change = None;
        coalesce(std::mem::take(&mut self.pending).into_values())
    }
}
pub fn frame(channel: &str, event: &str, data: Value) -> ClientFrame {
    ClientFrame {
        event: event.into(),
        channel: channel.into(),
        data,
    }
}
pub fn process_frame(
    channel: &str,
    sequence: u64,
    unit: Unit,
) -> Result<ClientFrame, serde_json::Error> {
    let payload = Envelope {
        sequence,
        at: iso_now(),
        data: ProcessData { unit },
    };
    Ok(frame(
        channel,
        "client-process",
        serde_json::to_value(payload)?,
    ))
}
pub fn snapshot_frames(
    channel: &str,
    sequence: &mut Sequencer,
    units: &[Unit],
    docker: &'static str,
) -> Result<Vec<ClientFrame>, serde_json::Error> {
    let chunks = split_snapshot(channel, units, docker)?;
    chunks
        .into_iter()
        .map(|part| {
            let payload = Envelope {
                sequence: sequence.advance(),
                at: iso_now(),
                data: part,
            };
            Ok(frame(
                channel,
                "client-snapshot",
                serde_json::to_value(payload)?,
            ))
        })
        .collect()
}
pub fn split_snapshot(
    channel: &str,
    units: &[Unit],
    docker: &'static str,
) -> Result<Vec<SnapshotData>, serde_json::Error> {
    let chunks = chunk_for_frames(
        channel,
        "client-snapshot",
        units,
        |units| json!({"sequence":u64::MAX,"at":"9999-12-31T23:59:59.999999Z","docker":docker,"part":usize::MAX,"parts":usize::MAX,"units":units}),
    )?;
    let parts = chunks.len();
    Ok(chunks
        .into_iter()
        .enumerate()
        .map(|(index, units)| SnapshotData {
            docker,
            part: index + 1,
            parts,
            units,
        })
        .collect())
}
pub fn split_workspaces(
    channel: &str,
    workspaces: &[WorkspaceState],
) -> Result<Vec<WorkspacesData>, serde_json::Error> {
    let chunks = chunk_for_frames(
        channel,
        "client-workspaces",
        workspaces,
        |workspaces| json!({"sequence":u64::MAX,"at":"9999-12-31T23:59:59.999999Z","part":usize::MAX,"parts":usize::MAX,"workspaces":workspaces}),
    )?;
    let parts = chunks.len();
    Ok(chunks
        .into_iter()
        .enumerate()
        .map(|(index, workspaces)| WorkspacesData {
            part: index + 1,
            parts,
            workspaces,
        })
        .collect())
}
pub fn workspaces_frames(
    channel: &str,
    sequence: &mut Sequencer,
    workspaces: &[WorkspaceState],
) -> Result<Vec<ClientFrame>, serde_json::Error> {
    split_workspaces(channel, workspaces)?
        .into_iter()
        .map(|part| {
            let payload = Envelope {
                sequence: sequence.advance(),
                at: iso_now(),
                data: part,
            };
            Ok(frame(
                channel,
                "client-workspaces",
                serde_json::to_value(payload)?,
            ))
        })
        .collect()
}
pub fn workspace_frame(
    channel: &str,
    sequence: u64,
    workspace: WorkspaceState,
) -> Result<ClientFrame, serde_json::Error> {
    let payload = Envelope {
        sequence,
        at: iso_now(),
        data: WorkspaceData { workspace },
    };
    Ok(frame(
        channel,
        "client-workspace",
        serde_json::to_value(payload)?,
    ))
}
fn frame_limit_error(message: &'static str) -> serde_json::Error {
    serde_json::Error::io(std::io::Error::new(
        std::io::ErrorKind::InvalidData,
        message,
    ))
}
/// Splits items into the fewest consecutive parts whose worst-case frame stays within the Pusher limit.
/// Always returns at least one part, so an empty list still sends one frame.
fn chunk_for_frames<T: Clone + Serialize>(
    channel: &str,
    event: &str,
    items: &[T],
    probe: impl Fn(&[T]) -> Value,
) -> Result<Vec<Vec<T>>, serde_json::Error> {
    let fits = |items: &[T]| -> Result<bool, serde_json::Error> {
        Ok(serde_json::to_vec(&frame(channel, event, probe(items)))?.len() <= PUSHER_FRAME_LIMIT)
    };
    let mut chunks: Vec<Vec<T>> = Vec::new();
    let mut current = Vec::new();
    for item in items {
        current.push(item.clone());
        if !fits(&current)? {
            let last = current.pop().expect("just appended");
            if current.is_empty() {
                return Err(frame_limit_error(
                    "one entry exceeds the Pusher frame limit",
                ));
            }
            chunks.push(std::mem::take(&mut current));
            current.push(last);
        }
    }
    if !current.is_empty() || chunks.is_empty() {
        chunks.push(current);
    }
    for chunk in &chunks {
        if !fits(chunk)? {
            return Err(frame_limit_error("snapshot part exceeds frame limit"));
        }
    }
    Ok(chunks)
}
pub fn iso_now() -> String {
    time::OffsetDateTime::now_utc()
        .format(&time::format_description::well_known::Rfc3339)
        .unwrap_or_else(|_| "1970-01-01T00:00:00Z".into())
}
/// The attempt number for the retry after a connection ended. `joined_for` is how long the session
/// stayed joined, or `None` when it never joined. Only a session that stayed joined for
/// `HEALTHY_SESSION` starts the backoff again, so a session that fails right after each join keeps
/// backing off. The first retry waits `retry_delay(1)`, between 1 and 2 seconds.
pub fn next_attempt(attempt: u32, joined_for: Option<std::time::Duration>) -> u32 {
    if joined_for.is_some_and(|duration| duration >= HEALTHY_SESSION) {
        1
    } else {
        attempt.saturating_add(1)
    }
}

/// Whether a connection failed before the Gateway or Reverb itself answered: nothing listens there yet (refused), there is
/// no route to it yet (unreachable), or the proxy in front of it answers that it is not up (502 or 503). A failure after it
/// answered, such as another HTTP error, a refused join or a closed socket, is not one. A 504 means a slow upstream, not a
/// missing one: it takes the backoff.
pub fn refused_before_answer(error: &(dyn std::error::Error + 'static)) -> bool {
    use std::io::ErrorKind;
    let unanswered = |io: &std::io::Error| matches!(io.kind(), ErrorKind::ConnectionRefused | ErrorKind::HostUnreachable | ErrorKind::NetworkUnreachable);
    let starting = |status: u16| matches!(status, 502 | 503);
    // The cause may sit anywhere down the chain: an HTTP client and the WebSocket connect each wrap it.
    let mut current = Some(error);
    while let Some(link) = current {
        let io = link.downcast_ref::<std::io::Error>().or_else(|| match link.downcast_ref::<tokio_tungstenite::tungstenite::Error>() {
            Some(tokio_tungstenite::tungstenite::Error::Io(io)) => Some(io),
            _ => None,
        });
        if io.is_some_and(unanswered) {
            return true;
        }
        if let Some(tokio_tungstenite::tungstenite::Error::Http(response)) = link.downcast_ref::<tokio_tungstenite::tungstenite::Error>() {
            return starting(response.status().as_u16());
        }
        if let Some(status) = link.downcast_ref::<reqwest::Error>().and_then(reqwest::Error::status) {
            return starting(status.as_u16());
        }
        current = link.source();
    }
    false
}

/// The wait before the next connection, or None for the backoff of `retry_delay`. An agent that never joined asks again
/// every `EARLY_RETRY` while its connections fail before any server answered, within the first `EARLY_RETRY_WINDOW` of
/// the process and at most `EARLY_RETRY_LIMIT` times: a Node that boots with its Gateway, as a nested fleet does, then
/// joins about half a second after Reverb starts instead of seconds later.
pub fn early_retry(ever_joined: bool, refused: bool, running_for: std::time::Duration, early_retries: u32) -> Option<std::time::Duration> {
    early_retry_with(ever_joined, refused, running_for, early_retries, &mut rand::thread_rng())
}

fn early_retry_with(ever_joined: bool, refused: bool, running_for: std::time::Duration, early_retries: u32, rng: &mut impl Rng) -> Option<std::time::Duration> {
    // ±20%, so a fleet of agents that boots together does not ask a slow Gateway in step.
    let base = u64::try_from(EARLY_RETRY.as_millis()).unwrap_or(500);
    (!ever_joined && refused && running_for < EARLY_RETRY_WINDOW && early_retries < EARLY_RETRY_LIMIT)
        .then(|| std::time::Duration::from_millis(rng.gen_range(base * 4 / 5..=base * 6 / 5)))
}

/// The systemd unit the Gateway installs. The agent runs only inside it.
pub const SERVICE_UNIT: &str = "orbit-agent.service";

/// Waits until one of the observer's tasks panics, and returns the panic as an error.
///
/// Tokio catches a panic inside a spawned task, so without this a poisoned lock would end only the task that
/// found it. The observer races this against its session, so a panic ends the run with an error; the
/// process then exits 1 and systemd restarts it. A task that ends without a panic stays ended, as before.
pub async fn supervise(
    tasks: &mut tokio::task::JoinSet<()>,
) -> Box<dyn std::error::Error + Send + Sync> {
    loop {
        match tasks.join_next().await {
            Some(Err(error)) if error.is_panic() => {
                return format!(
                    "an observer task panicked: {}",
                    panic_text(&*error.into_panic())
                )
                .into();
            }
            Some(_) => {}
            None => std::future::pending().await,
        }
    }
}

pub(crate) fn panic_text(payload: &(dyn std::any::Any + Send)) -> &str {
    payload
        .downcast_ref::<&str>()
        .copied()
        .or_else(|| payload.downcast_ref::<String>().map(String::as_str))
        .unwrap_or("no message")
}

/// Whether `/proc/self/cgroup` places this process in the managed `orbit-agent.service` unit.
///
/// A copy started by hand, for example a test build run in a shell on a fleet Node, would join
/// Reverb as that Node's agent and then leave, which drops the Gateway's view of the Node. Only
/// systemd can start a process inside the unit's cgroup, and it runs one copy at a time.
pub fn runs_in_service_unit(cgroup: &str) -> bool {
    cgroup.lines().any(|line| {
        line.rsplit(':')
            .next()
            .is_some_and(|path| path.trim_end().ends_with(&format!("/{SERVICE_UNIT}")))
    })
}

pub fn retry_delay(attempt: u32) -> std::time::Duration {
    retry_delay_with(attempt, &mut rand::thread_rng())
}

fn retry_delay_with(attempt: u32, rng: &mut impl Rng) -> std::time::Duration {
    let base = 1_u64.checked_shl(attempt.min(5)).unwrap_or(30).min(30);
    // Equal jitter spreads the fleet even at the 30-second cap.
    std::time::Duration::from_millis(rng.gen_range(base * 500..=base * 1000))
}
pub fn tls_config(
) -> Result<std::sync::Arc<rustls::ClientConfig>, Box<dyn std::error::Error + Send + Sync>> {
    let mut roots = rustls::RootCertStore::empty();
    let mut reader = BufReader::new(File::open(CA_PATH)?);
    let certs = rustls_pemfile::certs(&mut reader).collect::<Result<Vec<_>, _>>()?;
    if certs.is_empty() {
        return Err("CA bundle contains no certificates".into());
    }
    for cert in certs {
        roots.add(cert)?;
    }
    Ok(std::sync::Arc::new(
        rustls::ClientConfig::builder()
            .with_root_certificates(roots)
            .with_no_client_auth(),
    ))
}
pub fn gateway_client(
    address: IpAddr,
    secret: &AgentSecret,
) -> Result<reqwest::Client, Box<dyn std::error::Error + Send + Sync>> {
    let mut reader = BufReader::new(File::open(CA_PATH)?);
    let mut builder = reqwest::Client::builder()
        .default_headers(secret.headers())
        .connect_timeout(std::time::Duration::from_secs(10))
        .timeout(std::time::Duration::from_secs(30))
        .https_only(true)
        .redirect(reqwest::redirect::Policy::none())
        .tls_built_in_root_certs(false)
        .resolve("gateway.orbit", SocketAddr::new(address, 443));
    for cert in rustls_pemfile::certs(&mut reader) {
        builder = builder.add_root_certificate(reqwest::Certificate::from_der(&cert?)?);
    }
    Ok(builder.build()?)
}

/// Takes the lock that keeps a second agent off the Node. A second agent would publish as the same
/// Reverb member, and Reverb announces neither its join nor its exit, so subscribers would mix two
/// event streams. The lock ends with the process.
pub fn lock_single_instance(path: &str) -> Result<File, Box<dyn std::error::Error + Send + Sync>> {
    let file = File::open(path)?;
    match file.try_lock() {
        Ok(()) => Ok(file),
        Err(std::fs::TryLockError::WouldBlock) => {
            Err("another orbit-agent already runs on this Node".into())
        }
        Err(std::fs::TryLockError::Error(error)) => Err(error.into()),
    }
}

/// Tells a connected session when to ping Reverb and when to give its connection up. The agent
/// only publishes, so on a quiet channel nothing else would show that the connection died.
#[derive(Debug)]
pub struct Liveness {
    last_message: tokio::time::Instant,
    ping_sent: Option<tokio::time::Instant>,
}
#[derive(Debug, PartialEq, Eq)]
pub enum LivenessCheck {
    Alive,
    Ping,
    Dead,
}
impl Liveness {
    pub fn new(now: tokio::time::Instant) -> Self {
        Self {
            last_message: now,
            ping_sent: None,
        }
    }
    /// Reverb sent something: the connection is alive.
    pub fn message(&mut self, now: tokio::time::Instant) {
        self.last_message = now;
        self.ping_sent = None;
    }
    pub fn deadline(&self) -> tokio::time::Instant {
        self.ping_sent
            .map_or(self.last_message + PING_AFTER, |sent| sent + PONG_TIMEOUT)
    }
    pub fn check(&mut self, now: tokio::time::Instant) -> LivenessCheck {
        match self.ping_sent {
            Some(sent) if now >= sent + PONG_TIMEOUT => LivenessCheck::Dead,
            Some(_) => LivenessCheck::Alive,
            None if now >= self.last_message + PING_AFTER => {
                self.ping_sent = Some(now);
                LivenessCheck::Ping
            }
            None => LivenessCheck::Alive,
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test]
    async fn a_poisoned_workspace_lock_ends_the_observer_run() {
        let shared = workspace::SharedWorkspaces::default();
        let holder = std::sync::Arc::clone(&shared);
        let _ = std::thread::spawn(move || {
            let _guard = holder.lock();
            panic!("poison the workspace lock");
        })
        .join();
        let mut supervised = tokio::task::JoinSet::new();
        supervised.spawn(async move {
            let _guard = workspace::lock_workspaces(&shared);
        });
        supervised.spawn(std::future::pending());
        let error = tokio::time::timeout(
            std::time::Duration::from_secs(5),
            supervise(&mut supervised),
        )
        .await
        .expect("the panic ends the run");
        assert!(
            error
                .to_string()
                .contains("the workspace lock is never held across a panic"),
            "{error}"
        );
    }

    #[tokio::test]
    async fn a_task_that_ends_without_a_panic_does_not_end_the_observer_run() {
        let mut supervised = tokio::task::JoinSet::new();
        supervised.spawn(async {});
        let supervision = tokio::time::timeout(
            std::time::Duration::from_millis(50),
            supervise(&mut supervised),
        );
        assert!(supervision.await.is_err());
    }

    #[test]
    fn fixture_executable_replaces_an_inode_with_an_open_write_descriptor() {
        let work = crate::commands::recipe::WorkDir::new().unwrap();
        let path = work.0.join("fixture tool 'quoted'");
        std::fs::write(&path, "old").unwrap();
        // Linux rejects exec while any process holds a write descriptor. Keeping
        // one here reproduces the effect of another test's child inheriting it.
        let writer = std::fs::OpenOptions::new().write(true).open(&path).unwrap();
        crate::test_support::executable(&path, "#!/bin/sh\nprintf '%s' 'new fixture'\n");
        let output = std::process::Command::new(&path).output().unwrap();
        assert!(output.status.success());
        assert_eq!(output.stdout, b"new fixture");
        drop(writer);
    }

    #[test]
    fn reports_the_version_with_the_binary_digest_as_build_metadata() {
        assert_eq!(
            version_with_digest("0.4.4", &"ab".repeat(32)),
            "0.4.4+abababababababab"
        );
        assert_eq!(version_with_digest("0.4.4", ""), "0.4.4");
    }
    #[test]
    fn loads_a_hexadecimal_secret_and_never_prints_it() {
        let secret = "0123456789abcdef".repeat(4);
        let parsed = AgentSecret::parse(&format!("{secret}\n")).unwrap();
        assert!(!format!("{parsed:?}").contains(&secret));
        let headers = parsed.headers();
        let value = headers.get(reqwest::header::AUTHORIZATION).unwrap();
        assert_eq!(value.to_str().unwrap(), format!("Bearer {secret}"));
        assert!(value.is_sensitive());
        assert!(!format!("{headers:?}").contains(&secret));
    }
    #[test]
    fn refuses_a_missing_or_malformed_secret_without_echoing_it() {
        for bad in [
            "",
            "short",
            &"A".repeat(64),
            &"g".repeat(64),
            &"a".repeat(65),
            &format!("{} x", "a".repeat(62)),
        ] {
            let error = AgentSecret::parse(bad).unwrap_err().to_string();
            assert!(error.contains("64 lowercase hexadecimal"));
            if !bad.is_empty() {
                assert!(!error.contains(bad));
            }
        }
        let missing =
            std::env::temp_dir().join(format!("orbit-agent-no-secret-{}", std::process::id()));
        let error = AgentSecret::load(missing.to_str().unwrap())
            .unwrap_err()
            .to_string();
        assert!(error.contains("cannot read the agent secret"));
    }
    #[test]
    fn a_second_agent_cannot_take_the_lock_until_the_first_one_ends() {
        let dir = std::env::temp_dir().join(format!("orbit-agent-lock-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let path = dir.to_str().unwrap();
        let first = lock_single_instance(path).unwrap();
        let refused = lock_single_instance(path).unwrap_err();
        assert!(refused.to_string().contains("another orbit-agent"));
        drop(first);
        assert!(lock_single_instance(path).is_ok());
        std::fs::remove_dir_all(&dir).unwrap();
    }
    #[test]
    fn only_a_session_that_stayed_joined_starts_the_backoff_again() {
        assert_eq!(next_attempt(5, None), 6);
        assert_eq!(next_attempt(5, Some(std::time::Duration::from_secs(1))), 6);
        assert_eq!(
            next_attempt(
                5,
                Some(HEALTHY_SESSION - std::time::Duration::from_millis(1))
            ),
            6
        );
        assert_eq!(next_attempt(5, Some(HEALTHY_SESSION)), 1);
        assert_eq!(next_attempt(u32::MAX, None), u32::MAX);
        // A session that fails right after every join keeps backing off up to 30 seconds.
        let mut attempt = 0;
        for _ in 0..10 {
            attempt = next_attempt(attempt, Some(std::time::Duration::from_secs(1)));
        }
        assert!(retry_delay(attempt) >= std::time::Duration::from_secs(15));
        assert!(
            retry_delay(next_attempt(attempt, Some(HEALTHY_SESSION)))
                < std::time::Duration::from_secs(3)
        );
    }
    #[test]
    fn an_agent_that_never_joined_asks_again_every_half_second_while_nothing_answers() {
        let second = std::time::Duration::from_secs(1);
        let around = |wait: Option<std::time::Duration>| wait.is_some_and(|wait| (400..=600).contains(&wait.as_millis()));
        assert!(around(early_retry(false, true, second, 0)));
        assert!(around(early_retry(false, true, EARLY_RETRY_WINDOW - second, EARLY_RETRY_LIMIT - 1)));
        // The jitter spreads the retries of agents that boot together.
        use rand::{rngs::StdRng, SeedableRng};
        let mut rng = StdRng::seed_from_u64(7);
        let waits: std::collections::BTreeSet<u128> = (0..20).filter_map(|_| early_retry_with(false, true, second, 0, &mut rng)).map(|wait| wait.as_millis()).collect();
        assert!(waits.len() > 5 && waits.iter().all(|wait| (400..=600).contains(wait)));
        // A server that answered, an agent that joined once, the end of the window or of the count: the backoff runs.
        assert_eq!(early_retry(false, false, second, 0), None);
        assert_eq!(early_retry(true, true, second, 0), None);
        assert_eq!(early_retry(false, true, EARLY_RETRY_WINDOW, 0), None);
        assert_eq!(early_retry(false, true, second, EARLY_RETRY_LIMIT), None);
    }
    #[test]
    fn only_a_connection_that_nothing_answered_counts_as_refused() {
        let refused = std::io::Error::from(std::io::ErrorKind::ConnectionRefused);
        let unreachable = std::io::Error::from(std::io::ErrorKind::HostUnreachable);
        assert!(refused_before_answer(&refused));
        assert!(refused_before_answer(&unreachable));
        // As the WebSocket connect and an HTTP client wrap it, the cause is found down the chain.
        assert!(refused_before_answer(&tokio_tungstenite::tungstenite::Error::Io(std::io::Error::from(std::io::ErrorKind::ConnectionRefused))));
        let wrapped: Box<dyn std::error::Error + Send + Sync> = Box::new(std::io::Error::other(Wrapped(refused)));
        assert!(refused_before_answer(wrapped.as_ref()));
        // The proxy in front of Reverb answers that Reverb is not up yet.
        let starting = |status: u16| {
            let response = tokio_tungstenite::tungstenite::http::Response::builder().status(status).body(None).expect("response");
            refused_before_answer(&tokio_tungstenite::tungstenite::Error::Http(response))
        };
        assert!(starting(502) && starting(503));
        // A 504 is a slow upstream, not a missing one.
        assert!(!starting(504) && !starting(401) && !starting(500));
        // An answer: a reset after accept, a timeout, another HTTP status or a refused join.
        assert!(!refused_before_answer(&std::io::Error::from(std::io::ErrorKind::ConnectionReset)));
        assert!(!refused_before_answer(&std::io::Error::from(std::io::ErrorKind::TimedOut)));
        assert!(!refused_before_answer(&tokio_tungstenite::tungstenite::Error::ConnectionClosed));
        let answered: Box<dyn std::error::Error + Send + Sync> = "Gateway answered 502".into();
        assert!(!refused_before_answer(answered.as_ref()));
    }
    #[derive(Debug)]
    struct Wrapped(std::io::Error);
    impl std::fmt::Display for Wrapped {
        fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
            write!(formatter, "connect failed")
        }
    }
    impl std::error::Error for Wrapped {
        fn source(&self) -> Option<&(dyn std::error::Error + 'static)> {
            Some(&self.0)
        }
    }
    #[test]
    fn retry_jitter_remains_bounded_and_spread_at_the_cap() {
        use rand::{rngs::StdRng, SeedableRng};
        let mut rng = StdRng::seed_from_u64(42);
        for attempt in [1, 2, 3, 4, 5, 100, u32::MAX] {
            let ceiling = std::time::Duration::from_secs((1_u64 << attempt.min(5)).min(30));
            let samples: Vec<_> = (0..32)
                .map(|_| retry_delay_with(attempt, &mut rng))
                .collect();
            assert!(samples
                .iter()
                .all(|delay| *delay >= ceiling / 2 && *delay <= ceiling));
            assert!(samples.iter().any(|delay| *delay != samples[0]));
        }
    }

    #[test]
    fn liveness_pings_after_a_quiet_spell_and_gives_up_without_an_answer() {
        let start = tokio::time::Instant::now();
        let mut liveness = Liveness::new(start);
        assert_eq!(liveness.check(start + PING_AFTER / 2), LivenessCheck::Alive);
        assert_eq!(liveness.check(start + PING_AFTER), LivenessCheck::Ping);
        assert_eq!(
            liveness.check(start + PING_AFTER + PONG_TIMEOUT / 2),
            LivenessCheck::Alive
        );
        liveness.message(start + PING_AFTER + PONG_TIMEOUT / 2);
        assert_eq!(
            liveness.check(start + PING_AFTER + PONG_TIMEOUT),
            LivenessCheck::Alive
        );
        let pinged = start + PING_AFTER * 2 + PONG_TIMEOUT;
        assert_eq!(liveness.check(pinged), LivenessCheck::Ping);
        assert_eq!(liveness.check(pinged + PONG_TIMEOUT), LivenessCheck::Dead);
    }
    fn unit(name: &str, status: &str, runtime: &str) -> Unit {
        Unit {
            name: name.into(),
            runtime: runtime.into(),
            runtime_status: status.into(),
        }
    }
    #[test]
    fn gateway_address_is_required_and_parsed_as_an_ip_literal() {
        assert!(toml::from_str::<Config>(r#"gateway_url = "https://gateway.orbit""#).is_err());

        let config: Config = toml::from_str(
            r#"gateway_url = "https://gateway.orbit"
gateway_address = "10.44.0.1""#,
        )
        .unwrap();

        assert_eq!(
            config.gateway_address,
            "10.44.0.1".parse::<IpAddr>().unwrap()
        );
    }

    #[test]
    fn runs_only_inside_the_managed_service_unit() {
        assert!(runs_in_service_unit(
            "0::/system.slice/orbit-agent.service\n"
        ));
        assert!(!runs_in_service_unit(
            "0::/user.slice/user-1000.slice/session-3.scope\n"
        ));
        assert!(!runs_in_service_unit(
            "0::/system.slice/orbit-e2e-test.service\n"
        ));
        assert!(!runs_in_service_unit(
            "0::/system.slice/orbit-agent.service/extra\n"
        ));
        assert!(!runs_in_service_unit(""));
    }

    #[test]
    fn names_filter_and_parse() {
        assert_eq!(
            process_unit_name("orbit-process-9-api.service").as_deref(),
            Some("orbit-process-9-api")
        );
        assert!(process_unit_name("ssh.service").is_none());
        assert_eq!(
            docker_container_name(&["/other".into(), "/orbit-process-3-web".into()]).as_deref(),
            Some("orbit-process-3-web")
        );
    }
    #[test]
    fn state_vocabularies() {
        assert_eq!(docker_status("running"), "running");
        assert_eq!(docker_status("unknown"), "exited");
    }
    #[test]
    fn merges_updates_to_latest_value() {
        assert_eq!(
            coalesce([
                unit("orbit-process-1-web", "active", "systemd"),
                unit("orbit-process-1-web", "failed", "systemd")
            ])[0]
                .runtime_status,
            "failed"
        );
    }
    #[test]
    fn serialized_snapshot_frames_fit_limit() {
        let units = (0..1000)
            .map(|n| {
                unit(
                    &format!("orbit-process-{n}-{}", "x".repeat(35)),
                    "active",
                    "systemd",
                )
            })
            .collect::<Vec<_>>();
        let parts = split_snapshot("presence-node.12", &units, "available").unwrap();
        assert!(parts.len() > 1);
        let mut seq = Sequencer::default();
        let frames = snapshot_frames("presence-node.12", &mut seq, &units, "available").unwrap();
        assert_eq!(frames.len(), parts.len());
        for (index, frame) in frames.iter().enumerate() {
            assert_eq!(frame.data["sequence"], index as u64 + 1);
            assert!(serde_json::to_vec(frame).unwrap().len() <= PUSHER_FRAME_LIMIT);
            assert_eq!(frame.channel, "presence-node.12");
        }
    }
    #[tokio::test(start_paused = true)]
    async fn two_changes_merge_to_latest_state_after_250ms() {
        let mut batch = ChangeBatch::default();
        let start = tokio::time::Instant::now();
        assert!(batch.push(unit("orbit-process-1-web", "starting", "docker"), start));
        tokio::time::advance(std::time::Duration::from_millis(100)).await;
        assert!(!batch.push(
            unit("orbit-process-1-web", "running", "docker"),
            tokio::time::Instant::now()
        ));
        tokio::time::advance(std::time::Duration::from_millis(149)).await;
        assert!(!batch.due(tokio::time::Instant::now()));
        tokio::time::advance(std::time::Duration::from_millis(1)).await;
        assert!(batch.due(tokio::time::Instant::now()));
        let latest = batch.take();
        assert_eq!(latest.len(), 1);
        assert_eq!(latest[0].runtime_status, "running");
        assert!(batch.is_empty());
    }

    #[tokio::test(start_paused = true)]
    async fn continuous_changes_flush_250ms_after_the_first_change() {
        let mut batch = ChangeBatch::default();
        batch.push(
            unit("orbit-process-1-web", "starting", "docker"),
            tokio::time::Instant::now(),
        );
        for status in ["running", "restarting"] {
            tokio::time::advance(std::time::Duration::from_millis(100)).await;
            assert!(!batch.push(
                unit("orbit-process-1-web", status, "docker"),
                tokio::time::Instant::now()
            ));
        }
        tokio::time::advance(std::time::Duration::from_millis(49)).await;
        assert!(!batch.due(tokio::time::Instant::now()));
        tokio::time::advance(std::time::Duration::from_millis(1)).await;
        assert!(batch.due(tokio::time::Instant::now()));
        assert_eq!(batch.take()[0].runtime_status, "restarting");
    }
    fn workspace(id: u64) -> WorkspaceState {
        WorkspaceState {
            instance_id: id,
            base: format!("\"{}", "b".repeat(254)),
            start: Some("a".repeat(40)),
            branch: Some(format!("\u{1}{}", "t".repeat(254))),
            head: Some("f".repeat(40)),
            dirty: Some(true),
            commits: Some(1000),
            diff: Some(workspace::DiffCounts {
                files: 5000,
                added: u64::MAX,
                removed: u64::MAX,
                truncated: false,
            }),
        }
    }
    #[test]
    fn workspace_list_frames_fit_limit_with_consecutive_sequences() {
        let workspaces = (1..=64).map(workspace).collect::<Vec<_>>();
        let mut seq = Sequencer::default();
        seq.advance();
        let frames = workspaces_frames("presence-node.12", &mut seq, &workspaces).unwrap();
        assert!(frames.len() > 1);
        let mut seen = Vec::new();
        for (index, frame) in frames.iter().enumerate() {
            assert_eq!(frame.event, "client-workspaces");
            assert_eq!(frame.channel, "presence-node.12");
            assert!(serde_json::to_vec(frame).unwrap().len() <= PUSHER_FRAME_LIMIT);
            assert_eq!(frame.data["sequence"], index as u64 + 2);
            assert_eq!(frame.data["part"], index + 1);
            assert_eq!(frame.data["parts"], frames.len());
            assert!(frame.data["at"].is_string());
            for w in frame.data["workspaces"].as_array().unwrap() {
                seen.push(serde_json::from_value::<WorkspaceState>(w.clone()).unwrap());
            }
        }
        assert_eq!(seen, workspaces);
        assert_eq!(seq.current(), frames.len() as u64 + 1);
    }
    #[test]
    fn empty_workspace_list_is_one_frame_and_one_change_is_one_event() {
        let mut seq = Sequencer::default();
        let frames = workspaces_frames("presence-node.3", &mut seq, &[]).unwrap();
        assert_eq!(frames.len(), 1);
        assert_eq!(frames[0].data["part"], 1);
        assert_eq!(frames[0].data["parts"], 1);
        assert_eq!(frames[0].data["workspaces"], json!([]));
        let event = workspace_frame("presence-node.3", 9, workspace(7)).unwrap();
        assert_eq!(event.event, "client-workspace");
        assert_eq!(event.data["sequence"], 9);
        assert_eq!(event.data["workspace"]["instance_id"], 7);
        assert_eq!(
            event.data.as_object().unwrap().keys().collect::<Vec<_>>(),
            ["sequence", "at", "workspace"]
        );
        assert!(serde_json::to_vec(&event).unwrap().len() <= PUSHER_FRAME_LIMIT);
    }
    #[test]
    fn snapshot_parts_have_consecutive_sequences() {
        let mut sequence = Sequencer::default();
        let frames = snapshot_frames(
            "presence-node.1",
            &mut sequence,
            &(0..800)
                .map(|i| {
                    unit(
                        &format!("orbit-process-{i}-{}", "x".repeat(50)),
                        "active",
                        "systemd",
                    )
                })
                .collect::<Vec<_>>(),
            "absent",
        )
        .unwrap();
        assert!(frames.len() > 1);
        for (index, f) in frames.iter().enumerate() {
            assert_eq!(f.data["sequence"], index as u64 + 1);
        }
    }
}
