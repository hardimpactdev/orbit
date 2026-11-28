//! The append-only journal: one record before an action and one after it.
//!
//! One writer thread appends. Connection threads hand it each record over a channel and wait for the write,
//! so the open record is on file before the action runs, and no lock is held around the I/O. No record waits
//! for a disk sync: a separate syncer thread syncs the file once a second, because the page cache survives
//! an executor crash and a Node reboot expires every order. The writer keeps the live records and compacts
//! the journal while the executor runs (ADR 0266), with the same rule that start-up uses; the syncer writes
//! and syncs the compacted file, and the writer renames it into place.

use std::collections::HashMap;
use std::fs::{self, File, OpenOptions};
use std::io::{self, BufRead as _, BufReader, Write as _};
use std::path::{Path, PathBuf};
use std::sync::mpsc::{self, Receiver, RecvTimeoutError, Sender};
use std::sync::Arc;
use std::thread;
use std::time::{Duration, Instant};

use serde::{Deserialize, Serialize};

use crate::action::Action;
use crate::executor::ORDER_EXPIRED;
use crate::ids::{Nonce, OperationId, TargetId};
use crate::order::PowerCheck;

/// How often the background thread syncs the journal.
pub const SYNC_INTERVAL: Duration = Duration::from_secs(1);

/// How often the writer compacts the journal while the executor runs.
pub const COMPACT_INTERVAL: Duration = Duration::from_secs(10 * 60);

/// How many appended records make the writer compact before the interval ends.
pub const COMPACT_RECORDS: usize = 10_000;

/// How long a done record is kept, in milliseconds.
pub const DONE_RETENTION_MS: u64 = 24 * 60 * 60 * 1000;

/// The key of a done record: a repeat returns the first result only when all three are equal (ADR 0267).
#[derive(Debug, Clone, PartialEq, Eq, Hash, Serialize, Deserialize)]
pub struct DoneKey {
    /// The Operation.
    pub operation: OperationId,
    /// The action.
    pub action: Action,
    /// The target.
    pub target: TargetId,
}

/// How an action ended.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
#[serde(tag = "status", rename_all = "snake_case")]
#[non_exhaustive]
pub enum Outcome {
    /// The action finished.
    Done,
    /// Sleep completed and measured the actual container state after reclaim.
    Slept {
        /// Public sleep measurements, retained for a repeated delivery.
        receipt: serde_json::Value,
    },
    /// The action finished, but part of its work was cut short: a wake whose container runs again without every recorded
    /// page restored.
    Partial {
        /// Why the work was cut short.
        code: String,
    },
    /// The action failed with a stable code.
    Failed {
        /// The failure's code.
        code: String,
        /// Bounded public diagnostics for actions that provide them.
        #[serde(default, skip_serializing_if = "Option::is_none")]
        detail: Option<String>,
    },
    /// A desired-state primitive converged its resource and checked it (ADR 0271). A journal holding this outcome
    /// needs an executor that knows it: roll back only to a release that has the firewall family.
    Applied {
        /// How many changes it made; 0 means the resource was already right.
        changes: u32,
    },
    /// A primitive answered public text the Gateway reads, such as a certificate request (ADR 0271). Never a secret: the
    /// journal keeps it for a repeated delivery. A journal holding this outcome needs an executor that has the route family.
    Output {
        /// The answer.
        stdout: String,
    },
}

impl Outcome {
    /// A failure with no public diagnostic.
    pub fn failed(code: impl Into<String>) -> Self {
        Self::Failed {
            code: code.into(),
            detail: None,
        }
    }

    pub(crate) fn ends_operation(&self, action: Action) -> bool {
        !action.receipt_fenced() || !matches!(self, Self::Failed { code, .. } if code == ORDER_EXPIRED)
    }
}

/// An order the journal recorded as open whose key has no result yet: crash recovery finishes it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Unfinished {
    /// The order's nonce.
    pub nonce: Nonce,
    /// The done record's key.
    pub key: DoneKey,
    /// The signed check of a power action.
    pub power_check: PowerCheck,
    /// The order's typed input.
    pub input: serde_json::Value,
}

