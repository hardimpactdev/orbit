//! The agent's connection to Reverb: discovery, the Pusher handshake, channel authorization, and bounded writes.
//! The observer keeps one connection for its Node channels.

use futures_util::{SinkExt, StreamExt};
use serde::Deserialize;
use serde_json::{json, Value};
use std::error::Error;
use tokio::net::TcpStream;
use tokio_tungstenite::{tungstenite::Message, MaybeTlsStream, WebSocketStream};

pub type Socket = WebSocketStream<MaybeTlsStream<tokio::net::TcpStream>>;

#[derive(Deserialize)]
pub struct DiscoveryEnvelope {
    pub data: Discovery,
}
#[derive(Deserialize)]
pub struct Discovery {
    pub url: Option<String>,
    pub address: Option<String>,
    pub key: Option<String>,
    pub channel: String,
    pub log_channel: String,
    pub member: String,
}
#[derive(Deserialize)]
pub struct Auth {
    pub auth: String,
    /// The member data of a presence channel; a private channel has none.
    #[serde(default)]
    pub channel_data: Option<String>,
}

impl Auth {
    /// The `pusher:subscribe` data for this authorization.
    pub fn subscription(&self, channel: &str) -> Value {
        match &self.channel_data {
            Some(data) => json!({"channel": channel, "auth": self.auth, "channel_data": data}),
            None => json!({"channel": channel, "auth": self.auth}),
        }
    }
}
pub async fn discover(
    client: &reqwest::Client,
    gateway: &str,
) -> Result<Discovery, Box<dyn Error + Send + Sync>> {
    Ok(client
        .get(format!(
            "{}/api/v1/agent/realtime",
            gateway.trim_end_matches('/')
        ))
        .send()
        .await?
        .error_for_status()?
        .json::<DiscoveryEnvelope>()
        .await?
        .data)
}

pub async fn connect_by_address(
    address: std::net::IpAddr,
    port: u16,
) -> Result<TcpStream, std::io::Error> {
    TcpStream::connect(std::net::SocketAddr::new(address, port)).await
}

pub async fn protocol_handshake(
    mut socket: Socket,
    client: &reqwest::Client,
    auth_url: &str,
    channel: &str,
    version: &str,
) -> Result<(Socket, String), Box<dyn Error + Send + Sync>> {
    let established = loop {
        let message = socket
            .next()
            .await
            .ok_or("WebSocket closed before connection_established")??;
        if let Message::Text(text) = message {
            let value: Value = serde_json::from_str(&text)?;
            if value["event"] == "pusher:error" {
                return Err("Pusher connection error".into());
            }
            if value["event"] == "pusher:connection_established" {
                break value;
            }
        }
    };
    let data: Value = serde_json::from_str(
        established["data"]
            .as_str()
            .ok_or("missing connection data")?,
    )?;
    let socket_id = data["socket_id"]
        .as_str()
        .ok_or("missing socket id")?
        .to_owned();
    let auth = channel_auth(client, auth_url, &socket_id, channel, version).await?;
    send_control(&mut socket, "pusher:subscribe", auth.subscription(channel)).await?;
    loop {
        let message = socket
            .next()
            .await
            .ok_or("WebSocket closed before subscription_succeeded")??;
        match message {
            Message::Text(text) => {
                let value: Value = serde_json::from_str(&text)?;
                match value["event"].as_str().unwrap_or("") {
                    "pusher_internal:subscription_succeeded" if value["channel"] == channel => {
                        return Ok((socket, socket_id));
                    }
                    "pusher:error" | "pusher_internal:subscription_error" => {
                        return Err(
                            format!("Pusher rejected subscription: {}", value["data"]).into()
                        )
                    }
                    "pusher:ping" => send_control(&mut socket, "pusher:pong", json!({})).await?,
                    _ => {}
                }
            }
            Message::Ping(data) => socket_write(socket.send(Message::Pong(data))).await?,
            Message::Close(_) => return Err("Pusher closed before subscription_succeeded".into()),
            _ => {}
        }
    }
}

pub async fn channel_auth(
    client: &reqwest::Client,
    auth_url: &str,
    socket_id: &str,
    channel: &str,
    version: &str,
) -> Result<Auth, Box<dyn Error + Send + Sync>> {
    Ok(client
        .post(auth_url)
        .form(&[
            ("socket_id", socket_id),
            ("channel_name", channel),
            ("version", version),
        ])
        .send()
        .await
        .map_err(reqwest::Error::without_url)?
        .error_for_status()
        .map_err(reqwest::Error::without_url)?
        .json()
        .await?)
}

