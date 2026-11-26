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
            Action::TlsEnsureCsr | Action::RoutePublishSite => route(order),
            Action::DnsPublishRecords => dns(order),
            Action::InstanceDestroy => self.destroy(order),
            Action::InstanceClone | Action::InstancePrepare | Action::InstanceStart | Action::InstanceFetchBranch | Action::InstanceCheckout | Action::InstanceT3
            | Action::InstanceClubKey | Action::InstanceCopyVolume | Action::InstanceStop | Action::InstanceSpareVolume
            | Action::InstanceFleetReady | Action::InstanceNames | Action::InstanceSyncBase | Action::InstanceClearBase => instance(order),
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
        match repeating.recover(pending, || {
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

/// The route family (ADR 0271): a leaf's certificate request, or one Node's part of a Route. A failure's code goes to the
/// Gateway, its detail only to the log.
fn route(order: &VerifiedOrder) -> Outcome {
    let system = orbit_executor_route::NodeSystem::new(bus::BLOBS_PATH);
    let result = match order.action() {
        Action::TlsEnsureCsr => orbit_executor_route::ensure_csr(&system, order.input()).map(|stdout| Outcome::Output { stdout }),
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
        Outcome::failed(failure.code)
    })
}

#[cfg(test)]
mod tests {
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
    fn the_relay_user_is_read_from_passwd() {
        let passwd = "root:x:0:0::/root:/bin/bash\norbit-executor-relay:x:990:985::/nonexistent:/usr/sbin/nologin\n";
        assert_eq!(system_user(passwd, RELAY_USER), Some((990, 985)));
        assert_eq!(system_user(passwd, "orbit"), None);
    }
}
