//! The one restore pool of a Node (ADR 0266). Its threads are its own, so order checks never wait behind a restore. Jobs queue in
//! arrival order, and a job leaves the front only when every one of its items has finished, or when it is stalled: in a burst the
//! first wake finishes in about the time of a single wake, and no item of the next target starts before then.
//!
//! A stalled job's items may still run after it left the queue and its restore returned. Each such worker is replaced, so the pool
//! keeps its configured number of working threads, up to [`THREAD_CAP`] times that size in all. When its syscall returns, a stalled
//! worker exits if the pool is at size again, and rejoins otherwise.
//!
//! Lock order: the queue, then a job's progress, then the thread counts. Item work runs under none of them.
use std::collections::VecDeque;
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Arc, Condvar, Mutex};
use std::time::{Duration, Instant};

/// Why an item, and so its target's restore, is not complete.
pub(crate) type Shortfall = &'static str;

/// One unit of restore work. It gets the deadline, and returns the pages it read back and, if it did not read every page it
/// should have, why.
pub(crate) type Item = Box<dyn Fn(Instant) -> (u64, Option<Shortfall>) + Send + Sync>;

/// How long the restore waits, after its deadline, for items still inside a memory syscall. One that has not returned by then is
/// stalled: `process_madvise` and `process_vm_readv` cannot be cancelled, and a page fault can wait on a pager or device for good.
pub(crate) const STALL_GRACE: Duration = Duration::from_millis(250);

/// The restore items of one target that are still running, including stalled ones after their wake went on. A sleep or discard
/// of the same target waits for them, so no freeze, scan or plan overlaps a stale reader.
#[derive(Debug, Default)]
pub(crate) struct Readers {
    active: Mutex<usize>,
    idle: Condvar,
}
impl Readers {
    fn enter(&self) {
        *self.active.lock().expect("restore readers lock poisoned") += 1;
    }
    fn leave(&self) {
        *self.active.lock().expect("restore readers lock poisoned") -= 1;
        self.idle.notify_all();
    }
    /// Wait until no item of this target runs, at most `bound`. False when one still runs then.
    pub(crate) fn wait_idle(&self, bound: Duration) -> bool {
        let active = self.active.lock().expect("restore readers lock poisoned");
        let (active, _) = self
            .idle
            .wait_timeout_while(active, bound, |active| *active > 0)
            .expect("restore readers lock poisoned");
        *active == 0
    }
    #[cfg(test)]
    pub(crate) fn active(&self) -> usize {
        *self.active.lock().expect("restore readers lock poisoned")
    }
}

/// The pool never holds more than this many times its configured size in threads, stalled ones included. Past it, a new stall
/// spawns no replacement: other targets get the working threads that remain.
pub(crate) const THREAD_CAP: usize = 2;

#[expect(
    clippy::print_stderr,
    reason = "systemd sends the executor's standard error to its journal; the pool has no other log"
)]
fn log(line: &str) {
    eprintln!("{line}");
}

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
    readers: Arc<Readers>,
    deadline: Instant,
    pages: AtomicU64,
    cancelled: AtomicBool,
    /// Set under the progress lock when the restore gave up on the items still running; their workers are replaced.
    stalled: AtomicBool,
    progress: Mutex<Progress>,
    all: Condvar,
}
impl Job {
    fn done(&self, progress: &Progress) -> bool {
        progress.finished == self.items.len()
    }
}

#[derive(Debug, Default, Clone, Copy, PartialEq, Eq)]
struct Threads {
    /// Every live worker, stalled ones included.
    total: usize,
    /// Workers inside an item of a stalled job.
    stalled: usize,
}

#[derive(Default)]
struct Shared {
    queue: Mutex<VecDeque<Arc<Job>>>,
    work: Condvar,
    /// The configured number of working threads.
    size: usize,
    threads: Mutex<Threads>,
}
impl Shared {
    /// Start one worker. A failed spawn is logged, and the pool runs with what it has.
    fn spawn(shared: &Arc<Shared>) {
        let worker = Arc::clone(shared);
        shared
            .threads
            .lock()
            .expect("restore threads lock poisoned")
            .total += 1;
        if let Err(error) = std::thread::Builder::new()
            .name("restore".to_owned())
            .spawn(move || work(&worker))
        {
            shared
                .threads
                .lock()
                .expect("restore threads lock poisoned")
                .total -= 1;
            log(&format!("restore pool: a worker did not start: {error}"));
        }
    }

