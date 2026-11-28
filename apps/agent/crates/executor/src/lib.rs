//! The executor: runs Gateway-signed orders on this Node (ADR 0266, ADR 0267, ADR 0271).
//!
//! It is a mode of the one `orbit-agent` binary (ADR 0271 N7):
//!
//! - `orbit-agent execute serve` is the long-running executor that `orbit-executor.service` starts at boot.
//! - `orbit-agent execute relay` is the one relay command, run as `orbit-executor-relay`.

pub mod bus;

use std::fs;
use std::io;
use std::path::{Path, PathBuf};
use std::sync::Arc;

use orbit_executor_core::action::{Action, Enabled};
use orbit_executor_core::caller::Policy;
use orbit_executor_core::executor::{Executor, Run};
use orbit_executor_core::journal::Outcome;
use orbit_executor_core::order::{PinnedKeys, PowerCheck, VerifiedOrder};
use orbit_executor_core::server::{self, Settings, SOCKET_PATH};
use orbit_executor_core::{ids::NodeId, relay};

const KEYS_PATH: &str = "/etc/orbit/executor/gateway.pub";
const ENABLED_PATH: &str = "/etc/orbit/executor/enabled";
const NODE_PATH: &str = "/etc/orbit/executor/node";
const JOURNAL_PATH: &str = "/var/lib/orbit/executor/journal";
const RELAY_USER: &str = "orbit-executor-relay";
/// The restore pool's size, which the role writes (ADR 0266: one pool per Node, sized by the swap device, floor 16).
const POOL_PATH: &str = "/etc/orbit/executor/restore-threads";
/// The wake input's `web.ready_seconds`, within 1..=120 s. An order without it gets the power family's default (30 s).
fn ready_bound(input: &serde_json::Value) -> Option<std::time::Duration> {
    input["web"]["ready_seconds"]
        .as_u64()
        .map(|seconds| std::time::Duration::from_secs(seconds.clamp(1, 120)))
}
/// The wake failures the Gateway can act on; any other error reports `instance.container_start_failed`.
const WAKE_CODES: [&str; 4] = [
    "instance.container_frozen_unmanaged",
    "instance.container_stopped",
    "instance.container_start_failed",
    "instance.web_unready",
];

/// Joins the action families to the core. Every action is matched here, so a new one fails the build until
/// its family runs it.
#[derive(Debug)]
struct Families(orbit_executor_power::Power);

