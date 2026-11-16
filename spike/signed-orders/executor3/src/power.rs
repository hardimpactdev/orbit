//! SPIKE(signed-orders) round 3: sleep and wake of a whole cgroup subtree, in-process (ADR 0261 rules).
//!
//! Sleep: record the swap limits it will open, open them, freeze the subtree, scan every process's
//! resident readable pages, publish the plan atomically, then reclaim until the counters settle.
//! Wake: check the plan's identity (boot id, cgroup inode, every pid's start time), restore the pages
//! with process_madvise(MADV_WILLNEED) and one-byte process_vm_readv reads on a thread pool while the
//! subtree is still frozen, thaw, close exactly the opened limits, and wait for HTTP.
use serde_json::{json, Value};
use std::io::{Read, Write};
use std::os::unix::fs::{FileExt, MetadataExt};
use std::sync::atomic::{AtomicU64, AtomicUsize, Ordering};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

const PAGE: u64 = 4096;
const PLANS: &str = "/var/lib/orbit-spike/plans";
const MAGIC: &[u8; 8] = b"OSPLAN1\0";
/// The ADR's bound for a reclaim and for a restore attempt.
const BOUND: Duration = Duration::from_secs(2);
/// Pages per work item, so one big process is split over many threads.
const CHUNK_PAGES: u32 = 2048;
const BATCH: usize = 512;
const SPECIAL: &[&str] = &["[vvar]", "[vdso]", "[vsyscall]", "[vvar_vclock]"];

pub fn now_ns() -> u128 {
    SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_nanos()
}

pub fn cgroup_of(target: &str) -> String {
    format!("/sys/fs/cgroup/system.slice/orbit-spike-target-{target}.service")
}

fn plan_path(target: &str) -> String {
    format!("{PLANS}/{target}.plan")
}

fn limits_path(target: &str) -> String {
    format!("{PLANS}/{target}.limits")
}

fn ms(since: Instant) -> f64 {
    since.elapsed().as_secs_f64() * 1000.0
}

fn subtree(root: &str) -> Vec<String> {
    let mut all = vec![root.to_owned()];
    let mut i = 0;
    while i < all.len() {
        if let Ok(entries) = std::fs::read_dir(&all[i]) {
            for e in entries.flatten() {
                if e.file_type().map(|t| t.is_dir()).unwrap_or(false) {
                    all.push(e.path().to_string_lossy().into_owned());
                }
            }
        }
        i += 1;
    }
    all
}

fn procs(root: &str) -> Vec<i32> {
    let mut pids: Vec<i32> = subtree(root)
        .iter()
        .flat_map(|cg| std::fs::read_to_string(format!("{cg}/cgroup.procs")).unwrap_or_default().lines().filter_map(|l| l.parse().ok()).collect::<Vec<i32>>())
        .collect();
    pids.sort_unstable();
    pids.dedup();
    pids
}

fn start_time(pid: i32) -> Option<u64> {
    let stat = std::fs::read_to_string(format!("/proc/{pid}/stat")).ok()?;
    stat[stat.rfind(')')? + 1..].split_whitespace().nth(19)?.parse().ok()
}

fn boot_id() -> String {
    std::fs::read_to_string("/proc/sys/kernel/random/boot_id").unwrap_or_default().trim().to_owned()
}

fn read_u64(path: &str) -> u64 {
    std::fs::read_to_string(path).ok().and_then(|t| t.trim().parse().ok()).unwrap_or(0)
}

fn stat_field(root: &str, key: &str) -> u64 {
    std::fs::read_to_string(format!("{root}/memory.stat"))
        .unwrap_or_default()
        .lines()
        .find_map(|l| l.strip_prefix(key).and_then(|v| v.strip_prefix(' ')).and_then(|v| v.parse().ok()))
        .unwrap_or(0)
}

pub fn set_frozen(root: &str, frozen: bool) -> Result<(), String> {
    std::fs::write(format!("{root}/cgroup.freeze"), if frozen { "1" } else { "0" }).map_err(|e| format!("freeze write: {e}"))?;
    let want = if frozen { "frozen 1" } else { "frozen 0" };
    let deadline = Instant::now() + BOUND;
    loop {
        if std::fs::read_to_string(format!("{root}/cgroup.events")).unwrap_or_default().lines().any(|l| l == want) {
            return Ok(());
        }
        if Instant::now() > deadline {
            return Err(format!("not {want} within the bound"));
        }
        std::thread::sleep(Duration::from_micros(100));
    }
}

