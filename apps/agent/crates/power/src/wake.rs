//! In-process whole-container wake (ADR 0266 slice 5): identity, pool restore, Incus unfreeze, limits, marker, readiness.
//!
//! Nothing on this path syncs to disk: the journal's syncer covers the order, and a crash repeats the wake.
use crate::incus::{Api, Instance};
use crate::plan::{self, Plan, Range};
use crate::pool::{Item, Shortfall};
use crate::{invalid, load, micros, remove, valid_id, Identity, Power, UNMANAGED};
use serde::Serialize;
use std::fs::File;
use std::io::{self, Read as _, Write as _};
use std::net::{IpAddr, SocketAddr, TcpStream};
use std::os::fd::{AsRawFd as _, FromRawFd as _, OwnedFd};
use std::os::unix::fs::FileExt as _;
use std::path::Path;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::Arc;
use std::time::{Duration, Instant};

/// Pages per pool item: one `process_madvise` and at most one `process_vm_readv` batch (`IOV_MAX`) per failed page plus one.
const ITEM_PAGES: u64 = 1024;
const READY_BOUND: Duration = Duration::from_millis(1500);
const THAW_BOUND: Duration = Duration::from_secs(5);

/// Measured wake result. It contains counts and timings, never addresses or raw process data.
#[derive(Debug, Clone, Serialize, PartialEq, Eq)]
pub struct WakeReceipt {
    /// `complete`, `partial` (cut at the bound or a page that could not be read), `skipped` (no usable plan) or `not_needed` (the container already ran).
    pub restore: String,
    /// Why the restore is not complete, if it is not.
    pub reason: Option<String>,
    /// Processes in the plan that was restored.
    pub processes: usize,
    /// Pages read back that were not resident.
    pub pages_read: u64,
    /// Planned pages that could not be read; later pages in their items are still attempted.
    pub pages_failed: u64,
    /// Time in the pool.
    pub restore_us: u64,
    /// Time from the order to the readiness answer.
    pub total_us: u64,
}

/// One planned process, proven the same process before any memory syscall.
pub(crate) struct Target {
    pidfd: OwnedFd,
    pid: libc::pid_t,
    pagemap: File,
    page: u64,
    pages_failed: AtomicU64,
}

fn pidfd_open(pid: u32) -> io::Result<OwnedFd> {
    // SAFETY: pidfd_open takes a pid and flags by value and returns a new descriptor or -1; no Rust memory is passed.
    let fd = unsafe {
        libc::syscall(
            libc::SYS_pidfd_open,
            libc::pid_t::try_from(pid).map_err(io::Error::other)?,
            0,
        )
    };
    if fd < 0 {
        return Err(io::Error::last_os_error());
    }
    let fd = i32::try_from(fd).map_err(io::Error::other)?;
    // SAFETY: the kernel just returned this descriptor to us and nothing else owns it.
    Ok(unsafe { OwnedFd::from_raw_fd(fd) })
}

/// Signal 0 through the pidfd: success proves the pid still names the process the pidfd was opened for.
fn alive(pidfd: &OwnedFd) -> bool {
    // SAFETY: a null siginfo is allowed; the descriptor is borrowed and stays open for the call.
    let result = unsafe {
        libc::syscall(
            libc::SYS_pidfd_send_signal,
            pidfd.as_raw_fd(),
            0,
            std::ptr::null::<libc::siginfo_t>(),
            0,
        )
    };
    result == 0
}

/// The page runs of `ranges` that are not resident now: only those are worth a swap read.
fn missing(target: &Target, ranges: &[Range]) -> io::Result<Vec<Range>> {
    let mut runs: Vec<Range> = Vec::new();
    let mut buffer = Vec::new();
    for range in ranges {
        let count = usize::try_from(range.length / target.page).map_err(io::Error::other)?;
        buffer.resize(count * 8, 0);
        target
            .pagemap
            .read_exact_at(&mut buffer, (range.start / target.page) * 8)?;
        for (index, entry) in buffer.chunks_exact(8).enumerate() {
            if entry[7] & 0x80 != 0 {
                continue;
            }
            let address =
                range.start + target.page * u64::try_from(index).map_err(io::Error::other)?;
            match runs.last_mut() {
                Some(last) if last.start + last.length == address => last.length += target.page,
                _ => runs.push(Range {
                    start: address,
                    length: target.page,
                }),
            }
        }
    }
    Ok(runs)
}

/// An address in the target process, as an iovec base. It is never dereferenced here: only the kernel reads it, in that process.
fn remote(address: u64) -> *mut libc::c_void {
    std::ptr::null_mut::<libc::c_void>()
        .wrapping_byte_add(usize::try_from(address).unwrap_or(usize::MAX))
}

