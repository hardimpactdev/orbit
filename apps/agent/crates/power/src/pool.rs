//! The one restore pool of a Node (ADR 0266). Its threads are its own, so order checks never wait behind a restore. Jobs queue in
//! arrival order, and a job leaves the front only when every one of its items has finished, or when it is stalled: in a burst the
//! first wake finishes in about the time of a single wake, and no item of the next target starts before then.
//!
//! A stalled job's items may still run after it left the queue and its restore returned. Each such worker is replaced, so the pool
//! keeps its configured number of working threads, up to [`THREAD_CAP`] times that size in all. When its syscall returns, a stalled
//! worker exits if the pool is at size again, and rejoins otherwise.
//!
//! All accounting lives in one [`State`] under one mutex: the queue, each job's progress, the thread counts and each target's
//! reader count. Every transition (claiming an item, finishing it, cutting a job at its deadline, marking it stalled with its
//! workers and their replacements, a stalled worker returning, a spawn failing) is one critical section. Item work and thread
//! spawns run outside it; a replacement's slot is reserved in `total` before its spawn and given back if the spawn fails.
use std::collections::{HashMap, VecDeque};
use std::sync::{Arc, Condvar, Mutex, MutexGuard};
use std::time::{Duration, Instant};

/// Why an item, and so its target's restore, is not complete.
pub(crate) type Shortfall = &'static str;

/// One unit of restore work. It gets the deadline, and returns the pages it read back and, if it did not read every page it
/// should have, why.
pub(crate) type Item = Box<dyn Fn(Instant) -> (u64, Option<Shortfall>) + Send + Sync>;

/// How long the restore waits, after its deadline, for items still inside a memory syscall. One that has not returned by then is
/// stalled: `process_madvise` and `process_vm_readv` cannot be cancelled, and a page fault can wait on a pager or device for good.
pub(crate) const STALL_GRACE: Duration = Duration::from_millis(250);

/// The pool never holds more than this many times its configured size in threads, stalled ones and reserved slots included. Past
/// it, a new stall spawns no replacement: other targets get the working threads that remain.
pub(crate) const THREAD_CAP: usize = 2;

/// The least and most threads the role may ask for. Swap-in waits on the device, so the floor is not the CPU count.
pub const MIN_THREADS: usize = 16;
/// See [`MIN_THREADS`].
pub const MAX_THREADS: usize = 256;

#[expect(
    clippy::print_stderr,
    reason = "systemd sends the executor's standard error to its journal; the pool has no other log"
)]
fn log(line: &str) {
    eprintln!("{line}");
}

/// One target's progress, kept in [`State`].
#[derive(Debug, Default)]
struct Progress {
    /// Items claimed by a worker or skipped by the cut.
    next: usize,
    /// Items that ran to their end or were skipped.
    finished: usize,
    pages: u64,
    shortfall: Option<Shortfall>,
    /// The deadline passed and the items not started were skipped.
    cut: bool,
    /// The restore gave up on the items still running; their workers count as stalled until they return.
    stalled: bool,
}

/// What a job's workers need that never changes. Its progress is in [`State`].
struct Job {
    id: u64,
    reader: u64,
    /// Keeps the target's reader count alive while any item of this job may run.
    _readers: Arc<Readers>,
    items: Vec<Item>,
    deadline: Instant,
}

