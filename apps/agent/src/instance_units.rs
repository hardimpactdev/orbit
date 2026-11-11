//! Read only the Instance Process units named by the Gateway; publish through the existing Reverb view.
use crate::{process_unit_name, workspace::WatchFacts, Unit};
use futures_util::{stream, StreamExt};
use serde_json::Value;
use std::{
    collections::{BTreeSet, HashMap},
    future::Future,
    time::Duration,
};
use tokio::{process::Command, sync::watch};

const DEADLINE: Duration = Duration::from_secs(5);
const INTERVAL: Duration = Duration::from_secs(10);

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Target {
    pub name: String,
    pub units: Vec<String>,
}

pub fn parse_watch(body: &Value) -> Vec<Target> {
    let mut seen = BTreeSet::new();
    let mut targets = Vec::new();
    for row in body
        .pointer("/data/instance_containers")
        .and_then(Value::as_array)
        .into_iter()
        .flatten()
    {
        let Some(name) = row["name"].as_str() else {
            continue;
        };
        if row["project"] != "orbit-instances"
            || !name.strip_prefix("instance-").is_some_and(|id| {
                id.parse::<u64>()
                    .is_ok_and(|n| n > 0 && n.to_string() == id)
            })
        {
            continue;
        }
        let units = row["units"]
            .as_array()
            .into_iter()
            .flatten()
            .filter_map(Value::as_str)
            .filter(|unit| {
                unit.ends_with(".service")
                    && crate::facts::valid_service(unit)
                    && process_unit_name(unit).is_some()
            })
            .filter(|unit| seen.len() < 4096 && seen.insert((*unit).to_owned()))
            .map(str::to_owned)
            .collect::<Vec<_>>();
        if !units.is_empty() {
            targets.push(Target {
                name: name.to_owned(),
                units,
            });
        }
    }
    targets
}

fn arguments(target: &Target) -> Vec<String> {
    [
        "--force-local",
        "exec",
        "--project",
        "orbit-instances",
        &target.name,
        "--",
        "/usr/bin/systemctl",
        "show",
        "--property=Id,ActiveState",
        "--no-pager",
        "--",
    ]
    .into_iter()
    .map(str::to_owned)
    .chain(target.units.iter().cloned())
    .collect()
}

async fn read(arguments: Vec<String>) -> Option<String> {
    let output = Command::new("/usr/bin/incus")
        .args(arguments)
        .env("INCUS_CONF", "/var/empty")
        .kill_on_drop(true)
        .output()
        .await
        .ok()?;
    output
        .status
        .success()
        .then(|| String::from_utf8(output.stdout).ok())
        .flatten()
}

/// Four reads at once, independent of the Reverb heartbeat loop. Every failure replaces old state with unknown.
async fn observe_with<F, R>(targets: Vec<Target>, read: F) -> HashMap<String, Unit>
where
    F: Fn(Vec<String>) -> R,
    R: Future<Output = Option<String>>,
{
    let pending = targets
        .into_iter()
        .map(|target| {
            let pending = read(arguments(&target));
            async move {
                (
                    target,
                    tokio::time::timeout(DEADLINE, pending).await.ok().flatten(),
                )
            }
        })
        .collect::<Vec<_>>();
    let mut reads = stream::iter(pending).buffer_unordered(4);
    let mut units = HashMap::new();
    while let Some((target, output)) = reads.next().await {
        let mut observed = HashMap::new();
        if let Some(output) = output {
            for block in output.split("\n\n") {
                let fields = block
                    .lines()
                    .filter_map(|line| line.split_once('='))
                    .collect::<HashMap<_, _>>();
                if let (Some(name), Some(state)) = (fields.get("Id"), fields.get("ActiveState")) {
                    if target.units.iter().any(|unit| unit == name)
                        && matches!(
                            *state,
                            "active"
                                | "inactive"
                                | "failed"
                                | "activating"
                                | "deactivating"
                                | "reloading"
                                | "refreshing"
                                | "maintenance"
                        )
                    {
                        observed.insert((*name).to_owned(), (*state).to_owned());
                    }
                }
            }
        }
        for name in &target.units {
            let name_without_suffix = process_unit_name(name).expect("validated watch unit");
            units.insert(
                name_without_suffix.clone(),
                Unit {
                    name: name_without_suffix,
                    runtime: "incus".into(),
                    runtime_status: observed.remove(name).unwrap_or_else(|| "unknown".into()),
                },
            );
        }
    }
    units
}

