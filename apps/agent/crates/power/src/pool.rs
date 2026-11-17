//! The one restore pool of a Node (ADR 0266). Its threads are its own, so order checks never wait behind a restore. Jobs queue in
//! arrival order, and a job leaves the front only when every one of its items has finished: in a burst the first wake finishes in
//! about the time of a single wake, and no item of the next target starts before then.
//!
//! Lock order: the queue, then a job's progress. Item work runs under neither.
use std::collections::VecDeque;
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Arc, Condvar, Mutex};
use std::time::Instant;

/// Why an item, and so its target's restore, is not complete.
pub(crate) type Shortfall = &'static str;

/// One unit of restore work. It gets the deadline, and returns the pages it read back and, if it did not read every page it
/// should have, why.
pub(crate) type Item = Box<dyn Fn(Instant) -> (u64, Option<Shortfall>) + Send + Sync>;

/// The least and most threads the role may ask for. Swap-in waits on the device, so the floor is not the CPU count.
pub const MIN_THREADS: usize = 16;
/// See [`MIN_THREADS`].
pub const MAX_THREADS: usize = 256;

#[derive(Default)]
struct Progress {
    /// Items claimed by a worker or skipped by the cancel.
    next: usize,
    /// Items that ran to their end or were skipped.
    finished: usize,
    shortfall: Option<Shortfall>,
}

struct Job {
    items: Vec<Item>,
    deadline: Instant,
    pages: AtomicU64,
    cancelled: AtomicBool,
    progress: Mutex<Progress>,
    all: Condvar,
}
impl Job {
    fn done(&self, progress: &Progress) -> bool {
        progress.finished == self.items.len()
    }
}

#[derive(Default)]
struct Shared {
    queue: Mutex<VecDeque<Arc<Job>>>,
    work: Condvar,
}
impl Shared {
    /// Take a finished job off the queue and wake everyone waiting on it or on the next job.
    fn retire(&self, queue: &mut VecDeque<Arc<Job>>, job: &Arc<Job>) {
        queue.retain(|queued| !Arc::ptr_eq(queued, job));
        self.work.notify_all();
        job.all.notify_all();
    }
}

/// The restore pool: fixed threads that live as long as the executor. A clone shares the same threads and queue.
#[derive(Clone)]
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

/// How one target's restore ended. It returns only when none of its items runs any more.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) struct Restored {
    pub pages: u64,
    /// `None` when every item read every page it should have.
    pub shortfall: Option<Shortfall>,
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

    /// Queue one target's items behind earlier targets and wait until all are done. At the deadline the items not yet started
    /// are skipped, and the call waits for the items still running, which stop at their next deadline check. So no restore work
    /// of this target outlives the call: the caller may unfreeze and release the target.
    pub(crate) fn restore(&self, items: Vec<Item>, deadline: Instant) -> Restored {
        if items.is_empty() {
            return Restored {
                pages: 0,
                shortfall: None,
            };
        }
        let job = Arc::new(Job {
            items,
            deadline,
            pages: AtomicU64::new(0),
            cancelled: AtomicBool::new(false),
            progress: Mutex::new(Progress::default()),
            all: Condvar::new(),
        });
        self.shared
            .queue
            .lock()
            .expect("restore queue lock poisoned")
            .push_back(Arc::clone(&job));
        self.shared.work.notify_all();
        let mut progress = job.progress.lock().expect("restore job lock poisoned");
        while !job.done(&progress) {
            let now = Instant::now();
            if job.cancelled.load(Ordering::Relaxed) {
                progress = job.all.wait(progress).expect("restore job lock poisoned");
            } else if now >= deadline {
                drop(progress);
                self.cancel(&job);
                progress = job.progress.lock().expect("restore job lock poisoned");
            } else {
                progress = job
                    .all
                    .wait_timeout(progress, deadline - now)
                    .expect("restore job lock poisoned")
                    .0;
            }
        }
        Restored {
            pages: job.pages.load(Ordering::Relaxed),
            shortfall: progress.shortfall,
        }
    }

    /// Skip every item not yet started. A job behind another target leaves the queue at once; running items finish on their own.
    fn cancel(&self, job: &Arc<Job>) {
        let mut queue = self
            .shared
            .queue
            .lock()
            .expect("restore queue lock poisoned");
        let mut progress = job.progress.lock().expect("restore job lock poisoned");
        job.cancelled.store(true, Ordering::Relaxed);
        let skipped = job.items.len() - progress.next;
        if skipped > 0 {
            progress.next = job.items.len();
            progress.finished += skipped;
            progress.shortfall.get_or_insert("deadline");
        }
        if job.done(&progress) {
            drop(progress);
            self.shared.retire(&mut queue, job);
        }
    }
}