impl Run for Families {
    fn target_exists(&self, action: Action, target: &orbit_executor_core::ids::TargetId) -> bool {
        // A discard after destroy must still be possible when Incus no longer has the container.
        if matches!(action, Action::Discard | Action::InstanceDestroy) {
            return target
                .as_str()
                .parse::<u64>()
                .is_ok_and(|n| n > 0 && n.to_string() == target.as_str());
        }
        self.0.target_exists(target.as_str())
    }
    #[expect(clippy::too_many_lines, reason = "one arm per action, so the dispatcher fails to build when an action is added")]
    fn run(&self, order: &VerifiedOrder) -> Outcome {
        match order.action() {
            Action::ProofNoop => Outcome::Done,
            Action::Sleep => {
                let started = std::time::Instant::now();
                let target = order.target().as_str();
                let checked = if order.power_check() == PowerCheck::Route {
                    self.0.require_sleep_route(target)
                } else {
                    Ok(())
                };
                match checked.and_then(|()| self.0.sleep(target, order.operation().as_str())) {
                    Ok(receipt) => {
                        // This includes receipt and marker fsync, which cannot time their own publication.
                        log(&format!(
                            "power sleep {} completed: total_us={} reclaim_us={}",
                            order.operation(),
                            started.elapsed().as_micros(),
                            receipt.reclaim_us
                        ));
                        Outcome::Slept {
                            receipt: serde_json::json!(receipt),
                        }
                    }
                    Err(error) => {
                        log(&format!("power sleep failed: {error}"));
                        Outcome::failed(match error.to_string().as_str() {
                            code @ ("instance.container_frozen_unmanaged"
                                | orbit_executor_power::SLEEP_INCOMPLETE
                                | orbit_executor_power::ROUTE_UNREADY
                                | orbit_executor_power::READERS_BUSY) => code,
                            _ => "instance.container_freeze_failed",
                        })
                    }
                }
            }
            Action::Wake => {
                let started = std::time::Instant::now();
                let probe = match order.power_check() {
                    PowerCheck::NoWeb => orbit_executor_power::WebProbe::NotConfigured,
                    PowerCheck::Resume => orbit_executor_power::WebProbe::Lifecycle,
                    _ => orbit_executor_power::WebProbe::Required,
                };
                match self
                    .0
                    .wake(order.target().as_str(), probe, ready_bound(order.input()))
                {
                    Ok(receipt) => {
                        log(&format!(
                            "power wake {} {}: total_us={} restore_us={} pages_read={} pages_failed={}",
                            order.operation(),
                            receipt.restore,
                            started.elapsed().as_micros(),
                            receipt.restore_us,
                            receipt.pages_read,
                            receipt.pages_failed
                        ));
                        match receipt.reason {
                            // The container runs again either way; `partial` says its memory did not all come back first.
                            Some(reason) => Outcome::Partial {
                                code: format!("instance.restore_{reason}"),
                            },
                            None => Outcome::Done,
                        }
                    }
                    Err(error) => {
                        log(&format!("power wake failed: {error}"));
                        let code = error.to_string();
                        Outcome::failed(if WAKE_CODES.contains(&code.as_str()) {
                            code
                        } else {
                            "instance.container_start_failed".to_owned()
                        })
                    }
                }
            }
            Action::Discard => match self.0.discard_stopped(order.target().as_str()) {
                Ok(()) => Outcome::Done,
                Err(error) => Outcome::failed(if error.to_string() == orbit_executor_power::READERS_BUSY {
                    orbit_executor_power::READERS_BUSY
                } else {
                    "instance.power_discard_failed"
                }),
            },
            Action::UfwEnsureRule => match orbit_executor_firewall::ensure_rule(&orbit_executor_firewall::NodeUfw, order.input()) {
                Ok(changes) => Outcome::Applied { changes },
                Err(failure) => {
                    log(&format!("ufw.ensure_rule {} failed: {} {}", order.operation(), failure.code, failure.detail));
                    Outcome::failed(failure.code)
                }
            },
            Action::TlsEnsureCsr | Action::RoutePublishSite | Action::RouteWithdrawSite => route(order),
            Action::DnsPublishRecords => dns(order),
            Action::FileEnsure | Action::DotenvEnsure | Action::PosixAclEnsure | Action::CaTrustEnsure | Action::SystemdUnitEnsure
            | Action::ConfigApply | Action::HttpProbe => host(order),
            Action::FileRead | Action::AccountRead => host_read(order),
            Action::IncusInstanceEnsure => incus(order),
            Action::InstanceDestroy => self.destroy(order),
            Action::InstanceWorkRead => work_read(order),
            Action::InstanceClone | Action::InstancePrepare | Action::InstanceStart | Action::InstanceFetchBranch | Action::InstanceCheckout | Action::InstanceT3
            | Action::InstanceClubKey | Action::InstanceCopyVolume | Action::InstanceStop | Action::InstanceSpareVolume
            | Action::InstanceFleetReady | Action::InstanceNames | Action::InstanceSyncBase | Action::InstanceClearBase => instance(order),
            Action::ProcessEnsureState | Action::ProcessReadInstance | Action::ProcessRestart | Action::ScheduleEnsureTimer => process(order),
        }
    }
}

impl Families {
    fn destroy(&self, order: &VerifiedOrder) -> Outcome {
        let system = orbit_executor_instance::NodeSystem::new(bus::BLOBS_PATH);
        let id = order.target().as_str();
        if let Err(failure) = orbit_executor_instance::destroy(&system, id, order.input()) {
            log(&format!("{} {} failed: {}", order.action(), order.operation(), failure.code));
            return destroy_failure(failure.code, &failure.detail);
        }
        match self.0.discard_destroyed(id) {
            Ok(()) => Outcome::Done,
            Err(error) => {
                log(&format!(
                    "{} {} could not discard executor power state",
                    order.action(),
                    order.operation()
                ));
                destroy_failure(
                    "instance.power_discard_failed",
                    &format!("Could not discard executor power state: {error}"),
                )
            }
        }
    }
}

