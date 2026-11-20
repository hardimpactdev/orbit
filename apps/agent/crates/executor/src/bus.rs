//! The executor on NATS `JetStream` (ADR 0271): it pulls this Node's orders from its durable consumer, checks each one
//! exactly as the socket does, reads the content the order names, runs it, publishes the receipt, and acknowledges the
//! order only after `JetStream` stored the receipt. An order that is never acknowledged comes back after the consumer's
//! acknowledgement wait, and the journal answers it as a repeat. While an order runs, the bus sends progress
//! acknowledgements, so a short wait never redelivers a step that still runs. The order signature stays the boundary: a
//! NATS server cannot forge an order.

use std::fmt::Write as _;
use std::path::{Path, PathBuf};
use std::sync::Arc;
use std::time::Duration;

use async_nats::jetstream::{self, AckKind};
use futures_util::StreamExt as _;
use orbit_executor_core::executor::{Executor, Run};
use orbit_executor_core::server::{self, Log, Settings};
use serde::Deserialize;
use sha2::{Digest as _, Sha256};

/// The bus's settings, written by the converge. Without the file the executor serves its socket alone.
pub const CONFIG_PATH: &str = "/etc/orbit/executor/nats.json";
/// Content read from the `CONTENT` bucket, by SHA-256. Root only: content may hold a secret, such as an env file.
pub const BLOBS_PATH: &str = "/var/lib/orbit/executor/blobs";
const PROGRESS_EVERY: Duration = Duration::from_secs(2);
const RECONNECT_AFTER: Duration = Duration::from_secs(1);
const CONSUMER_WAIT: Duration = Duration::from_secs(5);
/// The capability report is refreshed this often; the Gateway's `NODES` bucket forgets a report after five minutes, so a
/// stopped executor stops receiving kinds.
const REPORT_EVERY: Duration = Duration::from_secs(60);

/// The Node holds no bus credential: the Gateway's auth callout admits the connection by its `WireGuard` address and gives
/// it a short-lived user with only this Node's subjects.
#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Config {
    /// `nats://<gateway>:4222`, over `WireGuard`.
    url: String,
}

/// Orders running at once: two per CPU, between 2 and 8. In an Incus container the CPU quota sits on the host side and the
/// affinity mask can name more CPUs than the container may use; lxcfs shows the real count in `/proc/cpuinfo`, so the
/// smaller of the two counts.
fn slots() -> usize {
    let affinity = std::thread::available_parallelism().map_or(1, std::num::NonZero::get);
    let listed = std::fs::read_to_string("/proc/cpuinfo")
        .map_or(affinity, |text| text.lines().filter(|line| line.starts_with("processor")).count());
    affinity.min(listed.max(1)).saturating_mul(2).clamp(2, 8)
}

/// Starts the bus in its own thread when the Node has a bus configuration; it reconnects after every failure.
pub fn start<R: Run + 'static>(settings: Arc<Settings>, executor: Arc<Executor<R>>, log: Log) {
    let text = match std::fs::read_to_string(CONFIG_PATH) {
        Ok(text) => text,
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => return,
        Err(error) => return log(&format!("{CONFIG_PATH}: {error}")),
    };
    let config: Config = match serde_json::from_str(&text) {
        Ok(config) => config,
        Err(error) => return log(&format!("{CONFIG_PATH} is not valid: {error}")),
    };
    let spawned = std::thread::Builder::new().name("nats-bus".to_owned()).spawn(move || {
        let runtime = match tokio::runtime::Builder::new_multi_thread().worker_threads(2).enable_all().build() {
            Ok(runtime) => runtime,
            Err(error) => return log(&format!("nats bus runtime failed: {error}")),
        };
        runtime.block_on(async move {
            loop {
                if let Err(error) = consume(&config, &settings, &executor, log).await {
                    log(&format!("nats bus ended: {error}"));
                }
                tokio::time::sleep(RECONNECT_AFTER).await;
            }
        });
    });
    if let Err(error) = spawned {
        log(&format!("nats bus thread failed: {error}"));
    }
}

async fn consume<R: Run + 'static>(
    config: &Config,
    settings: &Arc<Settings>,
    executor: &Arc<Executor<R>>,
    log: Log,
) -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
    let node = settings.node.as_str().to_owned();
    // The Node's NATS user may subscribe only to its own inbox prefix, so it never sees another Node's replies.
    let client = async_nats::ConnectOptions::new()
        .custom_inbox_prefix(format!("_INBOX.node-{node}"))
        .connect(config.url.as_str())
        .await?;
    let context = jetstream::new(client.clone());
    // The report comes first, and it keeps coming while the bus waits: the Gateway starts a kind on the state machine only
    // for a Node with a fresh report, and makes the Node's consumer with its first order. Until then the consumer is
    // missing, and the bus waits for it quietly.
    report_actions(&context, settings, &node).await?;
    let reporter = tokio::spawn({
        let (context, settings, node) = (context.clone(), Arc::clone(settings), node.clone());
        async move {
            let mut every = tokio::time::interval(REPORT_EVERY);
            every.tick().await;
            loop {
                every.tick().await;
                if let Err(error) = report_actions(&context, &settings, &node).await {
                    log(&format!("capability report failed: {error}"));
                }
            }
        }
    });
    let result = serve(&context, client, &node, settings, executor, log).await;
    reporter.abort();
    result
}

