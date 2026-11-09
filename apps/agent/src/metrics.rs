//! Read-only Linux metrics observations. CPU ratios compare per-core counters between host ticks;
//! every other value comes from procfs or statvfs. No collector program or network request is run.

use serde_json::{json, Value};
use std::collections::BTreeMap;
use std::fs;
use std::sync::Arc;
use std::time::Duration;
use tokio::{sync::watch, task::JoinHandle};

const DISK_TIMEOUT: Duration = Duration::from_millis(100);

#[derive(Clone, Debug)]
pub struct Disk {
    pub filesystem: crate::facts::Filesystem,
    used: u64,
}

impl Disk {
    fn data(&self) -> Value {
        json!({"mount": self.filesystem.mount, "used": self.used, "total": self.filesystem.size})
    }
}

/// A single blocking syscall may outlive its deadline. Keep its handle until it exits;
/// abandoning it and starting another would accumulate stuck threads on a dead mount.
#[derive(Default)]
struct DiskSampler {
    pending: Option<JoinHandle<Option<Disk>>>,
}

impl DiskSampler {
    async fn sample(
        &mut self,
        mounts: Vec<String>,
        read: Arc<dyn Fn(String) -> Option<Disk> + Send + Sync>,
    ) -> Vec<Disk> {
        if let Some(pending) = self.pending.as_ref() {
            if !pending.is_finished() {
                return Vec::new();
            }
            // Discard a late result: it belongs to the expired observation.
            let _ = self.pending.take().unwrap().await;
        }
        let mut disks = Vec::new();
        for mount in mounts {
            let read = read.clone();
            let mut pending = tokio::task::spawn_blocking(move || read(mount));
            match tokio::time::timeout(DISK_TIMEOUT, &mut pending).await {
                Ok(Ok(Some(disk))) => disks.push(disk),
                Ok(_) => {}
                Err(_) => {
                    self.pending = Some(pending);
                    break;
                }
            }
        }
        disks
    }
}

/// The facts loop only reads this channel. Neither a stalled mount nor a timed-out
/// blocking thread can delay units, retries, or the next metrics observation.
pub fn watch_disks() -> watch::Receiver<Vec<Disk>> {
    watch_disks_with(
        || {
            fs::File::open("/proc/self/mounts")
                .map(crate::facts::data_mounts)
                .unwrap_or_default()
        },
        Arc::new(read_disk),
    )
}

fn watch_disks_with(
    mounts: impl Fn() -> Vec<String> + Send + 'static,
    read: Arc<dyn Fn(String) -> Option<Disk> + Send + Sync>,
) -> watch::Receiver<Vec<Disk>> {
    let (sender, receiver) = watch::channel(Vec::new());
    tokio::spawn(async move {
        let mut sampler = DiskSampler::default();
        let mut tick = tokio::time::interval(crate::facts::HOST_INTERVAL);
        tick.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Delay);
        loop {
            tokio::select! {
                _ = sender.closed() => break,
                _ = tick.tick() => {}
            }
            let disks = sampler.sample(root_first(mounts()), read.clone()).await;
            if sender.send(disks).is_err() {
                break;
            }
        }
    });
    receiver
}

fn read_disk(mount: String) -> Option<Disk> {
    let stat = rustix::fs::statvfs(mount.as_str()).ok()?;
    Some(Disk {
        filesystem: crate::facts::Filesystem {
            mount,
            size: stat.f_blocks.saturating_mul(stat.f_frsize),
            free: stat.f_bavail.saturating_mul(stat.f_frsize),
        },
        used: stat
            .f_blocks
            .saturating_sub(stat.f_bfree)
            .saturating_mul(stat.f_frsize),
    })
}

fn root_first(mut mounts: Vec<String>) -> Vec<String> {
    mounts.sort_by_key(|mount| mount != "/");
    mounts
}

#[derive(Default)]
pub struct Sampler {
    previous: BTreeMap<u32, (u64, u64)>,
}

impl Sampler {
    pub fn new() -> Self {
        Self {
            previous: fs::read_to_string("/proc/stat")
                .map(|stat| cpu_counters(&stat))
                .unwrap_or_default(),
        }
    }

    /// An incomplete reading sends nothing and leaves the Gateway's last good snapshot intact.
    pub fn observe(&mut self, disks: &[Disk]) -> Option<Value> {
        let stat = fs::read_to_string("/proc/stat").ok()?;
        let meminfo = fs::read_to_string("/proc/meminfo").ok()?;
        let loadavg = fs::read_to_string("/proc/loadavg").ok()?;
        let uptime = fs::read_to_string("/proc/uptime").ok()?;
        let mut data = self.snapshot(&stat, &meminfo, &loadavg, &uptime)?;
        data["pressure"] = json!({
            "cpu": {"some_avg10": pressure(&fs::read_to_string("/proc/pressure/cpu").unwrap_or_default())},
            "memory": {"some_avg10": pressure(&fs::read_to_string("/proc/pressure/memory").unwrap_or_default())},
            "io": {"some_avg10": pressure(&fs::read_to_string("/proc/pressure/io").unwrap_or_default())},
        });
        data["disks"] = Value::Array(disks.iter().map(Disk::data).collect());
        Some(data)
    }