fn destroy_failure(code: &str, detail: &str) -> Outcome {
    const MAX_DETAIL_BYTES: usize = 4096;
    let detail = orbit_agent_contract::redact::redact_text(detail);
    let start = detail.len().saturating_sub(MAX_DETAIL_BYTES);
    let bytes = detail.as_bytes();
    let start = if start > 0 && bytes[start - 1] != b'\n' {
        bytes[start..]
            .iter()
            .position(|byte| *byte == b'\n')
            .map_or(detail.len(), |newline| start + newline + 1)
    } else {
        start
    };
    let tail = detail[start..].trim();
    let diagnostic = if !tail.is_empty() {
        Some(tail.to_owned())
    } else if start > 0 {
        Some("The incomplete native diagnostic line was omitted.".to_owned())
    } else {
        None
    };
    Outcome::Failed {
        code: code.to_owned(),
        detail: diagnostic,
    }
}

#[expect(
    clippy::print_stderr,
    reason = "systemd sends the executor's standard error to its journal"
)]
fn log(line: &str) {
    eprintln!("{line}");
}

/// Runs the executor mode with its arguments (`serve` or `relay`) and returns the process's exit code, as the agent's
/// other modes do.
pub fn main(args: &[String]) -> i32 {
    let args: Vec<&str> = args.iter().map(String::as_str).collect();
    let result = match args.as_slice() {
        ["serve"] => serve(),
        ["relay"] => relay::relay(
            Path::new(SOCKET_PATH),
            io::stdin().lock(),
            io::stdout().lock(),
        )
        .map_err(|error| error.to_string()),
        _ => Err("usage: orbit-agent execute serve | orbit-agent execute relay".to_owned()),
    };
    match result {
        Ok(()) => 0,
        Err(message) => {
            log(&format!("orbit-agent execute: {message}"));
            1
        }
    }
}

fn read(path: &str) -> Result<String, String> {
    fs::read_to_string(path).map_err(|error| format!("read {path}: {error}"))
}

/// The uid and gid of `user` from `/etc/passwd`, when it exists.
fn system_user(passwd: &str, user: &str) -> Option<(u32, u32)> {
    passwd.lines().find_map(|line| {
        let mut fields = line.split(':');
        (fields.next() == Some(user)).then_some(())?;
        let uid = fields.nth(1)?.parse().ok()?;
        let gid = fields.next()?.parse().ok()?;
        Some((uid, gid))
    })
}

fn serve() -> Result<(), String> {
    // Lock poisoning is fatal (ADR 0267 P9): any panic ends the process, so systemd restarts it and start-up
    // recovery finishes open orders, instead of one dead thread leaking a connection slot and a poisoned lock.
    let report = std::panic::take_hook();
    std::panic::set_hook(Box::new(move |info| {
        report(info);
        std::process::abort();
    }));
    let keys =
        PinnedKeys::parse(&read(KEYS_PATH)?).map_err(|error| format!("{KEYS_PATH}: {error}"))?;
    let enabled =
        Enabled::parse(&read(ENABLED_PATH)?).map_err(|error| format!("{ENABLED_PATH}: {error}"))?;
    let node: NodeId = read(NODE_PATH)?
        .trim()
        .parse()
        .map_err(|error| format!("{NODE_PATH}: {error}"))?;
    let relay_user = system_user(&read("/etc/passwd")?, RELAY_USER);
    let binary: PathBuf =
        std::env::current_exe().map_err(|error| format!("own executable: {error}"))?;
    let settings = Arc::new(Settings {
        policy: Policy::production(relay_user.map(|(uid, _)| uid), binary),
        keys,
        enabled,
        node,
    });

    let threads: usize = read(POOL_PATH)?
        .trim()
        .parse()
        .map_err(|error| format!("{POOL_PATH}: {error}"))?;
    let power = orbit_executor_power::Power::new(threads)
        .map_err(|error| format!("{POOL_PATH}: {error}"))?;
    let journal = Path::new(JOURNAL_PATH);
    let (executor, pending) = Executor::open(journal, Families(power), server::now_ms, log)
        .map_err(|error| error.to_string())?;
    let executor = Arc::new(executor);
    // Open orders repeat in the background, under their targets' locks. A converge restarts this service while wakes and
    // sleeps go on, and the observer offers each order once: a new order must find the socket at once, not after the repeats.
    let (held, locked) = std::sync::mpsc::channel();
    let repeating = Arc::clone(&executor);
    std::thread::spawn(move || {
        match recover_then_prune(&repeating, pending, &orbit_executor_process::Attempts::node(), || {
            let _ = held.send(());
        }) {
            Ok(recovered) => {
                for done in &recovered {
                    log(&format!(
                        "recovered {} {} {}: {:?}",
                        done.key.operation, done.key.action, done.key.target, done.outcome
                    ));
                }
            }
            Err(error) => {
                // A journal that cannot record a result ends the process.
                log(&format!("recovery failed: {error}"));
                std::process::abort();
            }
        }
    });
    locked
        .recv()
        .map_err(|_| "recovery ended before it held its targets".to_owned())?;
    let listener = server::bind(Path::new(SOCKET_PATH), relay_user.map(|(_, gid)| gid))
        .map_err(|error| format!("bind {SOCKET_PATH}: {error}"))?;
    log(&format!("serving {SOCKET_PATH}"));
    // Orders also arrive over NATS when the converge wrote the bus's settings (ADR 0271).
    bus::start(Arc::clone(&settings), Arc::clone(&executor), log);
    server::serve(&listener, &settings, &executor, log);
    Ok(())
}

