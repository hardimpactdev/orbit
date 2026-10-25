//! The existing image phase unit is durable; command calls only start or observe it.
use super::*;
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use sha2::{Digest, Sha256};
use std::{
    io::{Read, Seek, SeekFrom},
    os::unix::fs::MetadataExt,
};

pub const PHASE_SECONDS: u64 = 1440;
const STATE: &str = "/run/orbit-recipe-images";

command_errors! {
    pub enum Error {
        Failed { stage: String, reason: String } => "task_vm.image_build_failed", "The image phase could not complete.";
        Unknown { reason: String } => "task_vm.image_channel_lost", "The guest phase outcome is unknown.";
        Rebooted { expected: String, observed: String } => "task_vm.image_builder_rebooted", "The builder rebooted. Run task-vms:build-recipe --retry to start a new builder.";
    }
}
fn failed(stage: &str, reason: impl ToString) -> Error {
    Error::Failed {
        stage: stage.into(),
        reason: recipe::diagnostic(&reason.to_string()),
    }
}
impl From<Failure> for Error {
    fn from(error: Failure) -> Self {
        failed(&error.stage, serde_json::to_string(&error).unwrap())
    }
}
fn unknown(reason: impl ToString) -> Error {
    Error::Unknown {
        reason: recipe::diagnostic(&reason.to_string()),
    }
}
#[derive(schemars::JsonSchema, Clone, Copy, Debug, Deserialize, Serialize, PartialEq)]
#[serde(rename_all = "kebab-case")]
pub enum Phase {
    AptSource,
    Packages,
    Javascript,
    Rust,
    Agent,
    Pi,
}
impl Phase {
    pub fn name(self) -> &'static str {
        match self {
            Self::AptSource => "apt-source",
            Self::Packages => "packages",
            Self::Javascript => "javascript",
            Self::Rust => "rust",
            Self::Agent => "agent",
            Self::Pi => "pi",
        }
    }
}
#[derive(schemars::JsonSchema, Clone, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct Key {
    pub identity: String,
    pub profile: String,
    pub phase: Phase,
    pub execution: String,
}
impl Key {
    fn validate(&self) -> Result<(), Error> {
        if self.profile != "base"
            || !self
                .identity
                .strip_prefix("sha256:")
                .is_some_and(|v| recipe::types::valid_digest(v, 64))
            || self.execution.is_empty()
            || self.execution.len() > 128
            || !self
                .execution
                .bytes()
                .all(|c| c.is_ascii_alphanumeric() || b"_-".contains(&c))
        {
            return Err(failed(
                "input",
                "Invalid image phase identity, profile or execution",
            ));
        }
        Ok(())
    }
    pub fn unit(&self) -> String {
        let digest = format!(
            "{:x}",
            Sha256::digest(format!(
                "{}:{}:{}:{}",
                self.identity,
                self.profile,
                self.phase.name(),
                self.execution
            ))
        );
        format!("orbit-recipe-image-{}.service", &digest[..32])
    }
    pub fn path(&self, files: &Files) -> PathBuf {
        files.path(STATE).join(format!("{}.json", self.unit()))
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Observe {
    pub key: Key,
    pub boot_id: Option<String>,
}
#[derive(schemars::JsonSchema, Clone, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct Start {
    pub key: Key,
    pub boot_id: String,
    pub recipe: Input,
}
impl Start {
    pub fn validate(&self) -> Result<(), Error> {
        self.key.validate()?;
        self.recipe.validate()?;
        if self.recipe.identity != self.key.identity || !uuid(&self.boot_id) {
            return Err(failed("input", "Image phase recipe or boot ID is invalid"));
        }
        Ok(())
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Restart {
    pub key: Key,
    pub boot_id: String,
    pub invocation_id: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
#[serde(rename_all = "snake_case")]
pub enum State {
    Missing,
    Running,
    Complete,
    Failed,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Observation {
    pub boot_id: String,
    pub unit: String,
    pub state: State,
    pub invocation_id: Option<String>,
    pub exit_code: Option<u32>,
    pub reason: Option<String>,
    pub log_tail: String,
}
fn uuid(value: &str) -> bool {
    regex::Regex::new(r"\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z")
        .unwrap()
        .is_match(value)
}
pub fn boot(files: &Files, expected: Option<&str>) -> Result<String, Error> {
    if expected.is_some_and(|v| !uuid(v)) {
        return Err(failed("input", "Invalid builder boot ID"));
    }
    let boot = fs::read_to_string(files.path("/proc/sys/kernel/random/boot_id"))
        .map_err(unknown)?
        .trim()
        .to_string();
    if !uuid(&boot) {
        return Err(unknown("The builder returned no readable boot ID"));
    }
    if let Some(expected) = expected.filter(|v| *v != boot) {
        return Err(Error::Rebooted {
            expected: expected.into(),
            observed: boot,
        });
    }
    Ok(boot)
}
fn tail(files: &Files, phase: Phase) -> Result<String, Error> {
    let path = super::super::image_build::phase_log(&files.root, phase.name(), false)
        .map_err(|e| failed("log", format!("{e:?}")))?;
    let mut file = match fs::File::open(path) {
        Ok(file) => file,
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => return Ok(String::new()),
        Err(e) => return Err(unknown(e)),
    };
    let size = file.metadata().map_err(unknown)?.len();
    file.seek(SeekFrom::Start(size.saturating_sub(16384)))
        .map_err(unknown)?;
    let mut bytes = vec![];
    file.take(16384).read_to_end(&mut bytes).map_err(unknown)?;
    let text = String::from_utf8_lossy(&bytes);
    let lines = text.lines().collect::<Vec<_>>();
    Ok(recipe::diagnostic(
        &lines[lines.len().saturating_sub(40)..].join("\n"),
    ))
}
fn parse(output: &recipe::Output, unit: String, boot_id: String) -> Result<Observation, Error> {
    if output.truncated {
        return Err(unknown("The phase status reply exceeded its bound"));
    }
    let mut fields = BTreeMap::new();
    for line in output.stdout.lines() {
        if let Some((key, value)) = line.split_once('=') {
            if fields.insert(key, value).is_some() {
                return Err(unknown("Duplicate systemd phase status field"));
            }
        } else if !line.is_empty() {
            return Err(unknown("Malformed systemd phase status"));
        }
    }
    let mut result = Observation {
        boot_id,
        unit,
        state: State::Missing,
        invocation_id: None,
        exit_code: None,
        reason: None,
        log_tail: String::new(),
    };
    if fields.get("LoadState") == Some(&"not-found") {
        return Ok(result);
    }
    let get = |key| {
        fields
            .get(key)
            .copied()
            .ok_or_else(|| unknown("Incomplete systemd phase status"))
    };
    if output.code != 0 || get("LoadState")? != "loaded" {
        return Err(unknown(format!(
            "Unreadable systemd phase status: {}",
            output.stderr
        )));
    }
    let invocation = get("InvocationID")?;
    if !recipe::types::valid_digest(invocation, 32) {
        return Err(unknown("Invalid phase invocation ID"));
    }
    result.invocation_id = Some(invocation.into());
    let code = get("ExecMainCode")?.parse::<u32>().map_err(unknown)?;
    let status = get("ExecMainStatus")?.parse::<u32>().map_err(unknown)?;
    let active = get("ActiveState")?;
    let sub = get("SubState")?;
    let outcome = get("Result")?;
    result.state = if ["activating", "deactivating"].contains(&active)
        || (active == "active" && sub != "exited")
    {
        State::Running
    } else if active == "active" && sub == "exited" && outcome == "success" && status == 0 {
        State::Complete
    } else {
        result.exit_code = (outcome == "exit-code" && code == 1).then_some(status);
        result.reason = Some(match (outcome, code, status) {
            ("timeout", _, _) => format!("timed out after {PHASE_SECONDS} seconds"),
            ("exit-code", 1, 75) => "another image phase holds the builder lock".into(),
            (_, 2 | 3, signal) => format!("failed (systemd result {outcome}, signal {signal})"),
            _ => format!("failed (systemd result {outcome}, exit {status})"),
        });
        State::Failed
    };
    Ok(result)
}
pub fn observe(
    host: &mut impl Host,
    files: &Files,
    key: &Key,
    expected: Option<&str>,
) -> Result<Observation, Error> {
    key.validate()?;
    let boot = boot(files, expected)?;
    let unit = key.unit();
    let mut call = Invocation::new(&[
        "systemctl",
        "show",
        "--no-pager",
        "--property=LoadState,ActiveState,SubState,Result,ExecMainCode,ExecMainStatus,InvocationID",
        "--",
        &unit,
    ]);
    call.timeout = Duration::from_secs(30);
    let output = host
        .execute(&call)
        .map_err(|e| unknown(serde_json::to_string(&e).unwrap()))?;
    let mut result = parse(&output, unit, boot)?;
    if result.state == State::Failed {
        result.log_tail = tail(files, key.phase)?;
    }
    Ok(result)
}
fn stage(files: &Files, input: &Start) -> Result<(), Error> {
    let directory = files.path(STATE);
    directories(files, &directory)?;
    fs::set_permissions(
        &directory,
        std::os::unix::fs::PermissionsExt::from_mode(0o700),
    )
    .map_err(|e| failed("stage", e))?;
    let path = input.key.path(files);
    let bytes = serde_json::to_vec(input).unwrap();
    match fs::symlink_metadata(&path) {
        Ok(meta) if meta.is_file() && meta.uid() == files.uid && meta.mode() & 0o077 == 0 => {
            let previous: serde_json::Value =
                serde_json::from_slice(&fs::read(&path).map_err(|e| failed("stage", e))?)
                    .map_err(|e| failed("stage", e))?;
            if previous != serde_json::to_value(input).unwrap() {
                return Err(failed(
                    "stage",
                    "The phase already has different immutable input",
                ));
            }
            Ok(())
        }
        Ok(_) => Err(failed(
            "stage",
            "The phase input is not an owned private file",
        )),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => files
            .publish(&path, &bytes, 0o600, files.uid, files.gid, false)
            .map_err(Into::into),
        Err(e) => Err(failed("stage", e)),
    }
}
pub fn start(host: &mut impl Host, files: &Files, input: &Start) -> Result<Observation, Error> {
    input.validate()?;
    let observed = observe(host, files, &input.key, Some(&input.boot_id))?;
    if observed.state != State::Missing {
        return Ok(observed);
    }
    stage(files, input)?;
    let log = super::super::image_build::phase_log(&files.root, input.key.phase.name(), true)
        .map_err(|e| failed("log", format!("{e:?}")))?;
    let unit = input.key.unit();
    let args = [
        "systemd-run".into(),
        "--quiet".into(),
        format!("--unit={unit}"),
        "--property=Type=exec".into(),
        "--property=RemainAfterExit=yes".into(),
        "--property=ExitType=cgroup".into(),
        format!("--property=RuntimeMaxSec={PHASE_SECONDS}"),
        "--property=TimeoutStopSec=30".into(),
        "--property=KillMode=control-group".into(),
        "--property=UMask=0022".into(),
        format!("--property=StandardOutput=append:{}", log.display()),
        format!("--property=StandardError=append:{}", log.display()),
        "/usr/local/bin/orbit-agent".into(),
        "service".into(),
        "image-phase".into(),
        unit,
    ];
    let mut call = Invocation::new(&args.iter().map(String::as_str).collect::<Vec<_>>());
    call.timeout = Duration::from_secs(30);
    let delivery = host.execute(&call);
    let observed = observe(host, files, &input.key, Some(&input.boot_id))?;
    if observed.state == State::Missing {
        return Err(failed(
            "start",
            match delivery {
                Ok(out) => format!("The phase unit did not load: {}", out.stderr),
                Err(error) => serde_json::to_string(&error).unwrap(),
            },
        ));
    }
    Ok(observed)
}
pub fn restart(host: &mut impl Host, files: &Files, input: &Restart) -> Result<Observation, Error> {
    if !recipe::types::valid_digest(&input.invocation_id, 32) {
        return Err(failed("input", "Invalid prior phase invocation ID"));
    }
    let observed = observe(host, files, &input.key, Some(&input.boot_id))?;
    if observed.invocation_id.as_ref() != Some(&input.invocation_id)
        || observed.state != State::Failed
    {
        return Ok(observed);
    }
    let mut call = Invocation::new(&["systemctl", "restart", "--", &input.key.unit()]);
    call.timeout = Duration::from_secs(30);
    let _ = host.execute(&call);
    let observed = observe(host, files, &input.key, Some(&input.boot_id))?;
    if observed.invocation_id.as_ref() == Some(&input.invocation_id) {
        return Err(unknown("The phase restart has not been observed"));
    }
    Ok(observed)
}

pub struct Inspect;
impl Command for Inspect {
    const NAME: &'static str = "image.inspect-phase";
    const SUMMARY: &'static str = "Observe an image phase's retained service and builder boot.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = Observe;
    type Output = Observation;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Observe, context: &Context) -> Result<Observation, Error> {
        observe(
            &mut recipe::Native(context),
            &Files::system(),
            &input.key,
            input.boot_id.as_deref(),
        )
    }
}
pub struct Begin;
impl Command for Begin {
    const NAME: &'static str = "image.start-phase";
    const SUMMARY: &'static str =
        "Start an immutable recipe phase under its bounded, retained systemd unit.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(120);
    const RESOURCES: &'static [&'static str] = &["image-phase-control"];
    type Input = Start;
    type Output = Observation;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Start, context: &Context) -> Result<Observation, Error> {
        start(&mut recipe::Native(context), &Files::system(), &input)
    }
}
pub struct Retry;
impl Command for Retry {
    const NAME: &'static str = "image.restart-phase";
    const SUMMARY: &'static str =
        "Restart only the failed invocation recorded by the image build attempt.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(120);
    const RESOURCES: &'static [&'static str] = &["image-phase-control"];
    type Input = Restart;
    type Output = Observation;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Restart, context: &Context) -> Result<Observation, Error> {
        restart(&mut recipe::Native(context), &Files::system(), &input)
    }
}
#[cfg(test)]
mod tests;