    fn snapshot(
        &mut self,
        stat: &str,
        meminfo: &str,
        loadavg: &str,
        uptime: &str,
    ) -> Option<Value> {
        let counters = cpu_counters(stat);
        if counters.is_empty() {
            return None;
        }
        let cores: Vec<f64> = counters
            .iter()
            .map(|(core, (total, idle))| {
                // A newly online core starts a new interval rather than using counters since boot.
                let (old_total, old_idle) = self
                    .previous
                    .get(core)
                    .unwrap_or(&(*total, *idle))
                    .to_owned();
                let elapsed = total.saturating_sub(old_total);
                if elapsed == 0 {
                    0.0
                } else {
                    let idle = idle.saturating_sub(old_idle).min(elapsed);
                    (elapsed - idle) as f64 / elapsed as f64
                }
            })
            .collect();
        self.previous = counters;
        let memory: BTreeMap<&str, u64> = meminfo
            .lines()
            .filter_map(|line| {
                let (key, value) = line.split_once(':')?;
                Some((
                    key,
                    value
                        .split_whitespace()
                        .next()?
                        .parse::<u64>()
                        .ok()?
                        .saturating_mul(1024),
                ))
            })
            .collect();
        let total = *memory.get("MemTotal")?;
        let available = *memory.get("MemAvailable")?;
        let swap = *memory.get("SwapTotal")?;
        let free_swap = *memory.get("SwapFree")?;
        let load: Vec<f64> = loadavg
            .split_whitespace()
            .take(3)
            .map(str::parse)
            .collect::<Result<_, _>>()
            .ok()?;
        if load.len() != 3 || load.iter().any(|value| !value.is_finite() || *value < 0.0) {
            return None;
        }
        let uptime = uptime.split_whitespace().next()?.parse::<f64>().ok()?;
        if !uptime.is_finite() || uptime < 0.0 {
            return None;
        }
        Some(json!({
            "cores": cores,
            "memory": {"used": total.saturating_sub(available), "total": total},
            "swap": {"used": swap.saturating_sub(free_swap), "total": swap},
            "load": {"one": load[0], "five": load[1], "fifteen": load[2]},
            "uptime_seconds": uptime as u64,
        }))
    }
}

fn cpu_counters(stat: &str) -> BTreeMap<u32, (u64, u64)> {
    stat.lines()
        .filter_map(|line| {
            let mut fields = line.split_whitespace();
            let core = fields.next()?.strip_prefix("cpu")?.parse::<u32>().ok()?;
            // guest and guest_nice are already counted in user and nice.
            let times = fields
                .take(8)
                .map(str::parse)
                .collect::<Result<Vec<u64>, _>>()
                .ok()?;
            if times.len() < 4 {
                return None;
            }
            let total = times.iter().copied().fold(0_u64, u64::saturating_add);
            Some((
                core,
                (total, times[3].saturating_add(*times.get(4).unwrap_or(&0))),
            ))
        })
        .collect()
}

fn pressure(input: &str) -> f64 {
    input
        .lines()
        .find_map(|line| {
            let mut fields = line.split_whitespace();
            if fields.next()? != "some" {
                return None;
            }
            fields.find_map(|field| field.strip_prefix("avg10=")?.parse::<f64>().ok())
        })
        .filter(|value| value.is_finite())
        .unwrap_or(0.0)
        .clamp(0.0, 100.0)
}

#[cfg(test)]
mod tests {
    use super::*;

    const MEMORY: &str =
        "MemTotal: 1024 kB\nMemAvailable: 400 kB\nSwapTotal: 512 kB\nSwapFree: 500 kB\n";

    #[test]
    fn ratios_use_per_core_deltas_without_double_counting_guest_time() {
        let mut sampler = Sampler::default();
        sampler
            .snapshot(
                "cpu0 10 0 0 90 0 0 0 0 8 0\ncpu1 0 0 0 100",
                MEMORY,
                "0 0 0",
                "40",
            )
            .unwrap();
        let data = sampler
            .snapshot(
                "cpu0 30 0 0 170 0 0 0 0 12 0\ncpu1 40 0 10 140",
                MEMORY,
                "0.2 0.3 0.4 1/2 20",
                "101.9 40",
            )
            .unwrap();
        assert_eq!(data["cores"], json!([0.2, 50.0 / 90.0]));
        assert_eq!(
            data["memory"],
            json!({"used": 624 * 1024, "total": 1024 * 1024})
        );
        assert_eq!(
            data["swap"],
            json!({"used": 12 * 1024, "total": 512 * 1024})
        );
        assert_eq!(
            data["load"],
            json!({"one": 0.2, "five": 0.3, "fifteen": 0.4})
        );
        assert_eq!(data["uptime_seconds"], 101);
    }