/// The route family (ADR 0271): a leaf's certificate request, one Node's part of a Route, or its withdrawal. A failure's code goes to the
/// Gateway, its detail only to the log.
fn route(order: &VerifiedOrder) -> Outcome {
    let system = orbit_executor_route::NodeSystem::new(bus::BLOBS_PATH);
    let result = match order.action() {
        Action::TlsEnsureCsr => orbit_executor_route::ensure_csr(&system, order.input()).map(|stdout| Outcome::Output { stdout }),
        Action::RouteWithdrawSite => orbit_executor_route::withdraw_site(&system, order.input()).map(|changes| Outcome::Applied { changes }),
        _ => orbit_executor_route::publish_site(&system, order.input()).map(|changes| Outcome::Applied { changes }),
    };
    result.unwrap_or_else(|failure| {
        log(&format!("{} {} failed: {} {}", order.action(), order.operation(), failure.code, failure.detail));
        Outcome::failed(failure.code)
    })
}

/// The dns family (ADR 0271): a VPN Node's private DNS records. A failure's code goes to the Gateway, its detail only to
/// the log.
fn dns(order: &VerifiedOrder) -> Outcome {
    let system = orbit_executor_dns::NodeSystem::new(bus::BLOBS_PATH);
    orbit_executor_dns::publish_records(&system, order.input()).map_or_else(
        |failure| {
            log(&format!("{} {} failed: {} {}", order.action(), order.operation(), failure.code, failure.detail));
            Outcome::failed(failure.code)
        },
        |changes| Outcome::Applied { changes },
    )
}

/// The host family (ADR 0271 N3): one host resource per order, every path, name, lock and digest from the input. A failure's
/// code goes to the Gateway, its detail only to the log.
fn host(order: &VerifiedOrder) -> Outcome {
    if order.action() == Action::PosixAclEnsure {
        return acl_ensure(order);
    }
    let system = orbit_executor_host::NodeSystem::new(bus::BLOBS_PATH);
    let primitive: fn(&orbit_executor_host::NodeSystem, &serde_json::Value) -> Result<u32, orbit_executor_host::Failure> = match order.action() {
        Action::FileEnsure => orbit_executor_host::file_ensure,
        Action::DotenvEnsure => orbit_executor_host::dotenv_ensure,
        Action::CaTrustEnsure => orbit_executor_host::trust_ensure,
        Action::SystemdUnitEnsure => orbit_executor_host::unit_ensure,
        Action::ConfigApply => orbit_executor_host::config_apply,
        _ => orbit_executor_host::probe,
    };
    primitive(&system, order.input()).map_or_else(
        |failure| {
            log(&format!("{} {} failed: {} {}", order.action(), order.operation(), failure.code, failure.detail));
            Outcome::failed(failure.code)
        },
        |changes| Outcome::Applied { changes },
    )
}

