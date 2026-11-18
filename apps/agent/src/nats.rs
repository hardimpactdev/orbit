//! SPIKE: the agent bus on NATS JetStream. With `/etc/orbit/agent/nats.json` present, orders come from this Node's
//! durable consumer `node-<id>` on stream ORDERS instead of Reverb, and executor receipts go to `orbit.receipts.<id>`.
//! An order is acknowledged once it is handed to the executor; an unacknowledged one comes back after `ack_wait`. A
//! receipt is published with JetStream's store confirmation and a dedup id; only when the bus is down does it fall back to
//! the Reverb path.

use std::collections::hash_map::DefaultHasher;
use std::hash::{Hash as _, Hasher as _};
use std::sync::OnceLock;
use std::time::Duration;

use async_nats::jetstream::{self, AckKind};
use futures_util::StreamExt as _;
use serde::Deserialize;

use crate::realtime::{Dispatch, ExecutorOrder, ExecutorRelay};

const CONFIG: &str = "/etc/orbit/agent/nats.json";

static BUS: OnceLock<(jetstream::Context, String)> = OnceLock::new();

#[derive(Deserialize)]
struct Config {
    url: String,
    user: String,
    password: String,
    node: String,
}

#[derive(Deserialize)]
struct Order {
    operation: String,
    order: String,
}

/// Starts the bus when this Node has a NATS configuration; does nothing otherwise.
pub fn start(relay: ExecutorRelay) {
    let Ok(text) = std::fs::read_to_string(CONFIG) else { return };
    let config = match serde_json::from_str::<Config>(&text) {
        Ok(config) => config,
        Err(error) => {
            eprintln!("orbit-agent: {CONFIG} is not valid: {error}");
            return;
        }
    };
    tokio::spawn(async move {
        loop {
            if let Err(error) = run(&config, &relay).await {
                eprintln!("orbit-agent: nats bus ended: {error}");
            }
            tokio::time::sleep(Duration::from_secs(1)).await;
        }
    });
}

async fn run(config: &Config, relay: &ExecutorRelay) -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
    let client = async_nats::ConnectOptions::with_user_and_password(config.user.clone(), config.password.clone())
        .connect(config.url.as_str())
        .await?;
    let context = jetstream::new(client);
    let _ = BUS.set((context.clone(), config.node.clone()));
    let consumer: jetstream::consumer::PullConsumer =
        context.get_consumer_from_stream(format!("node-{}", config.node), "ORDERS").await?;
    eprintln!("orbit-agent: nats bus joined as node-{}", config.node);
    let mut messages = consumer.messages().await?;
    while let Some(message) = messages.next().await {
        let message = message?;
        let Ok(order) = serde_json::from_slice::<Order>(&message.payload) else {
            let _ = message.ack_with(AckKind::Term).await;
            continue;
        };
        match relay.dispatch(ExecutorOrder { operation: order.operation, wire: order.order }) {
            Dispatch::Busy => {
                let _ = message.ack_with(AckKind::Nak(Some(Duration::from_secs(1)))).await;
            }
            Dispatch::Forwarded | Dispatch::TooLarge => {
                let _ = message.ack().await;
            }
        }
    }
    Ok(())
}

/// Publishes one executor receipt to the Gateway and waits for JetStream to store it. Returns false when there is no
/// bus or the publish failed, so the caller can fall back.
pub async fn publish_receipt(operation: &str, line: &str) -> bool {
    let Some((context, node)) = BUS.get() else { return false };
    let mut hasher = DefaultHasher::new();
    line.hash(&mut hasher);
    let value = serde_json::from_str::<serde_json::Value>(line).unwrap_or(serde_json::Value::Null);
    let body = serde_json::json!({"operation": operation, "node": node.parse::<u64>().unwrap_or(0), "line": value});
    let mut headers = async_nats::HeaderMap::new();
    headers.insert("Nats-Msg-Id", format!("{operation}-{:016x}", hasher.finish()).as_str());
    match context.publish_with_headers(format!("orbit.receipts.{node}"), headers, body.to_string().into()).await {
        Ok(ack) => ack.await.is_ok(),
        Err(_) => false,
    }
}
