//! Starts and stops log streams from the Gateway's HTTPS stream list (ADR 0153), and hands their events
//! to the realtime connection. A channel message only prompts a fetch; it never names what to read.
use super::{
    docker, journalctl, laravel,
    laravel::Unavailable,
    limits::{
        agent_bucket, AgentBucket, LineSink, StreamQueue, LIST_UNAVAILABLE, SOURCE_UNAVAILABLE,
    },
    parse_stream_list, Source, StreamSpec,
};
use crate::ClientFrame;
use serde_json::Value;
use std::{
    collections::{BTreeMap, BTreeSet},
    future::Future,
    pin::Pin,
    sync::{Arc, Mutex, MutexGuard},
    task::{Context, Poll},
    time::Duration,
};
use tokio::{
    sync::mpsc,
    task::JoinHandle,
    time::{Instant, MissedTickBehavior},
};

/// While at least one stream runs, the list is fetched again this often.
pub const REFRESH: Duration = Duration::from_secs(15);
/// Without a list for this long, every stream stops.
pub const FAIL_CLOSED: Duration = Duration::from_secs(60);
pub const FETCH_TIMEOUT: Duration = Duration::from_secs(10);

#[derive(Debug, Default)]
struct Registry {
    streams: BTreeMap<String, Arc<StreamQueue>>,
    /// Streams whose end was sent, with its reason. They are not started again while the list still
    /// names them; the end is sent again instead, in case it was lost.
    ended: BTreeMap<String, &'static str>,
}

/// Shared between the controller and the realtime connection. It outlives reconnects.
#[derive(Clone)]
pub struct LogHub {
    registry: Arc<Mutex<Registry>>,
    prompt: mpsc::UnboundedSender<()>,
    agent: AgentBucket,
}

impl LogHub {
    pub fn new() -> (Self, mpsc::UnboundedReceiver<()>) {
        let (prompt, prompts) = mpsc::unbounded_channel();
        (
            Self {
                registry: Arc::default(),
                prompt,
                agent: agent_bucket(),
            },
            prompts,
        )
    }

    /// Locks the registry. A poisoned lock is fatal: the holder panics, `crate::supervise` ends the observer
    /// with exit code 1, and systemd restarts it.
    fn registry(&self) -> MutexGuard<'_, Registry> {
        self.registry
            .lock()
            .expect("the log registry lock is never held across a panic: holders only update its maps and queues")
    }

    /// Asks the controller to fetch the stream list. Prompts coalesce.
    pub fn prompt(&self) {
        let _ = self.prompt.send(());
    }

    pub fn running(&self) -> usize {
        self.registry().streams.len()
    }

    /// The events that are due on the log channel at `now`, at most one batch per stream every 250 ms.
    pub fn flush(
        &self,
        channel: &str,
        now: Instant,
    ) -> Result<Vec<ClientFrame>, serde_json::Error> {
        let streams: Vec<_> = self.registry().streams.values().cloned().collect();
        let mut frames = Vec::new();
        for queue in streams {
            let flush = queue.flush(channel, now)?;
            frames.extend(flush.frames);
            if flush.finished {
                let mut registry = self.registry();
                registry.streams.remove(&queue.id);
                let reason = queue.end_reason().unwrap_or(SOURCE_UNAVAILABLE);
                registry.ended.insert(queue.id.clone(), reason);
            }
        }
        Ok(frames)
    }
}

/// Where the stream list comes from: the Gateway in production, a fake in tests.
pub trait ListSource: Send + Sync {
    fn fetch(&self) -> impl Future<Output = Result<Value, String>> + Send;
}

pub struct GatewayList {
    pub client: reqwest::Client,
    pub gateway: String,
}
impl ListSource for GatewayList {
    async fn fetch(&self) -> Result<Value, String> {
        let url = format!(
            "{}/api/v1/agent/log-streams",
            self.gateway.trim_end_matches('/')
        );
        let response = self
            .client
            .get(url)
            .timeout(FETCH_TIMEOUT)
            .send()
            .await
            .map_err(|error| error.without_url().to_string())?
            .error_for_status()
            .map_err(|error| error.without_url().to_string())?;
        response
            .json()
            .await
            .map_err(|error| error.without_url().to_string())
    }
}

/// Starts the reader of one stream.
pub fn spawn_stream(spec: StreamSpec, queue: Arc<StreamQueue>) -> JoinHandle<()> {
    tokio::spawn(async move {
        let mut sink = LineSink::new(queue.clone(), spec.lines);
        let result = match spec.source {
            Source::Laravel { path } => laravel::run(path, &mut sink).await,
            Source::Journal { unit } => journalctl::run(unit, &mut sink).await,
            Source::Docker {
                container,
                process_id,
            } => docker::run(container, process_id, &mut sink).await,
        };
        if let Err(Unavailable(what)) = result {
            eprintln!(
                "orbit-agent: log stream {} ended: {what} unavailable",
                spec.id
            );
            queue.end(SOURCE_UNAVAILABLE);
        }
    })
}

