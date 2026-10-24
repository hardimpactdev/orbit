//! Host-side Incus observations. The Gateway owns placement and recorded ownership.
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::{
    collections::BTreeMap,
    io::{self, Read, Write},
    process::{Command as Process, Stdio},
    time::Duration,
};

command_errors! {
    pub enum Error {
        Invalid {} => "incus.input_invalid", "The Incus request is invalid.";
        Output { operation: String, reason: String } => "task_vm.invalid_host_output", "Incus returned invalid host output.";
        Failed { operation: String, exit_code: i32, stderr: String } => "task_vm.host_command_failed", "The Incus host command failed.";
        VmMissing {} => "task_vm.vm_missing", "The task VM is gone from its host.";
        ImageMismatch {} => "task_vm.image_mismatch", "The task VM did not launch its claimed recipe image.";
        BaseMissing {} => "task_vm.base_image_missing", "The Incus project has no base image. Build it with task-vms:build-image.";
        SeedMissing {} => "task_vm.topology_seed_missing", "The host has no topology seed for the VM's base image. Build the image again with task-vms:build-image.";
    }
}

const MAX_OUTPUT: usize = 1_048_576;
pub(super) struct Output {
    pub(super) code: i32,
    pub(super) stdout: String,
    pub(super) stderr: String,
    pub(super) truncated: bool,
}
trait Host {
    fn run(&mut self, args: &[&str], input: Option<&str>) -> Output;
}
struct Native<'a>(&'a Context);
impl Host for Native<'_> {
    fn run(&mut self, args: &[&str], input: Option<&str>) -> Output {
        bounded(self.0, "/usr/bin/incus", args, input)
    }
}
pub(super) fn bounded(
    context: &Context,
    program: &str,
    args: &[&str],
    input: Option<&str>,
) -> Output {
    let remaining = context.remaining().as_secs_f64();
    if remaining <= 0.0 {
        return unavailable();
    }
    let mut command = Process::new("/usr/bin/timeout");
    command
        .args([
            "--signal=TERM",
            "--kill-after=1",
            &format!("{remaining:.6}s"),
            program,
        ])
        .args(args);
    capture_input(&mut command, input).unwrap_or_else(|_| unavailable())
}
fn unavailable() -> Output {
    Output {
        code: -1,
        stdout: String::new(),
        stderr: "The Incus executable could not run.".into(),
        truncated: false,
    }
}
fn stream(mut reader: impl Read) -> io::Result<Vec<u8>> {
    let mut bytes = Vec::new();
    reader
        .by_ref()
        .take(MAX_OUTPUT as u64 + 1)
        .read_to_end(&mut bytes)?;
    // Drain both streams even after their retained output fills, so neither pipe blocks the child.
    io::copy(&mut reader, &mut io::sink())?;
    Ok(bytes)
}
#[cfg(test)]
fn capture(command: &mut Process) -> io::Result<Output> {
    capture_input(command, None)
}
fn capture_input(command: &mut Process, input: Option<&str>) -> io::Result<Output> {
    let mut child = command
        .stdin(if input.is_some() {
            Stdio::piped()
        } else {
            Stdio::null()
        })
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()?;
    let stdout = child.stdout.take().expect("piped stdout");
    let stderr = child.stderr.take().expect("piped stderr");
    let stdin = child.stdin.take();
    let (status, stdout, stderr) = std::thread::scope(|scope| {
        scope.spawn(move || {
            if let (Some(mut stdin), Some(input)) = (stdin, input) {
                let _ = stdin.write_all(input.as_bytes());
            }
        });
        let out = scope.spawn(|| stream(stdout));
        let err = scope.spawn(|| stream(stderr));
        (child.wait(), out.join(), err.join())
    });
    let stdout = stdout.map_err(|_| io::Error::other("Incus output reader failed"))??;
    let stderr = stderr.map_err(|_| io::Error::other("Incus error reader failed"))??;
    Ok(Output {
        code: status?.code().unwrap_or(-1),
        truncated: stdout.len() > MAX_OUTPUT
            || stderr.len() > MAX_OUTPUT
            || std::str::from_utf8(&stdout).is_err(),
        stdout: String::from_utf8_lossy(&stdout).into_owned(),
        stderr: String::from_utf8_lossy(&stderr).into_owned(),
    })
}
fn invalid_output(operation: &str, reason: &str) -> Error {
    Error::Output {
        operation: operation.into(),
        reason: reason.into(),
    }
}
fn read(host: &mut impl Host, operation: &str, args: &[&str]) -> Result<String, Error> {
    read_input(host, operation, args, None)
}
fn read_input(
    host: &mut impl Host,
    operation: &str,
    args: &[&str],
    input: Option<&str>,
) -> Result<String, Error> {
    let output = host.run(args, input);
    if output.code != 0 {
        let lines: Vec<_> = output.stderr.trim().lines().collect();
        return Err(Error::Failed {
            operation: operation.into(),
            exit_code: output.code,
            stderr: lines[lines.len().saturating_sub(5)..]
                .join("\n")
                .chars()
                .take(2000)
                .collect(),
        });
    }
    if output.truncated {
        return Err(invalid_output(operation, "the output is not complete"));
    }
    Ok(output.stdout)
}
fn json(host: &mut impl Host, operation: &str, args: &[&str]) -> Result<Vec<Value>, Error> {
    serde_json::from_str(&read(host, operation, args)?)
        .map_err(|_| invalid_output(operation, "the output is not a complete JSON list"))
}
fn valid_name(name: &str) -> bool {
    !name.is_empty()
        && name.len() <= 63
        && name.as_bytes()[0].is_ascii_alphanumeric()
        && name.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'-')
}
fn project(input: &Project) -> Result<(), Error> {
    if !valid_name(&input.project) {
        return Err(Error::Invalid {});
    }
    Ok(())
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Project {
    project: String,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Empty {}
#[derive(Debug, Serialize, PartialEq)]
pub struct Images {
    images: BTreeMap<String, Vec<String>>,
}
fn images(host: &mut impl Host, input: &Project) -> Result<Images, Error> {
    project(input)?;
    let mut images = BTreeMap::new();
    for image in json(
        host,
        "image list",
        &[
            "--project",
            &input.project,
            "image",
            "list",
            "--format",
            "json",
        ],
    )? {
        let fingerprint = image
            .get("fingerprint")
            .and_then(Value::as_str)
            .filter(|f| {
                f.len() == 64
                    && f.bytes()
                        .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
            })
            .ok_or_else(|| invalid_output("image list", "an image has no SHA256 fingerprint"))?;
        let aliases = image
            .get("aliases")
            .and_then(Value::as_array)
            .ok_or_else(|| invalid_output("image list", "an image has no aliases"))?;
        let aliases = aliases
            .iter()
            .map(|a| {
                a.get("name")
                    .and_then(Value::as_str)
                    .filter(|s| !s.is_empty())
                    .map(str::to_owned)
                    .ok_or_else(|| invalid_output("image list", "an image alias has no name"))
            })
            .collect::<Result<Vec<_>, _>>()?;
        if images.insert(fingerprint.into(), aliases).is_some() {
            return Err(invalid_output(
                "image list",
                "an image fingerprint appears more than once",
            ));
        }
    }
    Ok(Images { images })
}
#[derive(Debug, Serialize, PartialEq)]
pub struct Instances {
    names: Vec<String>,
}
fn instances(host: &mut impl Host, input: &Project) -> Result<Instances, Error> {
    project(input)?;
    read(
        host,
        "project show",
        &[
            "--project",
            &input.project,
            "project",
            "show",
            "--",
            &input.project,
        ],
    )?;
    let text = read(
        host,
        "list",
        &[
            "--project",
            &input.project,
            "list",
            "--format",
            "csv",
            "--columns",
            "n",
        ],
    )?;
    let names: Vec<String> = text
        .trim()
        .split('\n')
        .filter(|s| !s.is_empty())
        .map(str::to_owned)
        .collect();
    if names.iter().any(|name| !valid_name(name)) {
        return Err(invalid_output("list", "an instance name is invalid"));
    }
    Ok(Instances { names })
}
#[derive(Debug, Serialize, PartialEq)]
pub struct Resource {
    project: String,
    name: String,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct Volume {
    project: String,
    pool: String,
    name: String,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct Inventory {
    instances: Vec<Resource>,
    volumes: Vec<Volume>,
}
fn urls(host: &mut impl Host, path: &str) -> Result<Vec<String>, Error> {
    json(host, "query", &["query", "--", path])?
        .into_iter()
        .map(|v| {
            v.as_str()
                .map(str::to_owned)
                .ok_or_else(|| invalid_output("query", "the answer is not a list of URLs"))
        })
        .collect()
}
fn decode_component(value: &str) -> Option<String> {
    let mut bytes = Vec::new();
    let mut input = value.bytes();
    while let Some(b) = input.next() {
        bytes.push(if b == b'%' {
            let high = (input.next()? as char).to_digit(16)?;
            let low = (input.next()? as char).to_digit(16)?;
            (high * 16 + low) as u8
        } else {
            b
        });
    }
    String::from_utf8(bytes).ok()
}
fn encode_component(value: &str) -> String {
    let mut result = String::new();
    for b in value.bytes() {
        if b.is_ascii_alphanumeric() || b"-_.~".contains(&b) {
            result.push(b as char);
        } else {
            result.push_str(&format!("%{b:02X}"));
        }
    }
    result
}
fn resource(url: &str, prefix: &str) -> Result<Resource, Error> {
    let invalid = || invalid_output("query", "a URL names no resource");
    let (path, query) = url.split_once('?').unwrap_or((url, ""));
    let name =
        decode_component(path.strip_prefix(prefix).ok_or_else(invalid)?).ok_or_else(invalid)?;
    let mut project = "default".to_string();
    for item in query.split('&') {
        let (key, value) = item.split_once('=').unwrap_or((item, ""));
        let key = decode_component(key).ok_or_else(invalid)?;
        if key.starts_with("project[") {
            return Err(invalid());
        }
        if key == "project" {
            project = decode_component(&value.replace('+', " ")).ok_or_else(invalid)?;
        }
    }
    if name.is_empty()
        || name.contains('/')
        || name.chars().any(char::is_control)
        || project.is_empty()
        || project.chars().any(char::is_control)
    {
        return Err(invalid());
    }
    Ok(Resource { project, name })
}
fn inventory(host: &mut impl Host) -> Result<Inventory, Error> {
    let instances = urls(host, "/1.0/instances?all-projects=true")?
        .iter()
        .map(|url| resource(url, "/1.0/instances/"))
        .collect::<Result<_, _>>()?;
    let mut volumes = Vec::new();
    for url in urls(host, "/1.0/storage-pools")? {
        let pool = resource(&url, "/1.0/storage-pools/")?.name;
        let prefix = format!(
            "/1.0/storage-pools/{}/volumes/custom/",
            encode_component(&pool)
        );
        let path = format!("{}?all-projects=true", prefix.trim_end_matches('/'));
        for url in urls(host, &path)? {
            let resource = resource(&url, &prefix)?;
            volumes.push(Volume {
                project: resource.project,
                pool: pool.clone(),
                name: resource.name,
            });
        }
    }
    Ok(Inventory { instances, volumes })
}

pub struct ReadImages;
impl Command for ReadImages {
    const NAME: &'static str = "incus.images";
    const SUMMARY: &'static str = "Read the fingerprints and aliases of a project's Incus images.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = Project;
    type Output = Images;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Project, context: &Context) -> Result<Images, Error> {
        images(&mut Native(context), &input)
    }
}
pub struct ReadInstances;
impl Command for ReadInstances {
    const NAME: &'static str = "incus.instances";
    const SUMMARY: &'static str =
        "Read instance names only after proving the Incus project exists.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(240);
    type Input = Project;
    type Output = Instances;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Project, context: &Context) -> Result<Instances, Error> {
        instances(&mut Native(context), &input)
    }
}
pub struct ReadInventory;
impl Command for ReadInventory {
    const NAME: &'static str = "incus.inventory";
    const SUMMARY: &'static str =
        "Read instances and custom volumes across all Incus projects and pools.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = Empty;
    type Output = Inventory;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(_: Empty, context: &Context) -> Result<Inventory, Error> {
        inventory(&mut Native(context))
    }
}

#[cfg(test)]
mod tests;

pub(super) mod setup;
pub(super) mod volumes;

pub mod image;
pub mod seed;
pub mod vm;