/// Atomic publish: a reader sees the old file or the whole new one, never a half-written plan.
fn publish(path: &str, bytes: &[u8]) -> Result<(), String> {
    let tmp = format!("{path}.tmp");
    let mut f = std::fs::File::create(&tmp).map_err(|e| e.to_string())?;
    f.write_all(bytes).map_err(|e| e.to_string())?;
    f.sync_all().map_err(|e| e.to_string())?;
    std::fs::rename(&tmp, path).map_err(|e| e.to_string())?;
    std::fs::File::open(PLANS).and_then(|d| d.sync_all()).map_err(|e| e.to_string())
}

struct Proc {
    pid: i32,
    start: u64,
    runs: Vec<(u64, u32)>,
}

/// Resident pages of every readable mapping, from maps and pagemap, as runs of consecutive pages.
fn scan(pid: i32) -> Vec<(u64, u32)> {
    let mut runs: Vec<(u64, u32)> = Vec::new();
    let Ok(maps) = std::fs::read_to_string(format!("/proc/{pid}/maps")) else { return runs };
    let Ok(pagemap) = std::fs::File::open(format!("/proc/{pid}/pagemap")) else { return runs };
    let mut buf = vec![0u8; 65536 * 8];
    for line in maps.lines() {
        let mut f = line.split_whitespace();
        let (Some(range), Some(perms)) = (f.next(), f.next()) else { continue };
        let path = f.nth(3).unwrap_or("");
        if !perms.starts_with('r') || SPECIAL.contains(&path) {
            continue;
        }
        let Some((a, b)) = range.split_once('-') else { continue };
        let (Ok(start), Ok(end)) = (u64::from_str_radix(a, 16), u64::from_str_radix(b, 16)) else { continue };
        let mut page = start / PAGE;
        let last = end / PAGE;
        while page < last {
            let n = ((last - page) as usize).min(65536);
            let Ok(read) = pagemap.read_at(&mut buf[..n * 8], page * 8) else { break };
            for i in 0..read / 8 {
                let entry = u64::from_le_bytes(buf[i * 8..i * 8 + 8].try_into().unwrap());
                if entry >> 63 & 1 == 1 {
                    let addr = (page + i as u64) * PAGE;
                    match runs.last_mut() {
                        Some((s, len)) if *s + *len as u64 * PAGE == addr && *len < u32::MAX => *len += 1,
                        _ => runs.push((addr, 1)),
                    }
                }
            }
            if read < n * 8 {
                break;
            }
            page += n as u64;
        }
    }
    runs
}

fn encode(boot: &str, ino: u64, procs: &[Proc]) -> Vec<u8> {
    let mut out = Vec::with_capacity(64 + procs.iter().map(|p| 16 + p.runs.len() * 12).sum::<usize>());
    out.extend_from_slice(MAGIC);
    out.push(boot.len() as u8);
    out.extend_from_slice(boot.as_bytes());
    out.extend_from_slice(&ino.to_le_bytes());
    out.extend_from_slice(&(procs.len() as u32).to_le_bytes());
    for p in procs {
        out.extend_from_slice(&p.pid.to_le_bytes());
        out.extend_from_slice(&p.start.to_le_bytes());
        out.extend_from_slice(&(p.runs.len() as u32).to_le_bytes());
        for (addr, len) in &p.runs {
            out.extend_from_slice(&addr.to_le_bytes());
            out.extend_from_slice(&len.to_le_bytes());
        }
    }
    out
}