/// The pool's whole mutable state.
#[derive(Default)]
struct State {
    queue: VecDeque<Arc<Job>>,
    jobs: HashMap<u64, Progress>,
    /// Running items per target, stalled ones included.
    readers: HashMap<u64, usize>,
    /// Live workers, stalled ones and slots reserved for a spawn included.
    total: usize,
    /// Workers inside an item of a stalled job.
    stalled: usize,
    next_id: u64,
}
impl State {
    fn id(&mut self) -> u64 {
        self.next_id = self
            .next_id
            .checked_add(1)
            .expect("restore ids never run out in u64");
        self.next_id
    }
    fn progress(&mut self, job: u64) -> &mut Progress {
        self.jobs
            .get_mut(&job)
            .expect("a job's progress lives until its restore returned and its last item finished")
    }
    fn retire(&mut self, job: u64) {
        self.queue.retain(|queued| queued.id != job);
    }
    /// Reserve replacement slots so the working threads are back at `size`, within the cap. The caller spawns that many.
    fn reserve(&mut self, size: usize) -> usize {
        let working = self.working();
        let room = (size * THREAD_CAP).saturating_sub(self.total);
        let wanted = size.saturating_sub(working);
        if wanted > room {
            log(&format!(
                "restore pool: {} workers stalled; at its cap of {} threads it serves with {} working",
                self.stalled,
                size * THREAD_CAP,
                working + room
            ));
        }
        let reserved = wanted.min(room);
        self.total += reserved;
        reserved
    }
    fn working(&self) -> usize {
        self.total
            .checked_sub(self.stalled)
            .expect("stalled workers are always counted in the total")
    }
}

struct Shared {
    state: Mutex<State>,
    /// Workers wait here for an item.
    work: Condvar,
    /// Restores and reader waits wait here for progress.
    changed: Condvar,
    /// The configured number of working threads.
    size: usize,
    /// Worker threads alive now, to check the accounting against the threads themselves.
    #[cfg(test)]
    live: std::sync::atomic::AtomicUsize,
    /// Runs after a stall's critical section and before its spawns: the point where the reviewed races happened.
    #[cfg(test)]
    after_stall: Mutex<Option<Arc<dyn Fn() + Send + Sync>>>,
}
impl Shared {
    fn lock(&self) -> MutexGuard<'_, State> {
        self.state.lock().expect("restore pool lock poisoned")
    }

    /// Start `count` workers whose slots are already reserved in `total`. A failed spawn gives its slot back and is logged; the
    /// pool runs with what it has.
    fn start(shared: &Arc<Shared>, count: usize) {
        for _ in 0..count {
            let worker = Arc::clone(shared);
            if let Err(error) = std::thread::Builder::new()
                .name("restore".to_owned())
                .spawn(move || work(&worker))
            {
                let mut state = shared.lock();
                state.total = state
                    .total
                    .checked_sub(1)
                    .expect("a failed spawn gives back the slot it reserved");
                drop(state);
                log(&format!("restore pool: a worker did not start: {error}"));
            }
        }
    }
}

/// The restore items of one target that are still running, including stalled ones after their wake went on. A sleep or discard
/// of the same target waits for them, so no freeze, scan or plan overlaps a stale reader. The count lives in the pool's state.
pub(crate) struct Readers {
    id: u64,
    shared: Arc<Shared>,
}
impl std::fmt::Debug for Readers {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Readers")
            .field("id", &self.id)
            .finish_non_exhaustive()
    }
}
impl Readers {
    /// Wait until no item of this target runs, at most `bound`. False when one still runs then.
    pub(crate) fn wait_idle(&self, bound: Duration) -> bool {
        let state = self.shared.lock();
        let (state, _) = self
            .shared
            .changed
            .wait_timeout_while(state, bound, |state| {
                state.readers.get(&self.id).is_some_and(|n| *n > 0)
            })
            .expect("restore pool lock poisoned");
        !state.readers.get(&self.id).is_some_and(|n| *n > 0)
    }
    #[cfg(test)]
    pub(crate) fn active(&self) -> usize {
        self.shared
            .lock()
            .readers
            .get(&self.id)
            .copied()
            .unwrap_or(0)
    }
}