pub async fn watch_units(
    mut listed: watch::Receiver<WatchFacts>,
    reports: watch::Sender<HashMap<String, Unit>>,
) {
    let mut tick = tokio::time::interval(INTERVAL);
    tick.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Delay);
    loop {
        tokio::select! {
            _ = tick.tick() => {},
            changed = listed.changed() => if changed.is_err() { return },
        }
        let targets = listed.borrow().instance_containers.clone();
        reports.send_replace(observe_with(targets, read).await);
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn targets() -> Vec<Target> {
        parse_watch(&json!({"data": {"instance_containers": [
            {"project": "orbit-instances", "name": "instance-42", "units": ["orbit-process-9-worker.service"]}
        ]}}))
    }

    #[test]
    fn instance_units_watch_refuses_other_projects_names_and_units() {
        let body = json!({"data": {"instance_containers": [
            {"project": "other", "name": "instance-42", "units": ["orbit-process-9-worker.service"]},
            {"project": "orbit-instances", "name": "other", "units": ["orbit-process-9-worker.service"]},
            {"project": "orbit-instances", "name": "instance-42", "units": ["sshd.service", "../unit.service", "orbit-process-9-worker.service", "orbit-process-9-worker.service"]}
        ]}});
        assert_eq!(parse_watch(&body), targets());
    }

    #[tokio::test]
    async fn instance_units_read_runs_in_the_named_container_and_reports_its_state() {
        let units = observe_with(targets(), |argv| async move {
            assert_eq!(
                argv,
                [
                    "--force-local",
                    "exec",
                    "--project",
                    "orbit-instances",
                    "instance-42",
                    "--",
                    "/usr/bin/systemctl",
                    "show",
                    "--property=Id,ActiveState",
                    "--no-pager",
                    "--",
                    "orbit-process-9-worker.service"
                ]
            );
            Some("Id=orbit-process-9-worker.service\nActiveState=active\n".into())
        })
        .await;
        assert_eq!(units["orbit-process-9-worker"].runtime_status, "active");
        assert_eq!(units["orbit-process-9-worker"].runtime, "incus");
        assert_eq!(
            crate::snapshot_frames(
                "presence-node.9",
                &mut crate::Sequencer::default(),
                &units.into_values().collect::<Vec<_>>(),
                "absent"
            )
            .unwrap()[0]
                .data["units"][0]["runtime"],
            "incus"
        );
    }

    #[tokio::test]
    async fn instance_units_unreadable_or_unlisted_state_is_unknown() {
        for output in [
            None,
            Some("Id=orbit-process-10-other.service\nActiveState=active\n".into()),
            Some("Id=orbit-process-9-worker.service\nActiveState=garbage\n".into()),
        ] {
            let units = observe_with(targets(), |_| std::future::ready(output.clone())).await;
            assert_eq!(units["orbit-process-9-worker"].runtime_status, "unknown");
        }
    }

    #[tokio::test(start_paused = true)]
    async fn instance_units_timeout_does_not_hide_another_containers_state() {
        let mut targets = targets();
        targets.push(Target {
            name: "instance-43".into(),
            units: vec!["orbit-process-10-web.service".into()],
        });
        let units = observe_with(targets, |argv| async move {
            if argv[4] == "instance-42" {
                std::future::pending::<()>().await;
            }
            Some("Id=orbit-process-10-web.service\nActiveState=failed\n".into())
        })
        .await;
        assert_eq!(units["orbit-process-9-worker"].runtime_status, "unknown");
        assert_eq!(units["orbit-process-10-web"].runtime_status, "failed");
    }
}