fn decode(bytes: &[u8]) -> Option<(String, u64, Vec<Proc>)> {
    let mut at = 0usize;
    let mut take = |n: usize| -> Option<&[u8]> {
        let s = bytes.get(at..at + n)?;
        at += n;
        Some(s)
    };
    if take(8)? != MAGIC {
        return None;
    }
    let blen = take(1)?[0] as usize;
    let boot = String::from_utf8(take(blen)?.to_vec()).ok()?;
    let ino = u64::from_le_bytes(take(8)?.try_into().ok()?);
    let n = u32::from_le_bytes(take(4)?.try_into().ok()?);
    let mut procs = Vec::new();
    for _ in 0..n {
        let pid = i32::from_le_bytes(take(4)?.try_into().ok()?);
        let start = u64::from_le_bytes(take(8)?.try_into().ok()?);
        let r = u32::from_le_bytes(take(4)?.try_into().ok()?);
        let mut runs = Vec::with_capacity(r as usize);
        for _ in 0..r {
            let addr = u64::from_le_bytes(take(8)?.try_into().ok()?);
            let len = u32::from_le_bytes(take(4)?.try_into().ok()?);
            runs.push((addr, len));
        }
        procs.push(Proc { pid, start, runs });
    }
    Some((boot, ino, procs))
}

pub fn sleep(target: &str) -> Value {
    let root = cgroup_of(target);
    let t0 = Instant::now();
    std::fs::create_dir_all(PLANS).ok();
    if std::path::Path::new(&plan_path(target)).exists() {
        return json!({"status":"done","note":"already asleep; the existing plan stands"});
    }
    // 1. Record the swap limits this sleep opens, then open them (before the freeze, as in ADR 0261).
    let cgs = subtree(&root);
    let opened: Vec<(String, String)> = cgs
        .iter()
        .filter_map(|cg| {
            let old = std::fs::read_to_string(format!("{cg}/memory.swap.max")).ok()?.trim().to_owned();
            (old != "max").then(|| (cg.clone(), old))
        })
        .collect();
    let record = opened.iter().map(|(cg, old)| format!("{cg}\t{old}\n")).collect::<String>();
    if let Err(e) = publish(&limits_path(target), record.as_bytes()) {
        return json!({"status":"failed","error":format!("limits record: {e}")});
    }
    for (cg, _) in &opened {
        let _ = std::fs::write(format!("{cg}/memory.swap.max"), "max");
    }
    let t_limits = ms(t0);
    // 2. Freeze the whole subtree.
    let t = Instant::now();
    if let Err(e) = set_frozen(&root, true) {
        return json!({"status":"failed","error":e});
    }
    let t_freeze = ms(t);
    // 3. Scan every process in the subtree, one thread per process.
    let t = Instant::now();
    let pids = procs(&root);
    let mut list: Vec<Proc> = std::thread::scope(|s| {
        let handles: Vec<_> = pids.iter().map(|&pid| s.spawn(move || (pid, start_time(pid), scan(pid)))).collect();
        handles.into_iter().filter_map(|h| h.join().ok()).filter_map(|(pid, st, runs)| Some(Proc { pid, start: st?, runs })).collect()
    });
    list.sort_by_key(|p| p.pid);
    let pages: u64 = list.iter().flat_map(|p| p.runs.iter()).map(|r| r.1 as u64).sum();
    let runs: usize = list.iter().map(|p| p.runs.len()).sum();
    let t_scan = ms(t);
    // 4. Publish the plan.
    let t = Instant::now();
    let ino = std::fs::metadata(&root).map(|m| m.ino()).unwrap_or(0);
    let bytes = encode(&boot_id(), ino, &list);
    if let Err(e) = publish(&plan_path(target), &bytes) {
        return json!({"status":"failed","error":format!("plan: {e}")});
    }
    let t_plan = ms(t);
    // 5. Reclaim until the counters settle or the bound passes. EAGAIN is normal.
    let t = Instant::now();
    let before = (read_u64(&format!("{root}/memory.current")), read_u64(&format!("{root}/memory.swap.current")));
    let mut last = before.0;
    let mut still = 0;
    let mut rounds = 0;
    while t.elapsed() < BOUND && still < 3 {
        let _ = std::fs::write(format!("{root}/memory.reclaim"), last.max(PAGE).to_string());
        rounds += 1;
        let now = read_u64(&format!("{root}/memory.current"));
        still = if last.saturating_sub(now) < (1 << 20) { still + 1 } else { 0 };
        last = now;
    }
    let t_reclaim = ms(t);
    json!({
        "status":"done","processes":list.len(),"pages":pages,"runs":runs,"plan_bytes":bytes.len(),
        "opened_limits":opened.len(),"limits_ms":t_limits,"freeze_ms":t_freeze,"scan_ms":t_scan,"plan_ms":t_plan,
        "reclaim_ms":t_reclaim,"reclaim_rounds":rounds,"total_ms":ms(t0),
        "memory_before":before.0,"swap_before":before.1,
        "memory_after":read_u64(&format!("{root}/memory.current")),"swap_after":read_u64(&format!("{root}/memory.swap.current")),
        "anon_after":stat_field(&root,"anon"),"file_after":stat_field(&root,"file"),
    })
}