/// One journal line.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
#[serde(tag = "record", rename_all = "snake_case")]
#[non_exhaustive]
pub enum Record {
    /// Written before the action runs.
    Open {
        /// The order's nonce.
        nonce: Nonce,
        /// The done record's key.
        #[serde(flatten)]
        key: DoneKey,
        /// The order's expiry, in Unix milliseconds.
        expires_ms: u64,
        /// The signed check associated with the power action.
        #[serde(default)]
        power_check: PowerCheck,
        /// The order's typed input, so recovery can finish the order after a restart.
        #[serde(default, skip_serializing_if = "serde_json::Value::is_null")]
        input: serde_json::Value,
    },
    /// Written for a nonce whose order did not run, because its key already had a result.
    Seen {
        /// The order's nonce.
        nonce: Nonce,
        /// The done record's key.
        #[serde(flatten)]
        key: DoneKey,
        /// The order's expiry, in Unix milliseconds.
        expires_ms: u64,
    },
    /// Written after the action ran.
    Done {
        /// The order's nonce.
        nonce: Nonce,
        /// The done record's key.
        #[serde(flatten)]
        key: DoneKey,
        /// How the action ended.
        outcome: Outcome,
        /// When it ended, in Unix milliseconds.
        at_ms: u64,
    },
}

/// When the writer compacts the journal while the executor runs.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Compaction {
    /// At most this long after the last compaction.
    pub interval: Duration,
    /// Or once this many records were appended since it.
    pub records: usize,
}

impl Default for Compaction {
    fn default() -> Self {
        Self {
            interval: COMPACT_INTERVAL,
            records: COMPACT_RECORDS,
        }
    }
}

/// The records that still matter: nonces that have not expired, orders open without a result, and the done
/// records of the last 24 hours. Start-up and the running compaction both keep exactly these.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Live {
    nonces: HashMap<Nonce, (DoneKey, u64)>,
    open: HashMap<Nonce, (PowerCheck, serde_json::Value)>,
    done: HashMap<DoneKey, (Nonce, Outcome, u64)>,
}

impl Live {
    /// Builds the live records from a journal's records, in file order.
    pub fn from_records(records: impl IntoIterator<Item = Record>) -> Self {
        let mut live = Self::default();
        for record in records {
            live.apply(&record);
        }
        live
    }

    /// Takes one more record into account.
    pub fn apply(&mut self, record: &Record) {
        match record {
            Record::Open {
                nonce,
                key,
                expires_ms,
                power_check,
                input,
            } => {
                self.nonces.insert(*nonce, (key.clone(), *expires_ms));
                self.open.insert(*nonce, (*power_check, input.clone()));
            }
            Record::Seen {
                nonce,
                key,
                expires_ms,
            } => {
                self.nonces.insert(*nonce, (key.clone(), *expires_ms));
            }
            Record::Done {
                nonce,
                key,
                outcome,
                at_ms,
            } => {
                self.open.remove(nonce);
                if outcome.ends_operation(key.action) {
                    self.done.insert(key.clone(), (*nonce, outcome.clone(), *at_ms));
                }
            }
        }
    }

    /// Drops what no longer matters at `now_ms`: expired nonces, unless their order is still open, and done
    /// records older than 24 hours. An open order whose key already has a result is no longer open.
    pub fn prune(&mut self, now_ms: u64) {
        let done = &self.done;
        let nonces = &self.nonces;
        self.open.retain(|nonce, _| {
            nonces
                .get(nonce)
                .is_some_and(|(key, _)| !done.contains_key(key))
        });
        let open = &self.open;
        self.nonces
            .retain(|nonce, (_, expires_ms)| *expires_ms > now_ms || open.contains_key(nonce));
        self.done
            .retain(|_, (_, _, at_ms)| now_ms.saturating_sub(*at_ms) < DONE_RETENTION_MS);
    }

    /// The orders that were open without a result: crash recovery runs each one again.
    pub fn unfinished(&self) -> Vec<Unfinished> {
        let mut unfinished: Vec<Unfinished> = self
            .open
            .iter()
            .filter_map(|(nonce, (power_check, input))| {
                self.nonces.get(nonce).map(|(key, _)| Unfinished {
                    nonce: *nonce,
                    key: key.clone(),
                    power_check: *power_check,
                    input: input.clone(),
                })
            })
            .filter(|open| !self.done.contains_key(&open.key))
            .collect();
        unfinished.sort_by_key(|open| open.nonce);
        unfinished
    }

