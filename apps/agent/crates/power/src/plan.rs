//! Bounded, whole-subtree pagemap capture. Bits 63 and 62 both belong in the next wake.
use crate::{invalid, read, Identity};
use serde::{Deserialize, Serialize};
use std::collections::BTreeSet;
use std::fs::{self, File};
use std::io;
use std::os::unix::fs::{FileExt as _, MetadataExt as _};
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Mutex;
use std::time::Instant;

pub(crate) const MAX_METADATA: usize = 64 << 20;
const MAX_PROCESSES: usize = 4096;
const MAX_RANGES: usize = 1_048_576;
const MAX_BYTES: u64 = 8 << 30;

#[derive(Debug, Serialize, Deserialize, PartialEq, Eq)]
pub(crate) struct Range {
    pub start: u64,
    pub length: u64,
}
#[derive(Debug, Serialize, Deserialize, PartialEq, Eq)]
pub(crate) struct Process {
    pub pid: u32,
    pub start_ticks: u64,
    pub ranges: Vec<Range>,
}
#[derive(Debug, Serialize, Deserialize)]
pub(crate) struct Plan {
    pub version: u32,
    pub operation: String,
    pub identity: Identity,
    pub init_pid: u32,
    pub init_start_ticks: u64,
    pub page_size: u64,
    pub processes: Vec<Process>,
}

pub(crate) fn subtree(root: &Path) -> io::Result<Vec<PathBuf>> {
    let mut paths = vec![root.to_owned()];
    let mut index = 0;
    while index < paths.len() {
        for entry in fs::read_dir(&paths[index])? {
            let entry = entry?;
            if entry.file_type()?.is_dir() {
                paths.push(entry.path());
            }
        }
        index += 1;
    }
    paths.sort();
    Ok(paths)
}

pub(crate) fn ticks(proc: &Path, pid: u32) -> io::Result<u64> {
    let stat = read(&proc.join(pid.to_string()).join("stat"))?;
    stat.rsplit_once(") ")
        .and_then(|(_, fields)| fields.split_whitespace().nth(19))
        .and_then(|s| s.parse().ok())
        .ok_or_else(|| invalid("invalid process stat"))
}

fn runs(file: &File, start: u64, end: u64, page: u64, deadline: Instant) -> io::Result<Vec<Range>> {
    let mut ranges: Vec<Range> = Vec::new();
    let mut address = start;
    let mut buffer = vec![0; 65536 * 8];
    while address < end {
        if Instant::now() >= deadline {
            return Err(invalid("prepare_timeout"));
        }
        let count =
            usize::try_from(((end - address) / page).min(65536)).map_err(io::Error::other)?;
        if count == 0 {
            break;
        }
        file.read_exact_at(&mut buffer[..count * 8], (address / page) * 8)?;
        for entry in buffer[..count * 8].chunks_exact(8) {
            // The high byte contains present (63) and swapped (62); never inspect the PFN.
            if entry[7] & 0xc0 != 0 {
                match ranges.last_mut() {
                    Some(last) if last.start + last.length == address => last.length += page,
                    _ => ranges.push(Range {
                        start: address,
                        length: page,
                    }),
                }
            }
            address += page;
        }
        if ranges.len() > MAX_RANGES {
            return Err(invalid("bound_ranges"));
        }
    }
    Ok(ranges)
}

fn capture_process(proc: &Path, pid: u32, page: u64, deadline: Instant) -> io::Result<Process> {
    let start_ticks = ticks(proc, pid)?;
    let base = proc.join(pid.to_string());
    let maps = read(&base.join("maps"))?;
    let pagemap = File::open(base.join("pagemap"))?;
    let mut ranges = Vec::new();
    for line in maps.lines() {
        let fields: Vec<_> = line.split_whitespace().collect();
        if fields.len() < 5
            || !fields[1].starts_with('r')
            || fields
                .get(5)
                .is_some_and(|s| ["[vvar]", "[vvar_vclock]", "[vdso]", "[vsyscall]"].contains(s))
        {
            continue;
        }
        let (low, high) = fields[0]
            .split_once('-')
            .ok_or_else(|| invalid("invalid maps"))?;
        let low = u64::from_str_radix(low, 16).map_err(io::Error::other)?;
        let high = u64::from_str_radix(high, 16).map_err(io::Error::other)?;
        if low >= high || high > 1 << 56 || low % page != 0 || high % page != 0 {
            return Err(invalid("invalid mapping bounds"));
        }
        ranges.extend(runs(&pagemap, low, high, page, deadline)?);
        if ranges.len() > MAX_RANGES {
            return Err(invalid("bound_ranges"));
        }
    }
    if ticks(proc, pid)? != start_ticks {
        return Err(invalid("process changed while frozen"));
    }
    Ok(Process {
        pid,
        start_ticks,
        ranges,
    })
}