/// Waits for the Node's consumer, then pulls its orders.
async fn serve<R: Run + 'static>(
    context: &jetstream::Context,
    client: async_nats::Client,
    node: &str,
    settings: &Arc<Settings>,
    executor: &Arc<Executor<R>>,
    log: Log,
) -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
    let mut waited = false;
    let consumer: jetstream::consumer::PullConsumer = loop {
        match context.get_consumer_from_stream(format!("node-{node}"), "ORDERS").await {
            Ok(consumer) => break consumer,
            Err(error) if consumer_missing(&error) => {
                if !waited {
                    log(&format!("nats bus waits for consumer node-{node}: {error}"));
                    waited = true;
                }
                tokio::time::sleep(CONSUMER_WAIT).await;
            }
            Err(error) => return Err(error.into()),
        }
    };
    let width = slots();
    log(&format!("nats bus joined as node-{node} with {width} slots"));
    let job = Job { client, context: context.clone(), node: node.to_owned() };
    pull(&consumer, &job, width, settings, executor, log).await
}

/// Pulls orders while a slot is free: an order is taken only when it can start, so a busy Node never lets a pulled order
/// wait past its acknowledgement wait.
async fn pull<R: Run + 'static>(
    consumer: &jetstream::consumer::PullConsumer,
    job: &Job,
    width: usize,
    settings: &Arc<Settings>,
    executor: &Arc<Executor<R>>,
    log: Log,
) -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
    let permits = Arc::new(tokio::sync::Semaphore::new(width));
    // One message per pull: at most one order waits in the client for a free slot.
    let mut messages = consumer.stream().max_messages_per_batch(1).messages().await?;
    loop {
        let permit = Arc::clone(&permits).acquire_owned().await?;
        let Some(message) = messages.next().await else { break };
        let message = message?;
        let job = job.clone();
        let (settings, executor) = (Arc::clone(settings), Arc::clone(executor));
        tokio::spawn(async move {
            job.handle(message, &settings, &executor, log).await;
            drop(permit);
        });
    }
    Ok(())
}

/// The Node's consumer does not exist yet: the Gateway has sent this Node no order so far.
fn consumer_missing(error: &jetstream::stream::ConsumerError) -> bool {
    matches!(error.kind(), jetstream::stream::ConsumerErrorKind::JetStream(inner)
        if inner.error_code() == jetstream::ErrorCode::CONSUMER_NOT_FOUND)
}

/// Whether the kernel's clock follows a time source, as chrony or timesyncd set it; None when the kernel cannot say. A
/// read-only `adjtimex`: it changes nothing. Signed orders expire seconds after the Gateway signs them, so the Gateway's
/// Doctor warns about a Node whose clock drifts unsynchronized.
fn clock_synchronized() -> Option<bool> {
    // SAFETY: `timex` is a plain C struct of integers, for which all zero bytes are a valid value.
    let mut timex: libc::timex = unsafe { std::mem::zeroed() };
    // SAFETY: `modes` is 0, so adjtimex only reads the kernel's clock state into the struct, which is a valid, exclusive
    // reference for the call.
    let state = unsafe { libc::adjtimex(&mut timex) };
    if state < 0 {
        return None;
    }
    Some(state != libc::TIME_ERROR && timex.status & libc::STA_UNSYNC == 0)
}

/// Tells the Gateway which primitives this Node runs: every action whose family the Node's roles enable, in KV `NODES` under
/// `n<node>`, with its clock's state. The Gateway starts a kind on the state machine only when every Node it touches reported
/// its primitives.
async fn report_actions(
    context: &jetstream::Context,
    settings: &Settings,
    node: &str,
) -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
    let actions: Vec<&str> = orbit_executor_core::action::Action::ALL
        .into_iter()
        .filter(|action| settings.enabled.allows(*action))
        .map(orbit_executor_core::action::Action::name)
        .collect();
    let body = serde_json::json!({"actions": actions, "version": env!("CARGO_PKG_VERSION"), "clock_synchronized": clock_synchronized()})
        .to_string();
    context.publish(format!("$KV.NODES.n{node}"), body.into()).await?.await?;
    Ok(())
}

/// What one order needs from the bus.
#[derive(Clone)]
struct Job {
    client: async_nats::Client,
    context: jetstream::Context,
    node: String,
}