    /// The orders accepted without an open record or a result: a crash ended their wait for the target's lock,
    /// so they never acted. Start-up ends each one as expired.
    pub fn abandoned(&self) -> Vec<(Nonce, DoneKey)> {
        let open_keys: Vec<&DoneKey> = self
            .open
            .keys()
            .filter_map(|nonce| self.nonces.get(nonce).map(|(key, _)| key))
            .collect();
        let mut abandoned: Vec<(Nonce, DoneKey)> = self
            .nonces
            .iter()
            .filter(|(nonce, (key, _))| {
                !self.open.contains_key(*nonce)
                    && !self.done.contains_key(key)
                    && !open_keys.contains(&key)
            })
            .map(|(nonce, (key, _))| (*nonce, key.clone()))
            .collect();
        abandoned.sort_by_key(|(nonce, _)| *nonce);
        abandoned.dedup_by(|a, b| a.1 == b.1);
        abandoned
    }

    /// Every seen nonce with its key and expiry.
    pub fn nonces(&self) -> impl Iterator<Item = (Nonce, &DoneKey, u64)> {
        self.nonces
            .iter()
            .map(|(nonce, (key, expires_ms))| (*nonce, key, *expires_ms))
    }

    /// Every done record with its nonce, outcome and time.
    pub fn done(&self) -> impl Iterator<Item = (&DoneKey, Nonce, &Outcome, u64)> {
        self.done
            .iter()
            .map(|(key, (nonce, outcome, at_ms))| (key, *nonce, outcome, *at_ms))
    }

    /// The records a compacted journal holds: open orders first, so that a crash during recovery repeats
    /// them, then the other seen nonces, then the done records.
    pub fn records(&self) -> Vec<Record> {
        let mut records: Vec<Record> = self
            .nonces
            .iter()
            .map(|(nonce, (key, expires_ms))| {
                let (nonce, key, expires_ms) = (*nonce, key.clone(), *expires_ms);
                if let Some((power_check, input)) = self.open.get(&nonce) {
                    Record::Open {
                        nonce,
                        key,
                        expires_ms,
                        power_check: *power_check,
                        input: input.clone(),
                    }
                } else {
                    Record::Seen {
                        nonce,
                        key,
                        expires_ms,
                    }
                }
            })
            .collect();
        records.sort_by_key(|record| matches!(record, Record::Seen { .. }));
        records.extend(
            self.done
                .iter()
                .map(|(key, (nonce, outcome, at_ms))| Record::Done {
                    nonce: *nonce,
                    key: key.clone(),
                    outcome: outcome.clone(),
                    at_ms: *at_ms,
                }),
        );
        records
    }
}

/// Whether an append waits for the disk.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Durability {
    /// Written to the file; the syncer syncs it within a second.
    Written,
    /// Written and synced before the append answers.
    Synced,
}

enum Command {
    Append(Record, Sender<Result<(), JournalError>>),
    /// Append, then sync the file before answering.
    AppendSynced(Record, Sender<Result<(), JournalError>>),
    Compact(Sender<Result<(), JournalError>>),
}

/// The handle to the journal's writer thread. Cloning it is cheap; the writer ends when every handle is gone.
#[derive(Debug, Clone)]
pub struct Journal {
    commands: Sender<Command>,
}

impl std::fmt::Debug for Command {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(match self {
            Self::Append(..) => "Append",
            Self::AppendSynced(..) => "AppendSynced",
            Self::Compact(_) => "Compact",
        })
    }
}

/// Why the journal cannot be read or written.
#[derive(Debug, thiserror::Error)]
#[non_exhaustive]
pub enum JournalError {
    /// A file operation failed.
    #[error("journal {action} {path}")]
    Io {
        /// What was being done.
        action: &'static str,
        /// The file.
        path: PathBuf,
        /// The cause.
        #[source]
        source: io::Error,
    },
    /// A record could not be encoded.
    #[error("journal record does not encode")]
    Encode(#[source] serde_json::Error),
    /// The writer thread is gone, so nothing more is written.
    #[error("journal writer stopped")]
    WriterGone,
    /// The syncer thread is gone, so nothing is synced or compacted.
    #[error("journal syncer stopped")]
    SyncerGone,
    /// A compaction failed; the journal in place is still complete.
    #[error("journal compaction failed: {0}")]
    CompactionFailed(String),
}

fn io_error<'path>(
    action: &'static str,
    path: &'path Path,
) -> impl FnOnce(io::Error) -> JournalError + 'path {
    move |source| JournalError::Io {
        action,
        path: path.to_owned(),
        source,
    }
}

