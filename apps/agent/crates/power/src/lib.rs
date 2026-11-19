//! In-process whole-container sleep, wake and discard (ADR 0266 slices 4 and 5).
//!
//! The executor core supplies the per-target lock, Operation idempotency and recovery journal.
//! State here is private to Rust until the production cutover; no Python helper is called.
mod incus;
mod plan;
mod pool;
mod wake;

pub use pool::{MAX_THREADS, MIN_THREADS};
pub use wake::{WakeReceipt, WebProbe};

use incus::{Api, Incus, Instance};
use plan::{cgroup_identity, Plan};
use serde::{Deserialize, Serialize};
use std::fs::{self, File, OpenOptions};
use std::io::{self, Read as _, Write as _};
use std::net::{SocketAddr, TcpStream};
use std::os::unix::fs::{DirBuilderExt as _, OpenOptionsExt as _, PermissionsExt as _};
use std::path::{Path, PathBuf};
use std::time::{Duration, Instant};

const RESIDUAL_ANON: u64 = 32 << 20;
const UNMANAGED: &str = "instance.container_frozen_unmanaged";
/// The published route must already recognize the asleep marker.
pub const ROUTE_UNREADY: &str = "instance.sleep_route_unready";
/// Sleep failed after making the container unavailable; keep the state for this engine to wake.
pub const SLEEP_INCOMPLETE: &str = "instance.container_sleep_incomplete";
/// A sleep or discard waits this long for a stalled restore reader of its target, then refuses.
const READER_BOUND: Duration = Duration::from_secs(10);
/// The refusal when a stalled restore reader of the target outlives [`READER_BOUND`].
pub const READERS_BUSY: &str = "instance.restore_readers_busy";

fn invalid(message: &str) -> io::Error {
    io::Error::other(message)
}
fn read(path: &Path) -> io::Result<String> {
    fs::read_to_string(path).map(|s| s.trim().to_owned())
}
fn remove(path: &Path) -> io::Result<()> {
    match fs::remove_file(path) {
        Ok(()) => Ok(()),
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(()),
        Err(e) => Err(e),
    }
}
fn private_dir(path: &Path) -> io::Result<()> {
    fs::DirBuilder::new()
        .recursive(true)
        .mode(0o700)
        .create(path)
}