/// The incus family (ADR 0271 N3): one Incus instance per order through the Incus API, every name from the input. A failure's
/// code goes to the Gateway, its detail only to the log.
fn incus(order: &VerifiedOrder) -> Outcome {
    orbit_executor_incus::instance_ensure(order.input()).map_or_else(
        |failure| {
            log(&format!("{} {} failed: {} {}", order.action(), order.operation(), failure.code, failure.detail));
            Outcome::failed(failure.code)
        },
        |changes| Outcome::Applied { changes },
    )
}

/// `posix.acl.ensure` of the host family: the paths it changed and the paths a tree walk left out, as JSON in the receipt's
/// output (`{changes, skipped_mounts, skipped_hard_links}`).
fn acl_ensure(order: &VerifiedOrder) -> Outcome {
    let system = orbit_executor_host::NodeSystem::new(bus::BLOBS_PATH);
    orbit_executor_host::acl_ensure(&system, order.input()).map_or_else(
        |failure| {
            log(&format!("{} {} failed: {} {}", order.action(), order.operation(), failure.code, failure.detail));
            Outcome::failed(failure.code)
        },
        |acl| Outcome::Output { stdout: serde_json::json!(acl).to_string() },
    )
}

/// `file.read` and `account.read` of the host family: what one path or one account holds, as JSON in the receipt's output.
/// They change nothing.
fn host_read(order: &VerifiedOrder) -> Outcome {
    let system = orbit_executor_host::NodeSystem::new(bus::BLOBS_PATH);
    let read = match order.action() {
        Action::AccountRead => orbit_executor_host::account_read(&system, order.input()).map(|account| serde_json::json!(account)),
        _ => orbit_executor_host::file_read(&system, order.input()).map(|read| serde_json::json!(read)),
    };
    read.map_or_else(
        |failure| {
            log(&format!("{} {} failed: {} {}", order.action(), order.operation(), failure.code, failure.detail));
            Outcome::failed(failure.code)
        },
        |read| Outcome::Output { stdout: read.to_string() },
    )
}

/// Recovers every open order, then prunes the old restart attempts: only once no order is open does each old attempt belong
/// to an order whose `done` record answers its every resend, so an outage of any length never frees a recoverable order to
/// restart again.
fn recover_then_prune<R: Run>(
    executor: &Executor<R>,
    pending: orbit_executor_core::executor::Pending,
    attempts: &orbit_executor_process::Attempts,
    held: impl FnOnce(),
) -> Result<Vec<orbit_executor_core::executor::Recovered>, orbit_executor_core::journal::JournalError> {
    let recovered = executor.recover(pending, held)?;
    attempts.prune_recovered();
    Ok(recovered)
}

/// The process family (ADR 0271): a Process's run state and a Schedule's Node timer. A failure's code goes to the Gateway,
/// its detail only to the log.
fn process(order: &VerifiedOrder) -> Outcome {
    process_on(&orbit_executor_process::NodeSystem, order)
}

/// The process family on a given system: the Node's own, or a test double.
fn process_on(system: &impl orbit_executor_process::System, order: &VerifiedOrder) -> Outcome {
    if !process_slot(order.action(), order.target().as_str(), order.input()) {
        log(&format!("{} {} failed: its target is not the slot of the resource it names", order.action(), order.operation()));
        return Outcome::failed(if order.action() == Action::ScheduleEnsureTimer { "schedule.input_invalid" } else { "process.input_invalid" });
    }
    let result = match order.action() {
        Action::ScheduleEnsureTimer => orbit_executor_process::ensure_timer(system, order.input())
            .map(|timer| Outcome::Output { stdout: serde_json::json!(timer).to_string() }),
        Action::ProcessReadInstance => orbit_executor_process::read_instance(system, order.input())
            .map(|read| Outcome::Output { stdout: serde_json::json!(read).to_string() }),
        // The order's Operation step keys its attempt, so a replay of this order finds it and a new Operation does not.
        Action::ProcessRestart => orbit_executor_process::restart(system, &order.operation().as_str().replace(':', "-"), order.input())
            .map(|restart| Outcome::Output { stdout: serde_json::json!(restart).to_string() }),
        _ => orbit_executor_process::ensure_state(system, order.input()).map(|changes| Outcome::Applied { changes }),
    };
    result.unwrap_or_else(|failure| {
        log(&format!("{} {} failed: {} {}", order.action(), order.operation(), failure.code, failure.detail));
        Outcome::failed(failure.code)
    })
}

