//! Explicit Docker provider migration. Inspect documents remain in the private journal.
mod engine;
mod inventory;
#[cfg(all(test, target_os = "linux"))]
mod network_test;
mod packages;
mod recovery;
#[cfg(test)]
mod tests;
mod transaction;

use super::recipe::{self, types::Architecture, Files, Host, Invocation};
use crate::command::{Command, CommandError, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::{
    collections::BTreeMap,
    fs,
    os::unix::fs::{DirBuilderExt, MetadataExt},
    path::{Path, PathBuf},
    time::Duration,
};

const JOURNAL: &str = "/var/lib/orbit/docker-migration";
const DAEMON: &str = "/etc/docker/daemon.json";
const DROPIN: &str = "/etc/systemd/system/docker.service.d/orbit-docker-migration.conf";
const CE: &[&str] = &[
    "docker-ce",
    "docker-ce-cli",
    "containerd.io",
    "docker-buildx-plugin",
    "docker-compose-plugin",
    "docker-ce-rootless-extras",
    "docker-model-plugin",
];
type Result<T> = std::result::Result<T, Failure>;
#[derive(Debug)]
pub struct Failure {
    stage: &'static str,
    report: Option<Value>,
}
fn fail(stage: &'static str) -> Failure {
    Failure {
        stage,
        report: None,
    }
}
impl CommandError for Failure {
    const CODES: &'static [&'static str] = &["docker.migration_failed"];
    fn code(&self) -> &'static str {
        "docker.migration_failed"
    }
    fn message(&self) -> String {
        "Docker migration could not complete. Inspect the reported stage before resuming.".into()
    }
    fn details(&self) -> Value {
        let mut report = self
            .report
            .clone()
            .unwrap_or_else(|| json!({"containers":[]}));
        report["failure"] = json!(format!("docker-migration:{}", self.stage));
        report["resumable"] = json!(true);
        report["healthy"] = json!(false);
        report
    }
}
#[derive(Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    recipe: String,
    snapshot: String,
    architecture: Architecture,
    docker_version: String,
    containerd_version: String,
    process_ids: Vec<String>,
    process_states: BTreeMap<String, String>,
    process_specs: BTreeMap<String, ProcessSpec>,
    #[serde(default)]
    fallback: bool,
}
#[derive(Clone, Deserialize)]
#[serde(deny_unknown_fields)]
struct ProcessSpec {
    name: String,
    hash: String,
    volumes: Vec<Value>,
}
impl Input {
    fn validate(&self) -> Result<()> {
        if self.recipe.is_empty()
            || self.recipe.chars().any(char::is_control)
            || !regex::Regex::new(r"\A[0-9]{8}T[0-9]{6}Z\z")
                .unwrap()
                .is_match(&self.snapshot)
            || !recipe::types::valid_version(&self.docker_version)
            || !recipe::types::valid_version(&self.containerd_version)
            || self
                .process_ids
                .iter()
                .any(|id| id.is_empty() || !id.bytes().all(|b| b.is_ascii_digit()))
            || self.process_states.iter().any(|(id, state)| {
                !self.process_ids.contains(id) || !["running", "stopped"].contains(&state.as_str())
            })
            || self
                .process_specs
                .keys()
                .any(|id| !self.process_ids.contains(id))
        {
            return Err(fail("input"));
        }
        Ok(())
    }
}
#[derive(Clone, Deserialize, Serialize)]
struct State {
    recipe: String,
    containers: Vec<Value>,
    volumes: Vec<Value>,
    networks: Vec<Value>,
    images: Vec<Value>,
    image_refs: BTreeMap<String, Option<String>>,
    driver: Value,
    metadata_roots: BTreeMap<String, PathBuf>,
    defaults: Value,
    archives: BTreeMap<String, PathBuf>,
    committed: BTreeMap<String, String>,
    stage: String,
    daemon: Option<String>,
    fallback: bool,
    swapped: bool,
    prepared: bool,
    stopped: bool,
    #[serde(default)]
    swapping: bool,
    #[serde(default)]
    images_preserved: bool,
    #[serde(default)]
    metadata_backed_up: bool,
    #[serde(default)]
    policies_disabled: bool,
}
trait DockerHost: Host {
    fn api(
        &mut self,
        method: &str,
        path: &str,
        body: Option<&Value>,
        missing: bool,
    ) -> Result<Value>;
    fn free_space(&mut self, path: &Path) -> Result<u64>;
}
struct Native<'a> {
    commands: recipe::Native<'a>,
    context: &'a Context,
    runtime: tokio::runtime::Runtime,
    client: reqwest::Client,
}
impl Host for Native<'_> {
    fn execute(
        &mut self,
        invocation: &Invocation,
    ) -> std::result::Result<recipe::Output, recipe::Failure> {
        self.commands.execute(invocation)
    }
    fn wait(&mut self, delay: Duration) -> std::result::Result<(), recipe::Failure> {
        self.commands.wait(delay)
    }
}
impl DockerHost for Native<'_> {
    fn api(
        &mut self,
        method: &str,
        path: &str,
        body: Option<&Value>,
        missing: bool,
    ) -> Result<Value> {
        let method =
            reqwest::Method::from_bytes(method.as_bytes()).map_err(|_| fail("docker-api"))?;
        let prefix = if method == reqwest::Method::GET {
            ""
        } else {
            "/v1.52"
        };
        let request = self
            .client
            .request(method, format!("http://localhost{prefix}{path}"))
            .timeout(self.context.remaining().min(Duration::from_secs(120)));
        let request = if let Some(body) = body {
            request.json(body)
        } else {
            request
        };
        self.runtime.block_on(async {
            let mut response = request.send().await.map_err(|_| fail("docker-api"))?;
            if missing && response.status() == reqwest::StatusCode::NOT_FOUND {
                return Ok(Value::Null);
            }
            if !response.status().is_success() {
                return Err(fail("docker-api"));
            }
            let mut bytes = vec![];
            while let Some(chunk) = response.chunk().await.map_err(|_| fail("docker-api"))? {
                if bytes.len().saturating_add(chunk.len()) > 64 * 1024 * 1024 {
                    return Err(fail("docker-api"));
                }
                bytes.extend_from_slice(&chunk);
            }
            if bytes.is_empty() {
                Ok(json!({}))
            } else {
                serde_json::from_slice(&bytes).map_err(|_| fail("docker-api"))
            }
        })
    }
    fn free_space(&mut self, path: &Path) -> Result<u64> {
        let stat = rustix::fs::statvfs(path).map_err(|_| fail("space"))?;
        Ok(stat.f_bavail.saturating_mul(stat.f_frsize))
    }
}
fn command(host: &mut impl Host, args: &[&str], required: bool) -> Result<recipe::Output> {
    recipe::run(host, &Invocation::new(args), required).map_err(|_| fail("remote"))
}
fn get(host: &mut impl DockerHost, path: &str) -> Result<Value> {
    host.api("GET", path, None, false)
}
fn inspect(host: &mut impl DockerHost, reference: &str, missing: bool) -> Result<Value> {
    host.api(
        "GET",
        &format!(
            "/containers/{}/json",
            quote(reference.trim_start_matches('/'))
        ),
        None,
        missing,
    )
}
fn quote(value: &str) -> String {
    value
        .bytes()
        .map(|b| {
            if b.is_ascii_alphanumeric() || b"-_.~".contains(&b) {
                (b as char).to_string()
            } else {
                format!("%{b:02X}")
            }
        })
        .collect()
}
fn text(value: &Value) -> &str {
    value.as_str().unwrap_or_default()
}
fn array(value: &Value) -> &[Value] {
    value.as_array().map(Vec::as_slice).unwrap_or_default()
}
fn truth(value: &Value) -> bool {
    match value {
        Value::Null => false,
        Value::Bool(b) => *b,
        Value::String(s) => !s.is_empty(),
        Value::Array(a) => !a.is_empty(),
        Value::Object(o) => !o.is_empty(),
        Value::Number(n) => n.as_f64() != Some(0.0),
    }
}
fn managed(c: &Value) -> bool {
    c["Config"]["Labels"]["orbit.managed"] == "true"
}
fn record(files: &Files, name: &str, value: &impl Serialize) -> Result<()> {
    let path = files.path(JOURNAL).join(name);
    files
        .publish(
            &path,
            &serde_json::to_vec(value).map_err(|_| fail("journal"))?,
            0o600,
            files.uid,
            files.gid,
            false,
        )
        .map_err(|_| fail("journal"))?;
    fs::File::open(files.path(JOURNAL))
        .and_then(|f| f.sync_all())
        .map_err(|_| fail("journal"))
}
fn stage(files: &Files, state: &mut State, name: &str) -> Result<()> {
    state.stage = name.into();
    record(files, "inventory.json", state)
}
fn daemon_bytes(files: &Files) -> Result<Option<Vec<u8>>> {
    let path = files.path(DAEMON);
    if path.is_symlink() {
        return Err(fail("daemon-config"));
    }
    match fs::read(path) {
        Ok(bytes) => Ok(Some(bytes)),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(None),
        Err(_) => Err(fail("daemon-config")),
    }
}
fn daemon_unchanged(files: &Files, state: &State) -> Result<bool> {
    Ok(daemon_bytes(files)?.map(|bytes| hex(&bytes)) == state.daemon)
}
fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|b| format!("{b:02x}")).collect()
}
fn unhex(value: &str) -> Result<Vec<u8>> {
    if !value.len().is_multiple_of(2) || !value.is_ascii() {
        return Err(fail("journal"));
    }
    (0..value.len())
        .step_by(2)
        .map(|i| u8::from_str_radix(&value[i..i + 2], 16).map_err(|_| fail("journal")))
        .collect()
}
fn private_journal(files: &Files, create: bool) -> Result<()> {
    let journal = files.path(JOURNAL);
    if !journal.exists() && !journal.is_symlink() {
        if !create {
            return Ok(());
        }
        fs::DirBuilder::new()
            .recursive(true)
            .mode(0o700)
            .create(&journal)
            .map_err(|_| fail("journal-permissions"))?;
    }
    for path in [
        journal.clone(),
        journal.parent().unwrap().into(),
        journal.join("inventory.json"),
    ] {
        let meta = match fs::symlink_metadata(&path) {
            Ok(meta) => meta,
            Err(e)
                if e.kind() == std::io::ErrorKind::NotFound
                    && path == journal.join("inventory.json") =>
            {
                continue
            }
            Err(_) => return Err(fail("journal-permissions")),
        };
        let parent = path == journal.parent().unwrap();
        if meta.uid() != files.uid
            || meta.is_symlink()
            || meta.mode() & (if parent { 0o022 } else { 0o077 }) != 0
            || if path == journal.join("inventory.json") {
                !meta.is_file()
            } else {
                !meta.is_dir()
            }
        {
            return Err(fail("journal-permissions"));
        }
    }
    Ok(())
}
#[derive(Clone, Copy, PartialEq)]
enum Mode {
    Plan,
    Prepare,
    Restore,
    Report,
}
fn execute(input: Input, context: &Context, mode: Mode) -> Result<Value> {
    input.validate()?;
    let files = Files::system();
    let runtime = tokio::runtime::Builder::new_current_thread()
        .enable_all()
        .build()
        .map_err(|_| fail("remote"))?;
    let client = {
        let _entered = runtime.enter();
        reqwest::Client::builder()
            .unix_socket("/var/run/docker.sock")
            .redirect(reqwest::redirect::Policy::none())
            .build()
            .map_err(|_| fail("docker-api"))?
    };
    let mut host = Native {
        commands: recipe::Native(context),
        context,
        runtime,
        client,
    };
    let release =
        fs::read_to_string(files.path("/etc/os-release")).map_err(|_| fail("platform"))?;
    let release = super::os_release::parse(&release).map_err(|_| fail("platform"))?;
    if release.id != "ubuntu" || release.codename != "resolute" {
        return Err(fail("platform"));
    }
    if recipe::apt::architecture(&mut host).map_err(|_| fail("architecture"))?
        != input.architecture.name()
    {
        return Err(fail("architecture"));
    }
    private_journal(&files, mode != Mode::Plan && mode != Mode::Report)?;
    transaction::run(&mut host, &files, &input, mode)
}
macro_rules! migration_command {
    ($type:ident, $name:literal, $mode:ident, $mutates:literal, $summary:literal) => {
        pub struct $type;
        impl Command for $type {
            const NAME: &'static str = $name;
            const SUMMARY: &'static str = $summary;
            const MUTATES: bool = $mutates;
            const TIMEOUT: Duration = Duration::from_secs(3600);
            const RESOURCES: &'static [&'static str] = &["packages"];
            type Input = Input;
            type Output = Value;
            type Error = Failure;
            fn privilege(platform: Platform) -> Option<Privilege> {
                super::linux_only(platform, Privilege::Root)
            }
            fn target(_: &Input) -> Option<String> {
                Some("docker-migration".into())
            }
            fn run(input: Input, context: &Context) -> Result<Value> {
                execute(input, context, Mode::$mode)
            }
        }
    };
}
migration_command!(
    Plan,
    "docker.plan-migration",
    Plan,
    false,
    "Inspect Docker CE and plan a provider migration without changing the Node."
);
migration_command!(
    Prepare,
    "docker.prepare-migration",
    Prepare,
    true,
    "Preserve Docker state and migrate CE to the recipe's Ubuntu provider."
);
migration_command!(
    Restore,
    "docker.restore-migration-policies",
    Restore,
    true,
    "Restore original container policies after an operator restores Docker CE."
);
migration_command!(
    Report,
    "docker.report-migration",
    Report,
    false,
    "Report final container states without exposing private Docker inventory."
);
