use std::collections::BTreeSet;
use std::fs;
use std::io;
use std::thread;
use std::time::Duration;

use serde::Deserialize;
use serde_json::Value;

use crate::{Failure, Incus, System};

#[cfg(test)]
mod tests;

const FAILED: &str = "instance.removal_incomplete";
const UFW: &str = "/usr/sbin/ufw";
const ZFS: &str = "/usr/sbin/zfs";

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    project: String,
    name: String,
    pool: String,
    acl: String,
    inner_volume: Option<String>,
}

impl Input {
    fn checked(id: &str, input: &Value) -> Result<Self, Failure> {
        let invalid = |detail| Failure::new("instance.destroy_input_invalid", detail);
        let input: Self = serde_json::from_value(input.clone()).map_err(|_| invalid("invalid input fields"))?;
        if !id.parse::<u64>().is_ok_and(|number| number > 0 && number.to_string() == id)
            || !matches!(input.project.as_str(), "orbit-instances" | "orbit-instances-nested")
            || input.name != format!("instance-{id}")
            || input.acl != input.name
            || input.pool != "orbit-instances"
            || input
                .inner_volume
                .as_ref()
                .is_some_and(|volume| *volume != format!("inner-{id}"))
        {
            return Err(invalid("the saved identity does not own this target's resources"));
        }
        Ok(input)
    }

    fn instance(&self) -> String {
        format!("/1.0/instances/{}?project={}", self.name, self.project)
    }

    fn volumes(&self) -> String {
        format!(
            "/1.0/storage-pools/{}/volumes?project={}&recursion=1",
            self.pool, self.project
        )
    }

    fn volume(&self, name: &str) -> String {
        format!(
            "/1.0/storage-pools/{}/volumes/custom/{name}?project={}",
            self.pool, self.project
        )
    }

    fn owned_volume(&self, name: &str) -> bool {
        self.inner_volume.as_ref().is_some_and(|inner| {
            name == inner
                || name == format!("build-{inner}")
                || name.strip_prefix(&format!("spare-{inner}-")).is_some_and(|tail| {
                    let tail = tail.strip_prefix("seed-").unwrap_or(tail);
                    !tail.is_empty()
                        && tail
                            .bytes()
                            .all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte))
                })
        })
    }
}

/// Force-stops and deletes one admitted development clone, proves its owned Incus and firewall
/// resources absent, and removes its Node files and legacy prepared-restore state. The executor holds
/// the same target lock as sleep and wake until it also clears its own prepared state.
///
/// # Errors
/// Returns the invalid input, stop, removal, dataset ownership or power-helper failure.
pub fn destroy(system: &impl System, id: &str, input: &Value) -> Result<(), Failure> {
    let input = Input::checked(id, input)?;
    let incus = Incus { system, code: FAILED };
    let instance = input.instance();
    if let Some(found) = incus.query(&instance)? {
        if found["name"] != input.name || found["type"] != "container" || found["expanded_devices"]["root"]["pool"] != input.pool
        {
            return Err(Failure::new(
                FAILED,
                "the admitted name is not a container on the admitted pool",
            ));
        }
        let status = found["status"]
            .as_str()
            .ok_or_else(|| Failure::new(FAILED, "Incus did not report the admitted container's status"))?;
        if status != "Stopped" {
            incus
                .change(&["--project", &input.project, "stop", "--force", &input.name])
                .map_err(|error| Failure::new("instance.removal_stop_failed", error.detail))?;
        }
        let delete = || incus.change(&["--project", &input.project, "delete", "--force", &input.name]);
        if let Err(failure) = delete() {
            if incus.query(&instance)?.is_some() {
                let snapshots = snapshot_names(
                    &incus,
                    &format!(
                        "/1.0/instances/{}/snapshots?project={}&recursion=1",
                        input.name, input.project
                    ),
                    &input.name,
                )?;
                if !restore_missing_datasets(&incus, &input, "containers", &input.name, &snapshots)? {
                    return Err(failure);
                }
                delete()?;
            }
        }
    }

    if let Some(inner) = &input.inner_volume {
        remove_volume(&incus, &input, inner)?;
        for volume in volume_inventory(&incus, &input)? {
            if volume["type"] == "custom" {
                if let Some(name) = volume["name"].as_str().filter(|name| input.owned_volume(name)) {
                    remove_volume(&incus, &input, name)?;
                }
            }
        }
    }
    let acl = format!("/1.0/network-acls/{}?project=default", input.acl);
    if incus.query(&acl)?.is_some() {
        incus.change(&["network", "acl", "delete", &input.acl, "--project", "default"])?;
    }
    let volumes = volume_inventory(&incus, &input)?;
    if incus.query(&instance)?.is_some()
        || incus.query(&acl)?.is_some()
        || volumes.iter().any(|volume| {
            (volume["type"] == "container" && volume["name"] == input.name)
                || (volume["type"] == "custom" && volume["name"].as_str().is_some_and(|name| input.owned_volume(name)))
        })
    {
        return Err(Failure::new(FAILED, "an owned Incus resource still exists"));
    }
    remove_firewall(system, id)?;
    discard_legacy(system, id)?;
    for path in [
        format!("/var/lib/orbit/instance-mail/{}", input.name),
        format!("/var/lib/orbit/instance-repositories/{id}.git"),
        format!("/var/lib/orbit/instance-repositories/{id}.git.seeding"),
        format!("/var/tmp/orbit-task-base-{id}"),
        format!("/data/caddy/orbit/hibernation/app-instance-{id}.asleep"),
        format!("/data/caddy/orbit/hibernation/app-instance-{id}.cold"),
        format!("/data/caddy/orbit/hibernation/app-instance-{id}.log"),
        format!("/dev/shm/orbit/hibernation/app-instance-{id}.awake"),
    ] {
        let path = system.path(&path);
        let result = match fs::symlink_metadata(&path) {
            Ok(entry) if entry.is_dir() => fs::remove_dir_all(&path),
            Ok(_) => fs::remove_file(&path),
            Err(error) => Err(error),
        };
        if let Err(error) = result {
            if error.kind() != io::ErrorKind::NotFound {
                return Err(Failure::new(FAILED, format!("{}: {error}", path.display())));
            }
        }
    }
    Ok(())
}

