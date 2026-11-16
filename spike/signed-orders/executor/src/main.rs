//! SPIKE(signed-orders): socket-activated executor. systemd hands it one accepted connection on fd 0
//! (`Accept=yes`). It checks the caller, verifies the Gateway's signature over the order bytes, and
//! runs one fixed action. Each decision goes to the journal (stderr) and back to the caller (stdout).
use base64::{engine::general_purpose::STANDARD as B64, Engine};
use ed25519_dalek::{Signature, Verifier, VerifyingKey};
use serde_json::{json, Value};
use std::io::{Read, Write};
use std::os::fd::FromRawFd;
use std::time::{SystemTime, UNIX_EPOCH};

const PUBLIC_KEY: &str = "/etc/orbit-spike/gateway.pub";
const NODE_ID: &str = "/etc/orbit-spike/node-id";
const TARGETS: &str = "/etc/orbit-spike/targets";
const NONCES: &str = "/var/lib/orbit-spike/nonces";
const OBSERVER_CGROUP: &str = "0::/system.slice/orbit-agent.service";
const ACTIONS: &[&str] = &["wake"];
/// An order may not claim to live longer than this, so a stolen key's orders still expire fast.
const MAX_TTL_MS: i64 = 10_000;

fn now_ms() -> i64 {
    SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_millis() as i64
}

struct Peer {
    pid: i32,
    uid: u32,
}

fn peer(fd: i32) -> Result<Peer, String> {
    let mut cred = libc::ucred { pid: 0, uid: 0, gid: 0 };
    let mut len = std::mem::size_of::<libc::ucred>() as libc::socklen_t;
    let rc = unsafe {
        libc::getsockopt(fd, libc::SOL_SOCKET, libc::SO_PEERCRED, &mut cred as *mut _ as *mut _, &mut len)
    };
    if rc != 0 {
        return Err("no peer credentials".into());
    }
    Ok(Peer { pid: cred.pid, uid: cred.uid })
}

fn check_peer(p: &Peer) -> Result<(), String> {
    if p.uid != 0 {
        return Err(format!("peer uid {} is not the observer's user", p.uid));
    }
    let cgroup = std::fs::read_to_string(format!("/proc/{}/cgroup", p.pid))
        .map_err(|_| format!("peer pid {} has no cgroup", p.pid))?;
    if cgroup.trim() != OBSERVER_CGROUP {
        return Err(format!("peer pid {} runs in {} not orbit-agent.service", p.pid, cgroup.trim()));
    }
    Ok(())
}

fn verify(raw: &[u8]) -> Result<Value, (String, Value)> {
    let refuse = |reason: &str| (reason.to_owned(), Value::Null);
    let text = std::str::from_utf8(raw).map_err(|_| refuse("not utf-8"))?.trim();
    let (payload_b64, sig_b64) = text.split_once('.').ok_or_else(|| refuse("malformed order"))?;
    let payload = B64.decode(payload_b64).map_err(|_| refuse("malformed payload"))?;
    let sig = B64.decode(sig_b64).map_err(|_| refuse("malformed signature"))?;
    let key_bytes = B64
        .decode(std::fs::read_to_string(PUBLIC_KEY).map_err(|_| refuse("no pinned key"))?.trim())
        .map_err(|_| refuse("bad pinned key"))?;
    let key = VerifyingKey::from_bytes(&key_bytes.try_into().map_err(|_| refuse("bad pinned key"))?)
        .map_err(|_| refuse("bad pinned key"))?;
    let sig = Signature::from_slice(&sig).map_err(|_| refuse("malformed signature"))?;
    key.verify_strict(&payload, &sig).map_err(|_| refuse("bad signature"))?;
    // Only signed bytes are read from here on.
    let order: Value = serde_json::from_slice(&payload).map_err(|_| refuse("signed payload is not JSON"))?;
    let with = |reason: &str| (reason.to_owned(), order.clone());
    let action = order["action"].as_str().unwrap_or("");
    if !ACTIONS.contains(&action) {
        return Err(with("unknown action"));
    }
    let node = std::fs::read_to_string(NODE_ID).map_err(|_| with("no node id"))?;
    if order["node"].as_i64().map(|n| n.to_string()) != Some(node.trim().to_owned()) {
        return Err(with("wrong node"));
    }
    let target = order["target"].as_str().unwrap_or("");
    let targets = std::fs::read_to_string(TARGETS).unwrap_or_default();
    if target.is_empty() || !target.bytes().all(|b| b.is_ascii_digit()) || !targets.lines().any(|t| t.trim() == target) {
        return Err(with("wrong target"));
    }
    let expires = order["expires_ms"].as_i64().ok_or_else(|| with("no expiry"))?;
    let now = now_ms();
    if expires <= now {
        return Err(with("expired"));
    }
    if expires - now > MAX_TTL_MS {
        return Err(with("expiry too far ahead"));
    }
    let nonce = order["nonce"].as_str().unwrap_or("");
    if nonce.len() != 32 || !nonce.bytes().all(|b| b.is_ascii_hexdigit()) {
        return Err(with("bad nonce"));
    }
    remember_nonce(nonce, expires).map_err(|reason| with(&reason))?;
    Ok(order)
}

