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

/// The code of an order that expired while it waited for its target's lock. It never acted.
pub const ORDER_EXPIRED: &str = "executor.order_expired";

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
    clock: fn() -> u64,
}

impl<R> std::fmt::Debug for Executor<R> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Executor")
            .field("journal", &self.journal)
            .finish_non_exhaustive()
    }
}

/// The orders that were open in the journal without a result at start-up, for [`Executor::recover`].
#[derive(Debug)]
pub struct Pending(Vec<crate::journal::Unfinished>);

impl Pending {
    /// Whether no order waits for its repeat.
    pub fn is_empty(&self) -> bool {
        self.0.is_empty()
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
        let (executor, pending) = Self::open_with(path, runner, compaction, clock, log)?;
        let recovered = executor.recover(pending, || {})?;
        Ok((executor, recovered))
    }

    /// [`Executor::start`] without running the open orders: the caller runs them with [`Executor::recover`], so it can
    /// accept new orders while they run.
    ///
    /// # Errors
    ///
    /// Returns [`JournalError`] when the journal cannot be read, compacted or written.
    ///
    /// # Panics
    ///
    /// Panics when a lock of the new state is poisoned, which cannot happen before it is shared.
    pub fn open(path: &Path, runner: R, clock: fn() -> u64, log: fn(&str)) -> Result<(Self, Pending), JournalError> {
        Self::open_with(path, runner, Compaction::default(), clock, log)
    }

    fn open_with(
        path: &Path,
        runner: R,
        compaction: Compaction,
        clock: fn() -> u64,
        log: fn(&str),
    ) -> Result<(Self, Pending), JournalError> {
        let mut live = Live::from_records(journal::read(path)?);
        let now_ms = clock();
        let expired = Outcome::failed(ORDER_EXPIRED);
        for (nonce, key) in live.abandoned() {
            live.apply(&Record::Done {
                nonce,
                key,
                outcome: expired.clone(),
                at_ms: now_ms,
            });
        }
        live.prune(now_ms);
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
        // The Gateway reads this marker: this journal records an order when it is accepted, so no record past its expiry
        // proves that it never acted (ADR 0266, "Reconcile by the journal").
        journal::mark_accepts(path)?;
        let executor = Self {
            journal,
            runner,
            nonces: Mutex::new(nonces),
            done: Mutex::new(done),
            targets: Mutex::new(HashMap::new()),
            clock,
        };

        Ok((executor, Pending(unfinished)))
    }

    /// Finishes the open orders that start-up found, each under its target's lock. `held` runs once every such target's
    /// lock is held and before any order runs: from then on a new order is accepted at once, and one for a target that is
    /// being repeated waits for that repeat, as it would wait for any order of its target.
    ///
    /// # Errors
    ///
    /// Returns [`JournalError`] when a done record cannot be written.
    ///
    /// # Panics
    ///
    /// Panics when a lock is poisoned: a thread panicked while it held the state, which is fatal.
    pub fn recover(&self, pending: Pending, held: impl FnOnce()) -> Result<Vec<Recovered>, JournalError> {
        let mut targets: Vec<TargetId> = pending.0.iter().map(|open| open.key.target.clone()).collect();
        targets.sort_by(|a, b| a.as_str().cmp(b.as_str()));
        targets.dedup();
        let locks: Vec<(TargetId, Arc<Mutex<()>>)> = {
            let mut map = self.targets.lock().expect("target lock map poisoned");
            targets
                .into_iter()
                .map(|target| {
                    let lock = Arc::clone(map.entry(target.clone()).or_default());
                    (target, lock)
                })
                .collect()
        };
        let mut guards: HashMap<TargetId, std::sync::MutexGuard<'_, ()>> = locks
            .iter()
            .map(|(target, lock)| (target.clone(), lock.lock().expect("target lock poisoned")))
            .collect();
        held();
        let mut left: HashMap<TargetId, usize> = HashMap::new();
        for open in &pending.0 {
            *left.entry(open.key.target.clone()).or_default() += 1;
        }
        let mut recovered = Vec::new();
        for open in pending.0 {
            let key = open.key.clone();
            let order = VerifiedOrder::recovered(open.nonce, &open.key, open.power_check, open.input);
            let outcome = self.runner.run(&order);
            self.finish(open.nonce, &key, &outcome, (self.clock)())?;
            // The target's lock goes with its last repeat, so its new orders run without waiting for other targets.
            if left.get_mut(&key.target).is_some_and(|count| {
                *count -= 1;
                *count == 0
            }) {
                guards.remove(&key.target);
            }
            recovered.push(Recovered { key, outcome });
        }
        Ok(recovered)
    }

