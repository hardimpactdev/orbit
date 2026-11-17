//! In-process whole-container sleep and discard (ADR 0266 slice 4).
//!
//! The executor core supplies the per-target lock, Operation idempotency and recovery journal.
//! State here is private to Rust until the production cutover; no Python helper is called.
mod incus;
mod plan;

use incus::{Api, Incus, Instance};
use plan::{cgroup_identity, Plan};
use serde::{Deserialize, Serialize};
use std::fs::{self, File, OpenOptions};
use std::io::{self, Read as _, Write as _};
use std::os::unix::fs::{DirBuilderExt as _, OpenOptionsExt as _, PermissionsExt as _};
use std::path::{Path, PathBuf};
use std::time::{Duration, Instant};

const RESIDUAL_ANON: u64 = 32 << 20;
const UNMANAGED: &str = "instance.container_frozen_unmanaged";

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
    /// Total in-process sleep time.
    pub total_us: u64,
    /// Time spent reclaiming and waiting for counters to settle.
    pub reclaim_us: u64,
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
}
impl Default for Power {
    fn default() -> Self {
        Self {
            state: "/var/lib/orbit/executor/power".into(),
            cgroups: "/sys/fs/cgroup".into(),
            proc: "/proc".into(),
            markers: "/data/caddy/orbit/hibernation".into(),
            incus: Incus("/var/lib/incus/unix.socket".into()),
        }
    }
}
impl Power {
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

    /// Drop the plan and limits record after stop, destruction or a fresh boot. Safe to repeat.
    ///
    /// # Errors
    /// Returns a state I/O error. Discard does not thaw or change a running container's limits.
    pub fn discard(&self, id: &str) -> io::Result<()> {
        if !valid_id(id) {
            return Err(invalid("unknown_target"));
        }
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
        remove(&path)?;
        File::open(base)?.sync_all()
    }

    fn kernel_frozen(root: &Path) -> io::Result<bool> {
        Ok(read(&root.join("cgroup.freeze"))? == "1"
            || read(&root.join("cgroup.events"))?
                .lines()
                .any(|l| l == "frozen 1"))
    }

    fn sleep_with(&self, api: &dyn Api, id: &str, operation: &str) -> io::Result<Receipt> {
        if !valid_id(id) {
            return Err(invalid("unknown_target"));
        }
        let started = Instant::now();
        let instance = api.instance(id)?.ok_or_else(|| invalid("unknown_target"))?;
        let base = self.state.join(id);
        private_dir(&base)?;
        if instance.status == "stopped" {
            self.discard(id)?;
            let receipt = stopped_receipt(started);
            self.finish(id, &base, &receipt)?;
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
        if instance.status != "frozen" && api.change(&instance, "freeze").is_err() {
            // Incus may have completed the freeze before its response was lost.
            if api
                .instance(id)?
                .is_some_and(|live| live.status == "running")
                && !Self::kernel_frozen(&root)?
            {
                self.close_limits(&base, &identity)?;
            }
            return Err(invalid("instance.container_freeze_failed"));
        }
        if !Self::kernel_frozen(&root)? {
            return Err(invalid("instance.container_freeze_failed"));
        }
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
        };
        self.finish(id, &base, &receipt)?;
        Ok(receipt)
    }

    fn finish(&self, id: &str, base: &Path, receipt: &Receipt) -> io::Result<()> {
        publish(&base.join("receipt.json"), receipt)?;
        fs::create_dir_all(&self.markers)?;
        atomic(
            &self.markers.join(format!("app-instance-{id}.asleep")),
            b"",
            0o644,
        )
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
    }
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
