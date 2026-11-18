//! SPIKE: the executor on NATS JetStream, with no relay in between. It pulls this Node's orders from its durable
//! consumer `node-<id>` (at most `SLOTS` at once), checks each signed order as before, runs it through the journal,
//! publishes the receipt to `orbit.receipts.<id>` and waits until JetStream has stored it, and only then acks the order.
//! While an order runs it sends `Progress`, so `ack_wait` can stay short without a long step being redelivered. An order
//! that is never acked (executor or connection gone) comes back after `ack_wait`; the journal answers it as a `repeat`.
//! The order signature stays the boundary: a NATS server cannot forge an order.

use std::sync::Arc;
use std::time::Duration;

use async_nats::jetstream::{self, AckKind};
use futures_util::StreamExt as _;
use orbit_executor_core::executor::{Executor, Run, StateRefusal};
use orbit_executor_core::order::{self, Checks};
use orbit_executor_core::server::{now_ms, Log, Settings};
use serde::Deserialize;

const CONFIG: &str = "/etc/orbit/agent/nats.json";
/// Orders running at once: two per CPU, at least two and at most 8. A one-CPU Node running 15 container execs at once during a
/// boot slowed T3's start from 1.5 s to 11.5 s, so the Node's own size sets the fan-out, not the flow.
/// In an Incus container the CPU quota sits on the host side and the affinity mask can name more CPUs than it may use; lxcfs
/// shows the real count in /proc/cpuinfo, so the smaller of the two counts.
fn slots() -> usize {
    let affinity = std::thread::available_parallelism().map_or(1, std::num::NonZero::get);
    let listed = std::fs::read_to_string("/proc/cpuinfo")
        .map(|text| text.lines().filter(|line| line.starts_with("processor")).count())
        .unwrap_or(affinity);
    (affinity.min(listed.max(1)) * 2).clamp(2, 8)
}
const PROGRESS_EVERY: Duration = Duration::from_secs(2);

#[derive(Deserialize)]
struct Config {
    url: String,
    user: String,
    password: String,
    node: String,
}

#[derive(Deserialize)]
struct Envelope {
    operation: String,
    order: String,
}

/// Starts the bus in its own thread when the Node has a NATS configuration.
pub(crate) fn start<R: Run + 'static>(settings: Arc<Settings>, executor: Arc<Executor<R>>, log: Log) {
    let Ok(text) = std::fs::read_to_string(CONFIG) else { return };
    let config: Config = match serde_json::from_str(&text) {
        Ok(config) => config,
        Err(error) => return log(&format!("{CONFIG} is not valid: {error}")),
    };
    let spawned = std::thread::Builder::new().name("nats-bus".to_owned()).spawn(move || {
        let runtime = match tokio::runtime::Builder::new_multi_thread().worker_threads(2).enable_all().build() {
            Ok(runtime) => runtime,
            Err(error) => return log(&format!("nats bus runtime failed: {error}")),
        };
        runtime.block_on(async move {
            loop {
                if let Err(error) = run(&config, &settings, &executor, log).await {
                    log(&format!("nats bus ended: {error}"));
                }
                tokio::time::sleep(Duration::from_secs(1)).await;
            }
        });
    });
    if let Err(error) = spawned {
        log(&format!("nats bus thread failed: {error}"));
    }
}

async fn run<R: Run + 'static>(
    config: &Config,
    settings: &Arc<Settings>,
    executor: &Arc<Executor<R>>,
    log: Log,
) -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
    let client = async_nats::ConnectOptions::with_user_and_password(config.user.clone(), config.password.clone())
        .connect(config.url.as_str())
        .await?;
    let context = jetstream::new(client.clone());
    let consumer: jetstream::consumer::PullConsumer =
        context.get_consumer_from_stream(format!("node-{}", config.node), "ORDERS").await?;
    log(&format!("nats bus joined as node-{} with {} slots", config.node, slots()));
    let slots_n = slots();
    let slots = Arc::new(tokio::sync::Semaphore::new(slots_n));
    let mut messages = consumer.stream().max_messages_per_batch(slots_n).messages().await?;
    while let Some(message) = messages.next().await {
        let message = message?;
        let permit = Arc::clone(&slots).acquire_owned().await?;
        let (client, context, settings, executor, node) =
            (client.clone(), context.clone(), Arc::clone(settings), Arc::clone(executor), config.node.clone());
        tokio::spawn(async move {
            let _permit = permit;
            handle(message, &client, &context, &settings, &executor, &node, log).await;
        });
    }
    Ok(())
}

