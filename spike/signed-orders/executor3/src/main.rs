//! SPIKE(signed-orders) round 3: a long-running executor that sleeps and wakes a whole cgroup subtree
//! itself (see power.rs). systemd only starts it and restarts it after a crash.
//!
//! Changes from round 2:
//! - Each target has its own lock and its own done/open state. The only shared state is the nonce set,
//!   held for a map insert and no I/O.
//! - The journal is append-only with one write per order record and no fsync on the order's path. A
//!   write survives a crash of this process (the page cache keeps it); a background sync once a second
//!   covers power loss. After a Node reboot every order has expired (TTL ≤ 10 s) and the target's
//!   processes are gone, so a lost tail can neither allow a replay nor hide an open order that matters.
//! - `relay` mode: the SSH fallback runs this same binary as the fixed relay command, under the
//!   dedicated user `orbit-spike-relay`, and hands the same signed order to the same socket. So the
//!   nonce and Operation checks hold on both paths.
mod power;

use base64::{engine::general_purpose::STANDARD as B64, Engine};
use ed25519_dalek::{Signature, VerifyingKey};
use serde_json::{json, Value};
use std::collections::HashMap;
use std::io::{Read, Write};
use std::os::fd::AsRawFd;
use std::sync::{Arc, Mutex};
use std::time::{Duration, SystemTime, UNIX_EPOCH};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{UnixListener, UnixStream};

const SOCKET: &str = "/run/orbit-spike-exec.sock";
const SELF_PATH: &str = "/usr/local/libexec/orbit-spike-executor3";
const PUBLIC_KEY: &str = "/etc/orbit-spike/gateway.pub";
const NODE_ID: &str = "/etc/orbit-spike/node-id";
const TARGETS: &str = "/etc/orbit-spike/targets";
const RELAY_USER: &str = "orbit-spike-relay";
/// Test settings: the restore pool size (read at start; default the CPU count), and a crash-test pause.
const THREADS: &str = "/etc/orbit-spike/restore-threads";
const TEST_PAUSE: &str = "/etc/orbit-spike/test-pause-ms";
const JOURNAL: &str = "/var/lib/orbit-spike/journal";
const OBSERVER_CGROUP: &str = "0::/system.slice/orbit-agent.service";
const ACTIONS: &[&str] = &["sleep", "wake"];
const MAX_TTL_MS: i64 = 10_000;
const SO_PEERPIDFD: libc::c_int = 77;

fn now_ms() -> i64 {
    SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_millis() as i64
}

fn now_ns() -> String {
    power::now_ns().to_string()
}

#[derive(Default)]
struct TargetState {
    done: HashMap<String, Value>,
    open: HashMap<String, String>,
}

struct Executor {
    key: VerifyingKey,
    node: String,
    relay_uid: Option<u32>,
    targets: HashMap<String, Arc<tokio::sync::Mutex<TargetState>>>,
    nonces: Mutex<HashMap<String, i64>>,
    journal: std::fs::File,
}

impl Executor {
    /// One `write` per record on an O_APPEND file: each line lands whole, and no fsync waits here.
    fn record(&self, record: Value) {
        let mut line = record.to_string();
        line.push('\n');
        (&self.journal).write_all(line.as_bytes()).expect("journal write");
    }
}

fn getsockopt<T>(fd: i32, opt: libc::c_int, value: &mut T) -> bool {
    let mut len = std::mem::size_of::<T>() as libc::socklen_t;
    unsafe { libc::getsockopt(fd, libc::SOL_SOCKET, opt, value as *mut T as *mut _, &mut len) == 0 }
}