pub async fn send_client(
    socket: &mut Socket,
    channel: &str,
    event: &str,
    data: Value,
) -> Result<(), Box<dyn Error + Send + Sync>> {
    send_raw_frame(socket, crate::frame(channel, event, data)).await
}
pub async fn send_control(
    socket: &mut Socket,
    event: &str,
    data: Value,
) -> Result<(), Box<dyn Error + Send + Sync>> {
    socket_write(socket.send(Message::Text(
        json!({"event":event,"data":data}).to_string().into(),
    )))
    .await?;
    Ok(())
}
pub async fn send_raw_frame(
    socket: &mut Socket,
    event: crate::ClientFrame,
) -> Result<(), Box<dyn Error + Send + Sync>> {
    let text = serde_json::to_string(&event)?;
    if text.len() > crate::PUSHER_FRAME_LIMIT {
        return Err("Pusher client event exceeded the frame size limit".into());
    }
    socket_write(socket.send(Message::Text(text.into()))).await?;
    Ok(())
}
/// Bound writes so a peer that stops reading cannot hold the receive loop past its ping deadline.
/// A timed-out write ends this session; its sink is never reused.
pub async fn socket_write<T>(
    write: impl std::future::Future<Output = Result<T, tokio_tungstenite::tungstenite::Error>>,
) -> Result<T, Box<dyn Error + Send + Sync>> {
    tokio::time::timeout(crate::PONG_TIMEOUT, write)
        .await
        .map_err(|_| "Reverb WebSocket write timed out")?
        .map_err(Into::into)
}

/// Connects to Reverb at its WireGuard address and joins one channel as this Node's agent. `ws_url` must be
/// `wss://reverb.orbit`; the certificate is checked against the Orbit root in `tls`.
pub async fn join(
    client: &reqwest::Client,
    gateway: &str,
    ws_url: &str,
    ws_address: &str,
    key: &str,
    channel: &str,
    tls: std::sync::Arc<rustls::ClientConfig>,
) -> Result<(Socket, String), Box<dyn Error + Send + Sync>> {
    use tokio_tungstenite::{
        client_async_tls_with_config, tungstenite::client::IntoClientRequest, Connector,
    };
    let mut parsed = reqwest::Url::parse(ws_url)?;
    if parsed.scheme() != "wss"
        || parsed.host_str() != Some("reverb.orbit")
        || parsed.port_or_known_default() != Some(443)
    {
        return Err("realtime discovery must use wss://reverb.orbit".into());
    }
    parsed.set_path(&format!("/app/{key}"));
    parsed.set_query(Some(&format!(
        "protocol=7&client=orbit-agent&version={}&flash=false",
        crate::reported_version()
    )));
    let address = ws_address.parse::<std::net::IpAddr>()?;
    let request = parsed.as_str().into_client_request()?;
    let auth_url = format!(
        "{}/api/v1/agent/broadcasting/auth",
        gateway.trim_end_matches('/')
    );
    let join = async {
        let stream = connect_by_address(address, 443).await?;
        let (socket, _) =
            client_async_tls_with_config(request, stream, None, Some(Connector::Rustls(tls)))
                .await?;
        protocol_handshake(
            socket,
            client,
            &auth_url,
            channel,
            crate::reported_version(),
        )
        .await
    };
    tokio::time::timeout(crate::JOIN_TIMEOUT, join)
        .await
        .map_err(|_| "joining the Reverb channel timed out")?
}

// The executor pass-through (ADR 0266 D6, docs/reference/executor.md#the-observer-pass-through). The observer reads only the
// Pusher envelope of `executor-order`: the Operation id the Gateway sets, which it copies unread, and the order's wire form. It
// never parses, verifies or signs the order. It writes the bytes unchanged to the executor's socket and sends each reply line
// back as `client-executor-result`, with the same Operation id, on the same channel.

/// The executor's socket on a Node.
pub const EXECUTOR_SOCKET: &str = "/run/orbit-executor.sock";
/// The server event that carries a signed order.
pub const EXECUTOR_ORDER_EVENT: &str = "executor-order";
/// The client event that carries one reply line of the executor.
pub const EXECUTOR_RESULT_EVENT: &str = "client-executor-result";
/// The largest order the observer forwards, in bytes.
pub const MAX_EXECUTOR_ORDER_BYTES: usize = 4096;
/// The longest Operation id the observer copies back, in bytes.
pub const MAX_EXECUTOR_OPERATION_BYTES: usize = 64;
/// How many orders may wait for their reply at once.
pub const MAX_EXECUTOR_IN_FLIGHT: usize = 64;
/// How long the observer reads one order's reply.
pub const EXECUTOR_REPLY_TIMEOUT: std::time::Duration = std::time::Duration::from_secs(10);
/// The most reply bytes the observer reads for one order.
pub const MAX_EXECUTOR_REPLY_BYTES: u64 = 64 * 1024;

