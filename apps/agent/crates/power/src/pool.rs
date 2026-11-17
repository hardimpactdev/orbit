//! The one restore pool of a Node (ADR 0266). Its threads are its own, so order checks never wait behind a restore. Jobs queue in
//! arrival order, and every item of the first job is claimed before any item of the next: in a burst the first wake finishes in about
//! the time of a single wake.
use std::collections::VecDeque;
use std::sync::atomic::{AtomicBool, AtomicU64, AtomicUsize, Ordering};
use std::sync::{Arc, Condvar, Mutex};
use std::time::Instant;

/// One unit of restore work. It returns the pages it read back and whether it stopped early at the deadline.
pub(crate) type Item = Box<dyn Fn(Instant) -> (u64, bool) + Send + Sync>;

/// The least and most threads the role may ask for. Swap-in waits on the device, so the floor is not the CPU count.
pub const MIN_THREADS: usize = 16;
/// See [`MIN_THREADS`].
pub const MAX_THREADS: usize = 256;

struct Job {
    items: Vec<Item>,
    deadline: Instant,
    next: AtomicUsize,
    pages: AtomicU64,
    cut: AtomicBool,
    finished: Mutex<usize>,
    all: Condvar,
}

#[derive(Default)]
struct Shared {
    queue: Mutex<VecDeque<Arc<Job>>>,
    work: Condvar,
}

/// The restore pool: fixed threads that live as long as the executor.
pub(crate) struct Pool {
    shared: Arc<Shared>,
    threads: usize,
}
impl std::fmt::Debug for Pool {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Pool")
            .field("threads", &self.threads)
            .finish_non_exhaustive()
    }
}

/// How one target's restore ended.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) struct Restored {
    pub pages: u64,
    pub complete: bool,
}

impl Pool {
    pub(crate) fn new(threads: usize) -> Self {
        let shared = Arc::new(Shared::default());
        for index in 0..threads {
            let shared = Arc::clone(&shared);
            std::thread::Builder::new()
                .name(format!("restore-{index}"))
                .spawn(move || work(&shared))
                .expect("a restore thread must start: the executor cannot wake without its pool");
        }
        Self { shared, threads }
    }

    /// Queue one target's items behind earlier targets and wait until all are done or the deadline passes. Items still queued at
    /// the deadline are skipped; an item already running stops at its next deadline check.
    pub(crate) fn restore(&self, items: Vec<Item>, deadline: Instant) -> Restored {
        let total = items.len();
        let job = Arc::new(Job {
            items,
            deadline,
            next: AtomicUsize::new(0),
            pages: AtomicU64::new(0),
            cut: AtomicBool::new(false),
            finished: Mutex::new(0),
            all: Condvar::new(),
        });
        if total > 0 {
            self.shared
                .queue
                .lock()
                .expect("restore queue lock poisoned")
                .push_back(Arc::clone(&job));
            self.shared.work.notify_all();
        }
        let mut finished = job.finished.lock().expect("restore job lock poisoned");
        while *finished < total {
            let now = Instant::now();
            if now >= deadline {
                job.cut.store(true, Ordering::Relaxed);
                break;
            }
            finished = job
                .all
                .wait_timeout(finished, deadline - now)
                .expect("restore job lock poisoned")
                .0;
        }
        Restored {
            pages: job.pages.load(Ordering::Relaxed),
            complete: !job.cut.load(Ordering::Relaxed) && *finished == total,
        }
    }
}

fn work(shared: &Shared) {
    loop {
        let (job, index) = {
            let mut queue = shared.queue.lock().expect("restore queue lock poisoned");
            loop {
                let Some(job) = queue.front().map(Arc::clone) else {
                    queue = shared
                        .work
                        .wait(queue)
                        .expect("restore queue lock poisoned");
                    continue;
                };
                let index = job.next.fetch_add(1, Ordering::Relaxed);
                if index + 1 >= job.items.len() {
                    // The job's last item is claimed: the next target is served from now on.
                    queue.pop_front();
                }
                if index < job.items.len() {
                    break (job, index);
                }
            }
        };
        if Instant::now() >= job.deadline {
            job.cut.store(true, Ordering::Relaxed);
        } else {
            let (pages, cut) = job.items[index](job.deadline);
            job.pages.fetch_add(pages, Ordering::Relaxed);
            if cut {
                job.cut.store(true, Ordering::Relaxed);
            }
        }
        *job.finished.lock().expect("restore job lock poisoned") += 1;
        job.all.notify_all();
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::time::Duration;

    fn recording(log: &Arc<Mutex<Vec<char>>>, name: char, pause: Duration) -> Item {
        let log = Arc::clone(log);
        Box::new(move |_| {
            log.lock().expect("log").push(name);
            std::thread::sleep(pause);
            (1, false)
        })
    }

    #[test]
    fn one_target_is_served_fully_before_the_next() {
        let pool = Arc::new(Pool::new(4));
        let log = Arc::new(Mutex::new(Vec::new()));
        let far = Instant::now() + Duration::from_secs(10);
        let first: Vec<Item> = (0..12)
            .map(|_| recording(&log, 'a', Duration::from_millis(5)))
            .collect();
        let second: Vec<Item> = (0..6)
            .map(|_| recording(&log, 'b', Duration::ZERO))
            .collect();
        let waiting = {
            let pool = Arc::clone(&pool);
            std::thread::spawn(move || pool.restore(first, far))
        };
        // Queue the second target once the first is being served.
        while log.lock().expect("log").is_empty() {
            std::thread::sleep(Duration::from_millis(1));
        }
        let b = pool.restore(second, far);
        let a = waiting.join().expect("first wake");
        assert_eq!(
            (a, b),
            (
                Restored {
                    pages: 12,
                    complete: true
                },
                Restored {
                    pages: 6,
                    complete: true
                }
            )
        );
        let log = log.lock().expect("log");
        let last_a = log.iter().rposition(|c| *c == 'a').expect("a ran");
        let first_b = log.iter().position(|c| *c == 'b').expect("b ran");
        assert!(
            last_a < first_b,
            "every item of the first target starts first: {log:?}"
        );
    }

    #[test]
    fn the_deadline_cuts_the_restore_short_and_reports_it() {
        let pool = Pool::new(MIN_THREADS);
        let log = Arc::new(Mutex::new(Vec::new()));
        let items: Vec<Item> = (0..64)
            .map(|_| recording(&log, 'a', Duration::from_millis(20)))
            .collect();
        let restored = pool.restore(items, Instant::now() + Duration::from_millis(30));
        assert!(!restored.complete);
        assert!(restored.pages < 64);
    }

    #[test]
    fn a_past_deadline_runs_no_item() {
        let pool = Pool::new(MIN_THREADS);
        let log = Arc::new(Mutex::new(Vec::new()));
        let restored = pool.restore(vec![recording(&log, 'a', Duration::ZERO)], Instant::now());
        assert!(!restored.complete);
        assert_eq!(restored.pages, 0);
    }

    #[test]
    fn an_empty_restore_is_complete() {
        assert_eq!(
            Pool::new(MIN_THREADS).restore(Vec::new(), Instant::now() + Duration::from_secs(1)),
            Restored {
                pages: 0,
                complete: true
            }
        );
    }
}
