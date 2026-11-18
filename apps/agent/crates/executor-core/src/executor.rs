//! The executor's state: seen nonces, done records, per-target locks and crash recovery.
//!
//! Lock discipline: the nonce and done maps are held for one lookup or insert, with no I/O under them. A
//! target's own lock is held across its action on purpose, so that orders for one target run one at a time.

use std::collections::HashMap;
use std::path::Path;
use std::sync::{Arc, Mutex};

use serde::Serialize;

use crate::action::Action;
use crate::ids::{Nonce, OperationId, TargetId};
use crate::journal::{
    self, Compaction, DoneKey, Journal, JournalError, Live, Outcome, Record, DONE_RETENTION_MS,
};
use crate::order::VerifiedOrder;

/// Runs an allowlisted action. The binary implements it over the action family crates.
pub trait Run: Send + Sync {
    /// Whether an Instance action names a local target. No family accepts one by default.
    fn target_exists(&self, _action: Action, _target: &TargetId) -> bool {
        false
    }
    /// Runs the action of a verified order and reports how it ended. It must be safe to run again for the
    /// same Operation, action and target, because crash recovery repeats an action that has no result.
    fn run(&self, order: &VerifiedOrder) -> Outcome;
}

/// The result line for one order.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct Reply {
    /// The Operation.
    pub operation: OperationId,
    /// The action.
    pub action: Action,
    /// The target.
    pub target: TargetId,
    /// How the action ended.
    #[serde(flatten)]
    pub outcome: Outcome,
    /// Whether this is the stored result of an earlier order for the same key.
    pub repeat: bool,
}

/// Why a verified order is refused by the executor's state.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
#[non_exhaustive]
pub enum StateRefusal {
    /// The nonce was seen before, possibly before a restart. Carries the first result once it exists.
    #[error("replayed nonce")]
    ReplayedNonce {
        /// The first order's result, when it has one.
        first: Option<Box<Reply>>,
    },
    /// The open record could not be written, so the action did not run.
    #[error("journal write failed")]
    JournalWrite,
}

impl StateRefusal {
    /// The stable code the Gateway logs and alerts on.
    pub fn code(&self) -> &'static str {
        match self {
            Self::ReplayedNonce { .. } => "replayed_nonce",
            Self::JournalWrite => "journal_write_failed",
        }
    }
}

#[derive(Debug, Clone)]
struct SeenNonce {
    expires_ms: u64,
    key: DoneKey,
}

#[derive(Debug, Clone)]
struct Finished {
    outcome: Outcome,
    at_ms: u64,
}

/// The long-running executor state, shared by every connection thread.
pub struct Executor<R> {
    journal: Journal,
    runner: R,
    nonces: Mutex<HashMap<Nonce, SeenNonce>>,
    done: Mutex<HashMap<DoneKey, Finished>>,
    targets: Mutex<HashMap<TargetId, Arc<Mutex<()>>>>,
}

impl<R> std::fmt::Debug for Executor<R> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Executor")
            .field("journal", &self.journal)
            .finish_non_exhaustive()
    }
}

/// An order that was open in the journal without a result, finished at start-up.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Recovered {
    /// The order's key.
    pub key: DoneKey,
    /// How the repeated action ended.
    pub outcome: Outcome,
}

impl<R: Run> Executor<R> {
    /// Reads the action family's target truth before accepting an order.
    pub fn target_exists(&self, action: Action, target: &TargetId) -> bool {
        self.runner.target_exists(action, target)
    }
    /// Loads the journal at `path`, keeps its [`Live`] records (nonces that have not expired, open orders and
    /// the done records of the last 24 hours), rewrites the journal with them, starts its writer, and
    /// finishes every open order that has no result.
    ///
    /// # Errors
    ///
    /// Returns [`JournalError`] when the journal cannot be read, compacted or written.
    ///
    /// # Panics
    ///
    /// Panics when a lock of the new state is poisoned, which cannot happen before it is shared.
    pub fn start(
        path: &Path,
        runner: R,
        clock: fn() -> u64,
        log: fn(&str),
    ) -> Result<(Self, Vec<Recovered>), JournalError> {
        Self::start_with(path, runner, Compaction::default(), clock, log)
    }