/// Root in `orbit-agent.service`, or the relay user running this very binary. The pidfd ties every
/// /proc read to the connecting process: a live pidfd after the reads proves the PID was not reused.
fn check_peer(fd: i32, relay_uid: Option<u32>) -> Result<String, String> {
    let mut cred = libc::ucred { pid: 0, uid: 0, gid: 0 };
    if !getsockopt(fd, libc::SO_PEERCRED, &mut cred) {
        return Err("no peer credentials".into());
    }
    let who = format!("pid={} uid={}", cred.pid, cred.uid);
    let relay = relay_uid == Some(cred.uid);
    if cred.uid != 0 && !relay {
        return Err(format!("{who}: uid is neither the observer's nor the relay's"));
    }
    let mut pidfd: libc::c_int = -1;
    if !getsockopt(fd, SO_PEERPIDFD, &mut pidfd) || pidfd < 0 {
        return Err(format!("{who}: no pidfd"));
    }
    let info = std::fs::read_to_string(format!("/proc/self/fdinfo/{pidfd}")).unwrap_or_default();
    let named = info.lines().find_map(|l| l.strip_prefix("Pid:")).map(|p| p.trim().to_owned());
    let cgroup = std::fs::read_to_string(format!("/proc/{}/cgroup", cred.pid)).unwrap_or_default();
    let exe = std::fs::read_link(format!("/proc/{}/exe", cred.pid)).map(|p| p.to_string_lossy().into_owned()).unwrap_or_default();
    let alive = unsafe { libc::syscall(libc::SYS_pidfd_send_signal, pidfd, 0, std::ptr::null::<u8>(), 0) } == 0;
    unsafe { libc::close(pidfd) };
    if named != Some(cred.pid.to_string()) || !alive {
        return Err(format!("{who}: the caller changed during the check"));
    }
    if relay {
        if exe != SELF_PATH {
            return Err(format!("{who}: relay user but runs {exe}, not the relay command"));
        }
        return Ok(format!("{who} relay"));
    }
    if cgroup.trim() != OBSERVER_CGROUP {
        return Err(format!("{who}: runs in {} not orbit-agent.service", cgroup.trim()));
    }
    Ok(format!("{who} observer"))
}

type Refusal = (String, Value);