/// The marker file beside the journal at `path`: the executor that writes it records each order when it accepts it.
pub fn accept_marker(path: &Path) -> PathBuf {
    path.with_extension("accepts")
}

/// Writes the [`accept_marker`] of the journal at `path`.
///
/// # Errors
///
/// Returns [`JournalError::Io`] when the marker cannot be written.
pub fn mark_accepts(path: &Path) -> Result<(), JournalError> {
    let marker = accept_marker(path);
    fs::write(&marker, b"").map_err(io_error("write", &marker))
}

/// Reads every complete record of the journal at `path`. A missing file holds no record. A torn or unreadable
/// line, such as the last line of a crash, is skipped.
///
/// # Errors
///
/// Returns [`JournalError::Io`] when the file exists but cannot be read.
pub fn read(path: &Path) -> Result<Vec<Record>, JournalError> {
    let file = match File::open(path) {
        Ok(file) => file,
        Err(error) if error.kind() == io::ErrorKind::NotFound => return Ok(Vec::new()),
        Err(error) => return Err(io_error("open", path)(error)),
    };
    let mut records = Vec::new();
    for line in BufReader::new(file).split(b'\n') {
        let line = line.map_err(io_error("read", path))?;
        if let Ok(record) = serde_json::from_slice(&line) {
            records.push(record);
        }
    }
    Ok(records)
}

/// The file a compaction writes before it is renamed over the journal.
fn compacted_path(path: &Path) -> PathBuf {
    path.with_extension("compact")
}

/// Writes `records` to `temporary` and syncs it. The caller renames it into place.
fn write_synced(temporary: &Path, records: &[Record]) -> Result<(), JournalError> {
    let mut text = Vec::new();
    for record in records {
        serde_json::to_writer(&mut text, record).map_err(JournalError::Encode)?;
        text.push(b'\n');
    }
    let mut file = File::create(temporary).map_err(io_error("create", temporary))?;
    file.write_all(&text)
        .map_err(io_error("write", temporary))?;
    file.sync_all().map_err(io_error("sync", temporary))
}

fn sync_folder(path: &Path) -> Result<(), JournalError> {
    match path.parent() {
        Some(parent) => File::open(parent)
            .and_then(|dir| dir.sync_all())
            .map_err(io_error("sync", parent)),
        None => Ok(()),
    }
}

fn open_append(path: &Path) -> Result<File, JournalError> {
    OpenOptions::new()
        .append(true)
        .open(path)
        .map_err(io_error("open", path))
}

/// Replaces the journal at `path` with `records`, synced, and opens it for appending. Only start-up calls this, before any order.
fn rewrite(path: &Path, records: &[Record]) -> Result<File, JournalError> {
    let temporary = compacted_path(path);
    write_synced(&temporary, records)?;
    fs::rename(&temporary, path).map_err(io_error("rename", path))?;
    sync_folder(path)?;
    open_append(path)
}

/// Writes one record. It starts and ends with a newline, so a fragment that a failed earlier write left
/// behind never joins it on one unreadable line.
fn write_record(mut file: &File, path: &Path, record: &Record) -> Result<(), JournalError> {
    let mut line = vec![b'\n'];
    serde_json::to_writer(&mut line, record).map_err(JournalError::Encode)?;
    line.push(b'\n');
    file.write_all(&line).map_err(io_error("append", path))
}

/// Work for the syncer thread, which does every disk sync so that the writer never waits for one.
enum SyncJob {
    /// The writer renamed a compacted journal into place: sync it and its folder, and sync this file from now on.
    Renamed(Arc<File>),
    /// Write these records to the compaction file and sync it; the writer renames it once this answers.
    Compact(Vec<Record>, Sender<Result<(), JournalError>>),
}

/// What the syncer thread owns.
struct Syncer {
    path: PathBuf,
    file: Arc<File>,
    log: fn(&str),
}