fn volume_inventory(incus: &Incus<'_, impl System>, input: &Input) -> Result<Vec<Value>, Failure> {
    inventory(incus, &input.volumes())
}

fn inventory(incus: &Incus<'_, impl System>, url: &str) -> Result<Vec<Value>, Failure> {
    match incus.query(url)? {
        Some(Value::Array(rows)) => Ok(rows),
        _ => Err(Failure::new(FAILED, format!("Incus did not return an inventory for {url}"))),
    }
}

fn remove_volume(incus: &Incus<'_, impl System>, input: &Input, name: &str) -> Result<(), Failure> {
    let url = input.volume(name);
    let Some(volume) = incus.query(&url)? else {
        return Ok(());
    };
    if volume["name"] != name || volume["type"] != "custom" || !volume["used_by"].as_array().is_some_and(Vec::is_empty) {
        return Err(Failure::new(
            FAILED,
            format!("custom volume {name} is not an unused owned volume"),
        ));
    }
    let delete = || incus.change(&["--project", &input.project, "storage", "volume", "delete", &input.pool, name]);
    if delete().is_err() && incus.query(&url)?.is_some() {
        let snapshots = snapshot_names(
            incus,
            &format!(
                "/1.0/storage-pools/{}/volumes/custom/{name}/snapshots?project={}&recursion=1",
                input.pool, input.project
            ),
            name,
        )?;
        restore_missing_datasets(incus, input, "custom", name, &snapshots)?;
        for snapshot in snapshots {
            incus.change(&[
                "--project",
                &input.project,
                "storage",
                "volume",
                "snapshot",
                "delete",
                &input.pool,
                name,
                &snapshot,
            ])?;
        }
        delete()?;
    }
    Ok(())
}

fn snapshot_names(incus: &Incus<'_, impl System>, url: &str, resource: &str) -> Result<Vec<String>, Failure> {
    let prefix = format!("{resource}/");
    inventory(incus, url)?
        .into_iter()
        .map(|row| {
            let name = row["name"].as_str().unwrap_or_default();
            let name = name.strip_prefix(&prefix).unwrap_or(name);
            if path_component(name) {
                Ok(name.to_owned())
            } else {
                Err(Failure::new(FAILED, "Invalid snapshot name in Incus inventory"))
            }
        })
        .collect()
}