async fn handle<R: Run + 'static>(
    message: jetstream::Message,
    client: &async_nats::Client,
    context: &jetstream::Context,
    settings: &Arc<Settings>,
    executor: &Arc<Executor<R>>,
    node: &str,
    log: Log,
) {
    let Ok(envelope) = serde_json::from_slice::<Envelope>(&message.payload) else {
        let _ = message.ack_with(AckKind::Term).await;
        return;
    };
    let now = now_ms();
    let checks = Checks { keys: &settings.keys, enabled: &settings.enabled, node: &settings.node };
    let verified = order::check_with_target(envelope.order.as_bytes(), &checks, now, |action, target| {
        executor.target_exists(action, target)
    });
    let (line, final_ack) = match verified {
        Err(refusal) => {
            log(&format!("refused order of Operation {}: {}", envelope.operation, refusal.code()));
            (serde_json::json!({"status": "refused", "reason": refusal.code(), "node_clock_ms": now}), AckKind::Term)
        }
        Ok(verified) => match fetch_blobs(client, node, verified.input()).await {
          Err(code) => {
            log(&format!("content of Operation {} unavailable: {code}", envelope.operation));
            (serde_json::json!({"status": "failed", "code": code, "target": verified.target().as_str(), "node_clock_ms": now}), AckKind::Ack)
          }
          Ok(()) => {
            let executor = Arc::clone(executor);
            let mut work = tokio::task::spawn_blocking(move || receipt(&executor, &verified, now));
            let mut progress = tokio::time::interval(PROGRESS_EVERY);
            progress.tick().await;
            let line = loop {
                tokio::select! {
                    done = &mut work => break done.unwrap_or_else(|_| serde_json::json!({"status": "failed", "code": "executor.panicked"})),
                    _ = progress.tick() => { let _ = message.ack_with(AckKind::Progress).await; }
                }
            };
            (line, AckKind::Ack)
          }
        },
    };
    // The order is acked only once its receipt is stored; if this publish fails, the order comes back and the journal
    // answers it again.
    let body = serde_json::json!({"operation": envelope.operation, "node": node.parse::<u64>().unwrap_or(0), "line": line});
    let id = format!(
        "{}-{}-{}",
        envelope.operation,
        line["target"].as_str().unwrap_or("-"),
        line["status"].as_str().unwrap_or("-")
    );
    let mut headers = async_nats::HeaderMap::new();
    headers.insert("Nats-Msg-Id", id.as_str());
    let stored = match context.publish_with_headers(format!("orbit.receipts.{node}"), headers, body.to_string().into()).await {
        Ok(ack) => ack.await.is_ok(),
        Err(_) => false,
    };
    if stored {
        let _ = message.ack_with(final_ack).await;
    } else {
        log(&format!("receipt of Operation {} not stored; the order will come back", envelope.operation));
    }
}

/// Runs one verified order through the journal and returns its receipt line (a finished key returns its stored result).
fn receipt<R: Run>(executor: &Executor<R>, verified: &order::VerifiedOrder, now: u64) -> serde_json::Value {
    match executor.handle(verified, now) {
        Ok(reply) => {
            let mut value = serde_json::to_value(&reply).unwrap_or_default();
            value["node_clock_ms"] = now.into();
            value
        }
        Err(refusal) => {
            let first = match &refusal {
                StateRefusal::ReplayedNonce { first } => first.as_deref().and_then(|reply| serde_json::to_value(reply).ok()),
                _ => None,
            };
            serde_json::json!({"status": "refused", "reason": refusal.code(), "operation": verified.operation(),
                "first": first, "node_clock_ms": now})
        }
    }
}

/// Reads every blob the order names (`{"blob": {"sha256": …}}` anywhere in its input) from the CONTENT bucket into the local
/// cache, by direct get of this Node's own key, and checks its hash. A cached blob is not read again.
async fn fetch_blobs(client: &async_nats::Client, node: &str, input: &serde_json::Value) -> Result<(), &'static str> {
    let mut shas = Vec::new();
    collect(input, &mut shas);
    for sha in shas {
        if !crate::prims::valid_sha(&sha) {
            return Err("prim.input_invalid");
        }
        let path = std::path::Path::new(crate::prims::BLOBS).join(&sha);
        if path.exists() {
            continue;
        }
        let reply = client
            .request(format!("$JS.API.DIRECT.GET.KV_CONTENT.$KV.CONTENT.n{node}.{sha}"), "".into())
            .await
            .map_err(|_| "blob.fetch_failed")?;
        if reply.status.is_some_and(|status| status != async_nats::StatusCode::OK) {
            return Err("blob.missing");
        }
        if crate::prims::sha_hex(&reply.payload) != sha {
            return Err("blob.hash_mismatch");
        }
        std::fs::create_dir_all(crate::prims::BLOBS).map_err(|_| "blob.cache_failed")?;
        // Content may hold secrets (an env file): the cache is root's alone.
        let _ = std::fs::set_permissions(crate::prims::BLOBS, std::os::unix::fs::PermissionsExt::from_mode(0o700));
        let partial = path.with_extension("part");
        std::fs::write(&partial, &reply.payload).and_then(|()| std::fs::rename(&partial, &path)).map_err(|_| "blob.cache_failed")?;
    }
    Ok(())
}

fn collect(value: &serde_json::Value, shas: &mut Vec<String>) {
    match value {
        serde_json::Value::Object(map) => {
            if let Some(sha) = map.get("blob").and_then(|blob| blob.get("sha256")).and_then(serde_json::Value::as_str) {
                shas.push(sha.to_owned());
            }
            map.values().for_each(|inner| collect(inner, shas));
        }
        serde_json::Value::Array(items) => items.iter().for_each(|inner| collect(inner, shas)),
        _ => {}
    }
}