    /// Checks the nonce and the Operation of a verified order, records that it was accepted, then runs it once
    /// under its target's lock. An order that expired while it waited for the lock ends failed with
    /// `executor.order_expired` and does not act.
    ///
    /// # Errors
    ///
    /// Returns [`StateRefusal::ReplayedNonce`] for a nonce seen before, and [`StateRefusal::JournalWrite`]
    /// when the seen or open record cannot be written.
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
        // A read changes nothing: it writes no journal record, waits for no target lock and stores no result, so a repeat
        // (with a fresh nonce) is a fresh read. The nonce map still refuses the same order twice.
        if key.action.read_only() {
            let outcome = if (self.clock)().max(now_ms) >= order.expires_ms() {
                Outcome::failed(ORDER_EXPIRED)
            } else {
                self.runner.run(order)
            };
            return Ok(reply(&key, outcome, Source::Ran));
        }

        // The accept record is on file before the order waits for its target, so a reader of the journal sees
        // a pending order rather than no record.
        let seen = Record::Seen {
            nonce: order.nonce(),
            key: key.clone(),
            expires_ms: order.expires_ms(),
        };
        self.journal
            .append(&seen)
            .map_err(|_| StateRefusal::JournalWrite)?;

        let target_lock = {
            let mut targets = self.targets.lock().expect("target lock map poisoned");
            Arc::clone(targets.entry(order.target().clone()).or_default())
        };
        let _target = target_lock.lock().expect("target lock poisoned");

        if let Some(finished) = self.finished(&key) {
            return Ok(reply(&key, finished.outcome, Source::Stored));
        }
        let now_ms = (self.clock)().max(now_ms);
        if now_ms >= order.expires_ms() {
            let outcome = Outcome::failed(ORDER_EXPIRED);
            let _ = self.finish(order.nonce(), &key, &outcome, now_ms);
            return Ok(reply(&key, outcome, Source::Ran));
        }
        let open = Record::Open {
            nonce: order.nonce(),
            key: key.clone(),
            expires_ms: order.expires_ms(),
            power_check: order.power_check(),
            input: order.input().clone(),
        };
        self.journal
            .append(&open)
            .map_err(|_| StateRefusal::JournalWrite)?;
        let outcome = self.runner.run(order);
        // The action ran; a lost done record only means a repeat runs it again after a crash, which every
        // action allows, so the result still goes back.
        let written = self.finish(order.nonce(), &key, &outcome, now_ms);
        if key.action.receipt_fenced() {
            written.map_err(|_| StateRefusal::JournalWrite)?;
        }
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
        if !outcome.ends_operation(key.action) || (key.action.receipt_fenced() && written.is_err()) {
            return written;
        }
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
    const N3: &str = "00000000000000000000000000000003";

    fn destroy_order(nonce: &str) -> VerifiedOrder {
        destroy_order_at(nonce, NOW, NOW + 5000)
    }

    fn destroy_order_at(nonce: &str, now: u64, expiry: u64) -> VerifiedOrder {
        let mut fields = payload();
        fields["action"] = "instance.destroy".into();
        fields["target"] = "12".into();
        fields["operation"] = "42:remove".into();
        fields["nonce"] = nonce.into();
        fields["expires_ms"] = expiry.into();
        fields["input"] = serde_json::json!({"project":"orbit-instances-nested","name":"instance-12","pool":"orbit-instances","acl":"instance-12","inner_volume":"inner-12"});
        let keys = pinned(1);
        let enabled = Enabled::parse("instance").expect("valid");
        let node = "7".parse().expect("id");
        crate::order::check_with_target(
            &sign(1, &fields),
            &Checks {
                keys: &keys,
                enabled: &enabled,
                node: &node,
            },
            now,
            |action, target| action == Action::InstanceDestroy && target.as_str() == "12",
        )
        .expect("valid destroy")
    }