impl Syncer {
    fn run(mut self, jobs: &Receiver<SyncJob>) {
        loop {
            match jobs.recv_timeout(SYNC_INTERVAL) {
                Ok(SyncJob::Renamed(file)) => {
                    self.file = file;
                    self.sync();
                    if let Err(error) = sync_folder(&self.path) {
                        (self.log)(&format!("journal folder sync failed: {error}"));
                    }
                }
                Ok(SyncJob::Compact(records, reply)) => {
                    // A writer that stopped waiting has nothing left to learn.
                    let _ = reply.send(write_synced(&compacted_path(&self.path), &records));
                }
                Err(RecvTimeoutError::Timeout) => self.sync(),
                Err(RecvTimeoutError::Disconnected) => {
                    self.sync();
                    return;
                }
            }
        }
    }

    fn sync(&self) {
        if let Err(error) = self.file.sync_data() {
            (self.log)(&format!("journal sync failed: {error}"));
        }
    }
}

/// A compaction the syncer is writing. Records appended meanwhile go to the old journal and are kept here, so the writer
/// can add them to the compacted file before it renames that file into place.
struct InFlight {
    since: Vec<Record>,
    written: Receiver<Result<(), JournalError>>,
    callers: Vec<Sender<Result<(), JournalError>>>,
    /// A synced append went to the old journal meanwhile, so the compacted file is synced before it replaces it.
    synced_since: bool,
}

/// How long the writer waits for a command while a compaction is in flight, before it looks for the syncer's answer.
const COMPACTION_POLL: Duration = Duration::from_millis(5);

/// What the writer thread owns. It writes and renames; it syncs only for a synced append.
struct Writer {
    path: PathBuf,
    file: Arc<File>,
    live: Live,
    compaction: Compaction,
    clock: fn() -> u64,
    log: fn(&str),
    syncer: Sender<SyncJob>,
    appended: usize,
    next_compact: Instant,
    in_flight: Option<InFlight>,
}

impl Writer {
    fn run(mut self, commands: &Receiver<Command>) {
        loop {
            let wait = if self.in_flight.is_some() {
                COMPACTION_POLL
            } else {
                self.next_compact.saturating_duration_since(Instant::now())
            };
            match commands.recv_timeout(wait) {
                Ok(Command::Append(record, reply)) => {
                    let written = self.append(record, Durability::Written);
                    // A sender that stopped waiting has nothing left to learn.
                    let _ = reply.send(written);
                }
                Ok(Command::AppendSynced(record, reply)) => {
                    let written = self.append(record, Durability::Synced);
                    let _ = reply.send(written);
                }
                Ok(Command::Compact(reply)) => {
                    self.begin_compaction();
                    match self.in_flight.as_mut() {
                        Some(in_flight) => in_flight.callers.push(reply),
                        None => {
                            let _ = reply.send(Err(JournalError::SyncerGone));
                        }
                    }
                }
                Err(RecvTimeoutError::Timeout) => {}
                // The syncer sees its channel close too, syncs once more and ends.
                Err(RecvTimeoutError::Disconnected) => return,
            }
            self.finish_compaction();
            if self.in_flight.is_none()
                && (self.next_compact <= Instant::now() || self.appended >= self.compaction.records)
            {
                self.begin_compaction();
            }
        }
    }

    fn append(&mut self, record: Record, durability: Durability) -> Result<(), JournalError> {
        write_record(&self.file, &self.path, &record)?;
        if durability == Durability::Synced {
            self.file.sync_data().map_err(io_error("sync", &self.path))?;
        }
        self.live.apply(&record);
        self.appended += 1;
        if let Some(in_flight) = self.in_flight.as_mut() {
            in_flight.since.push(record);
            in_flight.synced_since |= durability == Durability::Synced;
        }
        Ok(())
    }

    fn begin_compaction(&mut self) {
        if self.in_flight.is_some() {
            return;
        }
        self.appended = 0;
        self.next_compact = Instant::now() + self.compaction.interval;
        self.live.prune((self.clock)());
        let (reply, written) = mpsc::channel();
        if self
            .syncer
            .send(SyncJob::Compact(self.live.records(), reply))
            .is_err()
        {
            (self.log)("journal compaction skipped: the syncer is gone");
            return;
        }
        self.in_flight = Some(InFlight {
            since: Vec::new(),
            written,
            callers: Vec::new(),
            synced_since: false,
        });
    }