fn work(shared: &Shared) {
    loop {
        let (job, index) = {
            let mut queue = shared.queue.lock().expect("restore queue lock poisoned");
            loop {
                let claimed = queue.front().and_then(|job| {
                    let mut progress = job.progress.lock().expect("restore job lock poisoned");
                    (progress.next < job.items.len()).then(|| {
                        progress.next += 1;
                        (Arc::clone(job), progress.next - 1)
                    })
                });
                if let Some(claimed) = claimed {
                    break claimed;
                }
                // The front job's items are all claimed but some still run: the next target waits for them.
                queue = shared
                    .work
                    .wait(queue)
                    .expect("restore queue lock poisoned");
            }
        };
        let (pages, shortfall) = if Instant::now() >= job.deadline {
            (0, Some("deadline"))
        } else {
            job.items[index](job.deadline)
        };
        job.pages.fetch_add(pages, Ordering::Relaxed);
        let mut queue = shared.queue.lock().expect("restore queue lock poisoned");
        let mut progress = job.progress.lock().expect("restore job lock poisoned");
        progress.finished += 1;
        if let Some(reason) = shortfall {
            progress.shortfall.get_or_insert(reason);
        }
        if job.done(&progress) {
            drop(progress);
            shared.retire(&mut queue, &job);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::mpsc;
    use std::time::Duration;

    fn recording(log: &Arc<Mutex<Vec<char>>>, name: char, pause: Duration) -> Item {
        let log = Arc::clone(log);
        Box::new(move |_| {
            log.lock().expect("log").push(name);
            std::thread::sleep(pause);
            log.lock().expect("log").push(name.to_ascii_uppercase());
            (1, None)
        })
    }

    /// An item that says it entered and then blocks until the gate opens.
    fn gated(
        gate: &Arc<(Mutex<bool>, Condvar)>,
        entered: mpsc::Sender<()>,
        finished: Option<mpsc::Sender<()>>,
    ) -> Item {
        let gate = Arc::clone(gate);
        Box::new(move |_| {
            entered.send(()).expect("entered");
            let (lock, cv) = &*gate;
            let mut release = lock.lock().expect("gate");
            while !*release {
                release = cv.wait(release).expect("gate");
            }
            if let Some(finished) = &finished {
                finished.send(()).expect("finished");
            }
            (1, None)
        })
    }
    fn open(gate: &(Mutex<bool>, Condvar)) {
        *gate.0.lock().expect("release") = true;
        gate.1.notify_all();
    }

    #[test]
    fn one_target_finishes_before_the_next_starts() {
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
        while log.lock().expect("log").is_empty() {
            std::thread::sleep(Duration::from_millis(1));
        }
        let b = pool.restore(second, far);
        let a = waiting.join().expect("first wake");
        let full = Restored {
            pages: 0,
            shortfall: None,
        };
        assert_eq!(
            (a, b),
            (
                Restored { pages: 12, ..full },
                Restored { pages: 6, ..full }
            )
        );
        let log = log.lock().expect("log");
        let last_a = log.iter().rposition(|c| *c == 'A').expect("a finished");
        let first_b = log.iter().position(|c| *c == 'b').expect("b ran");
        assert!(
            last_a < first_b,
            "every item of the first target finishes first: {log:?}"
        );
    }

    /// Review 5767, finding 2: the last claimed item of a target must finish before the next target's first item starts.
    #[test]
    fn next_target_must_wait_for_previous_target_completion() {
        let pool = Arc::new(Pool::new(2));
        let gate = Arc::new((Mutex::new(false), Condvar::new()));
        let (entered_tx, entered_rx) = mpsc::channel();
        let a = {
            let pool = Arc::clone(&pool);
            let item = gated(&gate, entered_tx, None);
            std::thread::spawn(move || {
                pool.restore(vec![item], Instant::now() + Duration::from_secs(1))
            })
        };
        entered_rx
            .recv_timeout(Duration::from_secs(1))
            .expect("first target entered");
        let (next_tx, next_rx) = mpsc::channel();
        let b = {
            let pool = Arc::clone(&pool);
            std::thread::spawn(move || {
                pool.restore(
                    vec![Box::new(move |_| {
                        next_tx.send(()).expect("next started");
                        (1, None)
                    })],
                    Instant::now() + Duration::from_secs(1),
                )
            })
        };
        let overlapped = next_rx.recv_timeout(Duration::from_millis(100)).is_ok();
        open(&gate);
        a.join().expect("first");
        b.join().expect("next");
        assert!(
            !overlapped,
            "next target ran while a previous target item was still blocked"
        );
    }

    /// Review 5767, finding 1: at the deadline the restore waits for the item still running before it returns.
    #[test]
    fn deadline_must_not_leave_restore_item_running_after_return() {
        let pool = Arc::new(Pool::new(2));
        let gate = Arc::new((Mutex::new(false), Condvar::new()));
        let (entered_tx, entered_rx) = mpsc::channel();
        let (finished_tx, finished_rx) = mpsc::channel();
        let (returned_tx, returned_rx) = mpsc::channel();
        let wake = {
            let pool = Arc::clone(&pool);
            let item = gated(&gate, entered_tx, Some(finished_tx));
            std::thread::spawn(move || {
                let restored =
                    pool.restore(vec![item], Instant::now() + Duration::from_millis(100));
                returned_tx.send(()).expect("returned");
                restored
            })
        };
        entered_rx
            .recv_timeout(Duration::from_secs(1))
            .expect("item entered");
        let returned_early = returned_rx.recv_timeout(Duration::from_millis(300)).is_ok();
        open(&gate);
        finished_rx
            .recv_timeout(Duration::from_secs(1))
            .expect("finished");
        let result = wake.join().expect("wake returned");
        assert!(
            !returned_early,
            "wake returned before its active restore item finished"
        );
        // The item itself read its page: the restore is complete although the deadline passed while it ran.
        assert_eq!(result.pages, 1);
    }

    #[test]
    fn the_deadline_skips_unstarted_items_and_reports_it() {
        let pool = Pool::new(MIN_THREADS);
        let log = Arc::new(Mutex::new(Vec::new()));
        let items: Vec<Item> = (0..64)
            .map(|_| recording(&log, 'a', Duration::from_millis(20)))
            .collect();
        let restored = pool.restore(items, Instant::now() + Duration::from_millis(30));
        assert_eq!(restored.shortfall, Some("deadline"));
        assert!(restored.pages < 64);
        // Every item that started also finished before the restore returned.
        let log = log.lock().expect("log");
        assert_eq!(
            log.iter().filter(|c| **c == 'a').count(),
            log.iter().filter(|c| **c == 'A').count()
        );
    }

    #[test]
    fn a_queued_target_cut_at_its_deadline_leaves_without_running() {
        let pool = Arc::new(Pool::new(MIN_THREADS));
        let gate = Arc::new((Mutex::new(false), Condvar::new()));
        let (entered_tx, entered_rx) = mpsc::channel();
        let first = {
            let pool = Arc::clone(&pool);
            let item = gated(&gate, entered_tx, None);
            std::thread::spawn(move || {
                pool.restore(vec![item], Instant::now() + Duration::from_secs(5))
            })
        };
        entered_rx
            .recv_timeout(Duration::from_secs(1))
            .expect("first entered");
        let log = Arc::new(Mutex::new(Vec::new()));
        let second = pool.restore(
            vec![recording(&log, 'b', Duration::ZERO)],
            Instant::now() + Duration::from_millis(20),
        );
        open(&gate);
        first.join().expect("first");
        assert_eq!((second.pages, second.shortfall), (0, Some("deadline")));
        assert!(log.lock().expect("log").is_empty());
    }

    #[test]
    fn an_item_shortfall_makes_the_restore_incomplete() {
        let pool = Pool::new(MIN_THREADS);
        let restored = pool.restore(
            vec![
                Box::new(|_| (3, Some("read_failed"))),
                Box::new(|_| (1, None)),
            ],
            Instant::now() + Duration::from_secs(1),
        );
        assert_eq!(
            (restored.pages, restored.shortfall),
            (4, Some("read_failed"))
        );
    }

    #[test]
    fn an_empty_restore_is_complete() {
        assert_eq!(
            Pool::new(MIN_THREADS).restore(Vec::new(), Instant::now() + Duration::from_secs(1)),
            Restored {
                pages: 0,
                shortfall: None
            }
        );
    }
}