    fn sync_order(action: &str, step: &str, nonce: &str, expiry: u64) -> VerifiedOrder {
        let mut fields = payload();
        fields["action"] = action.into();
        fields["target"] = match action {
            "instance.fetch_branch" => "7:fetch-12",
            // A Route or DNS action targets its Node.
            "tls.ensure_csr" | "route.publish_site" | "dns.publish_records" => "7",
            _ => "12",
        }
        .into();
        fields["operation"] = format!("42:{step}").into();
        fields["nonce"] = nonce.into();
        fields["expires_ms"] = expiry.into();
        fields["input"] = serde_json::json!({});
        let keys = pinned(1);
        let enabled = Enabled::parse("instance\nroute\ndns\n").expect("valid");
        let node = "7".parse().expect("id");
        crate::order::check_with_target(&sign(1, &fields), &Checks { keys: &keys, enabled: &enabled, node: &node }, NOW, |_, _| true)
            .expect("valid sync order")
    }

    #[test]
    fn a_copy_sync_step_that_expired_before_its_lock_runs_on_a_fresh_nonce_after_restart() {
        for (action, step) in [("instance.fetch_branch", "fetch"), ("instance.sync_base", "sync"), ("instance.clear_base", "clear"),
            ("instance.clear_base", "clear_after_failure")] {
            let directory = tempfile::tempdir().expect("scratch");
            let path = directory.path().join("journal");
            let runs = Arc::new(AtomicUsize::new(0));
            let (executor, _) = Executor::start(&path, Counting(Arc::clone(&runs)), || NOW, |_| {}).expect("start");
            let expired = sync_order(action, step, N1, NOW + 5000);
            assert_eq!(executor.handle(&expired, expired.expires_ms()).expect("expires").outcome, Outcome::failed(ORDER_EXPIRED));
            drop(executor);

            let (executor, _) = Executor::start(&path, Counting(Arc::clone(&runs)), || NOW, |_| {}).expect("restart");
            let fresh = executor.handle(&sync_order(action, step, N2, NOW + 5000), NOW).expect("fresh order acts");
            assert!(!fresh.repeat, "{action} {step}");
            let again = executor.handle(&sync_order(action, step, N3, NOW + 5000), NOW).expect("lost receipt");
            assert!(again.repeat, "{action} {step}: two sends, one execution");
            assert_eq!(runs.load(Ordering::SeqCst), 1, "{action} {step}");
        }
    }

    #[test]
    fn a_withdrawal_step_that_expired_before_its_lock_runs_on_a_fresh_nonce_after_restart() {
        for (action, step) in [("dns.publish_records", "dns-0"), ("route.withdraw_site", "withdraw-7")] {
            let order = |nonce: &str| {
                let mut fields = payload();
                fields["action"] = action.into();
                fields["target"] = "7".into();
                fields["operation"] = format!("42:{step}").into();
                fields["nonce"] = nonce.into();
                fields["expires_ms"] = (NOW + 5000).into();
                fields["input"] = serde_json::json!({});
                let keys = pinned(1);
                let enabled = Enabled::parse("dns\nroute").expect("valid");
                let node = "7".parse().expect("id");
                crate::order::check(&sign(1, &fields), &Checks { keys: &keys, enabled: &enabled, node: &node }, NOW).expect("valid withdrawal order")
            };
            let directory = tempfile::tempdir().expect("scratch");
            let path = directory.path().join("journal");
            let runs = Arc::new(AtomicUsize::new(0));
            let (executor, _) = Executor::start(&path, Counting(Arc::clone(&runs)), || NOW, |_| {}).expect("start");
            let expired = order(N1);
            assert_eq!(executor.handle(&expired, expired.expires_ms()).expect("expires").outcome, Outcome::failed(ORDER_EXPIRED));
            drop(executor);

            let (executor, _) = Executor::start(&path, Counting(Arc::clone(&runs)), || NOW, |_| {}).expect("restart");
            let fresh = executor.handle(&order(N2), NOW).expect("fresh order acts");
            assert!(!fresh.repeat, "{action}");
            let again = executor.handle(&order(N3), NOW).expect("lost receipt");
            assert!(again.repeat, "{action}: two sends, one execution");
            assert_eq!(runs.load(Ordering::SeqCst), 1, "{action}");
        }
    }