    /// Once the syncer has written and synced the compacted file, adds the records appended since, renames it into place and
    /// hands the new file to the syncer. Until the rename every append went to the old journal, so a crash at any point leaves
    /// a complete journal.
    fn finish_compaction(&mut self) {
        let Some(in_flight) = self.in_flight.as_ref() else {
            return;
        };
        let result = match in_flight.written.try_recv() {
            Ok(result) => result,
            Err(mpsc::TryRecvError::Empty) => return,
            Err(mpsc::TryRecvError::Disconnected) => Err(JournalError::SyncerGone),
        };
        let Some(in_flight) = self.in_flight.take() else {
            return;
        };
        let outcome = result.and_then(|()| self.swap(&in_flight));
        if let Err(error) = &outcome {
            (self.log)(&format!("journal compaction failed: {error}"));
        }
        for caller in in_flight.callers {
            let _ = caller.send(match &outcome {
                Ok(()) => Ok(()),
                Err(error) => Err(JournalError::CompactionFailed(error.to_string())),
            });
        }
    }

    fn swap(&mut self, in_flight: &InFlight) -> Result<(), JournalError> {
        let temporary = compacted_path(&self.path);
        let file = open_append(&temporary)?;
        for record in &in_flight.since {
            write_record(&file, &temporary, record)?;
        }
        if in_flight.synced_since {
            file.sync_data().map_err(io_error("sync", &temporary))?;
        }
        fs::rename(&temporary, &self.path).map_err(io_error("rename", &self.path))?;
        self.file = Arc::new(file);
        self.syncer
            .send(SyncJob::Renamed(Arc::clone(&self.file)))
            .map_err(|_| JournalError::SyncerGone)
    }
}

impl Journal {
    /// Rewrites the journal at `path` with exactly the `live` records, synced, and starts its writer and syncer threads.
    /// Executor start-up calls this once, after it has read the journal and pruned what it found.
    ///
    /// # Errors
    ///
    /// Returns [`JournalError`] when the journal cannot be rewritten or a thread cannot be started.
    pub fn start(
        path: &Path,
        live: Live,
        compaction: Compaction,
        clock: fn() -> u64,
        log: fn(&str),
    ) -> Result<Self, JournalError> {
        let file = Arc::new(rewrite(path, &live.records())?);
        let (syncer, jobs) = mpsc::channel();
        let sync_thread = Syncer {
            path: path.to_owned(),
            file: Arc::clone(&file),
            log,
        };
        thread::Builder::new()
            .name("journal-sync".to_owned())
            .spawn(move || sync_thread.run(&jobs))
            .map_err(io_error("start the syncer for", path))?;
        let (commands, received) = mpsc::channel();
        let writer = Writer {
            path: path.to_owned(),
            file,
            live,
            compaction,
            clock,
            log,
            syncer,
            appended: 0,
            next_compact: Instant::now() + compaction.interval,
            in_flight: None,
        };
        thread::Builder::new()
            .name("journal".to_owned())
            .spawn(move || writer.run(&received))
            .map_err(io_error("start the writer for", path))?;
        Ok(Self { commands })
    }

    /// Appends one record and waits until it is written. It never waits for a disk sync.
    ///
    /// # Errors
    ///
    /// Returns [`JournalError`] when the record cannot be encoded or written, or the writer is gone.
    pub fn append(&self, record: &Record) -> Result<(), JournalError> {
        let (reply, answer) = mpsc::channel();
        self.commands
            .send(Command::Append(record.clone(), reply))
            .map_err(|_| JournalError::WriterGone)?;
        answer.recv().map_err(|_| JournalError::WriterGone)?
    }

    /// Appends one record and waits until it is written and synced to disk. Only an at-most-once action's open record needs
    /// this (`Action::at_most_once`): it must survive a power loss before the action acts.
    ///
    /// # Errors
    ///
    /// Returns [`JournalError`] when the record cannot be written or synced, or the writer is gone.
    pub fn append_synced(&self, record: &Record) -> Result<(), JournalError> {
        let (reply, answer) = mpsc::channel();
        self.commands
            .send(Command::AppendSynced(record.clone(), reply))
            .map_err(|_| JournalError::WriterGone)?;
        answer.recv().map_err(|_| JournalError::WriterGone)?
    }