    /// [`Executor::start`] with its own compaction schedule.
    ///
    /// # Errors
    ///
    /// Returns [`JournalError`] when the journal cannot be read, compacted or written.
    ///
    /// # Panics
    ///
    /// Panics when a lock of the new state is poisoned, which cannot happen before it is shared.
    pub fn start_with(
        path: &Path,
        runner: R,
        compaction: Compaction,
        clock: fn() -> u64,
        log: fn(&str),
    ) -> Result<(Self, Vec<Recovered>), JournalError> {
        let mut live = Live::from_records(journal::read(path)?);
        live.prune(clock());
        let unfinished = live.unfinished();
        let nonces: HashMap<Nonce, SeenNonce> = live
            .nonces()
            .map(|(nonce, key, expires_ms)| {
                (
                    nonce,
                    SeenNonce {
                        expires_ms,
                        key: key.clone(),
                    },
                )
            })
            .collect();
        let done: HashMap<DoneKey, Finished> = live
            .done()
            .map(|(key, _, outcome, at_ms)| {
                (
                    key.clone(),
                    Finished {
                        outcome: outcome.clone(),
                        at_ms,
                    },
                )
            })
            .collect();
        let journal = Journal::start(path, live, compaction, clock, log)?;
        let executor = Self {
            journal,
            runner,
            nonces: Mutex::new(nonces),
            done: Mutex::new(done),
            targets: Mutex::new(HashMap::new()),
        };

        let mut recovered = Vec::new();
        for (nonce, key, power_check) in unfinished {
            let order = VerifiedOrder::recovered(nonce, &key, power_check);
            let outcome = executor.runner.run(&order);
            executor.finish(nonce, &key, &outcome, clock())?;
            recovered.push(Recovered { key, outcome });
        }
        Ok((executor, recovered))
    }

    /// Checks the nonce and the Operation of a verified order, then runs it once under its target's lock.
    ///
    /// # Errors
    ///
    /// Returns [`StateRefusal::ReplayedNonce`] for a nonce seen before, and [`StateRefusal::JournalWrite`]
    /// when the open record cannot be written.
    ///
    /// # Panics
    ///
    /// Panics when a lock is poisoned: a thread panicked while it held the state, which is fatal.
    pub fn handle(&self, order: &VerifiedOrder, now_ms: u64) -> Result<Reply, StateRefusal> {
        let key = DoneKey {
            operation: order.operation().clone(),
            action: order.action(),
            target: order.target().clone(),
        };
        let replayed = {
            let mut nonces = self.nonces.lock().expect("nonce map lock poisoned");
            nonces.retain(|_, seen| seen.expires_ms > now_ms);
            if let Some(seen) = nonces.get(&order.nonce()) {
                Some(seen.key.clone())
            } else {
                nonces.insert(
                    order.nonce(),
                    SeenNonce {
                        expires_ms: order.expires_ms(),
                        key: key.clone(),
                    },
                );
                None
            }
        };
        if let Some(first_key) = replayed {
            let first = self
                .finished(&first_key)
                .map(|finished| Box::new(reply(&first_key, finished.outcome, Source::Stored)));
            return Err(StateRefusal::ReplayedNonce { first });
        }

        let target_lock = {
            let mut targets = self.targets.lock().expect("target lock map poisoned");
            Arc::clone(targets.entry(order.target().clone()).or_default())
        };
        let _target = target_lock.lock().expect("target lock poisoned");

        if let Some(finished) = self.finished(&key) {
            let seen = Record::Seen {
                nonce: order.nonce(),
                key: key.clone(),
                expires_ms: order.expires_ms(),
            };
            // A lost seen record only lets this nonce through again after a crash, and it then returns the
            // same stored result as a repeat.
            let _ = self.journal.append(&seen);
            return Ok(reply(&key, finished.outcome, Source::Stored));
        }
        let open = Record::Open {
            nonce: order.nonce(),
            key: key.clone(),
            expires_ms: order.expires_ms(),
            power_check: order.power_check(),
        };
        self.journal
            .append(&open)
            .map_err(|_| StateRefusal::JournalWrite)?;
        let outcome = self.runner.run(order);
        // The action ran; a lost done record only means a repeat runs it again after a crash, which every
        // action allows, so the result still goes back.
        let _ = self.finish(order.nonce(), &key, &outcome, now_ms);
        Ok(reply(&key, outcome, Source::Ran))
    }