/// Whether the signed target's slot (`<node>:process-<id>`, `<node>:schedule-<uuid>`) names the resource the input acts on,
/// so the target lock serializes the orders of that resource and no other.
fn process_slot(action: Action, target: &str, input: &serde_json::Value) -> bool {
    let slot = target.split_once(':').map_or("", |(_, slot)| slot);
    match action {
        Action::ScheduleEnsureTimer => input["schedule_id"].as_str().is_some_and(|id| slot == format!("schedule-{id}")),
        _ => input["process_id"].as_u64().is_some_and(|id| slot == format!("process-{id}")),
    }
}

/// `instance.work_read`: a copy's workspace read, answered as the reader's JSON. A refused input goes to the Gateway as its
/// code, which the Gateway reads as `unknown` `unreachable`.
fn work_read(order: &VerifiedOrder) -> Outcome {
    let system = orbit_executor_instance::NodeSystem::new(bus::BLOBS_PATH);
    orbit_executor_instance::work_read(&system, order.target().as_str(), order.input(), order.expires_ms()).map_or_else(
        |failure| {
            log(&format!("{} {} failed: {} {}", order.action(), order.operation(), failure.code, failure.detail));
            Outcome::failed(failure.code)
        },
        |stdout| Outcome::Output { stdout },
    )
}

/// The instance family (ADR 0271): an Instance container made from its default's seed, and its files before its first
/// start. A failure's code goes to the Gateway, its detail only to the log.
fn instance(order: &VerifiedOrder) -> Outcome {
    let system = orbit_executor_instance::NodeSystem::new(bus::BLOBS_PATH);
    let result = match order.action() {
        Action::InstanceClone => orbit_executor_instance::clone(&system, order.input()).map(|stdout| Outcome::Output { stdout }),
        Action::InstanceFetchBranch => orbit_executor_instance::fetch_branch(&system, order.input()).map(|stdout| Outcome::Output { stdout }),
        Action::InstanceCheckout => orbit_executor_instance::checkout(&system, order.input()).map(|stdout| Outcome::Output { stdout }),
        Action::InstanceT3 => orbit_executor_instance::t3(&system, order.input()).map(|stdout| Outcome::Output { stdout }),
        Action::InstanceClubKey => orbit_executor_instance::club_key(&system, order.input()).map(|stdout| Outcome::Output { stdout }),
        Action::InstanceCopyVolume => orbit_executor_instance::copy_volume(&system, order.input()).map(|stdout| Outcome::Output { stdout }),
        Action::InstanceStop => orbit_executor_instance::stop(&system, order.input()).map(|changes| Outcome::Applied { changes }),
        Action::InstanceSpareVolume => orbit_executor_instance::spare_volume(&system, order.input()).map(|stdout| Outcome::Output { stdout }),
        Action::InstanceFleetReady => orbit_executor_instance::fleet_ready(&system, order.input()).map(|stdout| Outcome::Output { stdout }),
        Action::InstanceNames => orbit_executor_instance::names(&system, order.input()).map(|stdout| Outcome::Output { stdout }),
        Action::InstancePrepare => orbit_executor_instance::prepare(&system, order.input()).map(|stdout| Outcome::Output { stdout }),
        Action::InstanceSyncBase => orbit_executor_instance::sync_base(&system, order.input()).map(|stdout| Outcome::Output { stdout }),
        Action::InstanceClearBase => orbit_executor_instance::clear_base(&system, order.input()).map(|stdout| Outcome::Output { stdout }),
        _ => orbit_executor_instance::start(&system, order.input()).map(|changes| Outcome::Applied { changes }),
    };
    result.unwrap_or_else(|failure| {
        log(&format!("{} {} failed: {} {}", order.action(), order.operation(), failure.code, failure.detail));
        // A fenced step's failure keeps a redacted, bounded diagnostic with its journaled result, as a signed removal does.
        if order.action().receipt_fenced() { destroy_failure(failure.code, &failure.detail) } else { Outcome::failed(failure.code) }
    })
}

#[cfg(test)]
mod tests {

    /// A Node's on-demand units after a reboot, behind the process family's real code: each restart starts a new run.
    struct Rebooted {
        runs: std::sync::Mutex<std::collections::HashMap<String, String>>,
        restarts: std::sync::atomic::AtomicUsize,
        attempts: orbit_executor_process::Attempts,
    }