    /// Compacts the journal now, as the writer does on its own every interval, and waits until the compacted journal is in
    /// place.
    ///
    /// # Errors
    ///
    /// Returns [`JournalError`] when the compacted journal cannot be written or renamed, or a thread is gone.
    pub fn compact(&self) -> Result<(), JournalError> {
        let (reply, answer) = mpsc::channel();
        self.commands
            .send(Command::Compact(reply))
            .map_err(|_| JournalError::WriterGone)?;
        answer.recv().map_err(|_| JournalError::WriterGone)?
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const NOW: u64 = 1_800_000_000_000;

    fn key(operation: &str) -> DoneKey {
        DoneKey {
            operation: operation.parse().expect("id"),
            action: Action::ProofNoop,
            target: "7".parse().expect("id"),
        }
    }

    fn nonce(last: u8) -> Nonce {
        format!("{last:032x}").parse().expect("nonce")
    }

    fn start(path: &Path, compaction: Compaction) -> Journal {
        Journal::start(path, Live::default(), compaction, || NOW, |_| {}).expect("journal starts")
    }

    #[test]
    fn a_failed_outcome_without_a_diagnostic_keeps_its_existing_wire_shape() {
        let line = serde_json::json!({"status": "failed", "code": "executor.order_expired"});
        let outcome =
            serde_json::from_value::<Outcome>(line.clone()).expect("existing journal outcome");
        assert_eq!(outcome, Outcome::failed("executor.order_expired"));
        assert_eq!(
            serde_json::to_value(outcome).expect("receipt outcome"),
            line
        );
    }

    #[test]
    fn records_round_trip_and_a_torn_line_is_skipped() {
        let dir = tempfile::tempdir().expect("tempdir");
        let path = dir.path().join("journal");
        let open = Record::Open {
            nonce: nonce(1),
            key: key("op-1"),
            expires_ms: NOW + 5,
            power_check: PowerCheck::Default,
            input: serde_json::Value::Null,
        };
        let journal = start(&path, Compaction::default());
        journal.append(&open).expect("append");
        OpenOptions::new()
            .append(true)
            .open(&path)
            .and_then(|mut f| f.write_all(b"{\"record\":\"done\",\"non"))
            .expect("torn write");
        journal.append(&open).expect("append after a torn write");
        assert_eq!(read(&path).expect("read"), vec![open.clone(), open]);
    }

    #[test]
    fn a_missing_journal_holds_no_record() {
        let dir = tempfile::tempdir().expect("tempdir");
        assert_eq!(read(&dir.path().join("none")).expect("read"), vec![]);
    }

    #[test]
    fn the_running_writer_compacts_to_the_live_records() {
        let dir = tempfile::tempdir().expect("tempdir");
        let path = dir.path().join("journal");
        let journal = start(&path, Compaction::default());
        let expired = Record::Seen {
            nonce: nonce(1),
            key: key("op-old"),
            expires_ms: NOW,
        };
        let old_done = Record::Done {
            nonce: nonce(1),
            key: key("op-old"),
            outcome: Outcome::Done,
            at_ms: NOW - DONE_RETENTION_MS,
        };
        let open = Record::Open {
            nonce: nonce(2),
            key: key("op-open"),
            expires_ms: NOW - 1,
            power_check: PowerCheck::Route,
            input: serde_json::Value::Null,
        };
        let live = Record::Seen {
            nonce: nonce(3),
            key: key("op-live"),
            expires_ms: NOW + 5_000,
        };
        let done = Record::Done {
            nonce: nonce(3),
            key: key("op-live"),
            outcome: Outcome::Done,
            at_ms: NOW,
        };
        for record in [&expired, &old_done, &open, &live, &done] {
            journal.append(record).expect("append");
        }
        journal.compact().expect("compact");
        // The open order stays although its nonce expired: recovery must run it again.
        assert_eq!(read(&path).expect("read"), vec![open, live, done]);
        let after = Record::Seen {
            nonce: nonce(4),
            key: key("op-live"),
            expires_ms: NOW + 5_000,
        };
        journal.append(&after).expect("append after compaction");
        assert_eq!(read(&path).expect("read").last(), Some(&after));
    }

    #[test]
    fn the_writer_compacts_on_its_own_after_enough_records() {
        let dir = tempfile::tempdir().expect("tempdir");
        let path = dir.path().join("journal");
        let journal = start(
            &path,
            Compaction {
                interval: COMPACT_INTERVAL,
                records: 3,
            },
        );
        for last in 1..=3 {
            journal
                .append(&Record::Seen {
                    nonce: nonce(last),
                    key: key("op"),
                    expires_ms: NOW,
                })
                .expect("append");
        }
        // The third append starts a compaction; this append may land while the syncer writes it, and is kept.
        journal
            .append(&Record::Seen {
                nonce: nonce(9),
                key: key("op"),
                expires_ms: NOW + 1,
            })
            .expect("append");
        let deadline = Instant::now() + Duration::from_secs(2);
        let mut records = read(&path).expect("read");
        while records.len() > 1 && Instant::now() < deadline {
            thread::sleep(Duration::from_millis(10));
            records = read(&path).expect("read");
        }
        assert_eq!(
            records,
            vec![Record::Seen {
                nonce: nonce(9),
                key: key("op"),
                expires_ms: NOW + 1
            }]
        );
    }

    #[test]
    fn records_appended_during_a_compaction_are_in_the_compacted_journal() {
        let dir = tempfile::tempdir().expect("tempdir");
        let path = dir.path().join("journal");
        let journal = start(&path, Compaction::default());
        let appender = {
            let journal = journal.clone();
            thread::spawn(move || {
                (0..200_u8)
                    .map(|last| {
                        let open = Record::Open {
                            nonce: nonce(last),
                            key: key(&format!("op-{last}")),
                            expires_ms: NOW + 5_000,
                            power_check: PowerCheck::Default,
                            input: serde_json::Value::Null,
                        };
                        journal.append(&open).expect("append");
                        open
                    })
                    .collect::<Vec<_>>()
            })
        };
        for _ in 0..20 {
            journal.compact().expect("compact");
        }
        let opened = appender.join().expect("appender ends");
        journal.compact().expect("last compact");
        drop(journal);
        // A restart reads exactly what is on file: every open order must still be there to recover.
        let live = Live::from_records(read(&path).expect("read"));
        let unfinished: Vec<Nonce> = live
            .unfinished()
            .into_iter()
            .map(|open| open.nonce)
            .collect();
        let mut expected: Vec<Nonce> = opened
            .iter()
            .filter_map(|record| match record {
                Record::Open { nonce, .. } => Some(*nonce),
                _ => None,
            })
            .collect();
        expected.sort();
        assert_eq!(unfinished, expected);
    }

    #[test]
    fn an_open_record_keeps_its_input_through_compaction_and_an_old_line_reads_as_null() {
        let old = r#"{"record":"open","nonce":"00000000000000000000000000000001","operation":"op","action":"proof.noop","target":"7","expires_ms":1}"#;
        let record: Record = serde_json::from_str(old).expect("an old open line parses");
        let with_input = Record::Open {
            nonce: nonce(2),
            key: key("op-2"),
            expires_ms: 1,
            power_check: PowerCheck::Default,
            input: serde_json::json!({"rule": "443/tcp"}),
        };
        let encoded = serde_json::to_string(&with_input).expect("record");
        let live = Live::from_records([record, serde_json::from_str(&encoded).expect("round trip")]);
        let compacted = Live::from_records(live.records());
        let inputs: Vec<serde_json::Value> = compacted.unfinished().into_iter().map(|open| open.input).collect();
        assert_eq!(inputs, vec![serde_json::Value::Null, serde_json::json!({"rule": "443/tcp"})]);
        assert!(!serde_json::to_string(&compacted.records()).expect("records").contains(r#""input":null"#));
    }

    #[test]
    fn an_open_order_with_a_result_is_not_unfinished() {
        let mut live = Live::from_records([
            Record::Open {
                nonce: nonce(1),
                key: key("op"),
                expires_ms: NOW,
                power_check: PowerCheck::Default,
                input: serde_json::Value::Null,
            },
            Record::Open {
                nonce: nonce(2),
                key: key("op-2"),
                expires_ms: NOW,
                power_check: PowerCheck::NoWeb,
                input: serde_json::Value::Null,
            },
            Record::Done {
                nonce: nonce(1),
                key: key("op"),
                outcome: Outcome::Done,
                at_ms: NOW,
            },
        ]);
        live.prune(NOW + 1);
        assert_eq!(
            live.unfinished(),
            vec![Unfinished { nonce: nonce(2), key: key("op-2"), power_check: PowerCheck::NoWeb, input: serde_json::Value::Null }]
        );
    }
}