/// One `executor-order` as it arrived: the Operation id and the order's wire form, both unread.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ExecutorOrder {
    pub operation: String,
    pub wire: String,
}

impl ExecutorOrder {
    /// Reads the event's envelope. Pusher sends a server event's data as a JSON string; an object is accepted too.
    pub fn from_event(value: &Value) -> Option<Self> {
        let data = match &value["data"] {
            Value::String(text) => serde_json::from_str::<Value>(text).ok()?,
            data => data.clone(),
        };
        let operation = data["operation"].as_str()?;
        if operation.is_empty() || operation.len() > MAX_EXECUTOR_OPERATION_BYTES {
            return None;
        }
        Some(Self {
            operation: operation.to_owned(),
            wire: data["order"].as_str()?.to_owned(),
        })
    }
}

/// One reply line of the executor, unread and without its newline, with the Operation id of its order.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ExecutorReply {
    pub operation: String,
    pub line: String,
}

impl ExecutorReply {
    /// The `client-executor-result` frame for this line, or `None` when it does not fit one Pusher frame.
    pub fn frame(&self, channel: &str) -> Option<crate::ClientFrame> {
        let frame = crate::frame(
            channel,
            EXECUTOR_RESULT_EVENT,
            json!({"operation": self.operation, "line": self.line}),
        );
        let size = serde_json::to_vec(&frame).ok()?.len();
        (size <= crate::PUSHER_FRAME_LIMIT).then_some(frame)
    }
}

/// What became of an order the observer received.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Dispatch {
    Forwarded,
    TooLarge,
    Busy,
}

/// Forwards orders to the executor's socket and hands each reply line to the session that is joined when it arrives.
#[derive(Debug, Clone)]
pub struct ExecutorRelay {
    socket: std::path::PathBuf,
    in_flight: std::sync::Arc<tokio::sync::Semaphore>,
    replies: tokio::sync::mpsc::UnboundedSender<ExecutorReply>,
}

impl ExecutorRelay {
    pub fn new(
        socket: impl Into<std::path::PathBuf>,
    ) -> (Self, tokio::sync::mpsc::UnboundedReceiver<ExecutorReply>) {
        let (replies, receiver) = tokio::sync::mpsc::unbounded_channel();
        let relay = Self {
            socket: socket.into(),
            in_flight: std::sync::Arc::new(tokio::sync::Semaphore::new(MAX_EXECUTOR_IN_FLIGHT)),
            replies,
        };
        (relay, receiver)
    }

    /// How many orders wait for their reply now.
    pub fn in_flight(&self) -> usize {
        MAX_EXECUTOR_IN_FLIGHT - self.in_flight.available_permits()
    }

    /// Starts forwarding one order. An order over 4 KB, or one past the 64 in flight, is dropped: the Gateway's fallback
    /// covers it.
    pub fn dispatch(&self, order: ExecutorOrder) -> Dispatch {
        if order.wire.len() > MAX_EXECUTOR_ORDER_BYTES {
            return Dispatch::TooLarge;
        }
        let Ok(permit) = std::sync::Arc::clone(&self.in_flight).try_acquire_owned() else {
            return Dispatch::Busy;
        };
        let (socket, replies) = (self.socket.clone(), self.replies.clone());
        tokio::spawn(async move {
            let _permit = permit;
            let operation = order.operation.clone();
            if let Err(error) = forward(&socket, order, &replies).await {
                eprintln!("orbit-agent: executor order of Operation {operation} not relayed in full: {error}");
            }
        });
        Dispatch::Forwarded
    }
}