/// Read one item's missing pages back: one `process_madvise(MADV_WILLNEED)` to start every swap read at once, then one byte of
/// each page through `process_vm_readv` to wait for them. A refused advice is only a lost head start.
///
/// A short read stops at one unreadable page. Count and skip that page, then read the rest of the item within its deadline.
/// A pagemap that cannot be read or any failed page reports `read_failed`: the wake goes on, but as `partial`.
pub(crate) fn restore(
    target: &Target,
    ranges: &[Range],
    deadline: Instant,
) -> (u64, Option<Shortfall>) {
    let Ok(runs) = missing(target, ranges) else {
        return (0, Some("read_failed"));
    };
    if runs.is_empty() {
        return (0, None);
    }
    if Instant::now() >= deadline {
        return (0, Some("deadline"));
    }
    let advice: Vec<libc::iovec> = runs
        .iter()
        .map(|r| libc::iovec {
            iov_base: remote(r.start),
            iov_len: usize::try_from(r.length).unwrap_or(0),
        })
        .collect();
    // SAFETY: the iovecs name addresses in the target process, which the kernel only reads through the pidfd; the array lives
    // for the call and its length is passed with it.
    let _ = unsafe {
        libc::syscall(
            libc::SYS_process_madvise,
            target.pidfd.as_raw_fd(),
            advice.as_ptr(),
            advice.len(),
            libc::MADV_WILLNEED,
            0,
        )
    };
    let remote: Vec<libc::iovec> = runs
        .iter()
        .flat_map(|r| {
            (r.start..r.start + r.length).step_by(usize::try_from(target.page).unwrap_or(4096))
        })
        .map(|address| libc::iovec {
            iov_base: remote(address),
            iov_len: 1,
        })
        .collect();
    let mut scratch = vec![0_u8; remote.len()];
    let mut next = 0;
    let mut pages_read = 0;
    let mut shortfall = None;
    while next < remote.len() {
        if Instant::now() >= deadline {
            return (pages_read, Some("deadline"));
        }
        let remaining = &remote[next..];
        let local = libc::iovec {
            iov_base: scratch.as_mut_ptr().cast(),
            iov_len: remaining.len(),
        };
        // SAFETY: `local` fits in `scratch`, which outlives the call; the remote iovecs are only read by the kernel in the
        // target process; both counts match their arrays. Every remote iovec is exactly one byte of a different page.
        let read = unsafe {
            libc::process_vm_readv(
                target.pid,
                &local,
                1,
                remaining.as_ptr(),
                libc::c_ulong::try_from(remaining.len()).expect("an item has at most 1024 pages"),
                0,
            )
        };
        if read < 0 && io::Error::last_os_error().raw_os_error() == Some(libc::EINTR) {
            continue;
        }
        let read = usize::try_from(read).unwrap_or(0);
        pages_read += u64::try_from(read).expect("an item has at most 1024 pages");
        next += read;
        if next < remote.len() {
            // No iovec is split: the next one names the unreadable page. Never abandon the readable tail.
            target.pages_failed.fetch_add(1, Ordering::Relaxed);
            shortfall = Some("read_failed");
            next += 1;
        }
    }
    (pages_read, shortfall)
}

/// Split the plan into pool items of at most [`ITEM_PAGES`] pages, process by process.
/// How a pool item reads its pages back: [`restore`], except in tests that need a reader that stalls.
pub(crate) type Reader = fn(&Target, &[Range], Instant) -> (u64, Option<Shortfall>);

fn items(targets: Vec<(Arc<Target>, &[Range])>, reader: Reader) -> Vec<Item> {
    let mut items: Vec<Item> = Vec::new();
    for (target, ranges) in targets {
        let limit = ITEM_PAGES * target.page;
        let mut batch: Vec<Range> = Vec::new();
        let mut size = 0;
        let mut flush = |batch: &mut Vec<Range>, size: &mut u64| {
            if !batch.is_empty() {
                let target = Arc::clone(&target);
                let ranges = std::mem::take(batch);
                items.push(Box::new(move |deadline| reader(&target, &ranges, deadline)));
            }
            *size = 0;
        };
        for range in ranges {
            let mut start = range.start;
            let end = range.start + range.length;
            while start < end {
                let length = (end - start).min(limit - size);
                batch.push(Range { start, length });
                size += length;
                start += length;
                if size == limit {
                    flush(&mut batch, &mut size);
                }
            }
        }
        flush(&mut batch, &mut size);
    }
    items
}

