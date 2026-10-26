use crate::collector::{Cache, Error, LOCK_KEY};
use std::time::Duration;
use tokio::{
    io::{AsyncBufReadExt, AsyncReadExt, AsyncWriteExt, BufReader},
    net::TcpStream,
    time::timeout,
};

pub struct Valkey {
    pub host: String,
    pub port: u16,
    pub username: String,
    pub password: String,
}
fn encode(parts: &[&str]) -> Vec<u8> {
    let mut payload = format!("*{}\r\n", parts.len()).into_bytes();
    for part in parts {
        payload.extend(format!("${}\r\n", part.len()).as_bytes());
        payload.extend(part.as_bytes());
        payload.extend(b"\r\n");
    }
    payload
}
async fn reply(reader: &mut BufReader<TcpStream>) -> Result<Option<String>, Error> {
    let mut line = Vec::new();
    reader
        .read_until(b'\n', &mut line)
        .await
        .map_err(|_| Error::Cache)?;
    if !line.ends_with(b"\r\n") || line.len() < 3 {
        return Err(Error::Cache);
    }
    let payload = std::str::from_utf8(&line[1..line.len() - 2]).map_err(|_| Error::Cache)?;
    match line[0] {
        b'+' | b':' => Ok(Some(payload.into())),
        b'$' => {
            let length: i64 = payload.parse().map_err(|_| Error::Cache)?;
            if length == -1 {
                return Ok(None);
            }
            if !(0..=64 * 1024 * 1024).contains(&length) {
                return Err(Error::Cache);
            }
            let mut data = vec![0; length as usize + 2];
            reader
                .read_exact(&mut data)
                .await
                .map_err(|_| Error::Cache)?;
            if !data.ends_with(b"\r\n") {
                return Err(Error::Cache);
            }
            data.truncate(length as usize);
            Ok(Some(String::from_utf8(data).map_err(|_| Error::Cache)?))
        }
        _ => Err(Error::Cache),
    }
}
impl Valkey {
    async fn command(&self, parts: &[&str]) -> Result<Option<String>, Error> {
        timeout(Duration::from_secs(3), async {
            let socket = TcpStream::connect((self.host.as_str(), self.port))
                .await
                .map_err(|_| Error::Cache)?;
            let mut reader = BufReader::new(socket);
            if !self.password.is_empty() {
                let parts = if self.username.is_empty() {
                    vec!["AUTH", &self.password]
                } else {
                    vec!["AUTH", &self.username, &self.password]
                };
                reader
                    .write_all(&encode(&parts))
                    .await
                    .map_err(|_| Error::Cache)?;
                if reply(&mut reader).await?.as_deref() != Some("OK") {
                    return Err(Error::Cache);
                }
            }
            reader
                .write_all(&encode(parts))
                .await
                .map_err(|_| Error::Cache)?;
            reply(&mut reader).await
        })
        .await
        .map_err(|_| Error::Cache)?
    }
}
impl Cache for Valkey {
    async fn get(&self, key: &str) -> Result<Option<String>, Error> {
        self.command(&["GET", key]).await
    }
    async fn set(&self, key: &str, value: &str, seconds: Option<u64>) -> Result<(), Error> {
        let seconds = seconds.map(|s| s.to_string());
        let mut parts = vec!["SET", key, value];
        if let Some(seconds) = &seconds {
            parts.extend(["EX", seconds]);
        }
        if self.command(&parts).await?.as_deref() != Some("OK") {
            return Err(Error::Cache);
        }
        Ok(())
    }
    async fn acquire(&self, holder: &str) -> Result<bool, Error> {
        Ok(self
            .command(&["SET", LOCK_KEY, holder, "NX", "EX", "120"])
            .await?
            .as_deref()
            == Some("OK"))
    }
    async fn release(&self, holder: &str) -> Result<(), Error> {
        self.command(&["EVAL", "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) else return 0 end", "1", LOCK_KEY, holder]).await?;
        Ok(())
    }
}

#[cfg(test)]
pub(crate) mod tests {
    use super::*;
    use std::{
        collections::BTreeMap,
        sync::{Arc, Mutex},
    };
    use tokio::{net::TcpListener, task::JoinHandle};