/// Writes the order to the socket and relays every complete reply line, within 10 s and 64 KB in total. Lines relayed
/// before a bound passes stay relayed.
async fn forward(
    socket: &std::path::Path,
    order: ExecutorOrder,
    replies: &tokio::sync::mpsc::UnboundedSender<ExecutorReply>,
) -> std::io::Result<()> {
    use tokio::io::{AsyncBufReadExt as _, AsyncReadExt as _, AsyncWriteExt as _};
    let exchange = async {
        let mut stream = tokio::net::UnixStream::connect(socket).await?;
        stream.write_all(order.wire.as_bytes()).await?;
        stream.shutdown().await?;
        let mut reader = tokio::io::BufReader::new(stream.take(MAX_EXECUTOR_REPLY_BYTES));
        let mut line = Vec::new();
        loop {
            line.clear();
            if reader.read_until(b'\n', &mut line).await? == 0 {
                return Ok(());
            }
            if line.pop() != Some(b'\n') {
                return Err(std::io::Error::other(
                    "the reply ended inside a line or passed 64 KB",
                ));
            }
            let line = String::from_utf8(line.clone())
                .map_err(|_| std::io::Error::other("a reply line is not UTF-8"))?;
            // The receiver lives as long as the observer; a send fails only while it shuts down.
            let _ = replies.send(ExecutorReply {
                operation: order.operation.clone(),
                line,
            });
        }
    };
    tokio::time::timeout(EXECUTOR_REPLY_TIMEOUT, exchange)
        .await
        .map_err(|_| std::io::Error::new(std::io::ErrorKind::TimedOut, "no reply within 10 s"))?
}

#[cfg(test)]
mod tests {
    use super::*;
    use tokio::io::{AsyncReadExt as _, AsyncWriteExt as _};
    use tokio::net::UnixListener;