    #[test]
    fn a_process_step_that_expired_before_its_lock_runs_on_a_fresh_nonce_and_repeats_after_a_lost_receipt() {
        for (action, target, step) in [("process.ensure_state", "7:process-417", "converge"), ("process.read_instance", "7:process-417", "read"),
            ("process.restart", "7:process-417", "restart"),
            ("schedule.ensure_timer", "7:schedule-7f3c0a5e-1b2c-4d3e-8f90-0123456789ab", "timer")] {
            let order = |nonce: &str| {
                let mut fields = payload();
                fields["action"] = action.into();
                fields["target"] = target.into();
                fields["operation"] = format!("42:{step}").into();
                fields["nonce"] = nonce.into();
                fields["expires_ms"] = (NOW + 5000).into();
                fields["input"] = serde_json::json!({});
                let keys = pinned(1);
                let enabled = Enabled::parse("process").expect("valid");
                let node = "7".parse().expect("id");
                crate::order::check_with_target(&sign(1, &fields), &Checks { keys: &keys, enabled: &enabled, node: &node }, NOW, |_, _| true)
                    .expect("valid process order")
            };
            let directory = tempfile::tempdir().expect("scratch");
            let path = directory.path().join("journal");
            let runs = Arc::new(AtomicUsize::new(0));
            let (executor, _) = Executor::start(&path, Counting(Arc::clone(&runs)), || NOW, |_| {}).expect("start");
            let expired = order(N1);
            assert_eq!(executor.handle(&expired, expired.expires_ms()).expect("expires").outcome, Outcome::failed(ORDER_EXPIRED));
            drop(executor);

            let (executor, _) = Executor::start(&path, Counting(Arc::clone(&runs)), || NOW, |_| {}).expect("restart");
            assert!(!executor.handle(&order(N2), NOW).expect("fresh order acts").repeat, "{action}");
            assert!(executor.handle(&order(N3), NOW).expect("lost receipt").repeat, "{action}: two sends, one execution");
            assert_eq!(runs.load(Ordering::SeqCst), 1, "{action}");
        }
    }

    #[test]
    fn a_route_step_that_expired_before_its_lock_runs_once_on_a_fresh_nonce_after_restart() {
        for (action, step) in [("tls.ensure_csr", "csr-0"), ("route.publish_site", "site-3"), ("dns.publish_records", "dns-0")] {
            let directory = tempfile::tempdir().expect("scratch");
            let path = directory.path().join("journal");
            let runs = Arc::new(AtomicUsize::new(0));
            let (executor, _) = Executor::start(&path, Counting(Arc::clone(&runs)), || NOW, |_| {}).expect("start");
            let expired = sync_order(action, step, N1, NOW + 5000);
            assert_eq!(executor.handle(&expired, expired.expires_ms()).expect("expires").outcome, Outcome::failed(ORDER_EXPIRED));
            drop(executor);

            let (executor, _) = Executor::start(&path, Counting(Arc::clone(&runs)), || NOW, |_| {}).expect("restart");
            let fresh = executor.handle(&sync_order(action, step, N2, NOW + 5000), NOW).expect("fresh order acts");
            assert!(!fresh.repeat, "{action} {step}");
            let again = executor.handle(&sync_order(action, step, N3, NOW + 5000), NOW).expect("lost receipt");
            assert!(again.repeat, "{action} {step}: two sends, one execution");
            assert_eq!(runs.load(Ordering::SeqCst), 1, "{action} {step}");
        }
    }