/// Publish a complete file: sync data, rename, sync parent. A recovery can replace an abandoned staging file.
fn atomic(path: &Path, bytes: &[u8], mode: u32) -> io::Result<()> {
    let parent = path
        .parent()
        .ok_or_else(|| invalid("state path has no parent"))?;
    let temp = path.with_extension("tmp");
    let result = (|| {
        let mut file = OpenOptions::new()
            .create(true)
            .truncate(true)
            .write(true)
            .mode(mode)
            .custom_flags(libc::O_NOFOLLOW)
            .open(&temp)?;
        file.set_permissions(fs::Permissions::from_mode(mode))?;
        file.write_all(bytes)?;
        file.sync_all()?;
        fs::rename(&temp, path)?;
        File::open(parent)?.sync_all()
    })();
    if result.is_err() {
        let _ = remove(&temp);
    }
    result
}
fn publish<T: Serialize>(path: &Path, value: &T) -> io::Result<()> {
    let bytes = serde_json::to_vec(value).map_err(io::Error::other)?;
    if bytes.len() > plan::MAX_METADATA {
        return Err(invalid("bound_metadata"));
    }
    atomic(path, &bytes, 0o600)
}
fn load<T: for<'de> Deserialize<'de>>(path: &Path) -> io::Result<T> {
    let mut bytes = Vec::new();
    File::open(path)?
        .take(u64::try_from(plan::MAX_METADATA + 1).expect("metadata cap fits u64"))
        .read_to_end(&mut bytes)?;
    if bytes.len() > plan::MAX_METADATA {
        return Err(invalid("bound_metadata"));
    }
    serde_json::from_slice(&bytes).map_err(io::Error::other)
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
struct Identity {
    boot: String,
    incarnation: String,
    cgroup: String,
    device: u64,
    inode: u64,
}
#[derive(Debug, Serialize, Deserialize)]
struct Limit {
    relative: PathBuf,
    previous: String,
}
#[derive(Debug, Serialize, Deserialize)]
struct Limits {
    identity: Identity,
    opened: Vec<Limit>,
}

/// Measured sleep result. It contains counts and timings, never raw process data.
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct Receipt {
    /// `frozen` or the swap-full `stopped` outcome.
    pub sleep: String,
    /// `ready`, or `skipped` when capture cannot produce a complete plan.
    pub plan: String,
    /// Why capture was skipped, if applicable.
    pub reason: Option<String>,
    /// Processes recorded, including every process with no readable pages.
    pub processes: usize,
    /// Present or swapped readable bytes in the plan.
    pub mapping_bytes: u64,
    /// Last RAM reading of the cgroup.
    pub ram_bytes: u64,
    /// Last swap reading of the cgroup.
    pub swap_bytes: u64,
    /// Anonymous RAM remaining after reclaim.
    pub residual_anon_bytes: u64,
    /// File RAM remaining after reclaim.
    pub residual_file_bytes: u64,
    /// In-process time before receipt and marker publication; the executor logs the complete action time.
    pub total_us: u64,
    /// Time spent reclaiming and waiting for counters to settle.
    pub reclaim_us: u64,
    /// Number of inner cgroup limits opened, excluding the container root.
    #[serde(default)]
    pub swap_subtree: usize,
}
fn micros(start: Instant) -> u64 {
    u64::try_from(start.elapsed().as_micros()).unwrap_or(u64::MAX)
}

/// The fixed host paths used by the power family.
#[derive(Debug)]
pub struct Power {
    state: PathBuf,
    cgroups: PathBuf,
    proc: PathBuf,
    markers: PathBuf,
    incus: Incus,
    pool: pool::Pool,
    restore_bound: Duration,
    web_port: u16,
    /// How long a wake waits for the app when its order names no bound.
    ready_bound: Duration,
    /// Restore items still running per target, stalled ones included.
    readers: std::sync::Mutex<std::collections::HashMap<String, std::sync::Arc<pool::Readers>>>,
    /// How long a sleep or discard waits for a target's stalled restore readers.
    reader_bound: Duration,
    reader: wake::Reader,
}
impl Power {
    /// The Node's power family with its one restore pool of `threads` threads, which the role sizes.
    ///
    /// # Errors
    /// Refuses a pool size outside [`MIN_THREADS`]..=[`MAX_THREADS`].
    pub fn new(threads: usize) -> io::Result<Self> {
        if !(MIN_THREADS..=MAX_THREADS).contains(&threads) {
            return Err(invalid("restore pool size out of range"));
        }
        Ok(Self {
            state: "/var/lib/orbit/executor/power".into(),
            cgroups: "/sys/fs/cgroup".into(),
            proc: "/proc".into(),
            markers: "/data/caddy/orbit/hibernation".into(),
            incus: Incus("/var/lib/incus/unix.socket".into()),
            pool: pool::Pool::new(threads),
            restore_bound: Duration::from_secs(2),
            web_port: 8080,
            ready_bound: wake::READY_BOUND,
            readers: std::sync::Mutex::default(),
            reader_bound: READER_BOUND,
            reader: wake::restore,
        })
    }

    /// Whether the fixed Instance container exists in the local Incus.
    pub fn target_exists(&self, id: &str) -> bool {
        valid_id(id)
            && self
                .incus
                .instance(id)
                .is_ok_and(|instance| instance.is_some())
    }

    /// Sleep under the executor's per-target lock, preserving a plan already published while frozen.
    ///
    /// # Errors
    /// Returns a stable power error or a local I/O error. A frozen failure keeps recovery state.
    pub fn sleep(&self, id: &str, operation: &str) -> io::Result<Receipt> {
        self.sleep_with(&self.incus, id, operation)
    }

    /// Check the loaded route before changing swap or freezing a published Instance.
    ///
    /// # Errors
    /// Returns [`ROUTE_UNREADY`] when Caddy does not recognize the marker.
    pub fn require_sleep_route(&self, id: &str) -> io::Result<()> {
        if caddy_checks(&self.marker(id)) {
            Ok(())
        } else {
            Err(invalid(ROUTE_UNREADY))
        }
    }

    fn sleep_with(&self, api: &dyn Api, id: &str, operation: &str) -> io::Result<Receipt> {
        let marker = self.marker(id);
        let had_marker = marker.exists();
        let result = self.capture_sleep(api, id, operation);
        if result.is_err() && !had_marker {
            self.clear_failed_sleep_marker(api, id, &marker);
        }
        if result.is_err() && marker.exists() && self.state.join(id).join("limits.json").exists() {
            return Err(invalid(SLEEP_INCOMPLETE));
        }
        result
    }

    fn clear_failed_sleep_marker(&self, api: &dyn Api, id: &str, marker: &Path) {
        if let Ok(Some(instance)) = api.instance(id) {
            if instance.status == "running"
                && self.identity(&instance).is_ok_and(|identity| {
                    Self::kernel_frozen(&self.cgroups.join(identity.cgroup))
                        .is_ok_and(|frozen| !frozen)
                })
            {
                let _ = remove(marker);
            }
        }
    }

    /// Drop the plan and limits record after stop, destruction or a fresh boot. Safe to repeat.
    ///
    /// # Errors
    /// Returns a state I/O error. Discard does not thaw or change a running container's limits.
    pub fn discard(&self, id: &str) -> io::Result<()> {
        if !valid_id(id) {
            return Err(invalid("unknown_target"));
        }
        self.await_readers(id)?;
        let base = self.state.join(id);
        if !base.exists() {
            return Ok(());
        }
        for name in [
            "plan.json",
            "limits.json",
            "receipt.json",
            "plan.tmp",
            "limits.tmp",
            "receipt.tmp",
        ] {
            remove(&base.join(name))?;
        }
        File::open(base)?.sync_all()
    }

    /// Discard on a signed order only after Incus proves the target is stopped or absent.
    ///
    /// # Errors
    /// Returns a power state or local I/O error. On refusal the saved limits and plan stay intact.
    pub fn discard_stopped(&self, id: &str) -> io::Result<()> {
        self.discard_stopped_with(&self.incus, id)
    }

    fn discard_stopped_with(&self, api: &dyn Api, id: &str) -> io::Result<()> {
        if !valid_id(id)
            || api
                .instance(id)?
                .is_some_and(|instance| instance.status != "stopped")
        {
            return Err(invalid("instance.power_discard_failed"));
        }
        self.discard(id)
    }

    fn readers(&self, id: &str) -> std::sync::Arc<pool::Readers> {
        std::sync::Arc::clone(
            self.readers
                .lock()
                .expect("restore readers map lock poisoned")
                .entry(id.to_owned())
                .or_insert_with(|| self.pool.readers()),
        )
    }

    /// Wait until no restore item of the target runs, so no freeze, scan, plan or discard overlaps a stalled reader of a wake.
    fn await_readers(&self, id: &str) -> io::Result<()> {
        if self.readers(id).wait_idle(self.reader_bound) {
            Ok(())
        } else {
            Err(invalid(READERS_BUSY))
        }
    }

    fn identity(&self, instance: &Instance) -> io::Result<Identity> {
        let cgroup = format!("lxc.payload.{}_{}", instance.project, instance.name);
        let (device, inode) = cgroup_identity(&self.cgroups.join(&cgroup))?;
        Ok(Identity {
            boot: read(&self.proc.join("sys/kernel/random/boot_id"))?,
            incarnation: instance.incarnation.clone(),
            cgroup,
            device,
            inode,
        })
    }

    fn open_limits(&self, base: &Path, identity: &Identity) -> io::Result<()> {
        let path = base.join("limits.json");
        if path.exists() {
            let record: Limits = load(&path)?;
            if record.identity != *identity {
                return Err(invalid("stale_limits"));
            }
            // The record precedes every write, so repeating all of them finishes an interrupted open.
            for limit in &record.opened {
                fs::write(
                    self.cgroups
                        .join(&identity.cgroup)
                        .join(&limit.relative)
                        .join("memory.swap.max"),
                    "max",
                )?;
            }
            return Ok(());
        }
        let root = self.cgroups.join(&identity.cgroup);
        let mut opened = Vec::new();
        for directory in plan::subtree(&root)? {
            // Threaded cgroups have no memory controller of their own; their domain owns the limit.
            let previous = match read(&directory.join("memory.swap.max")) {
                Ok(value) => value,
                Err(error) if error.kind() == io::ErrorKind::NotFound => continue,
                Err(error) => return Err(error),
            };
            if previous != "max" {
                previous.parse::<u64>().map_err(io::Error::other)?;
                let relative = directory
                    .strip_prefix(&root)
                    .map_err(io::Error::other)?
                    .to_owned();
                opened.push(Limit { relative, previous });
            }
        }
        publish(
            &path,
            &Limits {
                identity: Identity {
                    boot: identity.boot.clone(),
                    incarnation: identity.incarnation.clone(),
                    cgroup: identity.cgroup.clone(),
                    device: identity.device,
                    inode: identity.inode,
                },
                opened,
            },
        )?;
        self.open_limits(base, identity)
    }

    fn close_limits(&self, base: &Path, identity: &Identity) -> io::Result<()> {
        self.close_limits_unsynced(base, identity)?;
        File::open(base)?.sync_all()
    }

    /// Restore exactly the recorded limits and drop the record. The wake calls this without a disk sync.
    fn close_limits_unsynced(&self, base: &Path, identity: &Identity) -> io::Result<()> {
        let path = base.join("limits.json");
        if !path.exists() {
            return Ok(());
        }
        let record: Limits = load(&path)?;
        if record.identity != *identity {
            return Err(invalid("stale_limits"));
        }
        for limit in record.opened {
            if limit
                .relative
                .components()
                .any(|p| !matches!(p, std::path::Component::Normal(_)))
            {
                return Err(invalid("invalid limits path"));
            }
            let path = self
                .cgroups
                .join(&identity.cgroup)
                .join(limit.relative)
                .join("memory.swap.max");
            match fs::write(path, limit.previous) {
                Ok(()) => {}
                Err(e) if e.kind() == io::ErrorKind::NotFound => {}
                Err(e) => return Err(e),
            }
        }
        remove(&path)
    }

    fn kernel_frozen(root: &Path) -> io::Result<bool> {
        Ok(read(&root.join("cgroup.freeze"))? == "1"
            || read(&root.join("cgroup.events"))?
                .lines()
                .any(|l| l == "frozen 1"))
    }

    fn capture_sleep(&self, api: &dyn Api, id: &str, operation: &str) -> io::Result<Receipt> {
        if !valid_id(id) {
            return Err(invalid("unknown_target"));
        }
        self.await_readers(id)?;
        let started = Instant::now();
        let instance = api.instance(id)?.ok_or_else(|| invalid("unknown_target"))?;
        let base = self.state.join(id);
        private_dir(&base)?;
        if instance.status == "stopped" {
            self.discard(id)?;
            let receipt = stopped_receipt(started);
            self.mark_asleep(id)?;
            publish(&base.join("receipt.json"), &receipt)?;
            return Ok(receipt);
        }
        let identity = self.identity(&instance)?;
        let root = self.cgroups.join(&identity.cgroup);
        if instance.status == "running" && Self::kernel_frozen(&root)? {
            return Err(invalid(UNMANAGED));
        }
        if !["running", "frozen"].contains(&instance.status.as_str()) {
            return Err(invalid("instance.container_freeze_failed"));
        }
        if instance.status == "running" {
            // Any stale running-state record is closed before a fresh capture replaces it.
            self.close_limits(&base, &identity)?;
            remove(&base.join("plan.json"))?;
            remove(&base.join("receipt.json"))?;
        }
        if let Err(error) = self.open_limits(&base, &identity) {
            if instance.status == "running" && !Self::kernel_frozen(&root)? {
                self.close_limits(&base, &identity)?;
            }
            return Err(error);
        }
        self.freeze(api, id, &instance, &base, &identity)?;
        let mut reason = None;
        let plan_path = base.join("plan.json");
        let plan =
            self.existing_or_capture(&plan_path, &identity, &instance, operation, &mut reason)?;
        let reclaim_started = Instant::now();
        Self::reclaim(&root)?;
        let reclaim_us = micros(reclaim_started);
        let (anon, file) = counters(&root)?;
        let ram_bytes = number(&root.join("memory.current"))?;
        let swap_bytes = number(&root.join("memory.swap.current"))?;
        let limits: Limits = load(&base.join("limits.json"))?;
        let swap_subtree = limits
            .opened
            .iter()
            .filter(|limit| !limit.relative.as_os_str().is_empty())
            .count();
        let mut sleep = "frozen";
        if anon > RESIDUAL_ANON {
            self.swap_full(api, id, &instance, &base, &identity)?;
            sleep = "stopped";
        }
        let receipt = Receipt {
            sleep: sleep.to_owned(),
            plan: if plan.is_some() && sleep == "frozen" {
                "ready"
            } else {
                "skipped"
            }
            .to_owned(),
            reason: if sleep == "stopped" {
                Some("stopped".to_owned())
            } else {
                reason
            },
            processes: plan.as_ref().map_or(0, |p| p.processes.len()),
            mapping_bytes: plan.as_ref().map_or(0, |p| {
                p.processes
                    .iter()
                    .flat_map(|p| &p.ranges)
                    .map(|r| r.length)
                    .sum()
            }),
            ram_bytes: if sleep == "stopped" { 0 } else { ram_bytes },
            swap_bytes: if sleep == "stopped" { 0 } else { swap_bytes },
            residual_anon_bytes: anon,
            residual_file_bytes: file,
            total_us: micros(started),
            reclaim_us,
            swap_subtree,
        };
        publish(&base.join("receipt.json"), &receipt)?;
        Ok(receipt)
    }

    fn freeze(
        &self,
        api: &dyn Api,
        id: &str,
        instance: &Instance,
        base: &Path,
        identity: &Identity,
    ) -> io::Result<()> {
        let root = self.cgroups.join(&identity.cgroup);
        if let Err(error) = self.mark_asleep(id) {
            if instance.status == "running" {
                self.close_limits(base, identity)?;
            }
            return Err(error);
        }
        if instance.status == "running"
            && api.change(instance, "freeze").is_err()
            && !api
                .instance(id)?
                .is_some_and(|live| live.status == "frozen")
        {
            if !Self::kernel_frozen(&root)? {
                self.close_limits(base, identity)?;
            }
            return Err(invalid("instance.container_freeze_failed"));
        }
        if Self::kernel_frozen(&root)? {
            Ok(())
        } else {
            Err(invalid("instance.container_freeze_failed"))
        }
    }

    fn marker(&self, id: &str) -> PathBuf {
        self.markers.join(format!("app-instance-{id}.asleep"))
    }

    fn mark_asleep(&self, id: &str) -> io::Result<()> {
        fs::create_dir_all(&self.markers)?;
        atomic(&self.marker(id), b"", 0o644)
    }

    fn existing_or_capture(
        &self,
        plan_path: &Path,
        identity: &Identity,
        instance: &Instance,
        operation: &str,
        reason: &mut Option<String>,
    ) -> io::Result<Option<Plan>> {
        if plan_path.exists() {
            let existing: Plan = load(plan_path)?;
            if existing.version != 1
                || existing.identity != *identity
                || existing.init_pid != instance.pid
                || plan::ticks(&self.proc, instance.pid)? != existing.init_start_ticks
            {
                return Err(invalid("plan_stale"));
            }
            Ok(Some(existing))
        } else {
            match self.capture(identity, instance, operation) {
                Ok(plan) => {
                    publish(plan_path, &plan)?;
                    Ok(Some(plan))
                }
                Err(error) => {
                    *reason = Some(capture_reason(&error).to_owned());
                    Ok(None)
                }
            }
        }
    }

    fn swap_full(
        &self,
        api: &dyn Api,
        id: &str,
        instance: &Instance,
        base: &Path,
        identity: &Identity,
    ) -> io::Result<()> {
        let root = self.cgroups.join(&identity.cgroup);
        // Never prefetch while swap is full. A nested fleet must retain its /run state.
        remove(&base.join("plan.json"))?;
        File::open(base)?.sync_all()?;
        api.change(instance, "unfreeze")?;
        let thaw = Instant::now();
        while Self::kernel_frozen(&root)? {
            if thaw.elapsed() >= Duration::from_secs(5) {
                return Err(invalid(UNMANAGED));
            }
            std::thread::sleep(Duration::from_millis(10));
        }
        self.close_limits(base, identity)?;
        if instance.project == "orbit-instances-nested" {
            return Err(invalid("instance.container_freeze_failed"));
        }
        api.change(instance, "stop")?;
        self.discard(id)
    }

    fn capture(
        &self,
        identity: &Identity,
        instance: &Instance,
        operation: &str,
    ) -> io::Result<Plan> {
        let (page_size, processes) = plan::capture(
            &self.proc,
            &self.cgroups.join(&identity.cgroup),
            Instant::now() + Duration::from_secs(30),
        )?;
        Ok(Plan {
            version: 1,
            operation: operation.to_owned(),
            identity: Identity {
                boot: identity.boot.clone(),
                incarnation: identity.incarnation.clone(),
                cgroup: identity.cgroup.clone(),
                device: identity.device,
                inode: identity.inode,
            },
            init_pid: instance.pid,
            init_start_ticks: plan::ticks(&self.proc, instance.pid)?,
            page_size,
            processes,
        })
    }

    fn reclaim(root: &Path) -> io::Result<()> {
        let started = Instant::now();
        let mut previous = number(&root.join("memory.current"))?;
        loop {
            match fs::write(root.join("memory.reclaim"), previous.max(1).to_string()) {
                Ok(()) => {}
                Err(e) if e.raw_os_error() == Some(libc::EAGAIN) => {}
                Err(e) => return Err(e),
            }
            std::thread::sleep(Duration::from_millis(50));
            let current = number(&root.join("memory.current"))?;
            if current == 0 || current == previous || started.elapsed() >= Duration::from_secs(2) {
                return Ok(());
            }
            previous = current;
        }
    }
}
fn stopped_receipt(started: Instant) -> Receipt {
    Receipt {
        sleep: "stopped".to_owned(),
        plan: "skipped".to_owned(),
        reason: Some("stopped".to_owned()),
        processes: 0,
        mapping_bytes: 0,
        ram_bytes: 0,
        swap_bytes: 0,
        residual_anon_bytes: 0,
        residual_file_bytes: 0,
        total_us: micros(started),
        reclaim_us: 0,
        swap_subtree: 0,
    }
}

fn caddy_checks(marker: &Path) -> bool {
    let result = caddy_config(([127, 0, 0, 1], 2019).into());
    result.is_ok_and(|bytes| route_checks(&bytes, marker))
}

fn caddy_config(address: SocketAddr) -> io::Result<Vec<u8>> {
    let bound = Duration::from_millis(250);
    let mut stream = TcpStream::connect_timeout(&address, bound)?;
    stream.set_read_timeout(Some(bound))?;
    stream.set_write_timeout(Some(bound))?;
    write!(stream, "GET /config/ HTTP/1.0\r\nHost: {address}\r\n\r\n")?;
    let mut bytes = Vec::new();
    stream.take(8 * 1024 * 1024).read_to_end(&mut bytes)?;
    Ok(bytes)
}

fn route_checks(response: &[u8], marker: &Path) -> bool {
    if !response.starts_with(b"HTTP/1.0 200 ") && !response.starts_with(b"HTTP/1.1 200 ") {
        return false;
    }
    let Some((root, filename)) = marker
        .parent()
        .and_then(Path::to_str)
        .zip(marker.file_name().and_then(|name| name.to_str()))
    else {
        return false;
    };
    let Some(headers) = response.windows(4).position(|bytes| bytes == b"\r\n\r\n") else {
        return false;
    };
    // The rule must send the matching visit to the Gateway's activation endpoint (Caddy's forward_auth is a reverse_proxy that
    // rewrites to it); a matcher whose route only answers or skips its log cannot wake the app.
    let Some(activation) = filename
        .strip_suffix(".asleep")
        .and_then(|key| key.strip_prefix("app-instance-"))
        .map(|id| format!("/api/v1/runtime-activations/app-instance/{id}"))
    else {
        return false;
    };
    serde_json::from_slice(&response[headers + 4..])
        .is_ok_and(|config| matches_marker(&config, root, &format!("/{filename}"), &activation))
}

fn matches_marker(config: &serde_json::Value, root: &str, file: &str, activation: &str) -> bool {
    let mut handlers = Vec::new();
    marker_handlers(config, root, file, &mut handlers);
    first_answer(&handlers, root, file).is_some_and(|handler| {
        let rewrite = handler.get("rewrite");
        handler.get("handler").and_then(serde_json::Value::as_str) == Some("reverse_proxy")
            && rewrite
                .and_then(|r| r.get("uri"))
                .and_then(serde_json::Value::as_str)
                == Some(activation)
            && rewrite
                .and_then(|r| r.get("method"))
                .and_then(serde_json::Value::as_str)
                == Some("GET")
    })
}

/// The first handler that answers a marked visit, which must be the activation proxy. The visit passes `vars` (Caddy's
/// `log_skip`) and enters each `subroute` through its routes without a matcher or with the exact marker matcher; any other
/// handler (a 503, an ordinary proxy) answers the visitor. Production nests the proxy this way (route 50: a `vars` route,
/// then a sibling marker route whose `subroute` holds the proxy).
fn first_answer<'a>(
    handlers: &[&'a serde_json::Value],
    root: &str,
    file: &str,
) -> Option<&'a serde_json::Value> {
    handlers.iter().find_map(|handler| {
        match handler.get("handler").and_then(serde_json::Value::as_str) {
            Some("vars") => None,
            Some("subroute") => {
                let entered: Vec<_> = handler
                    .get("routes")
                    .and_then(serde_json::Value::as_array)
                    .into_iter()
                    .flatten()
                    .filter(|route| reached(route, root, file))
                    .filter_map(|route| route.get("handle").and_then(serde_json::Value::as_array))
                    .flatten()
                    .collect();
                first_answer(&entered, root, file)
            }
            _ => Some(*handler),
        }
    })
}