struct Controller<F> {
    hub: LogHub,
    /// The reader of each started stream. A reader that has finished keeps its entry with no handle, so the
    /// stream is not started again while the Gateway still lists it.
    running: BTreeMap<String, Option<JoinHandle<()>>>,
    /// Readers the controller aborted. Each stays supervised until its end is observed, because it may have
    /// panicked before the abort.
    retired: Vec<JoinHandle<()>>,
    start: F,
}

impl<F: FnMut(StreamSpec, Arc<StreamQueue>) -> JoinHandle<()>> Controller<F> {
    fn reconcile(&mut self, specs: Vec<StreamSpec>) {
        let listed: BTreeSet<String> = specs.iter().map(|spec| spec.id.clone()).collect();
        let unlisted: Vec<String> = self
            .running
            .keys()
            .filter(|id| !listed.contains(*id))
            .cloned()
            .collect();
        for id in unlisted {
            self.stop(&id);
        }
        {
            let mut registry = self.hub.registry();
            // An end still waiting to be sent for a stream the Gateway no longer lists is not needed.
            registry.streams.retain(|id, _| listed.contains(id));
            registry.ended.retain(|id, _| listed.contains(id));
        }
        for spec in specs {
            if self.running.contains_key(&spec.id) {
                continue;
            }
            let mut registry = self.hub.registry();
            if registry.streams.contains_key(&spec.id) {
                // Its end waits to be sent.
                continue;
            }
            if let Some(reason) = registry.ended.remove(&spec.id) {
                // The Gateway still lists a stream whose end was sent, so the end may have been lost:
                // send it again instead of reading the source again.
                let queue = Arc::new(StreamQueue::new(spec.id.clone(), self.hub.agent.clone()));
                queue.end(reason);
                registry.streams.insert(spec.id.clone(), queue);
                continue;
            }
            let queue = Arc::new(StreamQueue::new(spec.id.clone(), self.hub.agent.clone()));
            registry.streams.insert(spec.id.clone(), queue.clone());
            drop(registry);
            let id = spec.id.clone();
            let handle = (self.start)(spec, queue);
            self.running.insert(id, Some(handle));
        }
    }

    fn stop(&mut self, id: &str) {
        if let Some(handle) = self.running.remove(id).flatten() {
            self.retire(handle);
        }
        self.hub.registry().streams.remove(id);
    }

    /// Aborts a reader and keeps supervising it: every path that stops a reader goes through here.
    fn retire(&mut self, handle: JoinHandle<()>) {
        handle.abort();
        self.retired.push(handle);
        #[cfg(test)]
        tests::note_retired(&self.retired);
    }

    fn stop_all(&mut self) {
        let ids: Vec<String> = self.running.keys().cloned().collect();
        for id in ids {
            self.stop(&id);
        }
    }

    /// Stops reading every stream after 60 seconds without a list, and ends each one after the lines
    /// it already read. The viewer opens a new stream that catches up, so no line is lost or repeated;
    /// reading the same stream again later would send its first lines a second time.
    fn fail_closed(&mut self) {
        let running = std::mem::take(&mut self.running);
        for (id, handle) in running {
            if let Some(handle) = handle {
                self.retire(handle);
            }
            if let Some(queue) = self.hub.registry().streams.get(&id) {
                queue.end(LIST_UNAVAILABLE);
            }
        }
    }

    /// Waits for every retired reader to end, and returns the first panic among them.
    async fn join_retired(&mut self) -> Option<tokio::task::JoinError> {
        let mut panic = None;
        for handle in self.retired.drain(..) {
            if let Err(error) = handle.await {
                if error.is_panic() && panic.is_none() {
                    panic = Some(error);
                }
            }
        }
        panic
    }

    /// Resolves when a running or retired stream reader panicked, with its panic. A reader that ends normally
    /// gives up its handle; so does a retired reader whose abort cancelled it.
    fn poll_panicked(&mut self, context: &mut Context<'_>) -> Poll<tokio::task::JoinError> {
        let mut index = 0;
        while index < self.retired.len() {
            match Pin::new(&mut self.retired[index]).poll(context) {
                Poll::Ready(result) => {
                    self.retired.swap_remove(index);
                    if let Err(error) = result {
                        if error.is_panic() {
                            return Poll::Ready(error);
                        }
                    }
                }
                Poll::Pending => index += 1,
            }
        }
        for slot in self.running.values_mut() {
            let Some(handle) = slot else { continue };
            if let Poll::Ready(result) = Pin::new(handle).poll(context) {
                *slot = None;
                if let Err(error) = result {
                    if error.is_panic() {
                        return Poll::Ready(error);
                    }
                }
            }
        }
        Poll::Pending
    }
}

