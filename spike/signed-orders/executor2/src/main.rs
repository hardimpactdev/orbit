//! SPIKE(signed-orders) round 2: a long-running executor. It holds its Unix socket itself, checks each
//! caller and each signed order, and does the action in-process: thaw the target's cgroup, then wait
//! until the target answers HTTP. systemd only starts it and restarts it after a crash.
//!
//! State survives a restart through an append-only journal: seen nonces (until they expire), open
//! orders, and the result of each done Operation. An open order without a result is finished on start.
use base64::{engine::general_purpose::STANDARD as B64, Engine};
use ed25519_dalek::{Signature, VerifyingKey};
use serde_json::{json, Value};
use std::collections::HashMap;
use std::io::Write;
use std::os::fd::AsRawFd;
use std::sync::{Arc, Mutex};
use std::time::{Duration, SystemTime, UNIX_EPOCH};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{UnixListener, UnixStream};

const SOCKET: &str = "/run/orbit-spike-exec.sock";
const PUBLIC_KEY: &str = "/etc/orbit-spike/gateway.pub";
const NODE_ID: &str = "/etc/orbit-spike/node-id";
const TARGETS: &str = "/etc/orbit-spike/targets";
/// Test hook for the crash test only: a pause between the thaw and the readiness check.
const TEST_PAUSE: &str = "/etc/orbit-spike/test-pause-ms";
const JOURNAL: &str = "/var/lib/orbit-spike/journal";
const OBSERVER_CGROUP: &str = "0::/system.slice/orbit-agent.service";
const ACTIONS: &[&str] = &["wake"];
const MAX_TTL_MS: i64 = 10_000;
/// Linux 6.5+; not every libc crate version names it.
const SO_PEERPIDFD: libc::c_int = 77;

fn now_ms() -> i64 {
    SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_millis() as i64
}

fn now_ns() -> String {
    SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_nanos().to_string()
}

struct State {
    nonces: HashMap<String, i64>,
    done: HashMap<String, Value>,
    open: HashMap<String, String>,
    file: std::fs::File,
}

impl State {
    /// Every record is on disk before the executor acts on it or answers.
    fn append(&mut self, record: Value) {
        let mut line = record.to_string();
        line.push('\n');
        self.file.write_all(line.as_bytes()).expect("journal write");
        self.file.sync_data().expect("journal sync");
    }

    fn load() -> State {
        std::fs::create_dir_all("/var/lib/orbit-spike").expect("state folder");
        let (mut nonces, mut done, mut open) = (HashMap::new(), HashMap::new(), HashMap::new());
        let now = now_ms();
        for line in std::fs::read_to_string(JOURNAL).unwrap_or_default().lines() {
            let Ok(r) = serde_json::from_str::<Value>(line) else { continue };
            let s = |k: &str| r[k].as_str().unwrap_or_default().to_owned();
            match r["k"].as_str() {
                Some("nonce") if r["e"].as_i64().unwrap_or(0) > now => {
                    nonces.insert(s("n"), r["e"].as_i64().unwrap());
                }
                Some("open") => {
                    open.insert(s("op"), s("target"));
                }
                Some("done") => {
                    open.remove(&s("op"));
                    done.insert(s("op"), r["result"].clone());
                }
                Some("closed") => {
                    open.remove(&s("op"));
                }
                _ => {}
            }
        }
        // Compact: rewrite only what still matters, then append from there.
        let tmp = format!("{JOURNAL}.tmp");
        let mut file = std::fs::File::create(&tmp).expect("journal");
        for (n, e) in &nonces {
            writeln!(file, "{}", json!({"k":"nonce","n":n,"e":e})).unwrap();
        }
        for (op, target) in &open {
            writeln!(file, "{}", json!({"k":"open","op":op,"target":target})).unwrap();
        }
        for (op, result) in &done {
            writeln!(file, "{}", json!({"k":"done","op":op,"result":result})).unwrap();
        }
        file.sync_all().unwrap();
        std::fs::rename(&tmp, JOURNAL).unwrap();
        let file = std::fs::OpenOptions::new().append(true).open(JOURNAL).unwrap();
        State { nonces, done, open, file }
    }
}

struct Executor {
    key: VerifyingKey,
    node: String,
    targets: Vec<String>,
    state: Mutex<State>,
    locks: Mutex<HashMap<String, Arc<tokio::sync::Mutex<()>>>>,
}

fn getsockopt<T>(fd: i32, opt: libc::c_int, value: &mut T) -> bool {
    let mut len = std::mem::size_of::<T>() as libc::socklen_t;
    unsafe { libc::getsockopt(fd, libc::SOL_SOCKET, opt, value as *mut T as *mut _, &mut len) == 0 }
}