    #[test]
    fn only_fenced_actions_keep_an_expiry_out_of_the_journal() {
        assert!(Action::InstanceDestroy.receipt_fenced() && Action::InstanceSyncBase.receipt_fenced() && Action::InstanceClearBase.receipt_fenced()
            && Action::InstanceFetchBranch.receipt_fenced() && Action::ProcessEnsureState.receipt_fenced() && Action::ScheduleEnsureTimer.receipt_fenced()
            && Action::ProcessReadInstance.receipt_fenced() && Action::ProcessRestart.receipt_fenced());
        assert!(Action::TlsEnsureCsr.receipt_fenced() && Action::RoutePublishSite.receipt_fenced() && Action::DnsPublishRecords.receipt_fenced()
            && Action::RouteWithdrawSite.receipt_fenced());
        assert!(!Action::Sleep.receipt_fenced() && !Action::InstanceCheckout.receipt_fenced());
        // The host primitives that change a resource are fenced as the Route actions they replace; the read-only probe is not.
        for action in [Action::FileEnsure, Action::DotenvEnsure, Action::PosixAclEnsure, Action::CaTrustEnsure, Action::SystemdUnitEnsure, Action::ConfigApply] {
            assert!(action.receipt_fenced() && !action.read_only(), "{action} is fenced");
        }
        assert!(Action::HttpProbe.read_only() && !Action::HttpProbe.receipt_fenced());
    }

    #[test]
    fn destroy_an_expired_order_does_not_settle_a_fresh_nonce_even_after_restart() {
        let directory = tempfile::tempdir().expect("scratch");
        let path = directory.path().join("journal");
        let runs = Arc::new(AtomicUsize::new(0));
        let (executor, _) = Executor::start(&path, Counting(Arc::clone(&runs)), || NOW, |_| {}).expect("start");
        let expired = destroy_order(N1);
        let reply = executor.handle(&expired, expired.expires_ms()).expect("expires waiting for the lock");
        assert_eq!(reply.outcome, Outcome::failed(ORDER_EXPIRED));
        assert_eq!(runs.load(Ordering::SeqCst), 0);
        drop(executor);

        let (executor, recovered) = Executor::start(&path, Counting(Arc::clone(&runs)), || NOW, |_| {}).expect("restart");
        assert_eq!(recovered, vec![]);
        let reply = executor.handle(&destroy_order(N2), NOW).expect("fresh order can act");
        assert_eq!(reply.outcome, Outcome::Done);
        assert!(!reply.repeat);
        assert_eq!(runs.load(Ordering::SeqCst), 1);
    }

    #[test]
    fn destroy_repeat_after_a_lost_receipt_waits_for_its_target_recovery() {
        let directory = tempfile::tempdir().expect("scratch");
        let path = directory.path().join("journal");
        let first = destroy_order(N1);
        let key = DoneKey { operation: first.operation().clone(), action: first.action(), target: first.target().clone() };
        let open = Record::Open { nonce: first.nonce(), key, expires_ms: first.expires_ms(), power_check: crate::order::PowerCheck::Default,
            input: first.input().clone() };
        std::fs::write(&path, format!("{}\n", serde_json::to_string(&open).expect("record"))).expect("journal before crash");
        let runs = Arc::new(AtomicUsize::new(0));
        let (started, on_start) = std::sync::mpsc::channel();
        let gate = Arc::new((Mutex::new(false), std::sync::Condvar::new()));
        let runner = Gated { runs: Arc::clone(&runs), started: Mutex::new(started), gate: Arc::clone(&gate) };
        let (executor, pending) = Executor::open(&path, runner, || NOW, |_| {}).expect("open");
        let fresh = destroy_order(N2);
        std::thread::scope(|scope| {
            let recovery = scope.spawn(|| executor.recover(pending, || {}));
            on_start.recv().expect("recovery holds the Instance lock");
            let repeated = scope.spawn(|| executor.handle(&fresh, NOW));
            let accepted = (0..100).any(|_| {
                if records(&path).iter().any(|record| matches!(record, Record::Seen { nonce, .. } if nonce.to_string() == N2)) {
                    return true;
                }
                std::thread::sleep(std::time::Duration::from_millis(10));
                false
            });
            let held_runs = runs.load(Ordering::SeqCst);
            let (open, opened) = &*gate;
            *open.lock().expect("gate") = true;
            opened.notify_all();
            assert!(accepted, "fresh nonce was recorded while recovery held the lock");
            assert_eq!(held_runs, 1, "the repeat did not enter the runner while recovery was active");
            assert_eq!(recovery.join().expect("thread").expect("recovers")[0].outcome, Outcome::Done);
            let reply = repeated.join().expect("thread").expect("repeat");
            assert!(reply.repeat);
            assert_eq!(reply.outcome, Outcome::Done);
        });
        drop(executor);
        let (executor, recovered) = Executor::start(&path, Counting(Arc::clone(&runs)), || NOW, |_| {}).expect("restart after stored result");
        assert_eq!(recovered, vec![]);
        let reply = executor.handle(&destroy_order(N3), NOW).expect("replay stored receipt");
        assert!(reply.repeat);
        assert_eq!(reply.outcome, Outcome::Done);
        assert_eq!(runs.load(Ordering::SeqCst), 1);
    }