    impl orbit_executor_process::System for Rebooted {
        fn run(&self, _seconds: u32, argv: &[&str]) -> Result<String, String> {
            let name = argv.last().copied().unwrap_or_default().to_owned();
            let mut runs = self.runs.lock().expect("runs");
            let run = runs.entry(name).or_default();
            match argv.get(1).copied() {
                Some("restart") => {
                    let count = self.restarts.fetch_add(1, std::sync::atomic::Ordering::SeqCst) + 1;
                    *run = format!("run-{count}");
                    Ok(String::new())
                }
                Some("show") if argv.contains(&"--property=InvocationID") => Ok(run.clone()),
                Some("show") => Ok(format!(
                    "LoadState=loaded\nActiveState={}\nSubState=dead\nUnitFileState=disabled\nNextElapseUSecRealtime=\n",
                    if run.is_empty() { "inactive" } else { "active" }
                )),
                _ => Ok(String::new()),
            }
        }

        fn begin_attempt(&self, key: &str) -> Result<(), String> {
            self.attempts.begin(key)
        }

        fn attempted(&self, key: &str) -> bool {
            self.attempts.attempted(key)
        }
    }

    struct Restarts(Arc<Rebooted>);

    impl Run for Restarts {
        fn run(&self, order: &VerifiedOrder) -> Outcome {
            process_on(&*self.0, order)
        }
    }

