//! Read-only Linux metrics observations. CPU ratios compare per-core counters between host ticks;
//! every other value comes from procfs or statvfs. No collector program or network request is run.

use futures_util::{future::select_all, FutureExt};
use serde_json::{json, Value};
use std::collections::BTreeMap;
use std::fs;
use std::sync::Arc;
use std::time::Duration;
use tokio::{sync::watch, task::JoinHandle};

const DISK_TIMEOUT: Duration = Duration::from_millis(100);

#[derive(Clone, Debug)]
struct DiskReading {
    used: u64,
    total: u64,
    available: u64,
    observed_at: String,
}

#[derive(Clone, Debug)]
pub struct Disk {
    mount: String,
    last_good: Option<DiskReading>,
    stale: bool,
}

impl Disk {
    fn data(&self) -> Value {
        json!({
            "mount": self.mount,
            "used": self.last_good.as_ref().map(|disk| disk.used),
            "total": self.last_good.as_ref().map(|disk| disk.total),
            "observed_at": self.last_good.as_ref().map(|disk| &disk.observed_at),
            "stale": self.stale,
        })
    }

    pub fn filesystem(&self) -> Option<crate::facts::Filesystem> {
        let disk = self.last_good.as_ref()?;
        Some(crate::facts::Filesystem {
            mount: self.mount.clone(),
            size: disk.total,
            free: disk.available,
            observed_at: disk.observed_at.clone(),
            stale: self.stale,
        })
    }
}

type DiskRead = Arc<dyn Fn(String) -> Option<DiskReading> + Send + Sync>;

#[derive(Default)]
struct MountSample {
    last_good: Option<DiskReading>,
    stale: bool,
    pending: Option<JoinHandle<Option<DiskReading>>>,
}

impl MountSample {
    fn finish(&mut self, result: Result<Option<DiskReading>, tokio::task::JoinError>) {
        self.pending = None;
        self.stale = true;
        if let Ok(Some(disk)) = result {
            self.last_good = Some(disk);
            self.stale = false;
        }
    }
}

/// One pass at a time; one outstanding call per mount. A blocked mount retains
/// its last good reading and cannot prevent another mount from being refreshed.
#[derive(Default)]
struct DiskSampler {
    mounts: BTreeMap<String, MountSample>,
    current: Vec<String>,
}

impl DiskSampler {
    async fn sample(&mut self, mounts: Vec<String>, read: DiskRead) {
        self.current = root_first(mounts);
        // A retired mount's blocked syscall still occupies its slot until it exits.
        // Mount churn can therefore never accumulate more than 64 outstanding calls.
        self.mounts.retain(|mount, sample| {
            self.current.contains(mount)
                || sample
                    .pending
                    .as_ref()
                    .is_some_and(|call| !call.is_finished())
        });
        for mount in &self.current {
            if !self.mounts.contains_key(mount) {
                if self.mounts.len() == crate::facts::MAX_FILESYSTEMS {
                    continue;
                }
                self.mounts.insert(mount.clone(), MountSample::default());
            }
            let sample = self.mounts.get_mut(mount).unwrap();
            if let Some(call) = sample.pending.as_ref() {
                if call.is_finished() {
                    let result = sample.pending.take().unwrap().await;
                    sample.finish(result);
                }
                continue;
            }
            let read = read.clone();
            let path = mount.clone();
            let mut call = tokio::task::spawn_blocking(move || read(path));
            match tokio::time::timeout(DISK_TIMEOUT, &mut call).await {
                Ok(result) => sample.finish(result),
                Err(_) => {
                    sample.stale = true;
                    sample.pending = Some(call);
                }
            }
        }
    }

    /// Consume a late success as soon as it arrives, without waiting for another tick.
    async fn completed(&mut self) {
        let calls: Vec<_> = self
            .mounts
            .iter_mut()
            .filter_map(|(mount, sample)| {
                sample
                    .pending
                    .as_mut()
                    .map(|call| async move { (mount.clone(), call.await) }.boxed())
            })
            .collect();
        if calls.is_empty() {
            std::future::pending::<()>().await;
            return;
        }
        let ((mount, result), _, _) = select_all(calls).await;
        self.mounts.get_mut(&mount).unwrap().finish(result);
    }