    #[test]
    fn destroy_repeats_a_journaled_terminal_refusal_with_its_detail_after_restart() {
        const REPLAY: u64 = NOW + DONE_RETENTION_MS - 1;
        struct DatasetTaken;
        impl Run for DatasetTaken {
            fn run(&self, _order: &VerifiedOrder) -> Outcome {
                Outcome::Failed {
                    code: "instance.removal_dataset_taken".to_owned(),
                    detail: Some("The saved dataset name is already held.".to_owned()),
                }
            }
        }

        let directory = tempfile::tempdir().expect("scratch");
        let path = directory.path().join("journal");
        let (executor, _) = Executor::start(&path, DatasetTaken, || NOW, |_| {}).expect("start");
        let first = executor
            .handle(&destroy_order(N1), NOW)
            .expect("terminal refusal");
        drop(executor);

        let runs = Arc::new(AtomicUsize::new(0));
        let (executor, recovered) =
            Executor::start(&path, Counting(Arc::clone(&runs)), || REPLAY, |_| {})
                .expect("restart");
        assert_eq!(recovered, vec![]);
        let repeat = executor
            .handle(&destroy_order_at(N2, REPLAY, REPLAY + 1), REPLAY)
            .expect("repeat stored refusal");
        assert!(repeat.repeat);
        assert_eq!(repeat.outcome, first.outcome);
        assert_eq!(repeat.operation.as_str(), "42:remove");
        assert_eq!(runs.load(Ordering::SeqCst), 0);
    }

    fn read_order(nonce: &str, expiry: u64) -> VerifiedOrder {
        let mut fields = payload();
        fields["action"] = "instance.work_read".into();
        fields["target"] = "12".into();
        fields["operation"] = "read:12".into();
        fields["nonce"] = nonce.into();
        fields["expires_ms"] = expiry.into();
        let keys = pinned(1);
        let enabled = Enabled::parse("instance").expect("valid");
        let node = "7".parse().expect("id");
        crate::order::check_with_target(&sign(1, &fields), &Checks { keys: &keys, enabled: &enabled, node: &node }, NOW, |_, target| {
            target.as_str() == "12"
        })
        .expect("valid read")
    }

    #[test]
    fn a_read_runs_every_time_outside_the_journal_and_the_target_lock() {
        let dir = tempfile::tempdir().expect("tempdir");
        let path = dir.path().join("j");
        let runs = Arc::new(AtomicUsize::new(0));
        let (executor, _) = Executor::start(&path, Counting(Arc::clone(&runs)), || NOW, |_| {}).expect("start");
        // A destroy of the same target holds its lock; the read does not wait for it.
        let lock = {
            let mut targets = executor.targets.lock().expect("targets");
            Arc::clone(targets.entry("12".parse().expect("target")).or_default())
        };
        let held = lock.lock().expect("target lock");
        let first = executor.handle(&read_order(N1, NOW + 4000), NOW).expect("reads");
        let second = executor.handle(&read_order(N2, NOW + 4000), NOW).expect("reads again");
        drop(held);
        assert!(!first.repeat && !second.repeat, "a lost reply is read again, never repeated from a record");
        assert_eq!(runs.load(Ordering::SeqCst), 2);
        assert!(matches!(executor.handle(&read_order(N1, NOW + 4000), NOW), Err(StateRefusal::ReplayedNonce { first: None })));
        assert_eq!(executor.handle(&read_order(N3, NOW + 1), NOW + 1).expect("expired").outcome, Outcome::failed(ORDER_EXPIRED));
        assert_eq!(runs.load(Ordering::SeqCst), 2);
        drop(executor);
        assert!(records(&path).is_empty(), "a read writes no journal record");
    }

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
            input: serde_json::Value::Null,
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

    /// Records the input each run receives.
    #[derive(Debug, Default)]
    struct Inputs(Arc<std::sync::Mutex<Vec<serde_json::Value>>>);

