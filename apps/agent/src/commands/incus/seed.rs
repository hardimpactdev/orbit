//! Build the immutable nested topology disk in a disposable smoke VM.
use super::{json, read, Error as IncusError, Host, Native};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::{
    collections::BTreeMap,
    fs,
    os::unix::fs::DirBuilderExt,
    path::Path,
    time::{Duration, Instant},
};

const POOL: &str = "topology-seed";
const TEMPLATE: &str = "node-template";
const DEVICE: &str = "/dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_incus_topology--seed";
const ARCHIVE: &str = "/var/tmp/orbit-topology-seed.tar";
command_errors! {
    pub enum Error {
        Invalid {} => "incus.input_invalid", "The topology seed request is invalid.";
        Failed { reason: String } => "task_vm.topology_seed_failed", "The topology seed could not be built.";
    }
}
fn failed(reason: impl Into<String>) -> Error {
    Error::Failed {
        reason: reason.into(),
    }
}
impl From<IncusError> for Error {
    fn from(error: IncusError) -> Self {
        match error {
            IncusError::Failed {
                operation,
                exit_code,
                stderr,
            } => failed(format!(
                "{operation} failed with exit {exit_code}: {stderr}"
            )),
            IncusError::Output { operation, reason } => failed(format!("{operation}: {reason}")),
            _ => failed("The Incus topology seed operation failed."),
        }
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    project: String,
    pool: String,
    vm: String,
    size: String,
    fingerprint: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Built {
    ready: bool,
    seconds: BTreeMap<String, f64>,
}
fn validate(input: &Input) -> Result<(), Error> {
    let name = regex::Regex::new(r"\A[a-z0-9](?:[a-z0-9_-]{0,61}[a-z0-9])?\z").unwrap();
    if ![&input.project, &input.pool, &input.vm]
        .iter()
        .all(|v| name.is_match(v))
        || !super::volumes::digest(&input.fingerprint)
        || !regex::Regex::new(r"\A[1-9][0-9]{0,4}GiB\z")
            .unwrap()
            .is_match(&input.size)
    {
        return Err(Error::Invalid {});
    }
    Ok(())
}
fn host(host: &mut impl Host, input: &Input, args: &[&str]) -> Result<String, Error> {
    Ok(read(
        host,
        &args.join(" "),
        &[&["--project", &input.project], args].concat(),
    )?)
}
fn guest(host: &mut impl Host, input: &Input, args: &[&str]) -> Result<String, Error> {
    self::host(
        host,
        input,
        &[&["exec", "--mode=non-interactive", &input.vm, "--"], args].concat(),
    )
}
fn build(
    host: &mut impl Host,
    input: &Input,
    temporary: &Path,
    mut wait: impl FnMut(),
) -> Result<Built, Error> {
    validate(input)?;
    let mut seconds = BTreeMap::new();
    let mut started = Instant::now();
    let volume = format!("orbit-topology-seed-{}-new", &input.fingerprint[..12]);
    // A complete inventory proves absence; a failed show must not authorize creation.
    let volumes = json(
        host,
        "storage volume list",
        &[
            "--project",
            &input.project,
            "storage",
            "volume",
            "list",
            &input.pool,
            "--format",
            "json",
        ],
    )?;
    for row in &volumes {
        if row.get("name").and_then(Value::as_str).is_none()
            || row.get("type").and_then(Value::as_str).is_none()
        {
            return Err(failed("The volume inventory is incomplete."));
        }
    }
    if volumes
        .iter()
        .any(|v| v["type"] == "custom" && v["name"] == volume)
    {
        self::host(
            host,
            input,
            &["storage", "volume", "delete", &input.pool, &volume],
        )?;
    }
    self::host(
        host,
        input,
        &[
            "storage",
            "volume",
            "create",
            &input.pool,
            &volume,
            "--type=block",
            &format!("size={}", input.size),
        ],
    )?;
    self::host(
        host,
        input,
        &[
            "config",
            "device",
            "add",
            &input.vm,
            POOL,
            "disk",
            &format!("pool={}", input.pool),
            &format!("source={volume}"),
        ],
    )?;
    seconds.insert("volume".into(), started.elapsed().as_secs_f64());
    started = Instant::now();
    let work = temporary.join(format!(
        "orbit-topology-seed.{:016x}",
        rand::random::<u64>()
    ));
    fs::DirBuilder::new()
        .mode(0o700)
        .create(&work)
        .map_err(|e| failed(format!("Create export directory: {e}")))?;
    let copied = (|| {
        self::host(
            host,
            input,
            &[
                "image",
                "export",
                &input.fingerprint,
                work.join("base").to_str().unwrap(),
            ],
        )?;
        let archive = work.join("base.tar");
        if !fs::symlink_metadata(&archive).is_ok_and(|m| m.is_file()) {
            return Err(failed("The image did not export as one archive."));
        }
        self::host(
            host,
            input,
            &[
                "file",
                "push",
                archive.to_str().unwrap(),
                &format!("{}{ARCHIVE}", input.vm),
                "--mode=0600",
            ],
        )?;
        Ok(())
    })();
    let cleanup = fs::remove_dir_all(&work);
    copied?;
    cleanup.map_err(|e| failed(format!("Remove export directory: {e}")))?;
    seconds.insert("copy".into(), started.elapsed().as_secs_f64());
    started = Instant::now();
    for attempt in 0..=60 {
        let output = host.run(
            &[
                "--project",
                &input.project,
                "exec",
                "--mode=non-interactive",
                &input.vm,
                "--",
                "test",
                "-b",
                DEVICE,
            ],
            None,
        );
        if output.code == 0 {
            break;
        }
        if attempt == 60 {
            return Err(failed(format!(
                "The disk did not appear as {DEVICE} in {}.",
                input.vm
            )));
        }
        wait();
    }
    guest(
        host,
        input,
        &[
            "incus",
            "storage",
            "create",
            POOL,
            "btrfs",
            &format!("source={DEVICE}"),
        ],
    )?;
    guest(host, input, &["incus", "image", "import", ARCHIVE])?;
    guest(host, input, &["rm", "-f", "--", ARCHIVE])?;
    guest(
        host,
        input,
        &[
            "incus",
            "init",
            &input.fingerprint,
            TEMPLATE,
            "--vm",
            "--no-profiles",
            "--storage",
            POOL,
        ],
    )?;
    guest(
        host,
        input,
        &["incus", "image", "delete", &input.fingerprint],
    )?;
    let text = guest(host, input, &["incus", "list", "--format", "json"])?;
    let instances: Vec<Value> = serde_json::from_str(&text)
        .map_err(|_| failed("The nested instance inventory is invalid."))?;
    if instances.len() != 1
        || instances[0]["name"] != TEMPLATE
        || instances[0]["status"] != "Stopped"
        || instances[0]["profiles"] != serde_json::json!([])
        || instances[0]["type"] != "virtual-machine"
        || instances[0]["devices"]["root"]["pool"] != POOL
    {
        return Err(failed("The template is not the one stopped VM without profiles and with its root disk on topology-seed."));
    }
    guest(
        host,
        input,
        &[
            "systemctl",
            "stop",
            "incus.socket",
            "incus-user.socket",
            "incus.service",
            "incus-user.service",
        ],
    )?;
    guest(host, input, &["sync"])?;
    seconds.insert("template".into(), started.elapsed().as_secs_f64());
    Ok(Built {
        ready: true,
        seconds,
    })
}
pub struct BuildSeed;
impl Command for BuildSeed {
    const NAME: &'static str = "incus.build-topology-seed";
    const SUMMARY: &'static str =
        "Build a topology seed from a published image in a disposable smoke VM.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(600);
    const RESOURCES: &'static [&'static str] = &["incus-volumes"];
    type Input = Input;
    type Output = Built;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        crate::commands::linux_only(platform, Privilege::Root)
    }
    fn target(input: &Input) -> Option<String> {
        Some(format!("incus-volumes:{}:{}", input.project, input.pool))
    }
    fn run(input: Input, context: &Context) -> Result<Built, Error> {
        build(&mut Native(context), &input, Path::new("/var/tmp"), || {
            std::thread::sleep(Duration::from_secs(1))
        })
    }
}
#[cfg(test)]
mod tests;