/// The caller must be root in `orbit-agent.service`. With SO_PEERPIDFD the cgroup read is tied to the
/// connecting process: its pidfd names that process, and a live pidfd after the read proves the PID
/// was not reused in between.
fn check_peer(fd: i32) -> Result<String, String> {
    let mut cred = libc::ucred { pid: 0, uid: 0, gid: 0 };
    if !getsockopt(fd, libc::SO_PEERCRED, &mut cred) {
        return Err("no peer credentials".into());
    }
    let who = format!("pid={} uid={}", cred.pid, cred.uid);
    if cred.uid != 0 {
        return Err(format!("{who}: uid is not the observer's user"));
    }
    let mut pidfd: libc::c_int = -1;
    let have_pidfd = getsockopt(fd, SO_PEERPIDFD, &mut pidfd) && pidfd >= 0;
    if have_pidfd {
        let info = std::fs::read_to_string(format!("/proc/self/fdinfo/{pidfd}")).unwrap_or_default();
        let pid = info.lines().find_map(|l| l.strip_prefix("Pid:")).map(|p| p.trim().to_owned());
        if pid != Some(cred.pid.to_string()) {
            unsafe { libc::close(pidfd) };
            return Err(format!("{who}: pidfd names another process"));
        }
    }
    let cgroup = std::fs::read_to_string(format!("/proc/{}/cgroup", cred.pid)).unwrap_or_default();
    if have_pidfd {
        let alive = unsafe { libc::syscall(libc::SYS_pidfd_send_signal, pidfd, 0, std::ptr::null::<u8>(), 0) } == 0;
        unsafe { libc::close(pidfd) };
        if !alive {
            return Err(format!("{who}: caller exited during the check"));
        }
    }
    if cgroup.trim() != OBSERVER_CGROUP {
        return Err(format!("{who} pidfd={have_pidfd}: runs in {} not orbit-agent.service", cgroup.trim()));
    }
    Ok(format!("{who} pidfd={have_pidfd}"))
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
        let target = order["target"].as_str().unwrap_or("");
        if !self.targets.iter().any(|t| t == target) {
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
        let mut state = self.state.lock().unwrap();
        state.nonces.retain(|_, e| *e > now);
        if state.nonces.contains_key(nonce) {
            return Err(with("replayed nonce"));
        }
        state.nonces.insert(nonce.to_owned(), expires);
        state.append(json!({"k":"nonce","n":nonce,"e":expires}));
        Ok(order)
    }

    fn lock_for(&self, target: &str) -> Arc<tokio::sync::Mutex<()>> {
        self.locks.lock().unwrap().entry(target.to_owned()).or_default().clone()
    }

    fn done(&self, op: &str) -> Option<Value> {
        self.state.lock().unwrap().done.get(op).cloned()
    }

    /// One Operation acts at most once. A second order for the same target waits for the first; a
    /// second order for the same Operation then gets the first result.
    async fn run(self: &Arc<Self>, op: &str, target: &str, recovered: bool) -> Value {
        if let Some(first) = self.done(op) {
            return json!({"repeat": true, "first": first});
        }
        let lock = self.lock_for(target);
        let wait_start = std::time::Instant::now();
        let _guard = lock.lock().await;
        let lock_wait_ms = wait_start.elapsed().as_secs_f64() * 1000.0;
        if let Some(first) = self.done(op) {
            return json!({"repeat": true, "joined": true, "first": first});
        }
        if !recovered {
            let mut state = self.state.lock().unwrap();
            state.open.insert(op.to_owned(), target.to_owned());
            state.append(json!({"k":"open","op":op,"target":target}));
        }
        let mut result = wake(target).await;
        result["operation"] = json!(op);
        result["target"] = json!(target);
        result["lock_wait_ms"] = json!(lock_wait_ms);
        result["recovered"] = json!(recovered);
        let mut state = self.state.lock().unwrap();
        state.open.remove(op);
        if result["status"] == "done" {
            state.done.insert(op.to_owned(), result.clone());
            state.append(json!({"k":"done","op":op,"result":result}));
        } else {
            state.append(json!({"k":"closed","op":op}));
        }
        result
    }
}