pub(crate) fn capture(
    proc: &Path,
    root: &Path,
    deadline: Instant,
) -> io::Result<(u64, Vec<Process>)> {
    let mut pids = BTreeSet::new();
    for path in subtree(root)? {
        match read(&path.join("cgroup.procs")) {
            Ok(procs) => {
                for pid in procs.split_whitespace() {
                    pids.insert(pid.parse::<u32>().map_err(io::Error::other)?);
                }
            }
            Err(error) if error.raw_os_error() == Some(libc::EOPNOTSUPP) => {
                // A threaded cgroup exposes threads, not cgroup.procs. Resolve each to its process.
                for tid in read(&path.join("cgroup.threads"))?.split_whitespace() {
                    let tid = tid.parse::<u32>().map_err(io::Error::other)?;
                    let status = read(&proc.join(tid.to_string()).join("status"))?;
                    let pid = status
                        .lines()
                        .find_map(|line| line.strip_prefix("Tgid:"))
                        .and_then(|s| s.trim().parse::<u32>().ok())
                        .ok_or_else(|| invalid("missing thread process id"))?;
                    pids.insert(pid);
                }
            }
            Err(error) => return Err(error),
        }
    }
    if pids.len() > MAX_PROCESSES {
        return Err(invalid("bound_processes"));
    }
    let pids: Vec<_> = pids.into_iter().collect();
    // SAFETY: sysconf with this constant has no pointer arguments and does not mutate Rust memory.
    let page =
        u64::try_from(unsafe { libc::sysconf(libc::_SC_PAGESIZE) }).map_err(io::Error::other)?;
    if page == 0 {
        return Err(invalid("invalid page size"));
    }
    let next = AtomicUsize::new(0);
    let results = Mutex::new(Vec::new());
    std::thread::scope(|scope| {
        for _ in 0..pids.len().min(16) {
            scope.spawn(|| loop {
                let index = next.fetch_add(1, Ordering::Relaxed);
                let Some(pid) = pids.get(index) else {
                    break;
                };
                let result = capture_process(proc, *pid, page, deadline);
                results
                    .lock()
                    .expect("capture result lock poisoned")
                    .push(result);
            });
        }
    });
    let mut processes = results
        .into_inner()
        .expect("capture result lock poisoned")
        .into_iter()
        .collect::<io::Result<Vec<_>>>()?;
    processes.sort_by_key(|p| p.pid);
    let ranges: usize = processes.iter().map(|p| p.ranges.len()).sum();
    let bytes: u64 = processes
        .iter()
        .flat_map(|p| &p.ranges)
        .map(|r| r.length)
        .sum();
    let limit = read(&root.join("memory.max"))?;
    let limit = if limit == "max" {
        MAX_BYTES
    } else {
        limit
            .parse::<u64>()
            .map_err(io::Error::other)?
            .min(MAX_BYTES)
    };
    if ranges > MAX_RANGES {
        return Err(invalid("bound_ranges"));
    }
    if bytes > limit {
        return Err(invalid("bound_residency"));
    }
    Ok((page, processes))
}

pub(crate) fn cgroup_identity(root: &Path) -> io::Result<(u64, u64)> {
    let meta = fs::metadata(root)?;
    Ok((meta.dev(), meta.ino()))
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::Write as _;
    use std::time::Duration;
    #[test]
    fn plan_contains_present_and_swapped_pages_and_excludes_absent_pages() {
        let mut file = tempfile::tempfile().expect("file");
        for entry in [1_u64 << 63, 1 << 62, 0, (1 << 63) | (1 << 62)] {
            file.write_all(&entry.to_le_bytes()).expect("pagemap");
        }
        assert_eq!(
            runs(
                &file,
                0,
                16384,
                4096,
                Instant::now() + Duration::from_secs(1)
            )
            .expect("runs"),
            vec![
                Range {
                    start: 0,
                    length: 8192
                },
                Range {
                    start: 12288,
                    length: 4096
                }
            ]
        );
    }
    #[test]
    fn short_pagemap_never_publishes_an_incomplete_plan() {
        assert!(runs(
            &tempfile::tempfile().expect("file"),
            0,
            4096,
            4096,
            Instant::now() + Duration::from_secs(1)
        )
        .is_err());
    }
}