    #[test]
    fn a_long_outage_with_two_open_restarts_never_restarts_either_again() {
        let dir = std::env::temp_dir().join(format!("orbit-restart-outage-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).expect("scratch");
        let attempts = orbit_executor_process::Attempts::at(dir.join("attempts"));
        let three_days_ago = std::time::SystemTime::now() - std::time::Duration::from_secs(3 * 24 * 60 * 60);
        let mut journal = String::new();
        for (nonce, process) in [(1_u8, 417), (2, 418)] {
            let key = orbit_executor_core::journal::DoneKey {
                operation: format!("{process}:restart").parse().expect("operation"),
                action: Action::ProcessRestart,
                target: format!("7:process-{process}").parse().expect("target"),
            };
            // Process 418's order recorded its attempt and restarted its stopped on-demand unit; 417's went down before its
            // attempt, so its recovery is its first execution and records a new attempt. Then the Node stayed down.
            if process == 418 {
                attempts.begin("418-restart").expect("attempt");
                std::fs::File::options()
                    .write(true)
                    .open(dir.join("attempts").join("418-restart"))
                    .and_then(|file| file.set_modified(three_days_ago))
                    .expect("age");
            }
            let record = orbit_executor_core::journal::Record::Open {
                nonce: format!("{nonce:032x}").parse().expect("nonce"),
                key,
                expires_ms: 1,
                power_check: PowerCheck::Default,
                input: serde_json::json!({"process_id": process, "runtime": "systemd", "name": format!("orbit-process-{process}-web.service"),
                    "instance": "", "boot": "disabled"}),
            };
            journal.push_str(&serde_json::to_string(&record).expect("record"));
            journal.push('\n');
        }
        let path = dir.join("journal");
        std::fs::write(&path, journal).expect("journal");

        // Three days later the Node is back: both units are inactive with an empty InvocationID, the pinned run.
        let node = Arc::new(Rebooted { runs: std::sync::Mutex::default(), restarts: std::sync::atomic::AtomicUsize::new(0), attempts: attempts.clone() });
        let (executor, pending) = Executor::open(&path, Restarts(Arc::clone(&node)), || 1_800_000_000_000, |_| {}).expect("open");
        let recovered = recover_then_prune(&executor, pending, &attempts, || {}).expect("recover");

        assert_eq!(recovered.len(), 2);
        assert!(matches!(&recovered[0].outcome, Outcome::Output { stdout } if stdout.contains("\"now\"")), "{:?}", recovered[0].outcome);
        assert!(matches!(&recovered[1].outcome, Outcome::Failed { code, .. } if code == "process.restart_unresolved"), "{:?}", recovered[1].outcome);
        assert_eq!(node.restarts.load(std::sync::atomic::Ordering::SeqCst), 1, "417 restarts once; 418 never again");
        // Only now, with both results durable, does the old attempt go; the new one stays.
        assert!(attempts.attempted("417-restart") && !attempts.attempted("418-restart"));
        let (_, again) = Executor::open(&path, Restarts(Arc::clone(&node)), || 1_800_000_000_000, |_| {}).expect("reopen");
        let again = recover_then_prune(&executor, again, &attempts, || {}).expect("nothing open");
        assert_eq!(again.len(), 0);
        assert_eq!(node.restarts.load(std::sync::atomic::Ordering::SeqCst), 1);
        let _ = std::fs::remove_dir_all(&dir);
    }
    use super::*;

    #[test]
    fn destroy_failure_redacts_before_keeping_a_bounded_tail() {
        let stderr = format!("{}Error: opening https://fixture:disposable-url-sentinel@example.test/owned\nPASSWORD=disposable-password-sentinel",
            "ressource occupée\n".repeat(500));
        let line = serde_json::to_value(destroy_failure("instance.removal_stop_failed", &stderr))
            .expect("receipt");
        let detail = line["detail"].as_str().expect("diagnostic");
        assert_eq!(line["status"], "failed");
        assert_eq!(line["code"], "instance.removal_stop_failed");
        assert!(
            detail.len() <= 4096,
            "keep the public diagnostic within its byte bound"
        );
        assert!(
            detail.contains("https://[REDACTED]@example.test/owned\nPASSWORD=[REDACTED]"),
            "keep the redacted tail"
        );
        assert!(
            !detail.contains("disposable-"),
            "do not journal the original values"
        );
    }

    #[test]
    fn destroy_failure_marks_an_oversized_final_line_as_recorded_without_returning_it() {
        let oversized = format!("earlier line\n{}", "resource fixture é ".repeat(300));
        for detail in [&oversized, &format!("{oversized}\n")] {
            let receipt = serde_json::to_value(destroy_failure("instance.removal_incomplete", detail))
                .expect("receipt");
            assert_eq!(
                receipt,
                serde_json::json!({
                    "status": "failed",
                    "code": "instance.removal_incomplete",
                    "detail": "The incomplete native diagnostic line was omitted."
                })
            );
        }
        let empty = serde_json::to_value(destroy_failure("instance.removal_incomplete", ""))
            .expect("receipt without diagnostic");
        assert!(empty.get("detail").is_none());
    }

    #[test]
    fn a_wake_takes_its_readiness_bound_from_the_signed_input() {
        use std::time::Duration;
        let input = |ready: serde_json::Value| serde_json::json!({"web": {"ready_seconds": ready}});
        assert_eq!(ready_bound(&input(30.into())), Some(Duration::from_secs(30)));
        assert_eq!(ready_bound(&input(0.into())), Some(Duration::from_secs(1)));
        assert_eq!(ready_bound(&input(900.into())), Some(Duration::from_secs(120)));
        // An order from a Gateway before the field gets the power family's default.
        assert_eq!(ready_bound(&serde_json::Value::Null), None);
        assert_eq!(ready_bound(&input("30".into())), None);
    }

    #[test]
    fn a_process_order_acts_only_on_the_resource_its_target_slot_names() {
        let state = serde_json::json!({"process_id": 417});
        assert!(process_slot(Action::ProcessEnsureState, "7:process-417", &state));
        assert!(!process_slot(Action::ProcessEnsureState, "7:process-418", &state));
        assert!(!process_slot(Action::ProcessEnsureState, "7", &state));
        assert!(process_slot(Action::ProcessRestart, "7:process-417", &state));
        assert!(!process_slot(Action::ProcessReadInstance, "7:process-418", &state));
        let timer = serde_json::json!({"schedule_id": "7f3c0a5e-1b2c-4d3e-8f90-0123456789ab"});
        assert!(process_slot(Action::ScheduleEnsureTimer, "7:schedule-7f3c0a5e-1b2c-4d3e-8f90-0123456789ab", &timer));
        assert!(!process_slot(Action::ScheduleEnsureTimer, "7:process-417", &timer));
    }

    #[test]
    fn the_relay_user_is_read_from_passwd() {
        let passwd = "root:x:0:0::/root:/bin/bash\norbit-executor-relay:x:990:985::/nonexistent:/usr/sbin/nologin\n";
        assert_eq!(system_user(passwd, RELAY_USER), Some((990, 985)));
        assert_eq!(system_user(passwd, "orbit"), None);
    }
}