    fn snapshot(&self) -> Vec<Disk> {
        self.current
            .iter()
            .map(|mount| {
                let sample = self.mounts.get(mount);
                Disk {
                    mount: mount.clone(),
                    last_good: sample.and_then(|sample| sample.last_good.clone()),
                    stale: sample.is_none_or(|sample| sample.stale),
                }
            })
            .collect()
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
    read: DiskRead,
) -> watch::Receiver<Vec<Disk>> {
    let (sender, receiver) = watch::channel(Vec::new());
    tokio::spawn(async move {
        let mut sampler = DiskSampler::default();
        let mut tick = tokio::time::interval(crate::facts::HOST_INTERVAL);
        tick.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Delay);
        loop {
            tokio::select! {
                _ = sender.closed() => break,
                _ = tick.tick() => sampler.sample(mounts(), read.clone()).await,
                _ = sampler.completed() => {}
            }
            if sender.send(sampler.snapshot()).is_err() {
                break;
            }
        }
    });
    receiver
}

fn read_disk(mount: String) -> Option<DiskReading> {
    let stat = rustix::fs::statvfs(mount.as_str()).ok()?;
    Some(DiskReading {
        total: stat.f_blocks.saturating_mul(stat.f_frsize),
        available: stat.f_bavail.saturating_mul(stat.f_frsize),
        used: stat
            .f_blocks
            .saturating_sub(stat.f_bfree)
            .saturating_mul(stat.f_frsize),
        observed_at: crate::facts::observed_at(),
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

    fn reading(used: u64, second: u64) -> DiskReading {
        DiskReading {
            used,
            total: 100,
            available: 30,
            observed_at: format!("2026-11-09T00:00:{second:02}Z"),
        }
    }

    #[tokio::test]
    async fn a_hung_mount_keeps_its_last_good_reading_while_healthy_disks_and_other_facts_refresh()
    {
        use std::sync::{
            atomic::{AtomicUsize, Ordering},
            Mutex,
        };

        let hung_calls = Arc::new(AtomicUsize::new(0));
        let counted = hung_calls.clone();
        let healthy_calls = Arc::new(AtomicUsize::new(0));
        let healthy = healthy_calls.clone();
        let (started, waiting) = tokio::sync::oneshot::channel();
        let started = Mutex::new(Some(started));
        let (release, stalled) = std::sync::mpsc::channel::<()>();
        let stalled = Mutex::new(stalled);
        let mut disks = watch_disks_with(
            || vec!["/hung".into(), "/".into()],
            Arc::new(move |mount| {
                if mount == "/hung" {
                    let call = counted.fetch_add(1, Ordering::SeqCst);
                    if call > 0 {
                        if let Some(started) = started.lock().unwrap().take() {
                            let _ = started.send(());
                        }
                        // Dropping `release` also releases the syscall after a failed assertion.
                        let _ = stalled.lock().unwrap().recv();
                    }
                    Some(reading(10, 1))
                } else {
                    let call = healthy.fetch_add(1, Ordering::SeqCst) as u64 + 1;
                    Some(reading(call, call))
                }
            }),
        );
        tokio::time::timeout(Duration::from_secs(1), disks.changed())
            .await
            .unwrap()
            .unwrap();
        let initial = disks.borrow_and_update().clone();
        assert_eq!(initial.len(), 2);
        assert!(!initial[0].stale && !initial[1].stale);
        let hung = initial[1].data();

        tokio::time::pause();
        tokio::time::advance(crate::facts::HOST_INTERVAL).await;
        tokio::time::resume();
        tokio::time::timeout(Duration::from_secs(1), waiting)
            .await
            .unwrap()
            .unwrap();

        // Real fact requests still reach the Gateway while the second disk call is blocked.
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
        tokio::time::timeout(Duration::from_secs(1), disks.changed())
            .await
            .unwrap()
            .unwrap();
        let snapshot = disks.borrow_and_update().clone();
        assert_eq!(
            snapshot[0].data(),
            json!({
                "mount": "/", "used": 2, "total": 100,
                "observed_at": "2026-11-09T00:00:02Z", "stale": false,
            })
        );
        let mut stale = hung.clone();
        stale["stale"] = json!(true);
        assert_eq!(snapshot[1].data(), stale);
        let filesystem = snapshot[1].filesystem().unwrap();
        assert_eq!(filesystem.observed_at, hung["observed_at"]);
        assert!(filesystem.stale);

        // Another pass refreshes the healthy root again, without retrying the blocked mount.
        tokio::time::pause();
        tokio::time::advance(crate::facts::HOST_INTERVAL).await;
        tokio::time::resume();
        tokio::time::timeout(Duration::from_secs(1), disks.changed())
            .await
            .unwrap()
            .unwrap();
        let snapshot = disks.borrow_and_update().clone();
        assert_eq!(snapshot[0].data()["used"], 3);
        assert!(!snapshot[0].stale);
        assert_eq!(snapshot[1].data(), stale);
        assert_eq!(hung_calls.load(Ordering::SeqCst), 2);
        assert_eq!(healthy_calls.load(Ordering::SeqCst), 3);
        drop(release);
        drop(disks);
        server.abort();
    }

    #[tokio::test]
    async fn a_slow_disk_publishes_late_successes_with_their_own_timestamp() {
        use std::sync::{
            atomic::{AtomicUsize, Ordering},
            Mutex,
        };
        let calls = Arc::new(AtomicUsize::new(0));
        let counted = calls.clone();
        let (release, stalled) = std::sync::mpsc::channel::<()>();
        let stalled = Mutex::new(stalled);
        let mut disks = watch_disks_with(
            || vec!["/slow".into()],
            Arc::new(move |_| {
                let call = counted.fetch_add(1, Ordering::SeqCst) as u64 + 1;
                let _ = stalled.lock().unwrap().recv();
                Some(reading(call, call))
            }),
        );
        tokio::time::timeout(Duration::from_secs(1), disks.changed())
            .await
            .unwrap()
            .unwrap();
        assert_eq!(
            disks.borrow_and_update()[0].data(),
            json!({
                "mount": "/slow", "used": null, "total": null, "observed_at": null, "stale": true,
            })
        );
        release.send(()).unwrap();
        // A late answer is published immediately, without advancing to the next host tick.
        tokio::time::timeout(Duration::from_secs(1), disks.changed())
            .await
            .unwrap()
            .unwrap();
        let first = disks.borrow_and_update()[0].data();
        assert_eq!(
            first,
            json!({
                "mount": "/slow", "used": 1, "total": 100,
                "observed_at": "2026-11-09T00:00:01Z", "stale": false,
            })
        );
        assert_eq!(calls.load(Ordering::SeqCst), 1);

        tokio::time::pause();
        tokio::time::advance(crate::facts::HOST_INTERVAL).await;
        tokio::time::resume();
        tokio::time::timeout(Duration::from_secs(1), disks.changed())
            .await
            .unwrap()
            .unwrap();
        let mut stale = first;
        stale["stale"] = json!(true);
        assert_eq!(disks.borrow_and_update()[0].data(), stale);
        release.send(()).unwrap();
        tokio::time::timeout(Duration::from_secs(1), disks.changed())
            .await
            .unwrap()
            .unwrap();
        assert_eq!(
            disks.borrow()[0].data(),
            json!({
                "mount": "/slow", "used": 2, "total": 100,
                "observed_at": "2026-11-09T00:00:02Z", "stale": false,
            })
        );
        assert_eq!(calls.load(Ordering::SeqCst), 2);
        drop(disks);
        drop(release);
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