impl Executor {
    fn verify(&self, raw: &[u8]) -> Result<Value, Refusal> {
        let refuse = |reason: &str| (reason.to_owned(), Value::Null);
        let text = std::str::from_utf8(raw).map_err(|_| refuse("not utf-8"))?.trim();
        let (p, s) = text.split_once('.').ok_or_else(|| refuse("malformed order"))?;
        let payload = B64.decode(p).map_err(|_| refuse("malformed payload"))?;
        let sig = B64.decode(s).map_err(|_| refuse("malformed signature"))?;
        let sig = Signature::from_slice(&sig).map_err(|_| refuse("malformed signature"))?;
        self.key.verify_strict(&payload, &sig).map_err(|_| refuse("bad signature"))?;
        let order: Value = serde_json::from_slice(&payload).map_err(|_| refuse("signed payload is not JSON"))?;
        let with = |reason: &str| (reason.to_owned(), order.clone());
        if !ACTIONS.contains(&order["action"].as_str().unwrap_or("")) {
            return Err(with("unknown action"));
        }
        if order["node"].as_i64().map(|n| n.to_string()).as_deref() != Some(self.node.as_str()) {
            return Err(with("wrong node"));
        }
        if !self.targets.contains_key(order["target"].as_str().unwrap_or("")) {
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
        if order["operation"].as_str().unwrap_or("").is_empty() {
            return Err(with("no operation"));
        }
        let mut nonces = self.nonces.lock().unwrap();
        nonces.retain(|_, e| *e > now);
        if nonces.insert(nonce.to_owned(), expires).is_some() {
            return Err(with("replayed nonce"));
        }
        Ok(order)
    }

    /// One Operation acts at most once; orders for one target run one at a time; targets run in parallel.
    async fn run(self: &Arc<Self>, op: &str, target: &str, action: &str, nonce: Option<(&str, i64)>) -> Value {
        let state = self.targets[target].clone();
        let wait = std::time::Instant::now();
        let mut st = state.lock().await;
        let lock_wait_ms = wait.elapsed().as_secs_f64() * 1000.0;
        if let Some(first) = st.done.get(op) {
            if let Some((n, e)) = nonce {
                self.record(json!({"k":"nonce","n":n,"e":e}));
            }
            let mut first = first.clone();
            first["repeat"] = json!(true);
            return first;
        }
        match nonce {
            // The nonce and the open order go in one record, before the action.
            Some((n, e)) => self.record(json!({"k":"open","op":op,"target":target,"action":action,"n":n,"e":e})),
            None => {}
        }
        st.open.insert(op.to_owned(), action.to_owned());
        let (t, a) = (target.to_owned(), action.to_owned());
        let pause = std::fs::read_to_string(TEST_PAUSE).ok().and_then(|t| t.trim().parse().ok());
        let mut result = tokio::task::spawn_blocking(move || if a == "sleep" { power::sleep(&t) } else { power::wake(&t, pause) })
            .await
            .unwrap_or_else(|_| json!({"status":"failed","error":"action panicked"}));
        result["operation"] = json!(op);
        result["target"] = json!(target);
        result["action"] = json!(action);
        result["lock_wait_ms"] = json!(lock_wait_ms);
        result["recovered"] = json!(nonce.is_none());
        st.open.remove(op);
        if result["status"] == "done" {
            st.done.insert(op.to_owned(), result.clone());
            self.record(json!({"k":"done","op":op,"target":target,"result":result}));
        } else {
            self.record(json!({"k":"closed","op":op,"target":target}));
        }
        result
    }
}

async fn connection(exec: Arc<Executor>, mut stream: UnixStream) {
    let accepted_ns = now_ns();
    let peer = match check_peer(stream.as_raw_fd(), exec.relay_uid) {
        Ok(peer) => peer,
        Err(reason) => {
            eprintln!("refused caller {reason}");
            let _ = stream.write_all(json!({"status":"refused","reason":"caller"}).to_string().as_bytes()).await;
            return;
        }
    };
    let mut raw = Vec::new();
    let read = tokio::time::timeout(Duration::from_secs(2), (&mut stream).take(8192).read_to_end(&mut raw)).await;
    if !matches!(read, Ok(Ok(_))) {
        eprintln!("refused: read failed from {peer}");
        return;
    }
    let reply = match exec.verify(&raw) {
        Err((reason, order)) => {
            eprintln!("refused order op={} nonce={} from {peer}: {reason}", order["operation"], order["nonce"]);
            let mut reply = json!({"status":"refused","reason":reason,"operation":order["operation"],"nonce":order["nonce"]});
            // The same signed order by the other path (WebSocket or SSH relay) does not act again. It
            // waits for the target's lock, so an order still in flight finishes first, and gets that result.
            if reason == "replayed nonce" {
                if let (Some(op), Some(state)) = (order["operation"].as_str(), exec.targets.get(order["target"].as_str().unwrap_or(""))) {
                    if let Some(first) = state.lock().await.done.get(op) {
                        reply["first_result"] = first.clone();
                    }
                }
            }
            reply
        }
        Ok(order) => {
            let s = |k: &str| order[k].as_str().unwrap_or_default().to_owned();
            let (op, target, action, nonce) = (s("operation"), s("target"), s("action"), s("nonce"));
            let verified_ns = now_ns();
            let mut result = exec.run(&op, &target, &action, Some((&nonce, order["expires_ms"].as_i64().unwrap_or(0)))).await;
            eprintln!("{} {action} op={op} target={target} from {peer}{}", result["status"].as_str().unwrap_or(""), if result["repeat"] == true { " (repeat: first result returned)" } else { "" });
            result["nonce"] = json!(nonce);
            result["peer"] = json!(peer);
            result["accepted_ns"] = json!(accepted_ns);
            result["verified_ns"] = json!(verified_ns);
            result
        }
    };
    let _ = stream.write_all(reply.to_string().as_bytes()).await;
    let _ = stream.shutdown().await;
}

/// The SSH fallback's fixed relay: stdin to the socket, the reply to stdout. Nothing else.
fn relay() {
    let mut order = Vec::new();
    std::io::stdin().take(8192).read_to_end(&mut order).expect("stdin");
    let mut s = std::os::unix::net::UnixStream::connect(SOCKET).expect("executor socket");
    s.set_read_timeout(Some(Duration::from_secs(10))).ok();
    s.write_all(&order).expect("send");
    s.shutdown(std::net::Shutdown::Write).ok();
    let mut reply = Vec::new();
    let _ = s.take(65536).read_to_end(&mut reply);
    std::io::stdout().write_all(&reply).ok();
}

fn uid_of(user: &str) -> Option<u32> {
    std::fs::read_to_string("/etc/passwd").ok()?.lines().find_map(|l| {
        let f: Vec<&str> = l.split(':').collect();
        (f.first() == Some(&user)).then(|| f.get(2)?.parse().ok()).flatten()
    })
}

fn main() {
    if std::env::args().nth(1).as_deref() == Some("relay") {
        return relay();
    }
    tokio::runtime::Builder::new_multi_thread().worker_threads(4).enable_all().build().unwrap().block_on(serve());
}

async fn serve() {
    let read = |path: &str| std::fs::read_to_string(path).unwrap_or_else(|_| panic!("{path} missing"));
    let key_bytes: [u8; 32] = B64.decode(read(PUBLIC_KEY).trim()).expect("pinned key").try_into().expect("pinned key");
    let targets: HashMap<String, Arc<tokio::sync::Mutex<TargetState>>> =
        read(TARGETS).lines().map(str::trim).filter(|t| !t.is_empty()).map(|t| (t.to_owned(), Default::default())).collect();
    // Replay the journal: unexpired nonces, done Operations, and open orders to finish.
    std::fs::create_dir_all("/var/lib/orbit-spike").unwrap();
    let mut nonces = HashMap::new();
    let mut open: Vec<(String, String, String)> = Vec::new();
    let now = now_ms();
    for line in std::fs::read_to_string(JOURNAL).unwrap_or_default().lines() {
        let Ok(r) = serde_json::from_str::<Value>(line) else { continue };
        let s = |k: &str| r[k].as_str().unwrap_or_default().to_owned();
        if let (Some(n), Some(e)) = (r["n"].as_str(), r["e"].as_i64()) {
            if e > now {
                nonces.insert(n.to_owned(), e);
            }
        }
        let Some(state) = targets.get(&s("target")) else { continue };
        let mut st = state.try_lock().unwrap();
        match r["k"].as_str() {
            Some("open") => {
                st.open.insert(s("op"), s("action"));
            }
            Some("done") => {
                st.open.remove(&s("op"));
                st.done.insert(s("op"), r["result"].clone());
            }
            Some("closed") => {
                st.open.remove(&s("op"));
            }
            _ => {}
        }
    }
    for (t, state) in &targets {
        let mut st = state.try_lock().unwrap();
        for (op, action) in st.open.drain() {
            open.push((op, t.clone(), action));
        }
    }
    let journal = std::fs::OpenOptions::new().create(true).append(true).open(JOURNAL).expect("journal");
    let exec = Arc::new(Executor {
        key: VerifyingKey::from_bytes(&key_bytes).expect("pinned key"),
        node: read(NODE_ID).trim().to_owned(),
        relay_uid: uid_of(RELAY_USER),
        targets,
        nonces: Mutex::new(nonces),
        journal,
    });
    // Off the order's path: the journal reaches the disk at least once a second.
    let syncer = exec.journal.try_clone().unwrap();
    std::thread::spawn(move || loop {
        std::thread::sleep(Duration::from_secs(1));
        let _ = syncer.sync_data();
    });
    let _ = std::fs::remove_file(SOCKET);
    let old = unsafe { libc::umask(0o117) };
    let listener = UnixListener::bind(SOCKET).expect("bind socket");
    unsafe { libc::umask(old) };
    // Root owns the socket; the relay user's group may connect as well (mode 0660).
    if let Some(uid) = exec.relay_uid {
        let gid = std::fs::read_to_string("/etc/passwd").ok().and_then(|p| p.lines().find(|l| l.starts_with(&format!("{RELAY_USER}:"))).and_then(|l| l.split(':').nth(3)?.parse().ok())).unwrap_or(uid);
        unsafe { libc::chown(std::ffi::CString::new(SOCKET).unwrap().as_ptr(), 0, gid) };
    }
    let pool = power::init_pool(THREADS);
    eprintln!("executor ready: restore pool of {pool} threads, {} open order(s) to finish", open.len());
    for (op, target, action) in open {
        let exec = exec.clone();
        tokio::spawn(async move {
            eprintln!("recovering open order op={op} target={target} action={action}");
            let result = exec.run(&op, &target, &action, None).await;
            eprintln!("recovered op={op} target={target}: {}", result["status"]);
        });
    }
    loop {
        if let Ok((stream, _)) = listener.accept().await {
            tokio::spawn(connection(exec.clone(), stream));
        }
    }
}