/// Thaw the target's cgroup through its freeze file, then wait until it answers HTTP.
async fn wake(target: &str) -> Value {
    let cgroup = format!("/sys/fs/cgroup/system.slice/orbit-spike-target-{target}.service");
    let port: u16 = 8000 + target.parse::<u16>().unwrap_or(0) % 1000;
    let action_ns = now_ns();
    if let Err(e) = std::fs::write(format!("{cgroup}/cgroup.freeze"), "0") {
        return json!({"status":"failed","error":format!("thaw: {e}"),"action_ns":action_ns});
    }
    let deadline = tokio::time::Instant::now() + Duration::from_secs(5);
    loop {
        let events = std::fs::read_to_string(format!("{cgroup}/cgroup.events")).unwrap_or_default();
        if events.lines().any(|l| l == "frozen 0") {
            break;
        }
        if tokio::time::Instant::now() > deadline {
            return json!({"status":"failed","error":"still frozen","action_ns":action_ns});
        }
        tokio::time::sleep(Duration::from_micros(200)).await;
    }
    let thawed_ns = now_ns();
    if let Some(ms) = std::fs::read_to_string(TEST_PAUSE).ok().and_then(|t| t.trim().parse::<u64>().ok()) {
        eprintln!("test pause {ms} ms after thaw of {target}");
        tokio::time::sleep(Duration::from_millis(ms)).await;
    }
    loop {
        if http_ok(port).await {
            return json!({"status":"done","action_ns":action_ns,"thawed_ns":thawed_ns,"ready_ns":now_ns()});
        }
        if tokio::time::Instant::now() > deadline {
            return json!({"status":"failed","error":"no HTTP answer","action_ns":action_ns});
        }
        tokio::time::sleep(Duration::from_millis(2)).await;
    }
}

async fn http_ok(port: u16) -> bool {
    let attempt = async {
        let mut s = tokio::net::TcpStream::connect(("127.0.0.1", port)).await.ok()?;
        s.write_all(b"GET / HTTP/1.0\r\n\r\n").await.ok()?;
        let mut head = [0u8; 12];
        s.read_exact(&mut head).await.ok()?;
        Some(head.ends_with(b" 200"))
    };
    matches!(tokio::time::timeout(Duration::from_millis(500), attempt).await, Ok(Some(true)))
}

async fn connection(exec: Arc<Executor>, mut stream: UnixStream) {
    let accepted_ns = now_ns();
    let peer = match check_peer(stream.as_raw_fd()) {
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
            eprintln!("refused order op={} nonce={}: {reason}", order["operation"], order["nonce"]);
            json!({"status":"refused","reason":reason,"operation":order["operation"],"nonce":order["nonce"]})
        }
        Ok(order) => {
            let op = order["operation"].as_str().unwrap_or_default().to_owned();
            let target = order["target"].as_str().unwrap_or_default().to_owned();
            let verified_ns = now_ns();
            let mut result = exec.run(&op, &target, false).await;
            if result["repeat"] == true {
                eprintln!("repeat op={op} target={target}: first result returned");
                let mut first = result["first"].clone();
                first["repeat"] = json!(true);
                first["joined"] = result["joined"].clone();
                result = first;
            } else {
                eprintln!("{} op={op} target={target}", result["status"].as_str().unwrap_or(""));
            }
            result["nonce"] = order["nonce"].clone();
            result["peer"] = json!(peer);
            result["accepted_ns"] = json!(accepted_ns);
            result["verified_ns"] = json!(verified_ns);
            result
        }
    };
    let _ = stream.write_all(reply.to_string().as_bytes()).await;
    let _ = stream.shutdown().await;
}

#[tokio::main(flavor = "multi_thread", worker_threads = 4)]
async fn main() {
    let read = |path: &str| std::fs::read_to_string(path).unwrap_or_else(|_| panic!("{path} missing"));
    let key_bytes: [u8; 32] = B64.decode(read(PUBLIC_KEY).trim()).expect("pinned key").try_into().expect("pinned key");
    let exec = Arc::new(Executor {
        key: VerifyingKey::from_bytes(&key_bytes).expect("pinned key"),
        node: read(NODE_ID).trim().to_owned(),
        targets: read(TARGETS).lines().map(|t| t.trim().to_owned()).filter(|t| !t.is_empty()).collect(),
        state: Mutex::new(State::load()),
        locks: Mutex::new(HashMap::new()),
    });
    let _ = std::fs::remove_file(SOCKET);
    let old = unsafe { libc::umask(0o177) };
    let listener = UnixListener::bind(SOCKET).expect("bind socket");
    unsafe { libc::umask(old) };
    let open: Vec<(String, String)> = exec.state.lock().unwrap().open.iter().map(|(o, t)| (o.clone(), t.clone())).collect();
    eprintln!("executor ready: {} open order(s) to finish", open.len());
    for (op, target) in open {
        let exec = exec.clone();
        tokio::spawn(async move {
            eprintln!("recovering open order op={op} target={target}");
            let result = exec.run(&op, &target, true).await;
            eprintln!("recovered op={op} target={target}: {}", result["status"]);
        });
    }
    loop {
        if let Ok((stream, _)) = listener.accept().await {
            tokio::spawn(connection(exec.clone(), stream));
        }
    }
}
