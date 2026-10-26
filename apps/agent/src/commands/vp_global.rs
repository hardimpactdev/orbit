//! Typed Vite+ global operations. Scope discovery and executable arguments stay on the Node.
use super::{
    tool_output::{json_depth as depth, lines, safe_text as safe},
    vp_scope,
};
use crate::command::{identity, Command, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use serde_json::value::RawValue;
use std::os::unix::process::CommandExt;
use std::{
    collections::BTreeSet,
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};
use tokio::io::{AsyncRead, AsyncReadExt};
crate::command_errors! {
    pub enum Error {
        Invalid {} => "tool.vp_input_invalid", "The Vite+ package coordinate is invalid.";
        Protected {} => "tool.vp_package_protected", "The recipe's pnpm runtime is protected.";
        Absent {} => "tool.vp_scope_absent", "The Vite+ global scope is absent for the enrolled account.";
        Conflict {} => "tool.vp_scope_conflict", "The Vite+ global scope conflicts with the enrolled account.";
        Unreadable {} => "tool.vp_scope_unreadable", "The Vite+ global scope cannot be inspected.";
        Failed {} => "tool.vp_operation_failed", "The Vite+ operation failed.";
        Output {} => "tool.vp_output_invalid", "Vite+ returned invalid or incomplete output.";
    }
}
type Result<T> = std::result::Result<T, Error>;
impl From<vp_scope::Error> for Error {
    fn from(value: vp_scope::Error) -> Self {
        match value {
            vp_scope::Error::Absent {} => Self::Absent {},
            vp_scope::Error::Conflict {} => Self::Conflict {},
            vp_scope::Error::Unreadable {} => Self::Unreadable {},
        }
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Empty {}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PackageInput {
    pub package: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct VersionOutput {
    pub version: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Package {
    pub name: String,
    pub version: Option<String>,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct InventoryOutput {
    pub packages: Vec<Package>,
    pub scan_complete: bool,
}
#[derive(schemars::JsonSchema, Serialize)]
pub struct RemovalOutput {
    pub packages: Vec<String>,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Done {}
const MAX_OUTPUT: u64 = 1_048_576;
trait Host {
    fn run(&mut self, args: &[&str]) -> Result<Vec<u8>>;
}
struct Native<'a> {
    context: &'a Context,
    binary: PathBuf,
    home: PathBuf,
}
impl<'a> Native<'a> {
    fn new(context: &'a Context) -> Result<Self> {
        let binary = vp_scope::Inspect::run(vp_scope::Input {}, context)?.binary;
        let account = identity::user_name(identity::effective_uid())
            .and_then(|name| identity::passwd(&name))
            .ok_or(Error::Unreadable {})?;
        Ok(Self {
            context,
            binary,
            home: account.home.into(),
        })
    }
}
async fn stream(mut pipe: impl AsyncRead + Unpin) -> std::io::Result<Vec<u8>> {
    let mut data = Vec::new();
    (&mut pipe)
        .take(MAX_OUTPUT + 1)
        .read_to_end(&mut data)
        .await?;
    tokio::io::copy(&mut pipe, &mut tokio::io::sink()).await?;
    Ok(data)
}
struct ProcessGroup(u32);
impl Drop for ProcessGroup {
    fn drop(&mut self) {
        if let Some(pid) = rustix::process::Pid::from_raw(self.0 as i32) {
            let _ = rustix::process::kill_process_group(pid, rustix::process::Signal::KILL);
        }
    }
}
impl Host for Native<'_> {
    fn run(&mut self, args: &[&str]) -> Result<Vec<u8>> {
        if self.context.remaining().is_zero() {
            return Err(Error::Failed {});
        }
        let mut process = Process::new(&self.binary);
        process
            .args(args)
            .env(
                "VP_HOME",
                self.binary
                    .parent()
                    .and_then(Path::parent)
                    .ok_or(Error::Unreadable {})?,
            )
            .env("HOME", &self.home)
            .current_dir(&self.home)
            .stdin(Stdio::null())
            .stdout(Stdio::piped())
            .stderr(Stdio::piped())
            .process_group(0);
        let runtime = tokio::runtime::Builder::new_current_thread()
            .enable_all()
            .build()
            .map_err(|_| Error::Failed {})?;
        runtime.block_on(async {
            let mut process = tokio::process::Command::from(process);
            process.kill_on_drop(true);
            let mut child = process.spawn().map_err(|_| Error::Failed {})?;
            let group = ProcessGroup(child.id().ok_or(Error::Failed {})?);
            let stdout = child.stdout.take().ok_or(Error::Failed {})?;
            let stderr = child.stderr.take().ok_or(Error::Failed {})?;
            let outcome = tokio::time::timeout(self.context.remaining(), async {
                tokio::join!(child.wait(), stream(stdout), stream(stderr))
            })
            .await;
            drop(group);
            let _ = child.wait().await;
            let (status, stdout, stderr) = outcome.map_err(|_| Error::Failed {})?;
            let stdout = stdout.map_err(|_| Error::Failed {})?;
            let stderr = stderr.map_err(|_| Error::Failed {})?;
            if !status.map_err(|_| Error::Failed {})?.success() {
                return Err(Error::Failed {});
            }
            if stdout.len() as u64 > MAX_OUTPUT || stderr.len() as u64 > MAX_OUTPUT {
                return Err(Error::Output {});
            }
            Ok(stdout)
        })
    }
}
fn valid_package(package: &str) -> bool {
    package.len() <= 214
        && regex::Regex::new(
            r"\A(?:[a-z0-9][a-z0-9._~-]*|@[a-z0-9][a-z0-9._~-]*/[a-z0-9][a-z0-9._~-]*)\z",
        )
        .unwrap()
        .is_match(package)
}
fn validate(package: &str, mutation: bool) -> Result<()> {
    if !valid_package(package) {
        return Err(Error::Invalid {});
    }
    if mutation && package == "pnpm" {
        return Err(Error::Protected {});
    }
    Ok(())
}
fn version(host: &mut impl Host) -> Result<VersionOutput> {
    let data = host.run(&["--version"])?;
    let text = std::str::from_utf8(&data).map_err(|_| Error::Output {})?;
    let first = lines(text).next().unwrap_or_default();
    if !safe(first) {
        return Err(Error::Output {});
    }
    Ok(VersionOutput {
        version: first.into(),
    })
}
fn candidate(host: &mut impl Host, package: &str) -> Result<VersionOutput> {
    validate(package, false)?;
    let data = host.run(&["info", package, "version", "--json"])?;
    let value: String = serde_json::from_slice(&data).map_err(|_| Error::Output {})?;
    if !safe(&value) {
        return Err(Error::Output {});
    }
    Ok(VersionOutput { version: value })
}
fn inventory(host: &mut impl Host) -> Result<InventoryOutput> {
    let data = host.run(&["list", "-g", "--json"])?;
    let nesting = depth(&data);
    if data.len() as u64 > MAX_OUTPUT || nesting >= 512 {
        return Err(Error::Output {});
    }
    #[derive(Deserialize)]
    struct Entry {
        name: String,
        version: Option<Box<RawValue>>,
    }
    let entries: Vec<Entry> = serde_json::from_slice(&data).map_err(|_| Error::Output {})?;
    let mut result = InventoryOutput {
        packages: vec![],
        scan_complete: nesting < 64,
    };
    let mut seen = BTreeSet::new();
    for entry in entries {
        let version = entry
            .version
            .and_then(|raw| serde_json::from_str::<String>(raw.get()).ok())
            .filter(|v| safe(v));
        result.scan_complete &=
            safe(&entry.name) && version.is_some() && seen.insert(entry.name.clone());
        result.packages.push(Package {
            name: entry.name,
            version,
        });
    }
    Ok(result)
}
#[derive(Clone, Copy)]
enum Operation {
    Install,
    Update,
    Remove,
    Plan,
}
fn operation(host: &mut impl Host, package: &str, operation: Operation) -> Result<Done> {
    validate(package, true)?;
    let args = match operation {
        Operation::Install => vec!["install", "-g", package, "--node", "lts"],
        Operation::Update => vec!["update", "-g", package, "--reinstall-node-mismatch"],
        Operation::Remove => vec!["remove", "-g", package],
        Operation::Plan => vec!["remove", "-g", "--dry-run", package],
    };
    host.run(&args)?;
    Ok(Done {})
}
macro_rules! read_command {
    ($name:ident,$wire:literal,$summary:literal,$input:ty,$output:ty,$run:expr) => {
        pub struct $name;
        impl Command for $name {
            const NAME: &'static str = $wire;
            const SUMMARY: &'static str = $summary;
            const MUTATES: bool = false;
            const TIMEOUT: Duration = Duration::from_secs(900);
            type Input = $input;
            type Output = $output;
            type Error = Error;
            fn privilege(_: Platform) -> Option<Privilege> {
                Some(Privilege::Account)
            }
            fn run(input: Self::Input, context: &Context) -> Result<Self::Output> {
                ($run)(input, context)
            }
        }
    };
}
read_command!(
    Version,
    "tool.vp-version",
    "Read the account's Vite+ manager version.",
    Empty,
    VersionOutput,
    |_: Empty, context: &Context| version(&mut Native::new(context)?)
);
read_command!(
    Candidate,
    "tool.vp-candidate",
    "Read Vite+'s normal registry candidate for one package.",
    PackageInput,
    VersionOutput,
    |input: PackageInput, context: &Context| {
        validate(&input.package, false)?;
        candidate(&mut Native::new(context)?, &input.package)
    }
);
read_command!(
    Inventory,
    "tool.vp-inventory",
    "Read Vite+ global package observations without changing Tool intent.",
    Empty,
    InventoryOutput,
    |_: Empty, context: &Context| inventory(&mut Native::new(context)?)
);
read_command!(
    Plan,
    "tool.vp-removal-plan",
    "Check removal of exactly one Vite+ global package.",
    PackageInput,
    RemovalOutput,
    |input: PackageInput, context: &Context| {
        validate(&input.package, true)?;
        operation(&mut Native::new(context)?, &input.package, Operation::Plan)?;
        Ok(RemovalOutput {
            packages: vec![input.package],
        })
    }
);
macro_rules! mutation {
    ($name:ident,$wire:literal,$summary:literal,$op:ident) => {
        pub struct $name;
        impl Command for $name {
            const NAME: &'static str = $wire;
            const SUMMARY: &'static str = $summary;
            const MUTATES: bool = true;
            const TIMEOUT: Duration = Duration::from_secs(900);
            type Input = PackageInput;
            type Output = Done;
            type Error = Error;
            fn privilege(_: Platform) -> Option<Privilege> {
                Some(Privilege::Account)
            }
            fn target(_: &PackageInput) -> Option<String> {
                Some("tools:vp-global".into())
            }
            fn run(input: PackageInput, context: &Context) -> Result<Done> {
                validate(&input.package, true)?;
                operation(&mut Native::new(context)?, &input.package, Operation::$op)
            }
        }
    };
}
mutation!(
    Install,
    "tool.vp-install",
    "Install one Vite+ global package with its normal Node selection.",
    Install
);
mutation!(
    Update,
    "tool.vp-update",
    "Update one Vite+ global package and reconcile its Node runtime.",
    Update
);
mutation!(
    Remove,
    "tool.vp-remove",
    "Remove exactly one Vite+ global package.",
    Remove
);
#[cfg(test)]
mod tests;