    fn finished(&self, key: &DoneKey) -> Option<Finished> {
        self.done
            .lock()
            .expect("done map lock poisoned")
            .get(key)
            .cloned()
    }

    fn finish(
        &self,
        nonce: Nonce,
        key: &DoneKey,
        outcome: &Outcome,
        now_ms: u64,
    ) -> Result<(), JournalError> {
        let record = Record::Done {
            nonce,
            key: key.clone(),
            outcome: outcome.clone(),
            at_ms: now_ms,
        };
        let written = self.journal.append(&record);
        let mut done = self.done.lock().expect("done map lock poisoned");
        done.retain(|_, finished| now_ms.saturating_sub(finished.at_ms) < DONE_RETENTION_MS);
        done.insert(
            key.clone(),
            Finished {
                outcome: outcome.clone(),
                at_ms: now_ms,
            },
        );
        written
    }
}

/// Whether a result comes from this order's own run or from an earlier one.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Source {
    Ran,
    Stored,
}

fn reply(key: &DoneKey, outcome: Outcome, source: Source) -> Reply {
    let repeat = source == Source::Stored;
    Reply {
        operation: key.operation.clone(),
        action: key.action,
        target: key.target.clone(),
        outcome,
        repeat,
    }
}

#[cfg(test)]
mod tests {
    use std::sync::atomic::{AtomicUsize, Ordering};

    use super::*;
    use crate::action::Enabled;
    use crate::ids::NodeId;
    use crate::order::tests::{payload, pinned, sign, NOW};
    use crate::order::{check, Checks};

    #[derive(Debug, Default)]
    struct Counting(Arc<AtomicUsize>);

    impl Run for Counting {
        fn run(&self, _order: &VerifiedOrder) -> Outcome {
            self.0.fetch_add(1, Ordering::SeqCst);
            Outcome::Done
        }
    }

    fn order(nonce: &str, operation: &str) -> VerifiedOrder {
        let mut p = payload();
        p["nonce"] = nonce.into();
        p["operation"] = operation.into();
        let keys = pinned(1);
        let enabled = Enabled::parse("proof").expect("valid");
        let node: NodeId = "7".parse().expect("id");
        check(
            &sign(1, &p),
            &Checks {
                keys: &keys,
                enabled: &enabled,
                node: &node,
            },
            NOW,
        )
        .expect("valid order")
    }

    const N1: &str = "00000000000000000000000000000001";
    const N2: &str = "00000000000000000000000000000002";

    #[test]
    fn an_order_runs_once_and_a_repeat_returns_the_first_result() {
        let dir = tempfile::tempdir().expect("tempdir");
        let runs = Arc::new(AtomicUsize::new(0));
        let (executor, _) = Executor::start(
            &dir.path().join("j"),
            Counting(Arc::clone(&runs)),
            || NOW,
            |_| {},
        )
        .expect("start");
        let first = executor.handle(&order(N1, "op-1"), NOW).expect("runs");
        assert!(!first.repeat);
        let second = executor.handle(&order(N2, "op-1"), NOW).expect("repeat");
        assert!(second.repeat);
        assert_eq!(second.outcome, Outcome::Done);
        assert_eq!(runs.load(Ordering::SeqCst), 1);
    }

    #[test]
    fn a_replayed_nonce_refuses_with_the_first_result() {
        let dir = tempfile::tempdir().expect("tempdir");
        let runs = Arc::new(AtomicUsize::new(0));
        let (executor, _) = Executor::start(
            &dir.path().join("j"),
            Counting(Arc::clone(&runs)),
            || NOW,
            |_| {},
        )
        .expect("start");
        executor.handle(&order(N1, "op-1"), NOW).expect("runs");
        // A replay after the original completed carries the stored first result; the action does not run again.
        let Err(StateRefusal::ReplayedNonce { first: Some(first) }) =
            executor.handle(&order(N1, "op-1"), NOW)
        else {
            panic!("replay must refuse with the first result");
        };
        assert_eq!(first.operation.as_str(), "op-1");
        assert_eq!(first.outcome, Outcome::Done);
        assert_eq!(runs.load(Ordering::SeqCst), 1);
    }