/// The restore pool: threads that live as long as the executor, replaced while stalled. A clone shares the same threads and queue.
#[derive(Clone)]
pub(crate) struct Pool {
    shared: Arc<Shared>,
}
impl std::fmt::Debug for Pool {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Pool")
            .field("threads", &self.shared.size)
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
    pub(crate) fn new(threads: usize) -> Self {
        let shared = Arc::new(Shared {
            state: Mutex::new(State {
                total: threads,
                ..State::default()
            }),
            work: Condvar::new(),
            changed: Condvar::new(),
            size: threads,
            #[cfg(test)]
            live: std::sync::atomic::AtomicUsize::new(0),
            #[cfg(test)]
            after_stall: Mutex::new(None),
        });
        Shared::start(&shared, threads);
        Self { shared }
    }

    /// A new reader count for one target. Its entry stays for the executor's life: the power family keeps one per target.
    pub(crate) fn readers(&self) -> Arc<Readers> {
        let mut state = self.shared.lock();
        let id = state.id();
        state.readers.insert(id, 0);
        Arc::new(Readers {
            id,
            shared: Arc::clone(&self.shared),
        })
    }

    /// Worker threads alive now.
    #[cfg(test)]
    fn live(&self) -> usize {
        self.shared.live.load(std::sync::atomic::Ordering::SeqCst)
    }

    #[cfg(test)]
    fn after_stall(&self, hook: impl Fn() + Send + Sync + 'static) {
        *self.shared.after_stall.lock().expect("test hook") = Some(Arc::new(hook));
    }

    /// Live workers and stalled workers.
    #[cfg(test)]
    fn threads(&self) -> (usize, usize) {
        let state = self.shared.lock();
        (state.total, state.stalled)
    }

    /// Queue one target's items behind earlier targets and wait until all are done. At the deadline the items not yet started
    /// are skipped, and the call waits [`STALL_GRACE`] for the items still running, which stop at their next deadline check.
    ///
    /// An item still inside its memory syscall after the grace is stalled. The call then reports `stalled`, takes the target off
    /// the queue so other targets go on, reserves replacements for the stalled workers within the cap, and returns. The caller may
    /// unfreeze: a late `process_vm_readv` only copies the target's pages into the executor's own buffer, and a late
    /// `MADV_WILLNEED` only starts a read into the page cache; neither changes the target. `readers` counts the stalled items until
    /// their syscall returns. Their workers stay with them until then, and replacements keep the pool's working threads at size,
    /// except past the cap of [`THREAD_CAP`] times the size, where the pool serves with fewer.
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
        let total = items.len();
        let mut state = self.shared.lock();
        let id = state.id();
        state.jobs.insert(id, Progress::default());
        state.queue.push_back(Arc::new(Job {
            id,
            reader: readers.id,
            _readers: Arc::clone(readers),
            items,
            deadline,
        }));
        self.shared.work.notify_all();
        let mut grace = None;
        loop {
            let now = Instant::now();
            if state.progress(id).finished == total {
                let progress = state
                    .jobs
                    .remove(&id)
                    .expect("the restore owns its job's progress until it returns");
                return Restored {
                    pages: progress.pages,
                    shortfall: progress.shortfall,
                };
            }
            match grace {
                Some(end) if now >= end => {
                    // Stalled: in this one critical section, mark the job, count its running workers as stalled, take the job
                    // off the queue and reserve their replacements. Its workers finish it when their syscalls return.
                    let progress = state.progress(id);
                    progress.stalled = true;
                    let running = progress.next - progress.finished;
                    let pages = progress.pages;
                    state.stalled = state
                        .stalled
                        .checked_add(running)
                        .expect("stalled workers fit in usize");
                    state.retire(id);
                    let reserved = state.reserve(self.shared.size);
                    drop(state);
                    self.shared.work.notify_all();
                    #[cfg(test)]
                    {
                        let hook = self.shared.after_stall.lock().expect("test hook").clone();
                        if let Some(hook) = hook {
                            hook();
                        }
                    }
                    Shared::start(&self.shared, reserved);
                    return Restored {
                        pages,
                        shortfall: Some("stalled"),
                    };
                }
                Some(end) => {
                    state = self
                        .shared
                        .changed
                        .wait_timeout(state, end - now)
                        .expect("restore pool lock poisoned")
                        .0;
                }
                None if now >= deadline => {
                    // Skip every item not yet started. A job behind another target leaves the queue at once.
                    let progress = state.progress(id);
                    let skipped = total - progress.next;
                    progress.cut = true;
                    if skipped > 0 {
                        progress.next = total;
                        progress.finished += skipped;
                        progress.shortfall.get_or_insert("deadline");
                    }
                    if progress.finished == total {
                        state.retire(id);
                        self.shared.work.notify_all();
                    }
                    grace = Some(now + STALL_GRACE);
                }
                None => {
                    state = self
                        .shared
                        .changed
                        .wait_timeout(state, deadline - now)
                        .expect("restore pool lock poisoned")
                        .0;
                }
            }
        }
    }
}