    /// `count` workers are now stalled: start replacements until the working threads are back at size, within the cap.
    fn replace(shared: &Arc<Shared>, count: usize) {
        let missing = {
            let mut threads = shared
                .threads
                .lock()
                .expect("restore threads lock poisoned");
            threads.stalled += count;
            let working = threads.total - threads.stalled;
            let room = (shared.size * THREAD_CAP).saturating_sub(threads.total);
            let wanted = shared.size.saturating_sub(working);
            if wanted > room {
                log(&format!(
                    "restore pool: {} workers stalled; at its cap of {} threads it serves with {} working",
                    threads.stalled,
                    shared.size * THREAD_CAP,
                    working + room
                ));
            }
            wanted.min(room)
        };
        for _ in 0..missing {
            Self::spawn(shared);
        }
    }

    /// A stalled worker's syscall returned. True when it should exit, because the pool has its working threads without it.
    fn returned(&self) -> bool {
        let mut threads = self.threads.lock().expect("restore threads lock poisoned");
        threads.stalled -= 1;
        let leave = threads.total - threads.stalled > self.size;
        if leave {
            threads.total -= 1;
        }
        leave
    }

    /// Take a finished job off the queue and wake everyone waiting on it or on the next job.
    fn retire(&self, queue: &mut VecDeque<Arc<Job>>, job: &Arc<Job>) {
        queue.retain(|queued| !Arc::ptr_eq(queued, job));
        self.work.notify_all();
        job.all.notify_all();
    }
}

/// The restore pool: threads that live as long as the executor, replaced while stalled. A clone shares the same threads and queue.
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

/// How one target's restore ended. Unless it is `stalled`, none of its items runs any more; a stalled restore's items may still
/// be inside their read-only syscalls, counted by the target's readers.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) struct Restored {
    pub pages: u64,
    /// `None` when every item read every page it should have.
    pub shortfall: Option<Shortfall>,
}

impl Pool {
    /// Live workers and stalled workers.
    #[cfg(test)]
    fn threads(&self) -> (usize, usize) {
        let threads = self
            .shared
            .threads
            .lock()
            .expect("restore threads lock poisoned");
        (threads.total, threads.stalled)
    }

    pub(crate) fn new(threads: usize) -> Self {
        let shared = Arc::new(Shared {
            size: threads,
            ..Shared::default()
        });
        for _ in 0..threads {
            Shared::spawn(&shared);
        }
        Self { shared, threads }
    }