/// Whether a route carries the exact marker matcher.
fn marked(route: &serde_json::Value, root: &str, file: &str) -> bool {
    route
        .get("match")
        .and_then(serde_json::Value::as_array)
        .is_some_and(|matchers| {
            matchers.iter().any(|matcher| {
                let files = matcher.get("file");
                files
                    .and_then(|f| f.get("root"))
                    .and_then(serde_json::Value::as_str)
                    == Some(root)
                    && files
                        .and_then(|f| f.get("try_files"))
                        .and_then(serde_json::Value::as_array)
                        .is_some_and(|candidates| {
                            candidates
                                .iter()
                                .any(|candidate| candidate.as_str() == Some(file))
                        })
            })
        })
}

/// The handlers that a marked visit reaches, in the order Caddy runs them. In a route list that holds a marker route, every route
/// without a matcher runs too, so a catch-all ahead of the activation route answers the visit and the guard refuses it.
fn marker_handlers<'a>(
    config: &'a serde_json::Value,
    root: &str,
    file: &str,
    handlers: &mut Vec<&'a serde_json::Value>,
) {
    match config {
        serde_json::Value::Object(object) => {
            if marked(config, root, file) {
                if let Some(handle) = object.get("handle").and_then(serde_json::Value::as_array) {
                    handlers.extend(handle);
                }
            }
            for value in object.values() {
                marker_handlers(value, root, file, handlers);
            }
        }
        serde_json::Value::Array(routes)
            if routes.iter().any(|route| marked(route, root, file)) =>
        {
            handlers.extend(
                routes
                    .iter()
                    .filter(|route| reached(route, root, file))
                    .filter_map(|route| route.get("handle").and_then(serde_json::Value::as_array))
                    .flatten(),
            );
        }
        serde_json::Value::Array(values) => {
            for value in values {
                marker_handlers(value, root, file, handlers);
            }
        }
        _ => {}
    }
}