fn work(shared: &Arc<Shared>) {
    #[cfg(test)]
    shared
        .live
        .fetch_add(1, std::sync::atomic::Ordering::SeqCst);
    loop {
        let job = {
            let mut state = shared.lock();
            loop {
                let front = state.queue.front().map(Arc::clone);
                if let Some(job) = front {
                    let progress = state.progress(job.id);
                    if progress.next < job.items.len() {
                        let index = progress.next;
                        progress.next += 1;
                        let count = state
                            .readers
                            .get_mut(&job.reader)
                            .expect("a job's reader count lives as long as the job");
                        *count = count.checked_add(1).expect("readers fit in usize");
                        break (job, index);
                    }
                }
                // No front job, or its items are all claimed but some still run: the next target waits for them.
                state = shared.work.wait(state).expect("restore pool lock poisoned");
            }
        };
        let (job, index) = job;
        let (pages, shortfall) = if Instant::now() >= job.deadline {
            (0, Some("deadline"))
        } else {
            job.items[index](job.deadline)
        };
        let mut state = shared.lock();
        let count = state
            .readers
            .get_mut(&job.reader)
            .expect("a job's reader count lives as long as the job");
        *count = count
            .checked_sub(1)
            .expect("a finishing item was counted as a reader when it was claimed");
        let progress = state.progress(job.id);
        progress.finished += 1;
        progress.pages = progress.pages.saturating_add(pages);
        if let Some(reason) = shortfall {
            progress.shortfall.get_or_insert(reason);
        }
        let stalled = progress.stalled;
        let done = progress.finished == job.items.len();
        if done {
            state.retire(job.id);
            if stalled {
                // The restore already returned; the last returning worker drops the progress.
                state.jobs.remove(&job.id);
            }
        }
        let mut exit = false;
        if stalled {
            // This worker was counted as stalled in the same critical section that marked its job stalled.
            state.stalled = state.stalled.checked_sub(1).expect(
                "a returning stalled worker was registered when its job was marked stalled",
            );
            if state.working() > shared.size {
                state.total = state
                    .total
                    .checked_sub(1)
                    .expect("an exiting worker is counted in the total");
                exit = true;
            }
        }
        drop(state);
        shared.changed.notify_all();
        shared.work.notify_all();
        if exit {
            #[cfg(test)]
            shared
                .live
                .fetch_sub(1, std::sync::atomic::Ordering::SeqCst);
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
        pool.restore(items, deadline, &pool.readers())
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
        let readers = pool.readers();
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
        let readers = pool.readers();
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
            if pool.threads() == expected && pool.live() == expected.0 {
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

    /// Re-review 5767 (4), race 1: a stalled worker returns right after its job was marked stalled, before the caller spawns.
    /// Its return must find itself counted as stalled: no underflow, and the pool settles at size with no extra thread.
    #[test]
    fn worker_return_in_stall_publication_gap_must_not_abort_executor() {
        let size = MIN_THREADS;
        let pool = Arc::new(Pool::new(size));
        let (items, gate, entered) = stalling(1);
        let hook_gate = Arc::clone(&gate);
        pool.after_stall(move || {
            open(&hook_gate);
            // The kernel may deschedule the caller here; the worker returns meanwhile.
            std::thread::sleep(Duration::from_millis(100));
        });
        let readers = pool.readers();
        let restored = {
            let pool = Arc::clone(&pool);
            let readers = Arc::clone(&readers);
            std::thread::spawn(move || {
                pool.restore(items, Instant::now() + Duration::from_millis(50), &readers)
            })
        };
        entered
            .recv_timeout(Duration::from_secs(1))
            .expect("entered");
        assert_eq!(restored.join().expect("restore").shortfall, Some("stalled"));
        assert!(readers.wait_idle(Duration::from_secs(1)));
        assert!(
            settles(&pool, (size, 0)),
            "{:?} live {}",
            pool.threads(),
            pool.live()
        );
    }

    /// Re-review 5767 (4), race 2a: stalled workers return while their replacements are being spawned. The pool must settle at
    /// exactly its size. The first replacement runs the next target's item, which releases the stalled items.
    #[test]
    fn returning_stalled_workers_during_spawn_must_settle_at_size() {
        let size = 32;
        let pool = Arc::new(Pool::new(size));
        let (items, gate, entered) = stalling(size);
        let readers = pool.readers();
        let first = {
            let pool = Arc::clone(&pool);
            let readers = Arc::clone(&readers);
            std::thread::spawn(move || {
                pool.restore(items, Instant::now() + Duration::from_millis(50), &readers)
            })
        };
        for _ in 0..size {
            entered
                .recv_timeout(Duration::from_secs(1))
                .expect("all entered");
        }
        let next = restore(
            &pool,
            vec![Box::new(move |_| {
                open(&gate);
                (1, None)
            })],
            Instant::now() + Duration::from_secs(2),
        );
        assert_eq!((next.pages, next.shortfall), (1, None));
        assert_eq!(first.join().expect("first").shortfall, Some("stalled"));
        assert!(readers.wait_idle(Duration::from_secs(1)));
        assert!(
            settles(&pool, (size, 0)),
            "{:?} live {}",
            pool.threads(),
            pool.live()
        );
    }

    /// Re-review 5767 (4), race 2b: two stall batches overlap; the first pauses between its critical section and its spawns
    /// while the second stalls every other worker. The reserved slots keep the threads within the cap throughout.
    #[test]
    fn concurrent_replacement_batches_must_not_exceed_the_thread_cap() {
        let size = MIN_THREADS;
        let cap = size * THREAD_CAP;
        let pool = Arc::new(Pool::new(size));
        let pause: Gate = Arc::new((Mutex::new(false), Condvar::new()));
        let (paused_tx, paused_rx) = mpsc::channel();
        let paused_tx = Mutex::new(paused_tx);
        let calls = std::sync::atomic::AtomicUsize::new(0);
        let hook_pause = Arc::clone(&pause);
        pool.after_stall(move || {
            if calls.fetch_add(1, std::sync::atomic::Ordering::SeqCst) == 0 {
                paused_tx.lock().expect("signal").send(()).expect("paused");
                let mut release = hook_pause.0.lock().expect("pause");
                while !*release {
                    release = hook_pause.1.wait(release).expect("pause");
                }
            }
        });
        let (a_items, a_gate, a_entered) = stalling(1);
        let a_readers = pool.readers();
        let first = {
            let pool = Arc::clone(&pool);
            let readers = Arc::clone(&a_readers);
            std::thread::spawn(move || {
                pool.restore(
                    a_items,
                    Instant::now() + Duration::from_millis(50),
                    &readers,
                )
            })
        };
        a_entered
            .recv_timeout(Duration::from_secs(1))
            .expect("first entered");
        paused_rx
            .recv_timeout(Duration::from_secs(1))
            .expect("first batch paused before its spawn");
        let (b_items, b_gate, b_entered) = stalling(size - 1);
        let b_readers = pool.readers();
        let second = {
            let pool = Arc::clone(&pool);
            let readers = Arc::clone(&b_readers);
            std::thread::spawn(move || {
                pool.restore(
                    b_items,
                    Instant::now() + Duration::from_millis(50),
                    &readers,
                )
            })
        };
        for _ in 0..size - 1 {
            b_entered
                .recv_timeout(Duration::from_secs(2))
                .expect("second entered");
        }
        assert_eq!(second.join().expect("second").shortfall, Some("stalled"));
        open(&pause);
        assert_eq!(first.join().expect("first").shortfall, Some("stalled"));
        let (total, stalled) = pool.threads();
        let live = pool.live();
        open(&a_gate);
        open(&b_gate);
        assert!(a_readers.wait_idle(Duration::from_secs(1)));
        assert!(b_readers.wait_idle(Duration::from_secs(1)));
        assert_eq!((total, stalled), (2 * size, size));
        assert!(live <= cap, "{live} threads against a cap of {cap}");
        assert!(
            settles(&pool, (size, 0)),
            "{:?} live {}",
            pool.threads(),
            pool.live()
        );
    }

    /// Many targets with random stalls and returns across threads. After every step: the threads stay within the cap, the
    /// stalled count never exceeds the total (an underflow would panic a worker and break the final count), the working threads
    /// never exceed the size, and the pool settles at exactly its size.
    #[test]
    fn random_stalls_and_returns_keep_the_accounting_whole() {
        let size = 4;
        let cap = size * THREAD_CAP;
        let pool = Arc::new(Pool::new(size));
        let stop = Arc::new(std::sync::atomic::AtomicBool::new(false));
        let monitor = {
            let pool = Arc::clone(&pool);
            let stop = Arc::clone(&stop);
            std::thread::spawn(move || {
                let mut samples = 0_u64;
                while !stop.load(std::sync::atomic::Ordering::SeqCst) {
                    let (total, stalled) = pool.threads();
                    assert!(total <= cap, "total {total} over the cap {cap}");
                    assert!(stalled <= total, "stalled {stalled} over total {total}");
                    assert!(
                        total - stalled <= size,
                        "working {} over the size",
                        total - stalled
                    );
                    assert!(pool.live() <= cap, "live {} over the cap", pool.live());
                    samples += 1;
                    std::thread::sleep(Duration::from_millis(1));
                }
                samples
            })
        };
        let callers: Vec<_> = (0..6_u64)
            .map(|caller| {
                let pool = Arc::clone(&pool);
                std::thread::spawn(move || {
                    let mut seed = 0x9e37_79b9_7f4a_7c15_u64 ^ (caller + 1);
                    let mut next = move || {
                        seed ^= seed << 13;
                        seed ^= seed >> 7;
                        seed ^= seed << 17;
                        seed
                    };
                    let readers = pool.readers();
                    for _ in 0..4 {
                        let items: Vec<Item> = (0..=(next() % 3))
                            .map(|_| {
                                // Most items return at once; some within the grace; some after it, so they stall.
                                let pause = Duration::from_millis(match next() % 4 {
                                    0 | 1 => 0,
                                    2 => 100,
                                    _ => 300 + next() % 200,
                                });
                                let item: Item = Box::new(move |_| {
                                    std::thread::sleep(pause);
                                    (1, None)
                                });
                                item
                            })
                            .collect();
                        pool.restore(items, Instant::now() + Duration::from_millis(20), &readers);
                    }
                    assert!(readers.wait_idle(Duration::from_secs(2)));
                })
            })
            .collect();
        for caller in callers {
            caller.join().expect("caller");
        }
        assert!(
            settles(&pool, (size, 0)),
            "{:?} live {}",
            pool.threads(),
            pool.live()
        );
        stop.store(true, std::sync::atomic::Ordering::SeqCst);
        assert!(monitor.join().expect("monitor: every sample held") > 0);
    }
}