/// One HTTP request from the Node to the container's web port, retried until an answer below 500 or the bound.
pub(crate) fn ready(address: IpAddr, port: u16, bound: Duration) -> bool {
    let deadline = Instant::now() + bound;
    let socket = SocketAddr::new(address, port);
    loop {
        let left = deadline.saturating_duration_since(Instant::now());
        if left.is_zero() {
            return false;
        }
        if let Some(status) = ask(socket, deadline) {
            if status < 500 && Instant::now() < deadline {
                return true;
            }
        }
        std::thread::sleep(Duration::from_millis(20).min(left));
    }
}
/// The time left before `deadline`, or `None` once it passed. Every socket call gets only this, so a peer that answers byte by
/// byte cannot stretch the check past its one absolute bound.
fn left(deadline: Instant) -> Option<Duration> {
    Some(deadline.saturating_duration_since(Instant::now())).filter(|left| !left.is_zero())
}
fn ask(socket: SocketAddr, deadline: Instant) -> Option<u16> {
    let mut stream = TcpStream::connect_timeout(&socket, left(deadline)?).ok()?;
    stream.set_nodelay(true).ok()?;
    stream.set_write_timeout(Some(left(deadline)?)).ok()?;
    write!(
        stream,
        "GET / HTTP/1.1\r\nHost: {}\r\nConnection: close\r\n\r\n",
        socket.ip()
    )
    .ok()?;
    let mut head = [0; 12];
    let mut filled = 0;
    while filled < head.len() {
        stream.set_read_timeout(Some(left(deadline)?)).ok()?;
        match stream.read(&mut head[filled..]).ok()? {
            0 => return None,
            read => filled += read,
        }
    }
    left(deadline)?;
    std::str::from_utf8(&head[9..12]).ok()?.parse().ok()
}

/// Whether the admitted container has an app port to check before releasing visitors.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
#[non_exhaustive]
pub enum WebProbe {
    /// Require the app to answer.
    Required,
    /// The container has no web unit.
    NotConfigured,
    /// A start or deploy owns readiness and the asleep marker after resume.
    Lifecycle,
}

impl Power {
    /// Wake under the executor's per-target lock. Safe to repeat: a container that already runs gets only the steps after the
    /// unfreeze.
    ///
    /// # Errors
    /// Returns a stable power error. A failed wake keeps the asleep marker, so Caddy keeps the visitor away from the app.
    pub fn wake(&self, id: &str, probe_web: WebProbe) -> io::Result<WakeReceipt> {
        self.wake_checked(&self.incus, id, probe_web)
    }

    #[cfg(test)]
    pub(crate) fn wake_with(&self, api: &dyn Api, id: &str) -> io::Result<WakeReceipt> {
        self.wake_checked(api, id, WebProbe::Required)
    }

    pub(crate) fn wake_checked(
        &self,
        api: &dyn Api,
        id: &str,
        probe_web: WebProbe,
    ) -> io::Result<WakeReceipt> {
        if !valid_id(id) {
            return Err(invalid("unknown_target"));
        }
        let started = Instant::now();
        let instance = api.instance(id)?.ok_or_else(|| invalid("unknown_target"))?;
        let base = self.state.join(id);
        if instance.status == "stopped" {
            // A boot is the run's work. The plan of a container that stopped is useless.
            self.discard(id)?;
            return Err(invalid("instance.container_stopped"));
        }
        if !["running", "frozen"].contains(&instance.status.as_str()) {
            return Err(invalid("instance.container_start_failed"));
        }
        let identity = self.identity(&instance)?;
        let root = self.cgroups.join(&identity.cgroup);
        let mut receipt = WakeReceipt {
            restore: "not_needed".to_owned(),
            reason: None,
            processes: 0,
            pages_read: 0,
            pages_failed: 0,
            restore_us: 0,
            total_us: 0,
        };
        if instance.status == "running" {
            // Incus lost a freeze that the kernel still holds. The executor never writes cgroup.freeze itself.
            if Self::kernel_frozen(&root)? {
                return Err(invalid(UNMANAGED));
            }
        } else {
            let restoring = Instant::now();
            match self.targets(&base, &identity, &instance) {
                Ok((plan, targets)) => {
                    receipt.processes = plan.processes.len();
                    let work = items(
                        targets
                            .iter()
                            .map(Arc::clone)
                            .zip(&plan.processes)
                            .map(|(t, p)| (t, p.ranges.as_slice()))
                            .collect(),
                        self.reader,
                    );
                    // A stalled reader outlives this call, harmlessly (see Pool::restore); the target's readers keep a later
                    // sleep or discard of it waiting until the reader returns.
                    let restored =
                        self.pool
                            .restore(work, restoring + self.restore_bound, &self.readers(id));
                    receipt.pages_read = restored.pages;
                    receipt.pages_failed = targets
                        .iter()
                        .map(|t| t.pages_failed.load(Ordering::Relaxed))
                        .sum();
                    match restored.shortfall {
                        None => "complete".clone_into(&mut receipt.restore),
                        Some(reason) => {
                            "partial".clone_into(&mut receipt.restore);
                            receipt.reason = Some(reason.to_owned());
                        }
                    }
                }
                Err(reason) => {
                    "skipped".clone_into(&mut receipt.restore);
                    receipt.reason = Some(reason.to_owned());
                }
            }
            receipt.restore_us = micros(restoring);
            remove(&base.join("plan.json"))?;
            Self::unfreeze(api, id, &instance, &root)?;
        }
        self.close_wake_limits(&base, &identity)?;
        if probe_web == WebProbe::Required
            && !instance
                .address
                .is_some_and(|address| ready(address, self.web_port, READY_BOUND))
        {
            return Err(invalid("instance.web_unready"));
        }
        if probe_web != WebProbe::Lifecycle {
            remove(&self.markers.join(format!("app-instance-{id}.asleep")))?;
        }
        receipt.total_us = micros(started);
        Ok(receipt)
    }

