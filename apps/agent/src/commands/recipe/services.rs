//! Restart only changed-package services; health includes their active transitive dependents.
use super::{run, Failure, Host, Invocation};
use std::collections::{BTreeMap, BTreeSet};
pub type Units = BTreeSet<String>;
type State = BTreeMap<String, String>;
const DEPENDENTS: [&str; 3] = ["RequiredBy", "BoundBy", "ConsistsOf"];
fn vanished(state: &State) -> bool {
    [
        ("LoadState", "not-found"),
        ("ActiveState", "inactive"),
        ("ControlGroup", ""),
        ("Job", ""),
    ]
    .iter()
    .all(|(k, v)| state.get(*k).map(String::as_str) == Some(*v))
}
fn state(host: &mut impl Host, unit: &str) -> Result<State, Failure> {
    let output = run(host, &Invocation::new(&["systemctl", "show", "--no-pager", "--property=LoadState,ActiveState,Result,ControlGroup,Job,RequiredBy,BoundBy,ConsistsOf", unit]), true)?;
    Ok(output
        .stdout
        .lines()
        .filter_map(|line| line.split_once('=').map(|(k, v)| (k.into(), v.into())))
        .collect())
}
pub fn active(host: &mut impl Host) -> Result<Units, Failure> {
    let output = run(
        host,
        &Invocation::new(&[
            "systemctl",
            "list-units",
            "--state=active",
            "--no-legend",
            "--plain",
        ]),
        true,
    )?;
    Ok(output
        .stdout
        .lines()
        .filter_map(|line| line.split_whitespace().next().map(str::to_string))
        .collect())
}
pub fn for_packages(
    host: &mut impl Host,
    packages: &BTreeSet<String>,
    before: &Units,
) -> Result<Units, Failure> {
    let mut services = Units::new();
    let unit_file =
        regex::Regex::new(r"\A/(?:usr/)?lib/systemd/system/([A-Za-z0-9@_.:-]+\.service)\z")
            .unwrap();
    let php = regex::Regex::new(r"\Aphp([0-9]+\.[0-9]+)-").unwrap();
    for package in packages {
        let output = run(
            host,
            &Invocation::new(&["dpkg-query", "-L", package]),
            false,
        )?;
        for line in output.stdout.lines() {
            let Some(entry) = unit_file.captures(line) else {
                continue;
            };
            let unit = &entry[1];
            if let Some(prefix) = unit.strip_suffix("@.service") {
                let prefix = format!("{prefix}@");
                services.extend(
                    before
                        .iter()
                        .filter(|unit| unit.starts_with(&prefix) && unit.ends_with(".service"))
                        .cloned(),
                );
            } else {
                services.insert(unit.into());
            }
        }
        if let Some(entry) = php.captures(package) {
            services.insert(format!("php{}-fpm.service", &entry[1]));
        }
    }
    Ok(services.intersection(before).cloned().collect())
}
pub fn affected(
    host: &mut impl Host,
    candidates: &Units,
    active: &Units,
) -> Result<Units, Failure> {
    let mut selected = Units::new();
    let mut visited = Units::new();
    let mut pending = candidates.clone();
    while let Some(unit) = pending.pop_first() {
        visited.insert(unit.clone());
        let state = state(host, &unit).map_err(|e| e.at(&format!("restart:{unit}")))?;
        if vanished(&state) {
            continue;
        }
        if state.get("LoadState").map(String::as_str) != Some("loaded") {
            return Err(Failure::new(
                &format!("restart:{unit}"),
                "Service did not report a loaded unit",
            ));
        }
        selected.insert(unit.clone());
        for property in DEPENDENTS {
            let values = state.get(property).ok_or_else(|| {
                Failure::new(
                    &format!("restart:{unit}"),
                    "Missing dependent unit evidence",
                )
            })?;
            pending.extend(
                values
                    .split_whitespace()
                    .filter(|name| active.contains(*name) && !visited.contains(*name))
                    .map(str::to_string),
            );
        }
    }
    Ok(selected)
}
pub fn restart_and_check(
    host: &mut impl Host,
    changed: &BTreeSet<String>,
    before_active: &Units,
    health: &Units,
) -> Result<Units, Failure> {
    let package_services = for_packages(host, changed, before_active)?;
    let affected = if package_services.is_empty() {
        Units::new()
    } else {
        let active = active(host)?;
        affected(host, &package_services, &active)?
    };
    let restarted: Units = package_services.intersection(&affected).cloned().collect();
    if !restarted.is_empty() {
        let names = restarted.iter().cloned().collect::<Vec<_>>().join(",");
        let mut invocation = Invocation::new(&["systemctl", "restart"]);
        invocation.argv.extend(restarted.iter().cloned());
        run(host, &invocation, true).map_err(|e| e.at(&format!("restart:{names}")))?;
        let fence = format!(
            "orbit-recipe-health-{:032x}.service",
            rand::random::<u128>()
        );
        let after = format!(
            "--property=After={}",
            affected.iter().cloned().collect::<Vec<_>>().join(" ")
        );
        let wait = Invocation::new(&[
            "systemd-run",
            "--quiet",
            "--wait",
            "--collect",
            &format!("--unit={fence}"),
            "--property=Type=oneshot",
            "--property=DefaultDependencies=no",
            &after,
            "/usr/bin/true",
        ]);
        if let Err(error) = run(host, &wait, true) {
            if error
                .diagnostic
                .as_ref()
                .is_some_and(|d| d.exit_code == 124 || d.exit_code == 137)
            {
                run(
                    host,
                    &Invocation::new(&["systemctl", "stop", "--no-block", &fence]),
                    true,
                )
                .map_err(|e| e.at(&format!("restart:{names}")))?;
            }
            return Err(error.at(&format!("restart:{names}")));
        }
    }
    for unit in health.union(&affected) {
        let invocation = Invocation::new(&["systemctl", "is-active", "--quiet", unit]);
        let output = run(host, &invocation, false)?;
        if output.code == 0 {
            continue;
        }
        let state = state(host, unit).map_err(|e| e.at(&format!("health:{unit}")))?;
        if vanished(&state)
            || [
                ("LoadState", "loaded"),
                ("ActiveState", "inactive"),
                ("Result", "success"),
            ]
            .iter()
            .all(|(k, v)| state.get(*k).map(String::as_str) == Some(*v))
        {
            continue;
        }
        return Err(Failure::command(&invocation, &output).at(&format!("health:{unit}")));
    }
    Ok(restarted)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::commands::recipe::Output;
    use std::time::Duration;
    struct Fake {
        calls: Vec<Invocation>,
        states: BTreeMap<String, String>,
        failed: Option<String>,
    }
    impl Host for Fake {
        fn execute(&mut self, invocation: &Invocation) -> Result<Output, Failure> {
            self.calls.push(invocation.clone());
            let args = &invocation.argv;
            let mut output = Output::default();
            if args[0] == "dpkg-query" {
                output.stdout = "/usr/lib/systemd/system/php8.5-fpm.service\n/lib/systemd/system/worker@.service\n".into();
            } else if args[0] == "systemctl" && args[1] == "list-units" {
                output.stdout = self
                    .states
                    .keys()
                    .map(|u| format!("{u} loaded active running\n"))
                    .collect();
            } else if args[0] == "systemctl" && args[1] == "show" {
                output.stdout = self.states[args.last().unwrap()].clone();
            } else if args[0] == "systemctl"
                && args[1] == "is-active"
                && self.failed.as_ref() == args.last()
            {
                output.code = 3;
            }
            Ok(output)
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            panic!("no retries")
        }
    }
    fn loaded(required: &str, bound: &str, consists: &str) -> String {
        format!("LoadState=loaded\nActiveState=active\nResult=success\nControlGroup=/system.slice/unit\nJob=\nRequiredBy={required}\nBoundBy={bound}\nConsistsOf={consists}\n")
    }
    #[test]
    fn restarts_only_package_units_and_checks_transitive_active_dependents() {
        let mut host = Fake {
            calls: vec![],
            failed: None,
            states: [
                (
                    "php8.5-fpm.service".into(),
                    loaded("horizon.service offline.service", "", ""),
                ),
                ("horizon.service".into(), loaded("", "queue.service", "")),
                ("queue.service".into(), loaded("", "", "php8.5-fpm.service")),
                ("worker@one.service".into(), loaded("", "", "")),
                (
                    "unchanged.service".into(),
                    loaded("", "", "not-restarted.service"),
                ),
            ]
            .into(),
        };
        let before = [
            "php8.5-fpm.service".into(),
            "worker@one.service".into(),
            "unchanged.service".into(),
        ]
        .into();
        let restarted = restart_and_check(
            &mut host,
            &["php8.5-cli".into()].into(),
            &before,
            &["unchanged.service".into()].into(),
        )
        .unwrap();
        assert_eq!(
            restarted,
            ["php8.5-fpm.service".into(), "worker@one.service".into()].into()
        );
        let call = host
            .calls
            .iter()
            .find(|c| c.argv[0] == "systemctl" && c.argv[1] == "restart")
            .unwrap();
        assert_eq!(
            call.argv,
            [
                "systemctl",
                "restart",
                "php8.5-fpm.service",
                "worker@one.service"
            ]
        );
        let checked: Units = host
            .calls
            .iter()
            .filter(|c| c.argv[0] == "systemctl" && c.argv[1] == "is-active")
            .map(|c| c.argv.last().unwrap().clone())
            .collect();
        assert_eq!(checked, host.states.keys().cloned().collect());
        assert!(!host
            .calls
            .iter()
            .any(|c| c.argv.contains(&"offline.service".into())
                || c.argv.contains(&"not-restarted.service".into())));
    }
    #[test]
    fn a_failed_dependent_stops_health_and_incomplete_vanished_evidence_refuses_restart() {
        let mut host = Fake {
            calls: vec![],
            failed: Some("horizon.service".into()),
            states: [
                (
                    "php8.5-fpm.service".into(),
                    loaded("horizon.service", "", ""),
                ),
                ("horizon.service".into(), loaded("", "", "")),
            ]
            .into(),
        };
        assert_eq!(
            restart_and_check(
                &mut host,
                &["php8.5-cli".into()].into(),
                &["php8.5-fpm.service".into()].into(),
                &Units::new()
            )
            .unwrap_err()
            .stage,
            "health:horizon.service"
        );
        for state in [
            "LoadState=not-found\nActiveState=inactive\nControlGroup=\nJob=\n",
            "LoadState=not-found\nActiveState=inactive\nJob=\n",
        ] {
            host.calls.clear();
            host.states.insert("gone.service".into(), state.into());
            let result = affected(&mut host, &["gone.service".into()].into(), &Units::new());
            assert_eq!(result.is_ok(), state.contains("ControlGroup=\n"));
        }
    }

    struct Scenario {
        host: Fake,
        fence: Option<Result<Output, Failure>>,
        cancellation: i32,
        final_state: Option<Output>,
    }
    impl Host for Scenario {
        fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
            let output = self.host.execute(call)?;
            if call.argv[0] == "systemd-run" {
                if let Some(result) = self.fence.take() {
                    return result;
                }
            }
            if call.argv[..2] == ["systemctl", "stop"] {
                return Ok(Output {
                    code: self.cancellation,
                    stderr: "cancellation failed".into(),
                    ..Output::default()
                });
            }
            if call.argv[..2] == ["systemctl", "show"]
                && call.argv.last().unwrap() == "consumer.service"
                && self.host.calls.iter().any(|c| {
                    c.argv[..2] == ["systemctl", "is-active"]
                        && c.argv.last().unwrap() == "consumer.service"
                })
            {
                if let Some(state) = &self.final_state {
                    return Ok(state.clone());
                }
            }
            Ok(output)
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            panic!("no retries")
        }
    }
    fn scenario() -> Scenario {
        Scenario {
            host: Fake {
                calls: vec![],
                failed: None,
                states: [
                    (
                        "php8.5-fpm.service".into(),
                        loaded("consumer.service", "", ""),
                    ),
                    ("consumer.service".into(), loaded("", "", "")),
                ]
                .into(),
            },
            fence: None,
            cancellation: 0,
            final_state: None,
        }
    }
    fn restart(host: &mut Scenario) -> Result<Units, Failure> {
        restart_and_check(
            host,
            &["php8.5-cli".into()].into(),
            &["php8.5-fpm.service".into()].into(),
            &Units::new(),
        )
    }
    #[test]
    fn fence_failure_stops_health_without_retry_and_only_timeout_cancels_the_fence() {
        for (code, cancel_code) in [(1, 0), (124, 0), (124, 1), (137, 0)] {
            let mut host = scenario();
            host.fence = Some(Ok(Output {
                code,
                stderr: "fence failed".into(),
                ..Output::default()
            }));
            host.cancellation = cancel_code;
            let error = restart(&mut host).unwrap_err();
            assert_eq!(error.stage, "restart:php8.5-fpm.service");
            assert_eq!(
                error.diagnostic.unwrap().stderr,
                if cancel_code == 1 {
                    "cancellation failed"
                } else {
                    "fence failed"
                }
            );
            let calls = &host.host.calls;
            assert_eq!(
                calls.iter().filter(|c| c.argv[0] == "systemd-run").count(),
                1
            );
            assert_eq!(
                calls
                    .iter()
                    .filter(|c| c.argv[..2] == ["systemctl", "restart"])
                    .count(),
                1
            );
            assert!(!calls
                .iter()
                .any(|c| c.argv[..2] == ["systemctl", "is-active"]));
            let stops: Vec<_> = calls
                .iter()
                .filter(|c| c.argv[..2] == ["systemctl", "stop"])
                .collect();
            assert_eq!(stops.len(), usize::from(code == 124 || code == 137));
            if let Some(stop) = stops.first() {
                let fence = calls.iter().find(|c| c.argv[0] == "systemd-run").unwrap();
                let unit = fence
                    .argv
                    .iter()
                    .find_map(|arg| arg.strip_prefix("--unit="))
                    .unwrap();
                assert_eq!(stop.argv, ["systemctl", "stop", "--no-block", unit]);
            }
        }
        let mut host = scenario();
        host.fence = Some(Err(Failure::new("interrupted", "interrupted")));
        assert_eq!(restart(&mut host).unwrap_err().stage, "interrupted");
        assert!(!host
            .host
            .calls
            .iter()
            .any(|c| c.argv[..2] == ["systemctl", "stop"]
                || c.argv[..2] == ["systemctl", "is-active"]));
    }
    #[test]
    fn health_accepts_completed_and_collected_dependents_but_names_unreadable_or_failed_units() {
        let cases = [
            (
                "LoadState=loaded\nActiveState=inactive\nResult=success\n",
                true,
            ),
            (
                "LoadState=not-found\nActiveState=inactive\nControlGroup=\nJob=\n",
                true,
            ),
            ("LoadState=loaded\nActiveState=inactive\n", false),
            (
                "LoadState=loaded\nActiveState=inactive\nResult=exit-code\n",
                false,
            ),
            (
                "LoadState=loaded\nActiveState=failed\nResult=success\n",
                false,
            ),
            (
                "LoadState=not-found\nActiveState=inactive\nControlGroup=/live\nJob=\n",
                false,
            ),
            (
                "LoadState=not-found\nActiveState=inactive\nControlGroup=\nJob=123\n",
                false,
            ),
            ("LoadState=not-found\n", false),
            ("", false),
        ];
        for (state, success) in cases {
            let mut host = scenario();
            host.host.failed = Some("consumer.service".into());
            host.final_state = Some(Output {
                stdout: state.into(),
                ..Output::default()
            });
            let result = restart(&mut host);
            assert_eq!(result.is_ok(), success, "{state}");
            if !success {
                assert_eq!(result.unwrap_err().stage, "health:consumer.service");
            }
        }
        let mut host = scenario();
        host.host.failed = Some("consumer.service".into());
        host.final_state = Some(Output {
            code: 1,
            stderr: "unreadable".into(),
            ..Output::default()
        });
        assert_eq!(
            restart(&mut host).unwrap_err().stage,
            "health:consumer.service"
        );
    }
}