impl Job {
    async fn handle<R: Run + 'static>(&self, message: jetstream::Message, settings: &Arc<Settings>, executor: &Arc<Executor<R>>, log: Log) {
        let now = server::now_ms();
        let wire = message.payload.to_vec();
        // The check may ask Incus whether the target exists: it runs on a blocking thread.
        let checked = tokio::task::spawn_blocking({
            let (wire, settings, executor) = (wire.clone(), Arc::clone(settings), Arc::clone(executor));
            move || server::verify(&wire, &settings, &executor, now, log, "nats")
        })
        .await
        .unwrap_or_else(|_| Err(serde_json::json!({"status": "refused", "reason": "executor.panicked", "node_clock_ms": now})));
        let (line, dedup) = match checked {
            Err(refused) => (with_unverified_identity(refused, &wire), format!("refused-{}", short_digest(&wire))),
            Ok(verified) => {
                let dedup = verified.nonce().to_string();
                if let Err(code) = self.fetch_blobs(verified.input()).await {
                    log(&format!("content of {} unavailable: {code}", verified.operation()));
                    let line = serde_json::json!({"status": "failed", "code": code, "operation": verified.operation(),
                        "action": verified.action(), "target": verified.target(), "node_clock_ms": now});
                    (line, dedup)
                } else {
                    let line = self.run(&message, executor, verified, now, log).await;
                    // Only the order's own result uses the nonce as its message id. A refusal, such as the replay of a
                    // redelivered order, must never take that id, or JetStream would drop the real result as a duplicate.
                    let dedup = if line["status"] == "refused" { format!("refused-{dedup}") } else { dedup };
                    (line, dedup)
                }
            }
        };
        if self.publish_receipt(&line, &dedup).await {
            if let Err(error) = message.ack().await {
                log(&format!("order ack failed: {error}"));
            }
        } else {
            log("receipt not stored; the order comes back and the journal answers it");
        }
    }

    /// Runs the order on a blocking thread and sends progress acknowledgements until it ends.
    async fn run<R: Run + 'static>(
        &self,
        message: &jetstream::Message,
        executor: &Arc<Executor<R>>,
        verified: orbit_executor_core::order::VerifiedOrder,
        now: u64,
        log: Log,
    ) -> serde_json::Value {
        let executor = Arc::clone(executor);
        let mut work = tokio::task::spawn_blocking(move || server::run_line(&executor, &verified, now, log, "nats"));
        let mut progress = tokio::time::interval(PROGRESS_EVERY);
        progress.tick().await;
        loop {
            tokio::select! {
                done = &mut work => {
                    return done.unwrap_or_else(|_| serde_json::json!({"status": "failed", "code": "executor.panicked", "node_clock_ms": now}));
                }
                _ = progress.tick() => {
                    if let Err(error) = message.ack_with(AckKind::Progress).await {
                        log(&format!("progress ack failed: {error}"));
                    }
                }
            }
        }
    }

    /// Publishes the receipt to `orbit.receipts.<node>` and waits until `JetStream` stored it.
    async fn publish_receipt(&self, line: &serde_json::Value, dedup: &str) -> bool {
        let body = serde_json::json!({"node": self.node, "line": line}).to_string();
        let mut headers = async_nats::HeaderMap::new();
        headers.insert("Nats-Msg-Id", dedup);
        match self.context.publish_with_headers(format!("orbit.receipts.{}", self.node), headers, body.into()).await {
            Ok(ack) => ack.await.is_ok(),
            Err(_) => false,
        }
    }

    /// Reads every blob the order names (`{"blob": {"sha256": …}}` anywhere in its input) from the `CONTENT` bucket into the
    /// local cache, by a direct get of this Node's own key, and checks its hash. A cached blob is not read again.
    async fn fetch_blobs(&self, input: &serde_json::Value) -> Result<(), &'static str> {
        let mut digests = Vec::new();
        collect_blobs(input, &mut digests);
        for digest in digests {
            if !valid_digest(&digest) {
                return Err("blob.input_invalid");
            }
            let path = blob_path(&digest);
            if path.exists() {
                continue;
            }
            let reply = self
                .client
                .request(format!("$JS.API.DIRECT.GET.KV_CONTENT.$KV.CONTENT.n{}.{digest}", self.node), "".into())
                .await
                .map_err(|_| "blob.fetch_failed")?;
            if reply.status.is_some_and(|status| status != async_nats::StatusCode::OK) {
                return Err("blob.missing");
            }
            if hex_digest(&reply.payload) != digest {
                return Err("blob.hash_mismatch");
            }
            let payload = reply.payload.clone();
            tokio::task::spawn_blocking(move || store_blob(&path, &payload))
                .await
                .map_err(|_| "blob.cache_failed")?
                .map_err(|_| "blob.cache_failed")?;
        }
        Ok(())
    }
}