    impl Run for Inputs {
        fn run(&self, order: &VerifiedOrder) -> Outcome {
            self.0.lock().expect("inputs lock").push(order.input().clone());
            Outcome::Done
        }
    }

    #[test]
    fn start_up_runs_an_open_order_with_its_recorded_input() {
        let dir = tempfile::tempdir().expect("tempdir");
        let path = dir.path().join("j");
        let open = order(N1, "op-input");
        let record = Record::Open {
            nonce: open.nonce(),
            key: DoneKey { operation: open.operation().clone(), action: open.action(), target: open.target().clone() },
            expires_ms: open.expires_ms(),
            power_check: crate::order::PowerCheck::Default,
            input: serde_json::json!({"path": "/etc/example", "mode": "0644"}),
        };
        std::fs::write(&path, format!("{}\n", serde_json::to_string(&record).expect("json"))).expect("journal");
        let seen = Arc::new(std::sync::Mutex::new(Vec::new()));
        let (_, recovered) = Executor::start(&path, Inputs(Arc::clone(&seen)), || NOW + 60_000, |_| {}).expect("recover");
        assert_eq!(recovered.len(), 1);
        assert_eq!(
            *seen.lock().expect("inputs lock"),
            vec![serde_json::json!({"path": "/etc/example", "mode": "0644"})]
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

    static GATED_CLOCK: std::sync::atomic::AtomicU64 = std::sync::atomic::AtomicU64::new(NOW);

    /// Holds its first order's action until the test opens the gate.
    struct Gated {
        runs: Arc<AtomicUsize>,
        started: Mutex<std::sync::mpsc::Sender<()>>,
        gate: Arc<(Mutex<bool>, std::sync::Condvar)>,
    }

    impl Run for Gated {
        fn run(&self, _order: &VerifiedOrder) -> Outcome {
            self.runs.fetch_add(1, Ordering::SeqCst);
            let _ = self.started.lock().expect("sender").send(());
            let (open, opened) = &*self.gate;
            let mut open = open.lock().expect("gate");
            while !*open {
                open = opened.wait(open).expect("gate");
            }
            Outcome::Done
        }
    }

    fn records(path: &Path) -> Vec<Record> {
        journal::read(path).expect("journal")
    }

    #[test]
    fn an_order_is_recorded_when_accepted_and_never_acts_once_it_expired_waiting_for_its_target() {
        let dir = tempfile::tempdir().expect("tempdir");
        let path = dir.path().join("j");
        let runs = Arc::new(AtomicUsize::new(0));
        let (started, on_start) = std::sync::mpsc::channel();
        let gate = Arc::new((Mutex::new(false), std::sync::Condvar::new()));
        let runner = Gated {
            runs: Arc::clone(&runs),
            started: Mutex::new(started),
            gate: Arc::clone(&gate),
        };
        let (executor, _) =
            Executor::start(&path, runner, || GATED_CLOCK.load(Ordering::SeqCst), |_| {})
                .expect("start");
        assert!(journal::accept_marker(&path).exists());
        std::thread::scope(|scope| {
            let first = scope.spawn(|| executor.handle(&order(N1, "op-1"), NOW));
            on_start.recv().expect("first order runs");
            let waiting = scope.spawn(|| executor.handle(&order(N2, "op-2"), NOW));
            let accepted = |records: &[Record]| {
                records.iter().any(|record| {
                    matches!(record, Record::Seen { key, .. } if key.operation.as_str() == "op-2")
                })
            };
            let mut tries = 0;
            while !accepted(&records(&path)) {
                tries += 1;
                assert!(tries < 500, "the waiting order has no accept record");
                std::thread::sleep(std::time::Duration::from_millis(10));
            }
            GATED_CLOCK.store(NOW + 10_000, Ordering::SeqCst);
            let (open, opened) = &*gate;
            *open.lock().expect("gate") = true;
            opened.notify_all();
            assert_eq!(
                first.join().expect("first").expect("runs").outcome,
                Outcome::Done
            );
            let late = waiting.join().expect("second").expect("answers");
            assert!(!late.repeat);
            assert_eq!(late.outcome, Outcome::failed(ORDER_EXPIRED));
        });
        assert_eq!(runs.load(Ordering::SeqCst), 1);
        assert!(records(&path).iter().any(|record| matches!(record,
            Record::Done { key, outcome: Outcome::Failed { code, .. }, .. }
                if key.operation.as_str() == "op-2" && code == ORDER_EXPIRED)));
    }

    #[test]
    fn a_new_order_is_accepted_while_start_up_repeats_an_open_order_and_waits_for_that_repeat() {
        let dir = tempfile::tempdir().expect("tempdir");
        let path = dir.path().join("j");
        let open = order(N1, "op-open");
        let record = Record::Open {
            nonce: open.nonce(),
            key: DoneKey { operation: open.operation().clone(), action: open.action(), target: open.target().clone() },
            expires_ms: open.expires_ms(),
            power_check: crate::order::PowerCheck::Default,
            input: serde_json::Value::Null,
        };
        std::fs::write(&path, format!("{}\n", serde_json::to_string(&record).expect("json"))).expect("journal");
        let runs = Arc::new(AtomicUsize::new(0));
        let (started, on_start) = std::sync::mpsc::channel();
        let gate = Arc::new((Mutex::new(false), std::sync::Condvar::new()));
        let runner = Gated { runs: Arc::clone(&runs), started: Mutex::new(started), gate: Arc::clone(&gate) };
        let (executor, pending) = Executor::open(&path, runner, || NOW, |_| {}).expect("open");
        assert!(!pending.is_empty());
        let (held, locked) = std::sync::mpsc::channel();
        std::thread::scope(|scope| {
            let repeat = scope.spawn(|| executor.recover(pending, move || held.send(()).expect("held")));
            locked.recv().expect("the repeat holds its target");
            on_start.recv().expect("the open order repeats");
            // The executor serves now: a new order for the same target is accepted at once and waits for the repeat.
            let second = scope.spawn(|| executor.handle(&order(N2, "op-2"), NOW));
            let accepted = |records: &[Record]| {
                records.iter().any(|record| matches!(record, Record::Seen { key, .. } if key.operation.as_str() == "op-2"))
            };
            let mut tries = 0;
            while !accepted(&records(&path)) {
                tries += 1;
                assert!(tries < 500, "the new order has no accept record while the repeat runs");
                std::thread::sleep(std::time::Duration::from_millis(10));
            }
            assert_eq!(runs.load(Ordering::SeqCst), 1, "the new order waits for its target's repeat");
            let (open, opened) = &*gate;
            *open.lock().expect("gate") = true;
            opened.notify_all();
            let recovered = repeat.join().expect("repeat").expect("recovers");
            assert_eq!(recovered.len(), 1);
            assert_eq!(recovered[0].outcome, Outcome::Done);
            let reply = second.join().expect("second").expect("runs");
            assert!(!reply.repeat);
            assert_eq!(reply.outcome, Outcome::Done);
        });
        assert_eq!(runs.load(Ordering::SeqCst), 2);
    }

    #[test]
    fn start_up_ends_an_order_accepted_without_an_open_record_as_expired() {
        let dir = tempfile::tempdir().expect("tempdir");
        let path = dir.path().join("j");
        let accepted = order(N1, "op-wait");
        let key = DoneKey {
            operation: accepted.operation().clone(),
            action: accepted.action(),
            target: accepted.target().clone(),
        };
        // A crash while the order waited for its target's lock leaves exactly this journal.
        let record = Record::Seen {
            nonce: accepted.nonce(),
            key,
            expires_ms: accepted.expires_ms(),
        };
        std::fs::write(
            &path,
            format!("{}\n", serde_json::to_string(&record).expect("json")),
        )
        .expect("journal");
        let runs = Arc::new(AtomicUsize::new(0));
        let (executor, recovered) =
            Executor::start(&path, Counting(Arc::clone(&runs)), || NOW, |_| {}).expect("start");
        assert_eq!(recovered, vec![]);
        let repeat = executor.handle(&order(N2, "op-wait"), NOW).expect("repeat");
        assert!(repeat.repeat);
        assert_eq!(repeat.outcome, Outcome::failed(ORDER_EXPIRED));
        assert_eq!(runs.load(Ordering::SeqCst), 0);
    }
}