    /// Load the plan and prove every recorded process is the one that slept, before any memory syscall. Any mismatch keeps the
    /// container's memory untouched; the wake goes on without a restore.
    fn targets(
        &self,
        base: &Path,
        identity: &Identity,
        instance: &Instance,
    ) -> Result<(Plan, Vec<Arc<Target>>), &'static str> {
        let path = base.join("plan.json");
        if !path.exists() {
            return Err("no_plan");
        }
        let plan: Plan = load(&path).map_err(|_| "plan_invalid")?;
        if !plan::valid(&plan) {
            return Err("plan_invalid");
        }
        if plan.version != 1 || plan.identity != *identity || plan.init_pid != instance.pid {
            return Err("plan_stale");
        }
        let mut targets = Vec::with_capacity(plan.processes.len());
        for process in &plan.processes {
            // Open the pidfd first: a pid reused before the open fails the start time; one reused after it fails the signal.
            let pidfd = pidfd_open(process.pid).map_err(|_| "plan_stale")?;
            if plan::ticks(&self.proc, process.pid).ok() != Some(process.start_ticks)
                || !alive(&pidfd)
            {
                return Err("plan_stale");
            }
            let pagemap = File::open(self.proc.join(process.pid.to_string()).join("pagemap"))
                .map_err(|_| "plan_stale")?;
            targets.push(Arc::new(Target {
                pidfd,
                pid: libc::pid_t::try_from(process.pid).map_err(|_| "plan_invalid")?,
                pagemap,
                page: plan.page_size,
                pages_failed: AtomicU64::new(0),
            }));
        }
        Ok((plan, targets))
    }

    /// Unfreeze through Incus with its short bounds, then wait for the kernel to thaw. A replacement container is never unfrozen.
    fn unfreeze(api: &dyn Api, id: &str, slept: &Instance, root: &Path) -> io::Result<()> {
        let live = api.instance(id)?;
        let Some(live) = live.filter(|l| l.incarnation == slept.incarnation) else {
            return Err(invalid("instance.container_start_failed"));
        };
        if live.status == "frozen" {
            // A lost response is judged by the state read below.
            let _ = api.change(&live, "unfreeze");
        }
        let resumed = api.instance(id)?;
        if !matches!(&resumed, Some(l) if l.incarnation == slept.incarnation && l.status == "running")
        {
            return Err(invalid("instance.container_start_failed"));
        }
        // liblxc can report running before the kernel thaw lands; one that never lands is the lost-freeze case.
        let thaw = Instant::now();
        while Self::kernel_frozen(root)? {
            if thaw.elapsed() >= THAW_BOUND {
                return Err(invalid(UNMANAGED));
            }
            std::thread::sleep(Duration::from_millis(2));
        }
        Ok(())
    }

    /// Close exactly the recorded limits, without a disk sync. A record of another incarnation names cgroups that are gone: it
    /// is dropped and nothing is written.
    fn close_wake_limits(&self, base: &Path, identity: &Identity) -> io::Result<()> {
        match self.close_limits_unsynced(base, identity) {
            Err(error) if error.to_string() == "stale_limits" => remove(&base.join("limits.json")),
            other => other,
        }
    }
}