/// Fetches the list when prompted and every 15 seconds while a stream runs, with at most one fetch in
/// flight, and starts and stops streams to match it. Without a list for 60 seconds, it stops all.
///
/// A stream reader that panics panics the controller with the same panic, so the observer's owner of this
/// task ends the process (`crate::supervise`). That holds for a reader the controller stopped, too: a stopped
/// reader stays supervised until its end is observed.
pub async fn run_controller<L: ListSource>(
    hub: LogHub,
    mut prompts: mpsc::UnboundedReceiver<()>,
    list: L,
    start: impl FnMut(StreamSpec, Arc<StreamQueue>) -> JoinHandle<()>,
) {
    let mut controller = Controller {
        hub,
        running: BTreeMap::new(),
        retired: Vec::new(),
        start,
    };
    let mut refresh = tokio::time::interval_at(Instant::now() + REFRESH, REFRESH);
    refresh.set_missed_tick_behavior(MissedTickBehavior::Delay);
    let mut last_ok: Option<Instant> = None;
    let mut last_error: Option<String> = None;
    loop {
        let deadline = last_ok.map(|at| at + FAIL_CLOSED);
        let armed = deadline.is_some() && !controller.running.is_empty();
        tokio::select! {
            prompt = prompts.recv() => if prompt.is_none() {
                controller.stop_all();
                if let Some(error) = controller.join_retired().await {
                    std::panic::resume_unwind(error.into_panic());
                }
                return;
            },
            _ = refresh.tick() => if controller.running.is_empty() {
                continue;
            },
            error = std::future::poll_fn(|context| controller.poll_panicked(context)) => {
                std::panic::resume_unwind(error.into_panic());
            },
            _ = tokio::time::sleep_until(deadline.unwrap_or_else(Instant::now)), if armed => {
                eprintln!("orbit-agent: no log stream list for 60 seconds; ending every stream");
                controller.fail_closed();
                continue;
            },
        }
        loop {
            while prompts.try_recv().is_ok() {}
            // Readers stay supervised during the fetch, so prompts that keep this loop busy cannot hide a
            // panic or let finished retired readers pile up.
            let fetched = tokio::select! {
                fetched = list.fetch() => fetched,
                error = std::future::poll_fn(|context| controller.poll_panicked(context)) => {
                    std::panic::resume_unwind(error.into_panic());
                },
            };
            let result = fetched.and_then(|body| parse_stream_list(&body).map_err(str::to_owned));
            match result {
                Ok(specs) => {
                    last_ok = Some(Instant::now());
                    last_error = None;
                    controller.reconcile(specs);
                }
                Err(error) => {
                    if last_error.as_ref() != Some(&error) {
                        eprintln!("orbit-agent: log stream list failed: {error}");
                        last_error = Some(error);
                    }
                    let stale = last_ok.is_none_or(|at| at.elapsed() >= FAIL_CLOSED);
                    if stale && !controller.running.is_empty() {
                        eprintln!(
                            "orbit-agent: no log stream list for 60 seconds; ending every stream"
                        );
                        controller.fail_closed();
                    }
                }
            }
            // A prompt that arrived during the fetch gets one more fetch.
            if prompts.try_recv().is_err() {
                break;
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::logs::limits::BATCH_INTERVAL;
    use serde_json::json;
    use std::sync::atomic::{AtomicUsize, Ordering};

    #[derive(Clone)]
    struct FakeList {
        body: Arc<Mutex<Result<Value, String>>>,
        fetches: Arc<AtomicUsize>,
        fetched: Arc<tokio::sync::Notify>,
        delay: Duration,
    }
    impl Default for FakeList {
        fn default() -> Self {
            Self {
                body: Arc::new(Mutex::new(Err("unset".into()))),
                fetches: Arc::default(),
                fetched: Arc::default(),
                delay: Duration::ZERO,
            }
        }
    }
    impl FakeList {
        fn set(&self, body: Result<Value, String>) {
            *self.body.lock().unwrap() = body;
        }
    }
    impl ListSource for FakeList {
        async fn fetch(&self) -> Result<Value, String> {
            self.fetches.fetch_add(1, Ordering::SeqCst);
            tokio::time::sleep(self.delay).await;
            self.fetched.notify_waiters();
            self.body.lock().unwrap().clone()
        }
    }

    fn list(ids: &[&str]) -> Result<Value, String> {
        Ok(json!({"data": ids.iter().map(|id| json!({
            "id": id, "lines": 10, "source": {"type": "journal", "unit": "orbit-process-1-a.service"}
        })).collect::<Vec<_>>(), "meta": {}}))
    }

    fn id(n: u8) -> String {
        format!("{n:032x}")
    }

    type Started = Arc<Mutex<Vec<String>>>;

    fn setup(fake: FakeList) -> (LogHub, Started, JoinHandle<()>) {
        let (hub, prompts) = LogHub::new();
        let started: Started = Arc::default();
        let record = started.clone();
        let controller = tokio::spawn(run_controller(
            hub.clone(),
            prompts,
            fake,
            move |spec, _queue| {
                record.lock().unwrap().push(spec.id.clone());
                tokio::spawn(std::future::pending::<()>())
            },
        ));
        (hub, started, controller)
    }

    async fn settle() {
        for _ in 0..20 {
            tokio::task::yield_now().await;
        }
    }

    #[tokio::test(start_paused = true)]
    async fn prompts_start_and_stop_listed_streams() {
        let fake = FakeList::default();
        fake.set(list(&[&id(1), &id(2)]));
        let (hub, started, _controller) = setup(fake.clone());
        settle().await;
        assert_eq!(
            fake.fetches.load(Ordering::SeqCst),
            0,
            "nothing without a prompt"
        );
        hub.prompt();
        settle().await;
        assert_eq!(*started.lock().unwrap(), [id(1), id(2)]);
        assert_eq!(hub.running(), 2);
        fake.set(list(&[&id(2), &id(3)]));
        hub.prompt();
        settle().await;
        assert_eq!(*started.lock().unwrap(), [id(1), id(2), id(3)]);
        assert_eq!(hub.running(), 2);
        let names: Vec<_> = hub.registry().streams.keys().cloned().collect();
        assert_eq!(names, [id(2), id(3)]);
    }

    #[tokio::test(start_paused = true)]
    async fn prompts_during_a_fetch_coalesce_into_one_more_fetch() {
        let fake = FakeList {
            delay: Duration::from_secs(1),
            ..FakeList::default()
        };
        fake.set(list(&[]));
        let (hub, _started, _controller) = setup(fake.clone());
        hub.prompt();
        settle().await;
        for _ in 0..5 {
            hub.prompt();
        }
        tokio::time::sleep(Duration::from_secs(5)).await;
        assert_eq!(fake.fetches.load(Ordering::SeqCst), 2);
    }

    #[tokio::test(start_paused = true)]
    async fn refreshes_every_15_seconds_only_while_streams_run() {
        let fake = FakeList::default();
        fake.set(list(&[]));
        let (hub, _started, _controller) = setup(fake.clone());
        hub.prompt();
        tokio::time::sleep(Duration::from_secs(61)).await;
        assert_eq!(fake.fetches.load(Ordering::SeqCst), 1);
        fake.set(list(&[&id(1)]));
        hub.prompt();
        settle().await;
        let before = fake.fetches.load(Ordering::SeqCst);
        tokio::time::sleep(Duration::from_secs(46)).await;
        assert_eq!(fake.fetches.load(Ordering::SeqCst), before + 3);
    }

    #[tokio::test(start_paused = true)]
    async fn fails_closed_after_60_seconds_without_a_list() {
        let fake = FakeList::default();
        fake.set(list(&[&id(1)]));
        let (hub, _started, _controller) = setup(fake.clone());
        hub.prompt();
        settle().await;
        assert_eq!(hub.running(), 1);
        fake.set(Err("gateway down".into()));
        tokio::time::sleep(Duration::from_secs(59)).await;
        assert_eq!(hub.running(), 1);
        tokio::time::sleep(Duration::from_secs(2)).await;
        let frames = hub.flush("presence-node-logs.1", Instant::now()).unwrap();
        assert_eq!(frames.len(), 1);
        assert_eq!(frames[0].event, "client-log-end");
        assert_eq!(frames[0].data["reason"], "list_unavailable");
        assert_eq!(hub.running(), 0);
    }

    #[tokio::test(start_paused = true)]
    async fn a_stream_ended_without_a_list_is_never_read_again_and_its_end_is_repeated() {
        let fake = FakeList::default();
        fake.set(list(&[&id(1)]));
        let (hub, started, _controller) = setup(fake.clone());
        hub.prompt();
        settle().await;
        hub.registry().streams[&id(1)].push("read before the outage".into());
        fake.set(Err("gateway down".into()));
        tokio::time::sleep(Duration::from_secs(61)).await;
        // The Gateway comes back and still lists the stream before the end reached it.
        fake.set(list(&[&id(1)]));
        hub.prompt();
        settle().await;
        assert_eq!(*started.lock().unwrap(), [id(1)], "never started again");
        // The lines read before the outage go out first, then the end.
        let frames = hub.flush("presence-node-logs.1", Instant::now()).unwrap();
        assert_eq!(lines_of(&frames), ["read before the outage"]);
        assert_eq!(frames.last().unwrap().data["reason"], "list_unavailable");
        // Listed again after the end was sent: the end is sent again, the source is not read.
        hub.prompt();
        settle().await;
        tokio::time::sleep(Duration::from_millis(300)).await;
        let frames = hub.flush("presence-node-logs.1", Instant::now()).unwrap();
        assert_eq!(frames.len(), 1);
        assert_eq!(frames[0].event, "client-log-end");
        assert_eq!(frames[0].data["reason"], "list_unavailable");
        assert_eq!(*started.lock().unwrap(), [id(1)]);
        // Once the Gateway closed it, nothing is left.
        fake.set(list(&[]));
        hub.prompt();
        settle().await;
        assert_eq!(hub.running(), 0);
        assert!(hub.registry().ended.is_empty());
    }

    #[tokio::test(start_paused = true)]
    async fn an_ended_stream_is_not_started_again_while_listed() {
        let fake = FakeList::default();
        fake.set(list(&[&id(1)]));
        let (hub, started, _controller) = setup(fake.clone());
        hub.prompt();
        settle().await;
        let queue = hub.registry().streams[&id(1)].clone();
        queue.end(SOURCE_UNAVAILABLE);
        let frames = hub.flush("presence-node-logs.1", Instant::now()).unwrap();
        assert_eq!(frames.len(), 1);
        assert_eq!(frames[0].event, "client-log-end");
        assert_eq!(hub.running(), 0);
        hub.prompt();
        settle().await;
        assert_eq!(started.lock().unwrap().len(), 1);
        fake.set(list(&[]));
        hub.prompt();
        settle().await;
        fake.set(list(&[&id(1)]));
        hub.prompt();
        settle().await;
        assert_eq!(started.lock().unwrap().len(), 2);
    }

    fn lines_of(frames: &[ClientFrame]) -> Vec<String> {
        frames
            .iter()
            .filter(|f| f.event == "client-log")
            .flat_map(|f| f.data["lines"].as_array().unwrap().clone())
            .map(|l| l.as_str().unwrap().to_owned())
            .collect()
    }

    /// Flush as the realtime connection does, stopping as soon as a frame arrives.
    async fn next_frames(hub: &LogHub) -> Vec<ClientFrame> {
        tokio::time::timeout(Duration::from_secs(5), async {
            let mut tick = tokio::time::interval(BATCH_INTERVAL);
            loop {
                tick.tick().await;
                let frames = hub.flush("presence-node-logs.7", Instant::now()).unwrap();
                if !frames.is_empty() {
                    return frames;
                }
            }
        })
        .await
        .expect("the stream produces a frame")
    }

    #[tokio::test(start_paused = true)]
    async fn laravel_stream_end_to_end_redacts_and_ends_on_a_link() {
        use std::io::Write;
        let root = std::env::temp_dir().join(format!("orbit-agent-e2e-{}", rand::random::<u64>()));
        let logs = root.join("storage/logs");
        std::fs::create_dir_all(&logs).unwrap();
        let root = std::fs::canonicalize(&root).unwrap();
        let log = root.join("storage/logs/laravel.log");
        std::fs::write(&log, "old\nfirst DB_PASSWORD=hunter2\n").unwrap();
        let fake = FakeList::default();
        fake.set(Ok(serde_json::json!({"data": [
            {"id": id(1), "lines": 5, "source": {"type": "laravel", "path": root.to_str().unwrap()}}
        ]})));
        let (hub, prompts) = LogHub::new();
        let starts = Arc::new(AtomicUsize::new(0));
        let started = starts.clone();
        tokio::spawn(run_controller(
            hub.clone(),
            prompts,
            fake.clone(),
            move |spec, queue| {
                started.fetch_add(1, Ordering::SeqCst);
                spawn_stream(spec, queue)
            },
        ));
        hub.prompt();
        let frames = next_frames(&hub).await;
        assert_eq!(lines_of(&frames), ["old", "first DB_PASSWORD=[REDACTED]"]);
        assert_eq!(frames[0].data["sequence"], 1);
        assert_eq!(frames[0].data["stream"], id(1));
        std::fs::OpenOptions::new()
            .append(true)
            .open(&log)
            .unwrap()
            .write_all(b"Authorization: Bearer abcdefgh12345\n")
            .unwrap();
        let frames = next_frames(&hub).await;
        assert_eq!(lines_of(&frames), ["Authorization: [REDACTED]"]);
        assert_eq!(frames[0].data["sequence"], 2);
        // storage/logs becomes a link: the stream ends with source_unavailable.
        std::fs::rename(&logs, root.join("storage/real")).unwrap();
        std::os::unix::fs::symlink(root.join("storage/real"), &logs).unwrap();
        let frames = next_frames(&hub).await;
        let end = frames.last().unwrap();
        assert_eq!(end.event, "client-log-end");
        assert_eq!(end.data["reason"], "source_unavailable");
        assert_eq!(hub.running(), 0);
        // The Gateway still lists it until it has processed the end; it is not started again.
        let fetched = fake.fetched.notified();
        hub.prompt();
        tokio::time::timeout(Duration::from_secs(5), fetched)
            .await
            .expect("the controller processes the prompt");
        assert_eq!(hub.running(), 0);
        assert_eq!(starts.load(Ordering::SeqCst), 1, "never started again");
        let _ = std::fs::remove_dir_all(&root);
    }

    #[tokio::test(start_paused = true)]
    async fn invalid_lists_count_as_failures() {
        let fake = FakeList::default();
        fake.set(list(&[&id(1)]));
        let (hub, _started, _controller) = setup(fake.clone());
        hub.prompt();
        settle().await;
        fake.set(Ok(json!({"data": "nope"})));
        tokio::time::sleep(Duration::from_secs(61)).await;
        let frames = hub.flush("presence-node-logs.1", Instant::now()).unwrap();
        assert_eq!(frames[0].data["reason"], "list_unavailable");
        assert_eq!(hub.running(), 0);
    }

    #[test]
    #[should_panic(expected = "the log registry lock is never held across a panic")]
    fn a_poisoned_registry_lock_is_fatal() {
        let (hub, _prompts) = LogHub::new();
        let holder = hub.clone();
        let _ = std::thread::spawn(move || {
            let _guard = holder.registry();
            panic!("poison the registry lock");
        })
        .join();
        hub.running();
    }

    #[tokio::test(start_paused = true)]
    async fn a_panicked_stream_reader_ends_the_observer_run() {
        let fake = FakeList::default();
        fake.set(list(&[&id(1)]));
        let (hub, prompts) = LogHub::new();
        let mut supervised = tokio::task::JoinSet::new();
        supervised.spawn(run_controller(hub.clone(), prompts, fake, |_, _| {
            tokio::spawn(async { panic!("the stream reader failed") })
        }));
        hub.prompt();
        let error = tokio::time::timeout(REFRESH, crate::supervise(&mut supervised))
            .await
            .expect("the panic ends the run");
        assert!(
            error.to_string().contains("the stream reader failed"),
            "{error}"
        );
    }

    #[tokio::test(start_paused = true)]
    async fn a_stream_reader_that_ends_is_not_fatal_or_started_again() {
        let fake = FakeList::default();
        fake.set(list(&[&id(1)]));
        let (hub, prompts) = LogHub::new();
        let started: Started = Arc::default();
        let record = started.clone();
        let mut supervised = tokio::task::JoinSet::new();
        supervised.spawn(run_controller(
            hub.clone(),
            prompts,
            fake,
            move |spec, _| {
                record.lock().unwrap().push(spec.id.clone());
                tokio::spawn(async {})
            },
        ));
        hub.prompt();
        settle().await;
        hub.prompt();
        let supervision = tokio::time::timeout(REFRESH * 2, crate::supervise(&mut supervised));
        assert!(supervision.await.is_err(), "no panic, so the run goes on");
        assert_eq!(*started.lock().unwrap(), [id(1)], "never started again");
    }

    /// A list fetch that hands out one stream, then waits for `release` and returns no stream.
    struct UnlistDuringFetch {
        calls: AtomicUsize,
        fetching: Arc<tokio::sync::Notify>,
        release: Arc<tokio::sync::Notify>,
    }
    impl ListSource for UnlistDuringFetch {
        async fn fetch(&self) -> Result<Value, String> {
            if self.calls.fetch_add(1, Ordering::SeqCst) == 0 {
                return list(&[&id(1)]);
            }
            self.fetching.notify_one();
            self.release.notified().await;
            list(&[])
        }
    }

    #[tokio::test(start_paused = true)]
    async fn a_reader_that_panics_during_a_fetch_that_unlists_it_ends_the_observer_run() {
        let (hub, prompts) = LogHub::new();
        let agent = Arc::clone(&hub.agent);
        assert!(std::thread::spawn(move || {
            let _guard = agent.lock().unwrap();
            panic!("poison the agent bucket");
        })
        .join()
        .is_err());
        let fetching = Arc::new(tokio::sync::Notify::new());
        let release = Arc::new(tokio::sync::Notify::new());
        let trigger = Arc::new(tokio::sync::Notify::new());
        let reader: Arc<Mutex<Option<tokio::task::AbortHandle>>> = Arc::default();
        let record = Arc::clone(&reader);
        let pull = Arc::clone(&trigger);
        let mut supervised = tokio::task::JoinSet::new();
        supervised.spawn(run_controller(
            hub.clone(),
            prompts,
            UnlistDuringFetch {
                calls: AtomicUsize::new(0),
                fetching: Arc::clone(&fetching),
                release: Arc::clone(&release),
            },
            move |_, queue| {
                let pull = Arc::clone(&pull);
                let handle = tokio::spawn(async move {
                    pull.notified().await;
                    queue.push("a log line".into());
                });
                *record.lock().unwrap() = Some(handle.abort_handle());
                handle
            },
        ));
        hub.prompt();
        settle().await;
        hub.prompt();
        fetching.notified().await;
        trigger.notify_one();
        let handle = reader.lock().unwrap().clone().unwrap();
        while !handle.is_finished() {
            tokio::task::yield_now().await;
        }
        release.notify_one();
        let error = tokio::time::timeout(REFRESH / 2, crate::supervise(&mut supervised))
            .await
            .expect("a stopped reader that panicked still ends the run");
        assert!(
            error
                .to_string()
                .contains("a stream queue or token bucket lock is never held across a panic"),
            "{error}"
        );
    }

    #[tokio::test(start_paused = true)]
    async fn a_reader_that_panics_before_the_list_fails_closed_ends_the_observer_run() {
        let fake = FakeList {
            delay: FAIL_CLOSED + Duration::from_secs(1),
            ..FakeList::default()
        };
        fake.set(list(&[&id(1)]));
        let (hub, prompts) = LogHub::new();
        let failing = fake.clone();
        let mut supervised = tokio::task::JoinSet::new();
        supervised.spawn(run_controller(hub.clone(), prompts, fake, move |_, _| {
            // The next fetch fails after 61 s; the reader panics within it, before the list fails closed.
            failing.set(Err("down".into()));
            tokio::spawn(async {
                tokio::time::sleep(FAIL_CLOSED / 2).await;
                panic!("the stream reader failed");
            })
        }));
        hub.prompt();
        settle().await;
        hub.prompt();
        let error = tokio::time::timeout(FAIL_CLOSED * 3, crate::supervise(&mut supervised))
            .await
            .expect("a reader retired by fail-closed that panicked still ends the run");
        assert!(
            error.to_string().contains("the stream reader failed"),
            "{error}"
        );
    }

    #[tokio::test(start_paused = true)]
    async fn a_reader_cancelled_by_its_stop_does_not_end_the_observer_run() {
        let fake = FakeList::default();
        fake.set(list(&[&id(1)]));
        let (hub, prompts) = LogHub::new();
        let mut supervised = tokio::task::JoinSet::new();
        supervised.spawn(run_controller(
            hub.clone(),
            prompts,
            fake.clone(),
            |_, _| tokio::spawn(std::future::pending::<()>()),
        ));
        hub.prompt();
        settle().await;
        assert_eq!(hub.running(), 1);
        fake.set(list(&[]));
        hub.prompt();
        settle().await;
        assert_eq!(hub.running(), 0);
        let supervision = tokio::time::timeout(REFRESH * 2, crate::supervise(&mut supervised));
        assert!(
            supervision.await.is_err(),
            "a cancelled reader is not fatal"
        );
    }

    thread_local! {
        /// The most retired readers that had already finished at one retire, on this test's thread.
        static MAX_FINISHED_RETIRED: std::cell::Cell<usize> = const { std::cell::Cell::new(0) };
    }
    pub(super) fn note_retired(retired: &[JoinHandle<()>]) {
        let finished = retired.iter().filter(|handle| handle.is_finished()).count();
        MAX_FINISHED_RETIRED.with(|max| max.set(max.get().max(finished)));
    }

    /// Lists one new stream per fetch, and prompts again while each fetch is in flight, so the fetch loop never
    /// goes back to waiting. The fetch after `stop_at` never ends.
    struct BusyLists {
        hub: LogHub,
        calls: AtomicUsize,
        stop_at: usize,
        reached: Arc<tokio::sync::Notify>,
    }
    impl ListSource for BusyLists {
        async fn fetch(&self) -> Result<Value, String> {
            let n = self.calls.fetch_add(1, Ordering::SeqCst);
            if n == self.stop_at {
                self.reached.notify_one();
                std::future::pending::<()>().await;
            }
            tokio::time::sleep(Duration::from_secs(1)).await;
            self.hub.prompt();
            list(&[&format!("{:032x}", n + 1)])
        }
    }

    #[tokio::test(start_paused = true)]
    async fn finished_readers_do_not_pile_up_while_prompts_keep_the_fetch_loop_busy() {
        MAX_FINISHED_RETIRED.with(|max| max.set(0));
        let (hub, prompts) = LogHub::new();
        let reached = Arc::new(tokio::sync::Notify::new());
        let controller = tokio::spawn(run_controller(
            hub.clone(),
            prompts,
            BusyLists {
                hub: hub.clone(),
                calls: AtomicUsize::new(0),
                stop_at: 128,
                reached: Arc::clone(&reached),
            },
            |_, _| tokio::spawn(async {}),
        ));
        hub.prompt();
        tokio::time::timeout(Duration::from_secs(140), reached.notified())
            .await
            .expect("128 fetches ran");
        controller.abort();
        let retained = MAX_FINISHED_RETIRED.with(std::cell::Cell::get);
        assert!(
            retained <= crate::logs::MAX_STREAMS,
            "finished retired readers are reaped during the fetches; {retained} were kept"
        );
    }

    #[tokio::test(start_paused = true)]
    async fn a_reader_panic_ends_the_observer_run_while_prompts_keep_the_fetch_loop_busy() {
        let (hub, prompts) = LogHub::new();
        let started = Arc::new(AtomicUsize::new(0));
        let record = Arc::clone(&started);
        let mut supervised = tokio::task::JoinSet::new();
        supervised.spawn(run_controller(
            hub.clone(),
            prompts,
            BusyLists {
                hub: hub.clone(),
                calls: AtomicUsize::new(0),
                stop_at: usize::MAX,
                reached: Arc::default(),
            },
            move |_, _| {
                let n = record.fetch_add(1, Ordering::SeqCst);
                tokio::spawn(async move {
                    if n == 0 {
                        panic!("the first reader panicked");
                    }
                })
            },
        ));
        hub.prompt();
        let error =
            tokio::time::timeout(Duration::from_secs(30), crate::supervise(&mut supervised))
                .await
                .expect("a reader panic ends the run while list updates continue");
        assert!(
            error.to_string().contains("the first reader panicked"),
            "{error}"
        );
    }

    /// A controller with one reader that has already panicked, as when the fetch result wins the race with
    /// the reader's completion and the reply stops it before supervision sees the panic.
    async fn controller_with_a_panicked_reader(
    ) -> Controller<impl FnMut(StreamSpec, Arc<StreamQueue>) -> JoinHandle<()>> {
        let (hub, _prompts) = LogHub::new();
        let reader = tokio::spawn(async { panic!("the stream reader failed") });
        while !reader.is_finished() {
            tokio::task::yield_now().await;
        }
        let mut controller = Controller {
            hub,
            running: BTreeMap::new(),
            retired: Vec::new(),
            start: |_: StreamSpec, _: Arc<StreamQueue>| tokio::spawn(async {}),
        };
        controller.running.insert(id(1), Some(reader));
        controller
    }

    async fn reported_panic<F>(controller: &mut Controller<F>) -> Option<String>
    where
        F: FnMut(StreamSpec, Arc<StreamQueue>) -> JoinHandle<()>,
    {
        let panicked = std::future::poll_fn(|context| controller.poll_panicked(context));
        let error = tokio::time::timeout(Duration::from_secs(1), panicked)
            .await
            .ok()?;
        Some(crate::panic_text(&*error.into_panic()).to_owned())
    }

    #[tokio::test(start_paused = true)]
    async fn a_reader_stopped_after_it_panicked_is_still_reported() {
        let mut controller = controller_with_a_panicked_reader().await;
        controller.stop(&id(1));
        assert!(controller.running.is_empty());
        assert_eq!(
            reported_panic(&mut controller).await.as_deref(),
            Some("the stream reader failed")
        );
    }

    #[tokio::test(start_paused = true)]
    async fn a_reader_failed_closed_after_it_panicked_is_still_reported() {
        let mut controller = controller_with_a_panicked_reader().await;
        controller.fail_closed();
        assert!(controller.running.is_empty());
        assert_eq!(
            reported_panic(&mut controller).await.as_deref(),
            Some("the stream reader failed")
        );
    }

    #[tokio::test(start_paused = true)]
    async fn a_reader_stopped_after_it_panicked_is_reported_when_the_controller_ends() {
        let mut controller = controller_with_a_panicked_reader().await;
        controller.stop_all();
        let error = controller.join_retired().await.expect("the panic is kept");
        assert_eq!(
            crate::panic_text(&*error.into_panic()),
            "the stream reader failed"
        );
    }
}
