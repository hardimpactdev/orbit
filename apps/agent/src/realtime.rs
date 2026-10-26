//! The agent's connection to Reverb: discovery, the Pusher handshake, channel authorization, and bounded writes.
//! The observer keeps one connection for its Node channels; a command opens its own to publish on one channel.

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