struct Work {
    pidfd: i32,
    pid: i32,
    runs: Vec<(u64, u32)>,
}

fn restore_item(w: &Work, done_pages: &AtomicU64, failed_pages: &AtomicU64) {
    let mut iov: Vec<libc::iovec> = Vec::with_capacity(BATCH);
    for chunk in w.runs.chunks(BATCH) {
        iov.clear();
        iov.extend(chunk.iter().map(|(a, n)| libc::iovec { iov_base: *a as *mut _, iov_len: *n as usize * PAGE as usize }));
        unsafe { libc::syscall(libc::SYS_process_madvise, w.pidfd, iov.as_ptr(), iov.len(), libc::MADV_WILLNEED, 0) };
    }
    // One byte per page: the read faults each page in and copies almost nothing.
    let mut local = [0u8; BATCH];
    let mut remote: Vec<libc::iovec> = Vec::with_capacity(BATCH);
    let pages = w.runs.iter().flat_map(|(a, n)| (0..*n as u64).map(move |i| a + i * PAGE));
    let mut flush = |remote: &mut Vec<libc::iovec>| {
        if remote.is_empty() {
            return;
        }
        let liov = libc::iovec { iov_base: local.as_mut_ptr() as *mut _, iov_len: remote.len() };
        let got = unsafe { libc::process_vm_readv(w.pid, &liov, 1, remote.as_ptr(), remote.len() as _, 0) };
        let ok = got.max(0) as u64;
        done_pages.fetch_add(ok, Ordering::Relaxed);
        failed_pages.fetch_add(remote.len() as u64 - ok, Ordering::Relaxed);
        remote.clear();
    };
    for addr in pages {
        remote.push(libc::iovec { iov_base: addr as *mut _, iov_len: 1 });
        if remote.len() == BATCH {
            flush(&mut remote);
        }
    }
    flush(&mut remote);
}