/// Whether a marked visit enters this route: it has no matcher or the exact marker matcher.
fn reached(route: &serde_json::Value, root: &str, file: &str) -> bool {
    route.get("match").is_none() || marked(route, root, file)
}

fn valid_id(id: &str) -> bool {
    id.parse::<u64>()
        .is_ok_and(|n| n > 0 && n.to_string() == id)
}
fn number(path: &Path) -> io::Result<u64> {
    read(path)?.parse().map_err(io::Error::other)
}
fn counters(root: &Path) -> io::Result<(u64, u64)> {
    let stat = read(&root.join("memory.stat"))?;
    let get = |key| {
        stat.lines()
            .find_map(|l| {
                l.split_once(' ')
                    .filter(|(k, _)| *k == key)
                    .map(|(_, v)| v.parse::<u64>())
            })
            .ok_or_else(|| invalid("missing memory counter"))?
            .map_err(io::Error::other)
    };
    Ok((get("anon")?, get("file")?))
}
fn capture_reason(error: &io::Error) -> &'static str {
    match error.to_string().as_str() {
        "bound_processes" => "bound_processes",
        "bound_ranges" => "bound_ranges",
        "bound_residency" => "bound_residency",
        "bound_metadata" => "bound_metadata",
        "prepare_timeout" => "prepare_timeout",
        _ => "capture_failed",
    }
}

#[cfg(test)]
mod tests;