    /// Queue one target's items behind earlier targets and wait until all are done. At the deadline the items not yet started
    /// are skipped, and the call waits [`STALL_GRACE`] for the items still running, which stop at their next deadline check.
    ///
    /// An item still inside its memory syscall after the grace is stalled. The call then reports `stalled`, takes the target off
    /// the front of the queue so other targets go on, and returns. The caller may unfreeze: a late `process_vm_readv` only copies
    /// the target's pages into the executor's own buffer, and a late `MADV_WILLNEED` only starts a read into the page cache; neither
    /// changes the target. `readers` counts the stalled items until their syscall returns, and the worker stays with it until then,
    /// so the pool serves with fewer threads meanwhile.
    pub(crate) fn restore(
        &self,
        items: Vec<Item>,
        deadline: Instant,
        readers: &Arc<Readers>,
    ) -> Restored {
        if items.is_empty() {
            return Restored {
                pages: 0,
                shortfall: None,
            };
        }
        let job = Arc::new(Job {
            items,
            readers: Arc::clone(readers),
            deadline,
            pages: AtomicU64::new(0),
            cancelled: AtomicBool::new(false),
            stalled: AtomicBool::new(false),
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
        let mut grace = None;
        while !job.done(&progress) {
            let now = Instant::now();
            if let Some(end) = grace {
                if now >= end {
                    drop(progress);
                    // Stalled: the job leaves the queue unfinished. Its workers finish it when their syscalls return, and are
                    // replaced meanwhile, so other targets keep the pool's working threads.
                    let mut queue = self
                        .shared
                        .queue
                        .lock()
                        .expect("restore queue lock poisoned");
                    let progress = job.progress.lock().expect("restore job lock poisoned");
                    job.stalled.store(true, Ordering::Relaxed);
                    let running = progress.next - progress.finished;
                    drop(progress);
                    self.shared.retire(&mut queue, &job);
                    drop(queue);
                    Shared::replace(&self.shared, running);
                    return Restored {
                        pages: job.pages.load(Ordering::Relaxed),
                        shortfall: Some("stalled"),
                    };
                }
                progress = job
                    .all
                    .wait_timeout(progress, end - now)
                    .expect("restore job lock poisoned")
                    .0;
            } else if now >= deadline {
                drop(progress);
                self.cancel(&job);
                grace = Some(now + STALL_GRACE);
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

fn work(shared: &Arc<Shared>) {
    loop {
        let (job, index) = {
            let mut queue = shared.queue.lock().expect("restore queue lock poisoned");
            loop {
                let claimed = queue.front().and_then(|job| {
                    let mut progress = job.progress.lock().expect("restore job lock poisoned");
                    (progress.next < job.items.len()).then(|| {
                        progress.next += 1;
                        job.readers.enter();
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
        job.readers.leave();
        let mut queue = shared.queue.lock().expect("restore queue lock poisoned");
        let mut progress = job.progress.lock().expect("restore job lock poisoned");
        progress.finished += 1;
        if let Some(reason) = shortfall {
            progress.shortfall.get_or_insert(reason);
        }
        // Read under the progress lock: the restore counted this worker as stalled exactly when it marked the job before now.
        let was_stalled = job.stalled.load(Ordering::Relaxed);
        if job.done(&progress) {
            drop(progress);
            shared.retire(&mut queue, &job);
        } else {
            drop(progress);
        }
        drop(queue);
        if was_stalled && shared.returned() {
            return;
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::mpsc;
    use std::time::Duration;

    fn restore(pool: &Pool, items: Vec<Item>, deadline: Instant) -> Restored {
        pool.restore(items, deadline, &Arc::default())
    }

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
            std::thread::spawn(move || restore(&pool, first, far))
        };
        while log.lock().expect("log").is_empty() {
            std::thread::sleep(Duration::from_millis(1));
        }
        let b = restore(&pool, second, far);
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
                restore(&pool, vec![item], Instant::now() + Duration::from_secs(1))
            })
        };
        entered_rx
            .recv_timeout(Duration::from_secs(1))
            .expect("first target entered");
        let (next_tx, next_rx) = mpsc::channel();
        let b = {
            let pool = Arc::clone(&pool);
            std::thread::spawn(move || {
                restore(
                    &pool,
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

    /// Review 5767, finding 1: within the stall grace after the deadline, the restore waits for the item still running.
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
                let restored = restore(
                    &pool,
                    vec![item],
                    Instant::now() + Duration::from_millis(50),
                );
                returned_tx.send(()).expect("returned");
                restored
            })
        };
        entered_rx
            .recv_timeout(Duration::from_secs(1))
            .expect("item entered");
        let returned_early = returned_rx.recv_timeout(Duration::from_millis(150)).is_ok();
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
        assert_eq!((result.pages, result.shortfall), (1, None));
    }

    /// Re-review 5767: an item stuck in its syscall cannot hold the wake. After the deadline and the grace the restore reports
    /// `stalled` and returns; the target's readers still count the item; another target restores meanwhile.
    #[test]
    fn deadline_drain_needs_an_upper_bound_for_a_stalled_item() {
        let pool = Arc::new(Pool::new(2));
        let gate = Arc::new((Mutex::new(false), Condvar::new()));
        let readers = Arc::new(Readers::default());
        let (entered_tx, entered_rx) = mpsc::channel();
        let (returned_tx, returned_rx) = mpsc::channel();
        let deadline = Instant::now() + Duration::from_millis(20);
        let wake = {
            let pool = Arc::clone(&pool);
            let readers = Arc::clone(&readers);
            let item = gated(&gate, entered_tx, None);
            std::thread::spawn(move || {
                let restored = pool.restore(vec![item], deadline, &readers);
                returned_tx.send(Instant::now()).expect("returned");
                restored
            })
        };
        entered_rx
            .recv_timeout(Duration::from_secs(1))
            .expect("item entered");
        let returned = returned_rx
            .recv_timeout(STALL_GRACE + Duration::from_secs(1))
            .expect("the restore returns while its item is still stalled");
        let restored = wake.join().expect("wake");
        assert_eq!(restored.shortfall, Some("stalled"));
        assert!(returned >= deadline + STALL_GRACE);
        assert_eq!(
            readers.active(),
            1,
            "the stalled reader still guards the target"
        );
        assert!(!readers.wait_idle(Duration::from_millis(20)));
        // The stalled target left the front: the other worker serves the next target.
        let other = restore(
            &pool,
            vec![Box::new(|_| (1, None))],
            Instant::now() + Duration::from_secs(1),
        );
        assert_eq!((other.pages, other.shortfall), (1, None));
        open(&gate);
        assert!(
            readers.wait_idle(Duration::from_secs(1)),
            "the reader leaves when its syscall returns"
        );
    }

    #[test]
    fn the_deadline_skips_unstarted_items_and_reports_it() {
        let pool = Pool::new(MIN_THREADS);
        let log = Arc::new(Mutex::new(Vec::new()));
        let items: Vec<Item> = (0..64)
            .map(|_| recording(&log, 'a', Duration::from_millis(20)))
            .collect();
        let restored = restore(&pool, items, Instant::now() + Duration::from_millis(30));
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
                restore(&pool, vec![item], Instant::now() + Duration::from_secs(5))
            })
        };
        entered_rx
            .recv_timeout(Duration::from_secs(1))
            .expect("first entered");
        let log = Arc::new(Mutex::new(Vec::new()));
        let second = restore(
            &pool,
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
        let restored = restore(
            &pool,
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
            restore(
                &Pool::new(MIN_THREADS),
                Vec::new(),
                Instant::now() + Duration::from_secs(1)
            ),
            Restored {
                pages: 0,
                shortfall: None
            }
        );
    }

    type Gate = Arc<(Mutex<bool>, Condvar)>;

    /// `count` items that each say they entered and block on one shared gate, as reads stuck on one pager.
    fn stalling(count: usize) -> (Vec<Item>, Gate, mpsc::Receiver<()>) {
        let gate = Arc::new((Mutex::new(false), Condvar::new()));
        let (entered_tx, entered_rx) = mpsc::channel();
        let items = (0..count)
            .map(|_| gated(&gate, entered_tx.clone(), None))
            .collect();
        (items, gate, entered_rx)
    }
    /// Stall `count` items of one new target on `pool` and return once its restore reported `stalled`.
    fn stall(pool: &Arc<Pool>, count: usize) -> (Gate, Arc<Readers>) {
        let (items, gate, entered) = stalling(count);
        let readers = Arc::new(Readers::default());
        let restore = {
            let pool = Arc::clone(pool);
            let readers = Arc::clone(&readers);
            std::thread::spawn(move || {
                pool.restore(items, Instant::now() + Duration::from_millis(50), &readers)
            })
        };
        for _ in 0..count {
            entered
                .recv_timeout(Duration::from_secs(2))
                .expect("every stalled item entered");
        }
        assert_eq!(
            restore.join().expect("stalled restore").shortfall,
            Some("stalled")
        );
        assert_eq!(readers.active(), count);
        (gate, readers)
    }
    /// Wait until the pool's thread counts settle at `expected`.
    fn settles(pool: &Pool, expected: (usize, usize)) -> bool {
        let end = Instant::now() + Duration::from_secs(2);
        while Instant::now() < end {
            if pool.threads() == expected {
                return true;
            }
            std::thread::sleep(Duration::from_millis(5));
        }
        false
    }

    /// Re-review 5767 (3): one target that stalls every worker must not take the pool from other targets.
    #[test]
    fn other_targets_must_restore_when_a_target_stalls_every_worker() {
        let threads = MIN_THREADS;
        let pool = Arc::new(Pool::new(threads));
        let (gate, readers) = stall(&pool, threads);
        assert_eq!(
            pool.threads(),
            (2 * threads, threads),
            "every stalled worker was replaced"
        );
        let other = restore(
            &pool,
            vec![Box::new(|_| (1, None))],
            Instant::now() + Duration::from_millis(500),
        );
        open(&gate);
        assert!(
            readers.wait_idle(Duration::from_secs(1)),
            "all reader counts return to zero after release"
        );
        assert_eq!(
            (other.pages, other.shortfall),
            (1, None),
            "an unrelated target restores while the first target's workers stay stalled"
        );
    }

    #[test]
    fn stalled_workers_exit_on_return_and_the_pool_stays_at_size() {
        let pool = Arc::new(Pool::new(2));
        let (gate, readers) = stall(&pool, 2);
        assert_eq!(pool.threads(), (4, 2));
        open(&gate);
        assert!(readers.wait_idle(Duration::from_secs(1)));
        assert!(
            settles(&pool, (2, 0)),
            "stalled workers left: {:?}",
            pool.threads()
        );
        let after = restore(
            &pool,
            vec![Box::new(|_| (1, None)), Box::new(|_| (1, None))],
            Instant::now() + Duration::from_secs(1),
        );
        assert_eq!((after.pages, after.shortfall), (2, None));
        assert_eq!(pool.threads(), (2, 0));
    }

    #[test]
    fn the_thread_cap_holds_under_repeated_stalls() {
        let pool = Arc::new(Pool::new(2));
        let (first_gate, first) = stall(&pool, 2);
        assert_eq!(pool.threads(), (4, 2));
        // A second stall of one worker spawns nothing past the cap; one working thread remains for other targets.
        let (second_gate, second) = stall(&pool, 1);
        assert_eq!(
            pool.threads(),
            (4, 3),
            "the cap of {} threads holds",
            2 * THREAD_CAP
        );
        let other = restore(
            &pool,
            vec![Box::new(|_| (1, None))],
            Instant::now() + Duration::from_millis(500),
        );
        assert_eq!((other.pages, other.shortfall), (1, None));
        open(&second_gate);
        assert!(second.wait_idle(Duration::from_secs(1)));
        // Below size without it, the returned worker rejoins instead of exiting.
        assert!(settles(&pool, (4, 2)), "{:?}", pool.threads());
        open(&first_gate);
        assert!(first.wait_idle(Duration::from_secs(1)));
        assert!(settles(&pool, (2, 0)), "{:?}", pool.threads());
    }
}