/// A refusal before verification carries the unverified Operation, action and target when the payload decodes, so the
/// Gateway can route it. The Gateway treats it as a refusal only; nothing acts on it.
fn with_unverified_identity(mut line: serde_json::Value, wire: &[u8]) -> serde_json::Value {
    use base64::Engine as _;
    let payload = std::str::from_utf8(wire)
        .ok()
        .and_then(|text| text.split('.').next())
        .and_then(|encoded| base64::engine::general_purpose::STANDARD.decode(encoded).ok())
        .and_then(|bytes| serde_json::from_slice::<serde_json::Value>(&bytes).ok());
    if let Some(payload) = payload {
        for field in ["operation", "action", "target"] {
            if let Some(value) = payload.get(field) {
                line[field] = value.clone();
            }
        }
    }
    line
}

fn collect_blobs(value: &serde_json::Value, digests: &mut Vec<String>) {
    match value {
        serde_json::Value::Object(map) => {
            if let Some(digest) = map.get("blob").and_then(|blob| blob.get("sha256")).and_then(serde_json::Value::as_str) {
                digests.push(digest.to_owned());
            }
            map.values().for_each(|inner| collect_blobs(inner, digests));
        }
        serde_json::Value::Array(items) => items.iter().for_each(|inner| collect_blobs(inner, digests)),
        _ => {}
    }
}

/// A lowercase SHA-256 in hex.
#[must_use]
pub fn valid_digest(digest: &str) -> bool {
    digest.len() == 64 && digest.bytes().all(|b| matches!(b, b'0'..=b'9' | b'a'..=b'f'))
}

/// The lowercase hex SHA-256 of some bytes.
#[must_use]
pub fn hex_digest(bytes: &[u8]) -> String {
    Sha256::digest(bytes).iter().fold(String::with_capacity(64), |mut hex, byte| {
        // Writing to a String cannot fail.
        let _ = write!(hex, "{byte:02x}");
        hex
    })
}

fn short_digest(bytes: &[u8]) -> String {
    hex_digest(bytes).chars().take(32).collect()
}

/// Where a blob with this digest is cached.
#[must_use]
pub fn blob_path(digest: &str) -> PathBuf {
    Path::new(BLOBS_PATH).join(digest)
}

/// Reads a cached blob and checks its digest again, so a primitive never uses bytes that changed on disk.
///
/// # Errors
///
/// `blob.input_invalid`, `blob.missing` or `blob.hash_mismatch`.
pub fn read_blob(digest: &str) -> Result<Vec<u8>, &'static str> {
    if !valid_digest(digest) {
        return Err("blob.input_invalid");
    }
    let bytes = std::fs::read(blob_path(digest)).map_err(|_| "blob.missing")?;
    if hex_digest(&bytes) != digest {
        return Err("blob.hash_mismatch");
    }
    Ok(bytes)
}

fn store_blob(path: &Path, bytes: &[u8]) -> std::io::Result<()> {
    use std::os::unix::fs::{DirBuilderExt as _, PermissionsExt as _};
    std::fs::DirBuilder::new().recursive(true).mode(0o700).create(BLOBS_PATH)?;
    std::fs::set_permissions(BLOBS_PATH, std::fs::Permissions::from_mode(0o700))?;
    let partial = path.with_extension("part");
    std::fs::write(&partial, bytes)?;
    std::fs::rename(&partial, path)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn blobs_are_found_anywhere_in_an_input() {
        let input = serde_json::json!({"files": [{"blob": {"sha256": "a"}}, {"nested": {"blob": {"sha256": "b"}}}], "blob": {"sha256": "c"}});
        let mut digests = Vec::new();
        collect_blobs(&input, &mut digests);
        digests.sort();
        assert_eq!(digests, ["a", "b", "c"]);
    }

    #[test]
    fn a_digest_is_lowercase_hex_of_sixty_four_characters() {
        assert!(valid_digest(&hex_digest(b"orbit")));
        assert!(!valid_digest(&hex_digest(b"orbit").to_uppercase()));
        assert!(!valid_digest("../../etc/passwd"));
    }

    #[test]
    fn an_unverified_refusal_names_the_operation_for_routing() {
        use base64::Engine as _;
        let payload = base64::engine::general_purpose::STANDARD.encode(br#"{"operation":"41:allow-0","action":"ufw.ensure_rule","target":"3"}"#);
        let line = with_unverified_identity(serde_json::json!({"status": "refused", "reason": "expired"}), format!("{payload}.sig").as_bytes());
        assert_eq!(line["operation"], "41:allow-0");
        assert_eq!(line["target"], "3");
        assert_eq!(line["status"], "refused");
    }

    #[test]
    fn slots_stay_between_two_and_eight() {
        assert!((2..=8).contains(&slots()));
    }
}
