use super::{bounded, encode_component, Output};
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::{net::Ipv4Addr, path::Path, time::Duration};

command_errors! {
    pub enum Error {
        Failed { stage: String, reason: String } => "task_vm.setup_failed", "The task VM host setup failed.";
    }
}
fn failed(stage: &str, reason: impl Into<String>) -> Error {
    Error::Failed {
        stage: stage.into(),
        reason: reason.into(),
    }
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    project: String,
    network: String,
    cidr: String,
    pool: String,
    zfs_dataset: Option<String>,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct Prepared {
    ready: bool,
}
#[derive(Debug, Clone, Copy, PartialEq)]
enum Tool {
    Incus,
    Zfs,
    Ufw,
}
impl Tool {
    fn path(self) -> &'static str {
        match self {
            Self::Incus => "/usr/bin/incus",
            Self::Zfs => "/usr/sbin/zfs",
            Self::Ufw => "/usr/sbin/ufw",
        }
    }
}
trait Host {
    fn available(&self, tool: Tool) -> bool;
    fn run(&mut self, tool: Tool, args: &[&str], input: Option<&str>) -> Output;
}
struct Native<'a>(&'a Context);
impl Host for Native<'_> {
    fn available(&self, tool: Tool) -> bool {
        rustix::fs::access(Path::new(tool.path()), rustix::fs::Access::EXEC_OK).is_ok()
    }
    fn run(&mut self, tool: Tool, args: &[&str], input: Option<&str>) -> Output {
        bounded(self.0, tool.path(), args, input)
    }
}
fn run(
    host: &mut impl Host,
    tool: Tool,
    args: &[&str],
    input: Option<&str>,
) -> Result<String, Error> {
    let result = host.run(tool, args, input);
    if result.code != 0 || result.truncated {
        return Err(failed(
            args.first().copied().unwrap_or("tool"),
            if result.truncated {
                "The host output is incomplete.".into()
            } else {
                result.stderr.trim().chars().take(2000).collect::<String>()
            },
        ));
    }
    Ok(result.stdout)
}
fn incus(host: &mut impl Host, args: &[&str], data: Option<&Value>) -> Result<String, Error> {
    run(
        host,
        Tool::Incus,
        args,
        data.map(Value::to_string).as_deref(),
    )
}
fn read(host: &mut impl Host, path: &str) -> Result<Value, Error> {
    serde_json::from_str(&incus(host, &["query", "--", path], None)?)
        .map_err(|_| failed("inspect", "Incus returned invalid JSON."))
}
fn named(host: &mut impl Host, collection: &str, name: &str) -> Result<Option<Value>, Error> {
    let scope = if matches!(collection, "networks" | "network-acls") {
        "project=default&"
    } else {
        ""
    };
    let rows = read(host, &format!("/1.0/{collection}?{scope}recursion=1"))?;
    let rows = rows
        .as_array()
        .ok_or_else(|| failed("inspect", "Incus returned no resource list."))?;
    let mut found = None;
    for row in rows {
        let row_name = row
            .get("name")
            .and_then(Value::as_str)
            .ok_or_else(|| failed("inspect", "An Incus resource has no name."))?;
        if row_name == name {
            if found.is_some() {
                return Err(failed("inspect", "An Incus resource is listed twice."));
            }
            found = Some(row.clone());
        }
    }
    Ok(found)
}
fn setting<'a>(resource: &'a Value, name: &str) -> Result<Option<&'a str>, Error> {
    let config = resource
        .get("config")
        .and_then(Value::as_object)
        .ok_or_else(|| failed("inspect", "An Incus resource has no configuration."))?;
    match config.get(name) {
        None => Ok(None),
        Some(Value::String(value)) => Ok(Some(value)),
        _ => Err(failed(
            "inspect",
            "An Incus configuration value is invalid.",
        )),
    }
}
fn pattern(value: &str, expression: &str) -> bool {
    regex::Regex::new(expression).unwrap().is_match(value)
}
fn validate(input: &Input) -> Result<String, Error> {
    if !pattern(&input.project, r"\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z")
        || !pattern(&input.network, r"\Aorbittask[a-z0-9]{1,6}\z")
        || !pattern(&input.pool, r"\A[A-Za-z0-9][A-Za-z0-9_.-]{0,62}\z")
        || input.zfs_dataset.as_ref().is_some_and(|d| {
            d.len() > 200
                || !pattern(
                    d,
                    r"\A[A-Za-z0-9][A-Za-z0-9_.:-]*(?:/[A-Za-z0-9][A-Za-z0-9_.:-]*)+\z",
                )
        })
    {
        return Err(failed(
            "input",
            "The project, bridge, pool, or ZFS dataset is invalid.",
        ));
    }
    let (network, prefix) = input
        .cidr
        .split_once('/')
        .ok_or_else(|| failed("input", "The bridge subnet is invalid."))?;
    let address = network
        .parse::<Ipv4Addr>()
        .map_err(|_| failed("input", "The bridge subnet is invalid."))?;
    let prefix = prefix
        .parse::<u32>()
        .ok()
        .filter(|p| (16..=28).contains(p))
        .ok_or_else(|| failed("input", "The bridge prefix must be /16 to /28."))?;
    let numeric = u32::from(address);
    if numeric & (u32::MAX << (32 - prefix)) != numeric {
        return Err(failed(
            "input",
            "The bridge subnet is not a network address.",
        ));
    }
    Ok(format!("{}/{prefix}", Ipv4Addr::from(numeric + 1)))
}
fn acl(bridge: &str) -> Value {
    let address = bridge.split('/').next().unwrap();
    json!({"description":"Orbit task VM boundary", "egress":[
        {"action":"drop","destination":"10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,169.254.0.0/16,100.64.0.0/10,224.0.0.0/4,240.0.0.0/4","state":"enabled"},
        {"action":"allow","state":"enabled"}
    ],"ingress":[{"action":"allow","source":format!("{address}/32"),"protocol":"tcp","destination_port":"22","state":"enabled"}]})
}
fn prepare(input: &Input, host: &mut impl Host) -> Result<Prepared, Error> {
    let bridge = validate(input)?;
    for tool in [Tool::Incus, Tool::Ufw] {
        if !host.available(tool) {
            return Err(failed(
                "prerequisites",
                format!("{} is not installed.", tool.path()),
            ));
        }
    }
    // Read every existing resource before mutation: incompatible state cannot partly reconfigure the host.
    let pool = named(host, "storage-pools", &input.pool)?;
    if let Some(pool) = &pool {
        if pool.get("driver").and_then(Value::as_str) != Some("zfs") {
            return Err(failed("storage", "The storage pool is not a ZFS pool."));
        }
        if let Some(dataset) = &input.zfs_dataset {
            if setting(pool, "source")? != Some(dataset.as_str()) {
                return Err(failed(
                    "storage",
                    "The storage pool uses another ZFS dataset.",
                ));
            }
        }
    } else {
        let dataset = input.zfs_dataset.as_deref().ok_or_else(|| {
            failed(
                "storage",
                "The pool is absent and no ZFS dataset is configured.",
            )
        })?;
        if !host.available(Tool::Zfs) {
            return Err(failed("storage", "ZFS is not installed."));
        }
        let datasets = run(host, Tool::Zfs, &["list", "-H", "-o", "name"], None)?;
        if datasets.lines().any(|name| name == dataset) {
            return Err(failed(
                "storage",
                "The ZFS dataset already exists; name a new dataset for the pool.",
            ));
        }
    }
    let project = named(host, "projects", &input.project)?;
    if let Some(project) = &project {
        if setting(project, "features.networks")? == Some("true") {
            return Err(failed("project", "The project has its own networks."));
        }
    }
    let network = named(host, "networks", &input.network)?;
    if let Some(network) = &network {
        if network.get("type").and_then(Value::as_str) != Some("bridge")
            || network.get("managed") != Some(&Value::Bool(true))
            || setting(network, "ipv4.address")? != Some(bridge.as_str())
        {
            return Err(failed(
                "network",
                "The task bridge type or IPv4 address does not match.",
            ));
        }
    }
    let acl_name = format!("{}-egress", input.network);
    let existing_acl = named(host, "network-acls", &acl_name)?;
    let profile = if project.is_some() {
        Some(read(
            host,
            &format!(
                "/1.0/profiles/default?project={}",
                encode_component(&input.project)
            ),
        )?)
    } else {
        None
    };
    let devices = match &profile {
        Some(profile) => Some(
            profile
                .get("devices")
                .and_then(Value::as_object)
                .ok_or_else(|| failed("profile", "The default profile has no devices."))?,
        ),
        None => None,
    };
    if pool.is_none() {
        incus(
            host,
            &[
                "storage",
                "create",
                &input.pool,
                "zfs",
                &format!("source={}", input.zfs_dataset.as_deref().unwrap()),
            ],
            None,
        )?;
    }
    if project.is_none() {
        incus(
            host,
            &[
                "project",
                "create",
                &input.project,
                "-c",
                "features.images=true",
                "-c",
                "features.profiles=true",
                "-c",
                "features.networks=false",
            ],
            None,
        )?;
    }
    if existing_acl.is_none() {
        incus(
            host,
            &[
                "network",
                "acl",
                "create",
                &acl_name,
                "--project",
                "default",
            ],
            None,
        )?;
    }
    incus(
        host,
        &["network", "acl", "edit", &acl_name, "--project", "default"],
        Some(&acl(&bridge)),
    )?;
    let settings = [
        "ipv4.nat=true".into(),
        "ipv6.address=none".into(),
        format!("security.acls={acl_name}"),
        "security.acls.default.ingress.action=reject".into(),
        "security.acls.default.egress.action=reject".into(),
    ];
    let mut arguments = vec![
        "network",
        if network.is_none() { "create" } else { "set" },
        &input.network,
        "--project",
        "default",
    ];
    let address = format!("ipv4.address={bridge}");
    if network.is_none() {
        arguments.extend(["--type=bridge", &address]);
    }
    arguments.extend(settings.iter().map(String::as_str));
    incus(host, &arguments, None)?;
    for (name, kind, key, value, extras) in [
        (
            "root",
            "disk",
            "pool",
            format!("pool={}", input.pool),
            vec!["path=/"],
        ),
        (
            "eth0",
            "nic",
            "network",
            format!("network={}", input.network),
            vec!["security.port_isolation=true"],
        ),
    ] {
        let exists = devices
            .and_then(|d| d.get(name))
            .and_then(|d| d.get(key))
            .is_some();
        let mut args = vec![
            "profile",
            "device",
            if exists { "set" } else { "add" },
            "default",
            name,
        ];
        if !exists {
            args.push(kind);
        }
        args.push(&value);
        if name == "eth0" && !exists {
            args.push("name=eth0");
        }
        args.extend(extras);
        args.extend(["--project", &input.project]);
        incus(host, &args, None)?;
    }
    incus(
        host,
        &[
            "project",
            "set",
            &input.project,
            "restricted=true",
            &format!("restricted.networks.access={}", input.network),
            "restricted.virtual-machines.lowlevel=allow",
            "restricted.devices.disk=allow",
            "restricted.devices.disk.paths=/var/lib/orbit/package-cache",
        ],
        None,
    )?;
    // A stock image is copied only if its fixed alias is absent.
    let images = super::images(
        &mut IncusRead(host),
        &super::Project {
            project: input.project.clone(),
        },
    )
    .map_err(|error| failed("image", crate::command::CommandError::message(&error)))?;
    if !images
        .images
        .values()
        .any(|aliases| aliases.iter().any(|alias| alias == "ubuntu-26.04-vm"))
    {
        incus(
            host,
            &[
                "image",
                "copy",
                "images:ubuntu/26.04/cloud",
                "local:",
                "--vm",
                "--alias",
                "ubuntu-26.04-vm",
                "--target-project",
                &input.project,
            ],
            None,
        )?;
    }
    run(
        host,
        Tool::Ufw,
        &[
            "route",
            "allow",
            "in",
            "on",
            "orbittask+",
            "comment",
            "orbit-task-vms",
        ],
        None,
    )?;
    Ok(Prepared { ready: true })
}
struct IncusRead<'a, H>(&'a mut H);
impl<H: Host> super::Host for IncusRead<'_, H> {
    fn run(&mut self, args: &[&str], input: Option<&str>) -> Output {
        self.0.run(Tool::Incus, args, input)
    }
}
pub struct PrepareHost;
impl Command for PrepareHost {
    const NAME: &'static str = "incus.prepare-host";
    const SUMMARY: &'static str =
        "Converge a task VM host's ZFS pool, isolated bridge, project, profile, and stock image.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    const RESOURCES: &'static [&'static str] = &["firewall", "incus-host"];
    type Input = Input;
    type Output = Prepared;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        crate::commands::linux_only(platform, Privilege::Root)
    }
    fn target(input: &Input) -> Option<String> {
        Some(format!("incus-project:{}", input.project))
    }
    fn run(input: Input, context: &Context) -> Result<Prepared, Error> {
        prepare(&input, &mut Native(context))
    }
}

#[cfg(test)]
mod tests;