fn restore_missing_datasets(
    incus: &Incus<'_, impl System>,
    input: &Input,
    kind: &str,
    name: &str,
    snapshots: &[String],
) -> Result<bool, Failure> {
    let pool = incus
        .query(&format!("/1.0/storage-pools/{}", input.pool))?
        .ok_or_else(|| Failure::new(FAILED, "the admitted pool is absent"))?;
    if pool["driver"] != "zfs" {
        return Ok(false);
    }
    let root = pool["config"]["zfs.pool_name"]
        .as_str()
        .filter(|root| root.split('/').all(path_component))
        .ok_or_else(|| Failure::new(FAILED, "invalid ZFS pool name"))?;
    let dataset = format!("{root}/{kind}/{}_{name}", input.project);
    let existing = command(incus.system, 60, &[ZFS, "list", "-H", "-o", "name", "-t", "all", "-r", root])?;
    let existing: BTreeSet<_> = existing.lines().collect();
    let expected =
        std::iter::once(dataset.clone()).chain(snapshots.iter().map(|snapshot| format!("{dataset}@snapshot-{snapshot}")));
    let missing: Vec<_> = expected.filter(|name| !existing.contains(name.as_str())).collect();
    for name in &missing {
        if command(incus.system, 60, &[ZFS, "list", "-H", "-o", "name", "-r", "-t", "all", name]).is_ok() {
            return Err(Failure::new("instance.removal_dataset_taken", name));
        }
    }
    for name in &missing {
        let args = if name.contains('@') {
            vec![ZFS, "snapshot", name]
        } else {
            vec![ZFS, "create", "-o", "mountpoint=legacy", "-o", "canmount=noauto", name]
        };
        if let Err(error) = command(incus.system, 60, &args) {
            if command(incus.system, 60, &[ZFS, "list", "-H", "-o", "name", "-r", "-t", "all", name]).is_ok() {
                return Err(Failure::new("instance.removal_dataset_taken", name));
            }
            return Err(error);
        }
    }
    Ok(true)
}

fn path_component(value: &str) -> bool {
    !value.is_empty()
        && !matches!(value, "." | "..")
        && !value.starts_with('-')
        && value
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || b"-_.:".contains(&byte))
}

fn firewall_rules<'a, 'prefix>(inventory: &'a str, prefix: &'prefix str) -> impl Iterator<Item = &'a str> + use<'a, 'prefix> {
    inventory.lines().filter_map(move |line| {
        let (rule, comment) = line.split_once(" comment '")?;
        comment.starts_with(prefix).then_some(rule)
    })
}

fn remove_firewall(system: &impl System, id: &str) -> Result<(), Failure> {
    let list = || command(system, 30, &[UFW, "show", "added"]);
    let prefix = format!("orbit:instance-{id}-");
    let before = list()?;
    for rule in firewall_rules(&before, &prefix) {
        if before.lines().any(|line| {
            line == rule
                || line
                    .split_once(" comment '")
                    .is_some_and(|(spec, comment)| spec == rule && !comment.starts_with(&prefix))
        }) {
            return Err(Failure::new(
                FAILED,
                "an owned firewall rule shares a specification with a foreign rule",
            ));
        }
        let words: Vec<_> = rule.split_whitespace().collect();
        let (scope, args) = match words.as_slice() {
            ["ufw", "route", args @ ..] => (Some("route"), args),
            ["ufw", args @ ..] => (None, args),
            _ => return Err(Failure::new(FAILED, "an owned firewall rule is not a ufw rule")),
        };
        if !matches!(args.first(), Some(&("allow" | "deny" | "reject" | "limit")))
            || args.iter().any(|word| word.starts_with('-'))
        {
            return Err(Failure::new(
                FAILED,
                "an owned firewall rule cannot be deleted by specification",
            ));
        }
        let mut delete = vec![UFW, "--force"];
        delete.extend(scope);
        delete.push("delete");
        delete.extend(args);
        command(system, 60, &delete)?;
    }
    if firewall_rules(&list()?, &prefix).next().is_some() {
        return Err(Failure::new(FAILED, "an owned firewall rule still exists"));
    }
    Ok(())
}

fn discard_legacy(system: &impl System, id: &str) -> Result<(), Failure> {
    let unit = format!("--unit=orbit-instance-power-{id}");
    let args = [
        "/usr/bin/systemd-run",
        unit.as_str(),
        "--wait",
        "--pipe",
        "--collect",
        "--quiet",
        "/usr/local/libexec/orbit-instance-power",
        "discard",
        "--instance",
        id,
    ];
    for attempt in 0..40 {
        match command(system, 60, &args) {
            Ok(text) if serde_json::from_str::<Value>(&text).is_ok_and(|receipt| receipt["outcome"] == "discarded") => {
                return Ok(())
            }
            Ok(_) => return Err(Failure::new("instance.power_discard_failed", "legacy discard did not report a terminal result")),
            Err(error) if attempt < 39 && error.detail.contains("already loaded or has a fragment file") => {
                thread::sleep(Duration::from_millis(250));
            }
            Err(error) => return Err(Failure::new("instance.power_discard_failed", error.detail)),
        }
    }
    unreachable!("the last attempt returns")
}

fn command(system: &impl System, seconds: u32, args: &[&str]) -> Result<String, Failure> {
    system
        .run(seconds, &args.iter().map(|part| (*part).to_owned()).collect::<Vec<_>>(), None)
        .map_err(|detail| Failure::new(FAILED, format!("{}: {detail}", args.join(" "))))
}
