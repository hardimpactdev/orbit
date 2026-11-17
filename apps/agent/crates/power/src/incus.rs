//! The fixed local Incus API, with bounded requests and no child programs.
use serde_json::{json, Value};
use std::io::{self, BufRead as _, BufReader, Read as _, Write as _};
use std::os::unix::net::UnixStream;
use std::path::PathBuf;
use std::time::Duration;

#[derive(Debug, Clone, PartialEq, Eq)]
pub(crate) struct Instance {
    pub project: String,
    pub name: String,
    pub status: String,
    pub incarnation: String,
    pub pid: u32,
}

pub(crate) trait Api: Send + Sync {
    fn instance(&self, id: &str) -> io::Result<Option<Instance>>;
    fn change(&self, instance: &Instance, action: &str) -> io::Result<()>;
}

/// The seconds one state change may take: Incus's own `timeout`, the operation wait, and the socket read around both.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) struct Bounds {
    pub action: u64,
    pub wait: u64,
    pub read: u64,
}

/// A stop keeps Prepared restore's clean-stop allowance (`incus stop --timeout 30`); freeze and unfreeze stay short.
pub(crate) fn bounds(action: &str) -> Bounds {
    if action == "stop" {
        Bounds {
            action: 30,
            wait: 35,
            read: 40,
        }
    } else {
        Bounds {
            action: 5,
            wait: 5,
            read: 6,
        }
    }
}

#[derive(Debug)]
pub(crate) struct Incus(pub PathBuf);
impl Incus {
    fn request(
        &self,
        method: &str,
        url: &str,
        body: Option<&Value>,
        read: u64,
    ) -> io::Result<(u16, Value)> {
        let mut stream = UnixStream::connect(&self.0)?;
        stream.set_read_timeout(Some(Duration::from_secs(read)))?;
        stream.set_write_timeout(Some(Duration::from_secs(6)))?;
        let body = body.map(ToString::to_string).unwrap_or_default();
        write!(stream, "{method} {url} HTTP/1.1\r\nHost: incus\r\nConnection: close\r\nContent-Type: application/json\r\nContent-Length: {}\r\n\r\n{body}", body.len())?;
        let mut reader = BufReader::new(stream);
        let mut line = String::new();
        reader.read_line(&mut line)?;
        let status = line
            .split_whitespace()
            .nth(1)
            .and_then(|s| s.parse().ok())
            .ok_or_else(|| io::Error::other("invalid Incus response"))?;
        let mut chunked = false;
        let mut length = None;
        let mut header_bytes = line.len();
        loop {
            line.clear();
            if reader.read_line(&mut line)? == 0 {
                return Err(io::Error::other("short Incus headers"));
            }
            header_bytes += line.len();
            if header_bytes > 16384 {
                return Err(io::Error::other("large Incus headers"));
            }
            if line == "\r\n" {
                break;
            }
            let lower = line.to_ascii_lowercase();
            if lower.starts_with("transfer-encoding:") {
                chunked = lower.contains("chunked");
            }
            if let Some(value) = lower.strip_prefix("content-length:") {
                length = value.trim().parse::<u64>().ok();
            }
        }
        let mut bytes = Vec::new();
        if chunked {
            loop {
                line.clear();
                reader.read_line(&mut line)?;
                let size =
                    usize::from_str_radix(line.trim().split(';').next().unwrap_or_default(), 16)
                        .map_err(io::Error::other)?;
                if size == 0 {
                    break;
                }
                if bytes.len() + size > 1 << 20 {
                    return Err(io::Error::other("large Incus response"));
                }
                let start = bytes.len();
                bytes.resize(start + size, 0);
                reader.read_exact(&mut bytes[start..])?;
                let mut end = [0; 2];
                reader.read_exact(&mut end)?;
                if end != *b"\r\n" {
                    return Err(io::Error::other("invalid Incus chunk"));
                }
            }
        } else {
            reader
                .take(length.unwrap_or((1 << 20) + 1))
                .read_to_end(&mut bytes)?;
        }
        if bytes.len() > 1 << 20 {
            return Err(io::Error::other("large Incus response"));
        }
        let value = serde_json::from_slice(&bytes).map_err(io::Error::other)?;
        Ok((status, value))
    }
}
impl Api for Incus {
    fn instance(&self, id: &str) -> io::Result<Option<Instance>> {
        for project in ["orbit-instances", "orbit-instances-nested"] {
            let name = format!("instance-{id}");
            let (status, data) = self.request(
                "GET",
                &format!("/1.0/instances/{name}?project={project}&recursion=1"),
                None,
                6,
            )?;
            if status == 404 {
                continue;
            }
            if status != 200 {
                return Err(io::Error::other("Incus target read failed"));
            }
            let meta = &data["metadata"];
            let incarnation = meta["config"]["volatile.uuid"]
                .as_str()
                .ok_or_else(|| io::Error::other("missing Incus incarnation"))?;
            let state = meta["status"]
                .as_str()
                .ok_or_else(|| io::Error::other("missing Incus status"))?;
            let pid = u32::try_from(meta["state"]["pid"].as_u64().unwrap_or(0))
                .map_err(io::Error::other)?;
            return Ok(Some(Instance {
                project: project.to_owned(),
                name,
                status: state.to_ascii_lowercase(),
                incarnation: incarnation.to_owned(),
                pid,
            }));
        }
        Ok(None)
    }
    fn change(&self, instance: &Instance, action: &str) -> io::Result<()> {
        let bounds = bounds(action);
        let (status, result) = self.request(
            "PUT",
            &format!(
                "/1.0/instances/{}/state?project={}",
                instance.name, instance.project
            ),
            Some(&json!({"action": action, "timeout": bounds.action})),
            bounds.read,
        )?;
        if ![200, 202].contains(&status) {
            return Err(io::Error::other("Incus state change refused"));
        }
        let operation = result["operation"]
            .as_str()
            .filter(|path| path.starts_with("/1.0/operations/"))
            .ok_or_else(|| io::Error::other("missing Incus operation"))?;
        let (status, result) = self.request(
            "GET",
            &format!("{operation}/wait?timeout={}", bounds.wait),
            None,
            bounds.read,
        )?;
        if status != 200 || result["metadata"]["status_code"] != 200 {
            return Err(io::Error::other("Incus state change failed"));
        }
        Ok(())
    }
}