    #[test]
    fn a_replayed_nonce_refuses_after_a_restart() {
        let dir = tempfile::tempdir().expect("tempdir");
        let path = dir.path().join("j");
        {
            let (executor, _) =
                Executor::start(&path, Counting::default(), || NOW, |_| {}).expect("start");
            executor.handle(&order(N1, "op-1"), NOW).expect("runs");
        }
        let (executor, recovered) =
            Executor::start(&path, Counting::default(), || NOW + 1, |_| {}).expect("restart");
        assert_eq!(recovered, vec![]);
        assert!(matches!(
            executor.handle(&order(N1, "op-1"), NOW + 1),
            Err(StateRefusal::ReplayedNonce { first: Some(_) })
        ));
        assert!(
            executor
                .handle(&order(N2, "op-1"), NOW + 1)
                .expect("repeat")
                .repeat
        );
    }

    #[test]
    fn start_up_finishes_an_open_order_and_a_repeat_returns_its_result() {
        let dir = tempfile::tempdir().expect("tempdir");
        let path = dir.path().join("j");
        let open = order(N1, "op-crash");
        let key = DoneKey {
            operation: open.operation().clone(),
            action: open.action(),
            target: open.target().clone(),
        };
        // A crash after the open record and before the done record leaves exactly this journal.
        let record = Record::Open {
            nonce: open.nonce(),
            key: key.clone(),
            expires_ms: open.expires_ms(),
            power_check: crate::order::PowerCheck::Default,
        };
        std::fs::write(
            &path,
            format!("{}\n", serde_json::to_string(&record).expect("json")),
        )
        .expect("journal");

        let runs = Arc::new(AtomicUsize::new(0));
        let (executor, recovered) =
            Executor::start(&path, Counting(Arc::clone(&runs)), || NOW + 60_000, |_| {})
                .expect("recover");
        assert_eq!(
            recovered,
            vec![Recovered {
                key,
                outcome: Outcome::Done
            }]
        );
        assert_eq!(runs.load(Ordering::SeqCst), 1);
        assert!(matches!(
            executor.handle(&order(N1, "op-crash"), NOW),
            Err(StateRefusal::ReplayedNonce { first: Some(_) })
        ));
        assert!(
            executor
                .handle(&order(N2, "op-crash"), NOW)
                .expect("repeat")
                .repeat
        );
        assert_eq!(runs.load(Ordering::SeqCst), 1);

        let (_, again) =
            Executor::start(&path, Counting(Arc::clone(&runs)), || NOW + 60_000, |_| {})
                .expect("second start");
        assert_eq!(
            again,
            vec![],
            "a finished order is not repeated at the next start"
        );
    }

    #[test]
    fn done_records_older_than_a_day_are_dropped_at_start_up() {
        let dir = tempfile::tempdir().expect("tempdir");
        let path = dir.path().join("j");
        {
            let (executor, _) =
                Executor::start(&path, Counting::default(), || NOW, |_| {}).expect("start");
            executor.handle(&order(N1, "op-1"), NOW).expect("runs");
        }
        let runs = Arc::new(AtomicUsize::new(0));
        let later = NOW + DONE_RETENTION_MS;
        let (executor, _) = Executor::start(
            &path,
            Counting(Arc::clone(&runs)),
            || NOW + DONE_RETENTION_MS,
            |_| {},
        )
        .expect("restart");
        let mut fresh = payload();
        fresh["nonce"] = N2.into();
        fresh["expires_ms"] = (later + 5_000).into();
        let keys = pinned(1);
        let enabled = Enabled::parse("proof").expect("valid");
        let node: NodeId = "7".parse().expect("id");
        let late = check(
            &sign(1, &fresh),
            &Checks {
                keys: &keys,
                enabled: &enabled,
                node: &node,
            },
            later,
        )
        .expect("valid");
        assert!(!executor.handle(&late, later).expect("runs").repeat);
        assert_eq!(runs.load(Ordering::SeqCst), 1);
    }
}