/// One file per nonce, created exclusively, so two connections with one order cannot both pass.
/// A file whose order has expired is pruned: the expiry check refuses that order anyway.
fn remember_nonce(nonce: &str, expires: i64) -> Result<(), String> {
    std::fs::create_dir_all(NONCES).map_err(|_| "nonce store unavailable".to_string())?;
    if let Ok(entries) = std::fs::read_dir(NONCES) {
        let now = now_ms();
        for entry in entries.flatten() {
            let keep = std::fs::read_to_string(entry.path())
                .ok()
                .and_then(|t| t.trim().parse::<i64>().ok())
                .is_some_and(|e| e > now);
            if !keep {
                let _ = std::fs::remove_file(entry.path());
            }
        }
    }
    let mut file = std::fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .open(format!("{NONCES}/{nonce}"))
        .map_err(|_| "replayed nonce".to_string())?;
    let _ = write!(file, "{expires}");
    Ok(())
}

fn wake(target: &str) -> Result<String, String> {
    let unit = format!("orbit-spike-wake@{target}.service");
    let status = std::process::Command::new("/usr/bin/systemctl")
        .args(["start", &unit])
        .status()
        .map_err(|e| e.to_string())?;
    if !status.success() {
        return Err(format!("systemctl start {unit} failed"));
    }
    std::fs::read_to_string(format!("/run/orbit-spike/started-{target}"))
        .map(|t| t.trim().to_owned())
        .map_err(|_| "unit left no start time".into())
}

fn now_ns() -> String {
    SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_nanos().to_string()
}

fn main() {
    let exec_ns = now_ns();
    let mut conn = unsafe { std::os::unix::net::UnixStream::from_raw_fd(0) };
    let reply = |conn: &mut std::os::unix::net::UnixStream, body: Value| {
        let _ = conn.write_all(body.to_string().as_bytes());
    };
    let peer = match peer(0) {
        Ok(p) => p,
        Err(e) => {
            eprintln!("refused: {e}");
            return;
        }
    };
    if let Err(reason) = check_peer(&peer) {
        eprintln!("refused caller pid={} uid={}: {reason}", peer.pid, peer.uid);
        reply(&mut conn, json!({"status":"refused","reason":"caller"}));
        return;
    }
    let mut raw = Vec::new();
    let _ = conn.set_read_timeout(Some(std::time::Duration::from_secs(2)));
    if (&mut conn).take(8192).read_to_end(&mut raw).is_err() {
        eprintln!("refused: read failed");
        return;
    }
    match verify(&raw) {
        Err((reason, order)) => {
            eprintln!("refused order op={} nonce={}: {reason}", order["operation"], order["nonce"]);
            reply(&mut conn, json!({"status":"refused","reason":reason,"operation":order["operation"],"nonce":order["nonce"]}));
        }
        Ok(order) => {
            let verified_ns = now_ns();
            let target = order["target"].as_str().unwrap_or_default();
            match wake(target) {
                Ok(started) => {
                    eprintln!("done op={} target={target}", order["operation"]);
                    reply(&mut conn, json!({"status":"done","operation":order["operation"],"nonce":order["nonce"],"unit_started_ns":started,"exec_ns":exec_ns,"verified_ns":verified_ns}));
                }
                Err(e) => {
                    eprintln!("failed op={} target={target}: {e}", order["operation"]);
                    reply(&mut conn, json!({"status":"failed","operation":order["operation"],"nonce":order["nonce"]}));
                }
            }
        }
    }
}
