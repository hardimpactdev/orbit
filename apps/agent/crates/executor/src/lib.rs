//! The executor: runs Gateway-signed orders on this Node (ADR 0266, ADR 0267, ADR 0271).
//!
//! It is a mode of the one `orbit-agent` binary (ADR 0271 N7):
//!
//! - `orbit-agent execute serve` is the long-running executor that `orbit-executor.service` starts at boot.
//! - `orbit-agent execute relay` is the one relay command, run as `orbit-executor-relay`.

use std::fs;
use std::io;
use std::path::{Path, PathBuf};
use std::process::ExitCode;
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
        if action == Action::Discard {
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
                        Outcome::Failed {
                            code: match error.to_string().as_str() {
                                code @ ("instance.container_frozen_unmanaged"
                                | orbit_executor_power::SLEEP_INCOMPLETE
                                | orbit_executor_power::ROUTE_UNREADY
                                | orbit_executor_power::READERS_BUSY) => code,
                                _ => "instance.container_freeze_failed",
                            }
                            .to_owned(),
                        }
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
                match self.0.wake(order.target().as_str(), probe) {
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
                        Outcome::Failed {
                            code: if WAKE_CODES.contains(&code.as_str()) {
                                code
                            } else {
                                "instance.container_start_failed".to_owned()
                            },
                        }
                    }
                }
            }
            Action::Discard => match self.0.discard_stopped(order.target().as_str()) {
                Ok(()) => Outcome::Done,
                Err(error) => Outcome::Failed {
                    code: if error.to_string() == orbit_executor_power::READERS_BUSY {
                        orbit_executor_power::READERS_BUSY
                    } else {
                        "instance.power_discard_failed"
                    }
                    .to_owned(),
                },
            },
        }
    }
}

#[expect(
    clippy::print_stderr,
    reason = "systemd sends the executor's standard error to its journal"
)]
fn log(line: &str) {
    eprintln!("{line}");
}

/// Runs the executor mode with its arguments (`serve` or `relay`) and returns the process's exit code.
pub fn main(args: &[String]) -> ExitCode {
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
        Ok(()) => ExitCode::SUCCESS,
        Err(message) => {
            log(&format!("orbit-agent execute: {message}"));
            ExitCode::FAILURE
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
    let (executor, recovered) = Executor::start(journal, Families(power), server::now_ms, log)
        .map_err(|error| error.to_string())?;
    for done in &recovered {
        log(&format!(
            "recovered {} {} {}: {:?}",
            done.key.operation, done.key.action, done.key.target, done.outcome
        ));
    }
    let listener = server::bind(Path::new(SOCKET_PATH), relay_user.map(|(_, gid)| gid))
        .map_err(|error| format!("bind {SOCKET_PATH}: {error}"))?;
    log(&format!("serving {SOCKET_PATH}"));
    server::serve(&listener, &settings, &Arc::new(executor), log);
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_relay_user_is_read_from_passwd() {
        let passwd = "root:x:0:0::/root:/bin/bash\norbit-executor-relay:x:990:985::/nonexistent:/usr/sbin/nologin\n";
        assert_eq!(system_user(passwd, RELAY_USER), Some((990, 985)));
        assert_eq!(system_user(passwd, "orbit"), None);
    }
}