    pub struct Server {
        pub port: u16,
        pub values: Arc<Mutex<BTreeMap<String, String>>>,
        pub commands: Arc<Mutex<Vec<Vec<String>>>>,
        task: JoinHandle<()>,
    }
    impl Drop for Server {
        fn drop(&mut self) {
            self.task.abort();
        }
    }
    async fn command(reader: &mut BufReader<TcpStream>) -> Option<Vec<String>> {
        let mut line = String::new();
        reader.read_line(&mut line).await.ok()?;
        let count: usize = line.trim().strip_prefix('*')?.parse().ok()?;
        let mut parts = Vec::new();
        for _ in 0..count {
            line.clear();
            reader.read_line(&mut line).await.ok()?;
            let len: usize = line.trim().strip_prefix('$')?.parse().ok()?;
            let mut data = vec![0; len + 2];
            reader.read_exact(&mut data).await.ok()?;
            data.truncate(len);
            parts.push(String::from_utf8(data).ok()?);
        }
        Some(parts)
    }
    impl Server {
        pub async fn start() -> Self {
            let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
            let port = listener.local_addr().unwrap().port();
            let values = Arc::new(Mutex::new(BTreeMap::<String, String>::new()));
            let commands = Arc::new(Mutex::new(Vec::new()));
            let data = values.clone();
            let recorded = commands.clone();
            let task = tokio::spawn(async move {
                loop {
                    let (socket, _) = listener.accept().await.unwrap();
                    let data = data.clone();
                    let recorded = recorded.clone();
                    tokio::spawn(async move {
                        let mut reader = BufReader::new(socket);
                        while let Some(parts) = command(&mut reader).await {
                            recorded.lock().unwrap().push(parts.clone());
                            let reply = {
                                let mut data = data.lock().unwrap();
                                match parts[0].as_str() {
                                    "AUTH" => "+OK\r\n".into(),
                                    "GET" => data
                                        .get(&parts[1])
                                        .map(|v| format!("${}\r\n{v}\r\n", v.len()))
                                        .unwrap_or("$-1\r\n".into()),
                                    "SET"
                                        if parts.contains(&"NX".into())
                                            && data.contains_key(&parts[1]) =>
                                    {
                                        "$-1\r\n".into()
                                    }
                                    "SET" => {
                                        data.insert(parts[1].clone(), parts[2].clone());
                                        "+OK\r\n".into()
                                    }
                                    "EVAL" => {
                                        if data.get(&parts[3]) == Some(&parts[4]) {
                                            data.remove(&parts[3]);
                                        }
                                        ":1\r\n".into()
                                    }
                                    _ => "-ERR unsupported\r\n".into(),
                                }
                            };
                            if reader.write_all(reply.as_bytes()).await.is_err() {
                                break;
                            }
                        }
                    });
                }
            });
            Self {
                port,
                values,
                commands,
                task,
            }
        }
        pub fn client(&self) -> Valkey {
            Valkey {
                host: "127.0.0.1".into(),
                port: self.port,
                username: "collector".into(),
                password: "private".into(),
            }
        }
    }
    #[tokio::test]
    async fn real_resp_client_reads_large_bulk_and_holds_only_its_own_lock() {
        let server = Server::start().await;
        let client = server.client();
        let large = "é".repeat(65536);
        client.set("snapshot", &large, None).await.unwrap();
        assert_eq!(client.get("snapshot").await.unwrap(), Some(large));
        assert!(client.acquire("one").await.unwrap());
        assert!(!client.acquire("two").await.unwrap());
        client.release("two").await.unwrap();
        assert!(!client.acquire("two").await.unwrap());
        client.release("one").await.unwrap();
        assert!(client.acquire("two").await.unwrap());
        let commands = server.commands.lock().unwrap();
        assert_eq!(commands[0], ["AUTH", "collector", "private"]);
        assert!(commands
            .iter()
            .any(|c| c == &["SET", LOCK_KEY, "one", "NX", "EX", "120"]));
    }
    #[tokio::test]
    async fn refuses_truncated_or_malformed_bulk_and_server_errors() {
        for wire in [
            "$4\r\nab",
            "$2\r\nabXX",
            "$-2\r\n",
            "-ERR private\r\n",
            "+OK\n",
        ] {
            let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
            let port = listener.local_addr().unwrap().port();
            let server = tokio::spawn(async move {
                let (mut socket, _) = listener.accept().await.unwrap();
                socket.write_all(wire.as_bytes()).await.unwrap();
            });
            let socket = TcpStream::connect(("127.0.0.1", port)).await.unwrap();
            assert_eq!(reply(&mut BufReader::new(socket)).await, Err(Error::Cache));
            server.await.unwrap();
        }
    }
}
