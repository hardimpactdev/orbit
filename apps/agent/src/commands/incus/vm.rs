use super::{
    images, invalid_output, json, read, read_input, valid_name, volumes, Error, Host, Native,
    Project,
};
use crate::command::{Command, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::{net::Ipv4Addr, time::Duration};

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Location {
    project: String,
    name: String,
    cidr: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Observation {
    running: bool,
    settled: bool,
    address: Option<Ipv4Addr>,
    image_fingerprint: Option<String>,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct State {
    observation: Option<Observation>,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    vm: Location,
    operation: Operation,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(tag = "kind", rename_all = "snake_case", deny_unknown_fields)]
enum Operation {
    Create {
        image: String,
        expected_fingerprint: Option<String>,
        base_alias: String,
        gateway_public_key: String,
        cpus: u16,
        memory: String,
        disk: String,
        network: String,
        pool: String,
        topology: bool,
    },
    CreateImage {
        image: String,
        gateway_public_key: String,
        cpus: u16,
        memory: String,
        disk: String,
        network: String,
        builder: bool,
    },
    RemoveInstance {},
    Start {},
    Stop {},
    Delete {
        pool: String,
    },
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Changed {
    ready: bool,
}
fn subnet(input: &Location) -> Result<(u32, u32), Error> {
    if !valid_name(&input.project) || !valid_name(&input.name) {
        return Err(Error::Invalid {});
    }
    let (address, prefix) = input.cidr.split_once('/').ok_or(Error::Invalid {})?;
    let address = u32::from(address.parse::<Ipv4Addr>().map_err(|_| Error::Invalid {})?);
    let prefix = prefix
        .parse::<u32>()
        .ok()
        .filter(|p| (16..=28).contains(p))
        .ok_or(Error::Invalid {})?;
    let mask = u32::MAX << (32 - prefix);
    if address & mask != address {
        return Err(Error::Invalid {});
    }
    Ok((address, mask))
}
fn observe(host: &mut impl Host, input: &Location) -> Result<Option<Observation>, Error> {
    let (network, mask) = subnet(input)?;
    let rows = json(
        host,
        "list",
        &[
            "--project",
            &input.project,
            "list",
            "--format",
            "json",
            "--",
            &input.name,
        ],
    )?;
    let matches: Vec<_> = rows
        .iter()
        .filter(|row| row.get("name").and_then(Value::as_str) == Some(&input.name))
        .collect();
    if matches.is_empty() {
        return Ok(None);
    }
    let row = matches[0];
    let status = row.get("status").and_then(Value::as_str);
    if matches.len() != 1
        || row.get("type").and_then(Value::as_str) != Some("virtual-machine")
        || !matches!(status, Some("Running" | "Stopped"))
    {
        return Err(invalid_output(
            "list",
            "the instance is not one virtual machine that is running or stopped",
        ));
    }
    let fingerprint = match row.get("config").and_then(|v| v.get("volatile.base_image")) {
        None | Some(Value::Null) => None,
        Some(Value::String(value)) if volumes::digest(value) => Some(value.clone()),
        _ => {
            return Err(invalid_output(
                "list",
                "the base image fingerprint is not a SHA256 digest",
            ))
        }
    };
    let mut addresses = vec![];
    if let Some(interfaces) = row
        .get("state")
        .and_then(|v| v.get("network"))
        .and_then(Value::as_object)
    {
        for interface in interfaces.values() {
            if let Some(rows) = interface.get("addresses").and_then(Value::as_array) {
                for row in rows {
                    if row.get("family").and_then(Value::as_str) != Some("inet") {
                        continue;
                    }
                    if let Some(address) = row
                        .get("address")
                        .and_then(Value::as_str)
                        .and_then(|s| s.parse::<Ipv4Addr>().ok())
                        .filter(|a| u32::from(*a) & mask == network)
                    {
                        addresses.push(address);
                    }
                }
            }
        }
    }
    if addresses.len() > 1
        || addresses
            .first()
            .is_some_and(|a| u32::from(*a) <= network + 1 || u32::from(*a) == network | !mask)
    {
        return Err(invalid_output(
            "list",
            &format!(
                "the VM needs exactly one guest IPv4 address in [{}]",
                input.cidr
            ),
        ));
    }
    Ok(Some(Observation {
        running: status == Some("Running"),
        settled: true,
        address: addresses.first().copied(),
        image_fingerprint: fingerprint,
    }))
}
fn pattern(value: &str, expression: &str) -> bool {
    regex::Regex::new(expression).unwrap().is_match(value)
}
fn pool(value: &str) -> bool {
    pattern(value, r"\A[A-Za-z0-9][A-Za-z0-9_.-]{0,62}\z")
}
fn validate(input: &Input) -> Result<(), Error> {
    subnet(&input.vm)?;
    match &input.operation {
        Operation::Create {
            image,
            expected_fingerprint,
            base_alias,
            gateway_public_key,
            cpus,
            memory,
            disk,
            network,
            pool: storage,
            ..
        } => {
            validate_launch(image, gateway_public_key, *cpus, memory, disk, network)?;
            if !pattern(base_alias, r"\A[A-Za-z0-9][A-Za-z0-9._/-]{0,253}\z")
                || !pool(storage)
                || expected_fingerprint
                    .as_ref()
                    .is_some_and(|v| !volumes::digest(v))
            {
                return Err(Error::Invalid {});
            }
        }
        Operation::CreateImage {
            image,
            gateway_public_key,
            cpus,
            memory,
            disk,
            network,
            ..
        } => {
            validate_launch(image, gateway_public_key, *cpus, memory, disk, network)?;
        }
        Operation::Delete { pool: storage } if !pool(storage) => return Err(Error::Invalid {}),
        _ => {}
    }
    Ok(())
}
fn validate_launch(
    image: &str,
    key: &str,
    cpus: u16,
    memory: &str,
    disk: &str,
    network: &str,
) -> Result<(), Error> {
    let size = |s: &str| pattern(s, r"\A[1-9][0-9]{0,5}(?:MiB|GiB)\z");
    if !pattern(image, r"\A[A-Za-z0-9][A-Za-z0-9._/-]{0,253}\z")
        || !(1..=64).contains(&cpus)
        || !size(memory)
        || !size(disk)
        || !valid_name(network)
        || !pattern(
            key,
            r"\A(?:ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+/]+={0,3}(?: [!-~][ -~]*)?\z",
        )
    {
        return Err(Error::Invalid {});
    }
    Ok(())
}
fn cloud_init(key: &str, builder: bool) -> Value {
    let mut user = json!({"name":"orbit","lock_passwd":true,"shell":"/bin/bash","sudo":"ALL=(ALL) NOPASSWD:ALL","ssh_authorized_keys":[key]});
    if builder {
        user["uid"] = json!(1000);
        user["no_user_group"] = json!(false);
    }
    let cloud = json!({"users":[user]});
    json!({"config":{"cloud-init.user-data":format!("#cloud-config\n{}\n", serde_json::to_string_pretty(&cloud).unwrap())},"devices":{"cloud-init":{"type":"disk","source":"cloud-init:config"}}})
}
struct Launch<'a> {
    image: &'a str,
    cpus: u16,
    memory: &'a str,
    disk: &'a str,
    network: &'a str,
}
fn launch(
    host: &mut impl Host,
    vm: &Location,
    spec: Launch<'_>,
    settings: &Value,
) -> Result<String, Error> {
    read_input(
        host,
        "init",
        &[
            "--project",
            &vm.project,
            "init",
            "--vm",
            "--config",
            &format!("limits.cpu={}", spec.cpus),
            "--config",
            &format!("limits.memory={}", spec.memory),
            "--device",
            &format!("root,size={}", spec.disk),
            "--device",
            &format!("eth0,network={}", spec.network),
            "--device",
            "eth0,security.port_isolation=true",
            "--",
            spec.image,
            &vm.name,
        ],
        Some(&settings.to_string()),
    )?;
    boot(host, vm)
}
fn remove(host: &mut impl Host, vm: &Location) -> Result<(), Error> {
    if let Err(error) = read(
        host,
        "delete",
        &[
            "--project",
            &vm.project,
            "delete",
            "--force",
            "--",
            &vm.name,
        ],
    ) {
        if observe(host, vm)?.is_some() {
            return Err(error);
        }
    }
    Ok(())
}
fn running(host: &mut impl Host, vm: &Location) -> Result<bool, Error> {
    Ok(observe(host, vm)?.is_some_and(|o| o.running))
}
fn boot(host: &mut impl Host, vm: &Location) -> Result<String, Error> {
    super::image::prepare_metadata(host, &vm.project, &vm.name)?;
    read(
        host,
        "start",
        &["--project", &vm.project, "start", "--", &vm.name],
    )
}
fn start(host: &mut impl Host, vm: &Location, observation: &Observation) -> Result<(), Error> {
    if !observation.running {
        if let Err(error) = boot(host, vm) {
            if !running(host, vm)? {
                return Err(error);
            }
        }
    }
    Ok(())
}
fn change(host: &mut impl Host, input: &Input) -> Result<Changed, Error> {
    validate(input)?;
    let vm = &input.vm;
    match &input.operation {
        Operation::Create {
            image,
            expected_fingerprint,
            base_alias,
            gateway_public_key,
            cpus,
            memory,
            disk,
            network,
            pool,
            topology,
        } => {
            if let Some(observed) = observe(host, vm)? {
                if expected_fingerprint
                    .as_ref()
                    .is_some_and(|f| observed.image_fingerprint.as_ref() != Some(f))
                {
                    return Err(Error::ImageMismatch {});
                }
                start(host, vm, &observed)?;
                return Ok(Changed { ready: true });
            }
            let mut settings = cloud_init(gateway_public_key, false);
            if *topology {
                let seed = volumes::change(
                    host,
                    &volumes::Input {
                        location: volumes::Pool {
                            project: vm.project.clone(),
                            pool: pool.clone(),
                        },
                        operation: volumes::Operation::CloneSeed {
                            vm: vm.name.clone(),
                            fingerprint: volumes::digest(image).then(|| image.clone()),
                        },
                    },
                )?;
                settings["devices"]["topology-seed"] =
                    json!({"type":"disk","pool":pool,"source":seed.name});
                settings["config"]["raw.qemu.conf"] =
                    json!("[device \"qemu_balloon\"]\nfree-page-reporting = \"on\"\n");
            }
            let result = launch(
                host,
                vm,
                Launch {
                    image,
                    cpus: *cpus,
                    memory,
                    disk,
                    network,
                },
                &settings,
            );
            if let Err(error) = result {
                if !running(host, vm)? {
                    if !images(
                        host,
                        &Project {
                            project: vm.project.clone(),
                        },
                    )?
                    .images
                    .values()
                    .any(|a| a.contains(base_alias))
                    {
                        return Err(Error::BaseMissing {});
                    }
                    return Err(error);
                }
            }
        }
        Operation::CreateImage {
            image,
            gateway_public_key,
            cpus,
            memory,
            disk,
            network,
            builder,
        } => {
            if let Some(observed) = observe(host, vm)? {
                let expected = if volumes::digest(image) {
                    Some(image.clone())
                } else {
                    images(
                        host,
                        &Project {
                            project: vm.project.clone(),
                        },
                    )?
                    .images
                    .into_iter()
                    .find(|(_, aliases)| aliases.contains(image))
                    .map(|(fingerprint, _)| fingerprint)
                };
                if expected.is_none() || observed.image_fingerprint != expected {
                    return Err(Error::ImageMismatch {});
                }
                start(host, vm, &observed)?;
            } else {
                launch(
                    host,
                    vm,
                    Launch {
                        image,
                        cpus: *cpus,
                        memory,
                        disk,
                        network,
                    },
                    &cloud_init(gateway_public_key, *builder),
                )?;
            }
        }
        Operation::RemoveInstance {} => remove(host, vm)?,
        Operation::Start {} => {
            let observed = observe(host, vm)?.ok_or(Error::VmMissing {})?;
            start(host, vm, &observed)?;
        }
        Operation::Stop {} => {
            if !running(host, vm)? {
                return Ok(Changed { ready: true });
            }
            if read(
                host,
                "stop",
                &[
                    "--project",
                    &vm.project,
                    "stop",
                    "--timeout",
                    "120",
                    "--",
                    &vm.name,
                ],
            )
            .is_err()
                && running(host, vm)?
            {
                if let Err(error) = read(
                    host,
                    "stop",
                    &["--project", &vm.project, "stop", "--force", "--", &vm.name],
                ) {
                    if running(host, vm)? {
                        return Err(error);
                    }
                }
            }
        }
        Operation::Delete { pool } => {
            remove(host, vm)?;
            volumes::change(
                host,
                &volumes::Input {
                    location: volumes::Pool {
                        project: vm.project.clone(),
                        pool: pool.clone(),
                    },
                    operation: volumes::Operation::Remove {
                        name: format!("{}-topology-seed", vm.name),
                    },
                },
            )?;
        }
    }
    Ok(Changed { ready: true })
}
pub struct ReadVm;
impl Command for ReadVm {
    const NAME: &'static str = "incus.vm-state";
    const SUMMARY: &'static str =
        "Read the exact VM state, guest bridge address, and original image.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Location;
    type Output = State;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        crate::commands::linux_only(platform, Privilege::Root)
    }
    fn run(input: Location, context: &Context) -> Result<State, Error> {
        Ok(State {
            observation: observe(&mut Native(context), &input)?,
        })
    }
}
pub struct ChangeVm;
impl Command for ChangeVm {
    const NAME: &'static str = "incus.vm";
    const SUMMARY: &'static str =
        "Create, start, stop, or remove a task or image VM and its selected seed clone.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    const RESOURCES: &'static [&'static str] = &["incus-volumes"];
    type Input = Input;
    type Output = Changed;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        crate::commands::linux_only(platform, Privilege::Root)
    }
    fn target(input: &Input) -> Option<String> {
        Some(format!("incus-vm:{}:{}", input.vm.project, input.vm.name))
    }
    fn run(input: Input, context: &Context) -> Result<Changed, Error> {
        change(&mut Native(context), &input)
    }
}
#[cfg(test)]
mod tests;