pub fn wake(target: &str, threads: usize, test_pause_ms: Option<u64>) -> Value {
    let root = cgroup_of(target);
    let t0 = Instant::now();
    let start_ns = now_ns();
    let plan = std::fs::read(plan_path(target)).ok().and_then(|b| decode(&b));
    let mut restore = json!({"status":"skipped","reason":"no plan"});
    let mut restore_end_ns = now_ns();
    if let Some((boot, ino, list)) = plan {
        // Identity: no memory syscall before these checks pass.
        let cg_ino = std::fs::metadata(&root).map(|m| m.ino()).unwrap_or(0);
        let mut pidfds = Vec::new();
        let mut mismatch = None;
        if boot != boot_id() || ino != cg_ino {
            mismatch = Some("boot id or cgroup changed".to_owned());
        }
        for p in list.iter().filter(|_| mismatch.is_none()) {
            let fd = unsafe { libc::syscall(libc::SYS_pidfd_open, p.pid, 0) } as i32;
            // The start time is read after pidfd_open, so the pidfd is bound to the recorded process.
            if fd < 0 || start_time(p.pid) != Some(p.start) {
                mismatch = Some(format!("pid {} is not the recorded process", p.pid));
                if fd >= 0 {
                    unsafe { libc::close(fd) };
                }
                break;
            }
            pidfds.push(fd);
        }
        let identity_ms = ms(t0);
        if let Some(reason) = mismatch {
            pidfds.iter().for_each(|fd| unsafe { libc::close(*fd); });
            restore = json!({"status":"skipped","reason":reason,"identity_ms":identity_ms});
        } else {
            let mut work: Vec<Work> = Vec::new();
            for (p, fd) in list.iter().zip(&pidfds) {
                let mut cur: Vec<(u64, u32)> = Vec::new();
                let mut size = 0u32;
                for &(addr, n) in &p.runs {
                    let mut addr = addr;
                    let mut n = n;
                    while n > 0 {
                        let take = n.min(CHUNK_PAGES - size);
                        cur.push((addr, take));
                        size += take;
                        addr += take as u64 * PAGE;
                        n -= take;
                        if size == CHUNK_PAGES {
                            work.push(Work { pidfd: *fd, pid: p.pid, runs: std::mem::take(&mut cur) });
                            size = 0;
                        }
                    }
                }
                if !cur.is_empty() {
                    work.push(Work { pidfd: *fd, pid: p.pid, runs: cur });
                }
            }
            let next = AtomicUsize::new(0);
            let (done, failed) = (AtomicU64::new(0), AtomicU64::new(0));
            let t = Instant::now();
            let deadline = t + BOUND;
            std::thread::scope(|s| {
                for _ in 0..threads.max(1) {
                    s.spawn(|| loop {
                        let i = next.fetch_add(1, Ordering::Relaxed);
                        if i >= work.len() || Instant::now() > deadline {
                            break;
                        }
                        restore_item(&work[i], &done, &failed);
                    });
                }
            });
            let secs = t.elapsed().as_secs_f64();
            restore_end_ns = now_ns();
            pidfds.iter().for_each(|fd| unsafe { libc::close(*fd); });
            let pages = done.load(Ordering::Relaxed);
            let complete = next.load(Ordering::Relaxed) >= work.len();
            restore = json!({
                "status": if complete { "complete" } else { "partial" }, "identity_ms":identity_ms,
                "pages":pages,"failed_pages":failed.load(Ordering::Relaxed),"items":work.len(),"threads":threads,
                "restore_ms":secs*1000.0,"mb_per_s":pages as f64 * PAGE as f64 / 1048576.0 / secs.max(1e-9),
            });
        }
    }
    if let Some(ms) = test_pause_ms {
        eprintln!("test pause {ms} ms after restore of {target}");
        std::thread::sleep(Duration::from_millis(ms));
    }
    if let Err(e) = set_frozen(&root, false) {
        return json!({"status":"failed","error":e,"restore":restore});
    }
    let thawed_ns = now_ns();
    // Close exactly the limits the sleep opened, from its record.
    let mut closed = 0;
    for line in std::fs::read_to_string(limits_path(target)).unwrap_or_default().lines() {
        if let Some((cg, old)) = line.split_once('\t') {
            if std::fs::write(format!("{cg}/memory.swap.max"), old).is_ok() {
                closed += 1;
            }
        }
    }
    let limits_closed_ns = now_ns();
    let port: u16 = 8000 + target.parse::<u16>().unwrap_or(0) % 1000;
    let deadline = Instant::now() + Duration::from_secs(5);
    let ready = loop {
        if http_ok(port) {
            break true;
        }
        if Instant::now() > deadline {
            break false;
        }
        std::thread::sleep(Duration::from_millis(1));
    };
    let ready_ns = now_ns();
    let _ = std::fs::remove_file(plan_path(target));
    let _ = std::fs::remove_file(limits_path(target));
    json!({
        "status": if ready { "done" } else { "failed" }, "restore":restore, "limits_closed":closed,
        "wake_start_ns":start_ns.to_string(),"restore_end_ns":restore_end_ns.to_string(),"thawed_ns":thawed_ns.to_string(),
        "limits_closed_ns":limits_closed_ns.to_string(),"ready_ns":ready_ns.to_string(),"total_ms":ms(t0),
    })
}

fn http_ok(port: u16) -> bool {
    let Ok(mut s) = std::net::TcpStream::connect_timeout(&([127, 0, 0, 1], port).into(), Duration::from_millis(500)) else { return false };
    let _ = s.set_read_timeout(Some(Duration::from_millis(1000)));
    if s.write_all(b"GET / HTTP/1.0\r\nHost: localhost\r\n\r\n").is_err() {
        return false;
    }
    let mut head = [0u8; 12];
    s.read_exact(&mut head).is_ok() && head.ends_with(b" 200")
}