    #[test]
    fn new_reset_and_idle_counters_stay_in_range() {
        let mut sampler = Sampler::default();
        for stat in [
            "cpu0 40 0 10 140",
            "cpu0 1 0 1 1\ncpu1 100 0 0 0",
            "cpu0 1 0 1 1\ncpu1 100 0 0 50",
        ] {
            let data = sampler.snapshot(stat, MEMORY, "0 0 0", "1").unwrap();
            assert_eq!(
                data["cores"],
                if stat.contains("cpu1") {
                    json!([0.0, 0.0])
                } else {
                    json!([0.0])
                }
            );
        }
    }

    #[test]
    fn disks_put_root_first_when_mount_input_lists_a_data_mount_before_it() {
        let mounts = crate::facts::data_mounts(
            "/dev/data /tmp ext4 rw 0 0\n/dev/root / ext4 rw 0 0\n".as_bytes(),
        );
        assert_eq!(root_first(mounts), vec!["/", "/tmp"]);
    }

    #[tokio::test]
    async fn a_stalled_mount_expires_while_other_facts_flow_and_only_one_call_stays_in_flight() {
        use std::sync::{
            atomic::{AtomicUsize, Ordering},
            Mutex,
        };

        let calls = Arc::new(AtomicUsize::new(0));
        let counted = calls.clone();
        let (started, waiting) = tokio::sync::oneshot::channel();
        let started = Mutex::new(Some(started));
        let (release, stalled) = std::sync::mpsc::channel::<()>();
        let stalled = Mutex::new(stalled);
        let mut disks = watch_disks_with(
            || vec!["/stalled".into(), "/".into()],
            Arc::new(move |mount| {
                counted.fetch_add(1, Ordering::SeqCst);
                if mount == "/stalled" {
                    if let Some(started) = started.lock().unwrap().take() {
                        let _ = started.send(());
                    }
                    // Dropping `release` on a failed assertion also releases the fake syscall.
                    let _ = stalled.lock().unwrap().recv();
                }
                Some(Disk {
                    filesystem: crate::facts::Filesystem {
                        mount,
                        size: 100,
                        free: 30,
                    },
                    used: 60,
                })
            }),
        );
        tokio::time::timeout(Duration::from_secs(1), waiting)
            .await
            .unwrap()
            .unwrap();

        // The pass expires with its healthy entry even though the syscall stays blocked.
        tokio::time::timeout(Duration::from_secs(1), disks.changed())
            .await
            .unwrap()
            .unwrap();
        assert_eq!(
            disks
                .borrow_and_update()
                .iter()
                .map(Disk::data)
                .collect::<Vec<_>>(),
            vec![json!({"mount": "/", "used": 60, "total": 100})]
        );

        // Real fact requests still reach the Gateway while the fake statvfs is blocked.
        let (received, mut facts) = tokio::sync::mpsc::unbounded_channel();
        let app = axum::Router::new().route(
            "/api/v1/agent/facts",
            axum::routing::post(move |axum::Json(body): axum::Json<Value>| {
                received.send(body).unwrap();
                async { axum::http::StatusCode::NO_CONTENT }
            }),
        );
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let url = format!("http://{}", listener.local_addr().unwrap());
        let server = tokio::spawn(async move { axum::serve(listener, app).await.unwrap() });
        let mut reporter = crate::facts::Reporter::new(reqwest::Client::new(), &url);
        for kind in ["units", "metrics", "host"] {
            tokio::time::timeout(
                Duration::from_secs(1),
                reporter.report(kind, None, &json!({})),
            )
            .await
            .unwrap();
            assert_eq!(facts.recv().await.unwrap()["kind"], kind);
        }

        // Later passes omit disks instead of queuing more blocking syscalls.
        tokio::time::pause();
        tokio::time::advance(crate::facts::HOST_INTERVAL).await;
        disks.changed().await.unwrap();
        tokio::time::resume();
        assert!(disks.borrow_and_update().is_empty());
        assert_eq!(calls.load(Ordering::SeqCst), 2);
        // A blocked call also cannot prevent a later unit report.
        reporter
            .report("units", None, &json!({"changed": true}))
            .await;
        assert_eq!(facts.recv().await.unwrap()["kind"], "units");

        drop(release);
        drop(disks);
        server.abort();
    }

    #[test]
    fn pressure_reads_some_avg10_and_is_zero_without_kernel_data() {
        assert_eq!(
            pressure("some avg10=1.25 avg60=2.5 total=123\nfull avg10=0.2"),
            1.25
        );
        for input in ["", "full avg10=2.3", "some avg10=NaN", "some total=3"] {
            assert_eq!(pressure(input), 0.0);
        }
    }

    #[test]
    fn missing_or_invalid_required_readings_do_not_produce_a_zero_snapshot() {
        let mut sampler = Sampler::default();
        assert!(sampler.snapshot("", MEMORY, "0 0 0", "1").is_none());
        assert!(sampler.snapshot("cpu0 1 2 3 4", "", "0 0 0", "1").is_none());
        assert!(sampler
            .snapshot("cpu0 1 2 3 4", MEMORY, "NaN 0 0", "1")
            .is_none());
        assert!(sampler
            .snapshot("cpu0 1 2 3 4", MEMORY, "0 0 0", "bad")
            .is_none());
    }
}