    fn socket_path(name: &str) -> std::path::PathBuf {
        let dir = std::env::temp_dir().join(format!(
            "orbit-executor-relay-{name}-{}",
            std::process::id()
        ));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).expect("temp dir");
        dir.join("executor.sock")
    }

    fn order(operation: &str, wire: &str) -> ExecutorOrder {
        ExecutorOrder {
            operation: operation.into(),
            wire: wire.into(),
        }
    }

    async fn settle(relay: &ExecutorRelay) {
        while relay.in_flight() > 0 {
            tokio::time::sleep(std::time::Duration::from_millis(5)).await;
        }
    }

    #[test]
    fn the_envelope_is_read_and_the_order_left_unread() {
        let as_string = json!({"event": "executor-order", "data": json!({"operation": "42", "order": "e30=.c2ln"}).to_string()});
        assert_eq!(
            ExecutorOrder::from_event(&as_string),
            Some(order("42", "e30=.c2ln"))
        );
        let as_object = json!({"event": "executor-order", "data": {"operation": "42", "order": "not even base64"}});
        assert_eq!(
            ExecutorOrder::from_event(&as_object),
            Some(order("42", "not even base64"))
        );
        assert_eq!(
            ExecutorOrder::from_event(&json!({"data": {"order": "x"}})),
            None
        );
        assert_eq!(
            ExecutorOrder::from_event(&json!({"data": {"operation": "", "order": "x"}})),
            None
        );
        let long = "r".repeat(MAX_EXECUTOR_OPERATION_BYTES + 1);
        assert_eq!(
            ExecutorOrder::from_event(&json!({"data": {"operation": long, "order": "x"}})),
            None
        );
    }

    #[test]
    fn a_reply_line_that_does_not_fit_a_frame_has_none() {
        let small = ExecutorReply {
            operation: "42".into(),
            line: r#"{"status":"accepted"}"#.into(),
        };
        let frame = small.frame("presence-node.7").expect("fits");
        assert_eq!(frame.event, EXECUTOR_RESULT_EVENT);
        assert_eq!(frame.channel, "presence-node.7");
        assert_eq!(
            frame.data,
            json!({"operation": "42", "line": r#"{"status":"accepted"}"#})
        );
        let large = ExecutorReply {
            operation: "42".into(),
            line: "x".repeat(crate::PUSHER_FRAME_LIMIT),
        };
        assert!(large.frame("presence-node.7").is_none());
    }

    #[tokio::test]
    async fn the_order_reaches_the_socket_unchanged_and_every_reply_line_comes_back() {
        let path = socket_path("unchanged");
        let listener = UnixListener::bind(&path).expect("bind");
        let wire = format!("{}.{}", "A".repeat(3000), "B+/=".repeat(10));
        let server = tokio::spawn(async move {
            let (mut stream, _) = listener.accept().await.expect("accept");
            let mut got = Vec::new();
            stream.read_to_end(&mut got).await.expect("read");
            stream
                .write_all(b"{\"status\":\"accepted\"}\n{\"status\":\"done\"}\n")
                .await
                .expect("write");
            got
        });
        let (relay, mut replies) = ExecutorRelay::new(&path);
        assert_eq!(relay.dispatch(order("42", &wire)), Dispatch::Forwarded);
        assert_eq!(server.await.expect("server"), wire.as_bytes());
        let first = replies.recv().await.expect("accepted line");
        assert_eq!(
            first,
            ExecutorReply {
                operation: "42".into(),
                line: r#"{"status":"accepted"}"#.into()
            }
        );
        assert_eq!(
            replies.recv().await.expect("result line").line,
            r#"{"status":"done"}"#
        );
        settle(&relay).await;
        assert!(replies.try_recv().is_err(), "two lines, two replies");
    }

    #[tokio::test]
    async fn an_order_over_4_kb_is_dropped_and_4_kb_is_forwarded() {
        let path = socket_path("cap");
        let listener = UnixListener::bind(&path).expect("bind");
        let (relay, _replies) = ExecutorRelay::new(&path);
        assert_eq!(
            relay.dispatch(order("42", &"a".repeat(MAX_EXECUTOR_ORDER_BYTES + 1))),
            Dispatch::TooLarge
        );
        assert_eq!(relay.in_flight(), 0);
        assert_eq!(
            relay.dispatch(order("n2", &"a".repeat(MAX_EXECUTOR_ORDER_BYTES))),
            Dispatch::Forwarded
        );
        let (mut stream, _) = listener.accept().await.expect("accept");
        let mut got = Vec::new();
        stream.read_to_end(&mut got).await.expect("read");
        assert_eq!(got.len(), MAX_EXECUTOR_ORDER_BYTES);
    }

    #[tokio::test]
    async fn at_most_64_orders_are_in_flight() {
        let path = socket_path("in-flight");
        let listener = UnixListener::bind(&path).expect("bind");
        let (relay, _replies) = ExecutorRelay::new(&path);
        for index in 0..MAX_EXECUTOR_IN_FLIGHT {
            assert_eq!(
                relay.dispatch(order(&format!("n{index}"), "x")),
                Dispatch::Forwarded
            );
        }
        assert_eq!(relay.dispatch(order("over", "x")), Dispatch::Busy);
        let mut held = Vec::new();
        for _ in 0..MAX_EXECUTOR_IN_FLIGHT {
            held.push(listener.accept().await.expect("accept").0);
        }
        drop(held);
        settle(&relay).await;
        assert_eq!(relay.dispatch(order("again", "x")), Dispatch::Forwarded);
    }

    #[tokio::test(start_paused = true)]
    async fn a_reply_is_read_for_10_s_and_lines_before_that_stay_relayed() {
        let path = socket_path("timeout");
        let listener = UnixListener::bind(&path).expect("bind");
        let server = tokio::spawn(async move {
            let (mut stream, _) = listener.accept().await.expect("accept");
            stream
                .write_all(b"{\"status\":\"accepted\"}\n")
                .await
                .expect("write");
            tokio::time::sleep(std::time::Duration::from_secs(3600)).await;
            drop(stream);
        });
        let (relay, mut replies) = ExecutorRelay::new(&path);
        let started = tokio::time::Instant::now();
        assert_eq!(relay.dispatch(order("42", "x")), Dispatch::Forwarded);
        assert_eq!(
            replies.recv().await.expect("accepted line").line,
            r#"{"status":"accepted"}"#
        );
        settle(&relay).await;
        let waited = started.elapsed();
        assert!(
            waited >= EXECUTOR_REPLY_TIMEOUT
                && waited < EXECUTOR_REPLY_TIMEOUT + std::time::Duration::from_secs(1),
            "waited {waited:?}"
        );
        assert!(replies.try_recv().is_err());
        server.abort();
    }

    #[tokio::test]
    async fn a_reply_is_read_up_to_64_kb() {
        let path = socket_path("reply-cap");
        let listener = UnixListener::bind(&path).expect("bind");
        let server = tokio::spawn(async move {
            let (mut stream, _) = listener.accept().await.expect("accept");
            let mut big = vec![b'x'; 70 * 1024];
            big.push(b'\n');
            let _ = stream.write_all(b"first\n").await;
            let _ = stream.write_all(&big).await;
        });
        let (relay, mut replies) = ExecutorRelay::new(&path);
        assert_eq!(relay.dispatch(order("42", "x")), Dispatch::Forwarded);
        assert_eq!(replies.recv().await.expect("first line").line, "first");
        settle(&relay).await;
        assert!(
            replies.try_recv().is_err(),
            "a line past 64 KB is not relayed"
        );
        server.await.expect("server");
    }

    #[tokio::test]
    async fn a_missing_socket_relays_nothing_and_frees_the_slot() {
        let path = socket_path("missing");
        let (relay, mut replies) = ExecutorRelay::new(&path);
        assert_eq!(relay.dispatch(order("42", "x")), Dispatch::Forwarded);
        settle(&relay).await;
        assert_eq!(relay.in_flight(), 0);
        assert!(replies.try_recv().is_err());
    }
}
