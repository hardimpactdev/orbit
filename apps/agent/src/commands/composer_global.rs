//! Composer global package execution and parsing, confined to Orbit's fixed shared scope.
use super::{
    incus,
    tool_output::{json_depth, lines, safe_text},
};
use crate::command::{identity, Command, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use serde_json::value::RawValue;
use std::time::Duration;
crate::command_errors! {
    pub enum Error {
        Invalid {} => "tool.composer_input_invalid", "The Composer package or operation is invalid.";
        Failed {} => "tool.composer_operation_failed", "The Composer operation failed.";
        Output {} => "tool.composer_output_invalid", "Composer returned invalid or incomplete output.";
    }
}
type Result<T> = std::result::Result<T, Error>;
const PACKAGE: &str = r"[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?/[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?";
const FLAGS: [&str; 5] = [
    "--no-interaction",
    "--no-ansi",
    "--no-progress",
    "--no-audit",
    "--with-all-dependencies",
];
const MAX_STREAM: usize = 65_536;
const MAX_CANDIDATE: usize = 32_768;
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Empty {}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    package: String,
}
#[derive(schemars::JsonSchema, Clone, Copy, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum CandidateOperation {
    Install,
    Update,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct CandidateInput {
    package: String,
    operation: CandidateOperation,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct VersionOutput {
    version: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct CandidateOutput {
    version: Option<String>,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Package {
    name: String,
    version: Option<String>,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct InventoryOutput {
    packages: Vec<Package>,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct PlanOutput {
    packages: Vec<String>,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Done {}
trait Host {
    fn run(&mut self, args: &[&str]) -> Result<incus::Output>;
}
struct Native<'a>(&'a Context);
impl Host for Native<'_> {
    fn run(&mut self, args: &[&str]) -> Result<incus::Output> {
        let account = identity::user_name(identity::effective_uid())
            .and_then(|n| identity::passwd(&n))
            .ok_or(Error::Failed {})?;
        let home = format!("HOME={}", account.home);
        let mut argv = vec![
            "-C",
            &account.home,
            &home,
            "COMPOSER_HOME=/opt/orbit/composer",
            "/usr/bin/composer",
        ];
        argv.extend_from_slice(args);
        let output = incus::bounded(self.0, "/usr/bin/env", &argv, None);
        Ok(output)
    }
}
fn run(host: &mut impl Host, args: &[&str]) -> Result<incus::Output> {
    let output = host.run(args)?;
    if output.truncated || output.stdout.len() > MAX_STREAM || output.stderr.len() > MAX_STREAM {
        return Err(Error::Output {});
    }
    Ok(output)
}
fn valid_package(name: &str) -> bool {
    name.len() <= 255
        && regex::Regex::new(&format!(r"\A{PACKAGE}\z"))
            .unwrap()
            .is_match(name)
}
fn validate(name: &str) -> Result<()> {
    if valid_package(name) {
        Ok(())
    } else {
        Err(Error::Invalid {})
    }
}
fn safe_version(value: &str) -> bool {
    safe_text(value) && !value.chars().any(char::is_whitespace)
}
fn success(output: incus::Output) -> Result<incus::Output> {
    if output.code == 0 {
        Ok(output)
    } else {
        Err(Error::Failed {})
    }
}
fn version(host: &mut impl Host) -> Result<VersionOutput> {
    let output = success(run(host, &["--version", "--no-ansi"])?)?;
    let version = lines(&output.stdout).next().unwrap_or_default();
    if !safe_text(version) {
        return Err(Error::Output {});
    }
    Ok(VersionOutput {
        version: version.into(),
    })
}
fn inventory(host: &mut impl Host) -> Result<InventoryOutput> {
    let output = success(run(
        host,
        &["global", "show", "--format=json", "--no-ansi"],
    )?)?;
    let data = output.stdout.as_bytes();
    if json_depth(data) >= 512 {
        return Err(Error::Output {});
    }
    let raw: Box<RawValue> = serde_json::from_slice(data).map_err(|_| Error::Output {})?;
    if raw.get().starts_with('[') {
        let empty: Vec<serde::de::IgnoredAny> =
            serde_json::from_str(raw.get()).map_err(|_| Error::Output {})?;
        return if empty.is_empty() {
            Ok(InventoryOutput { packages: vec![] })
        } else {
            Err(Error::Output {})
        };
    }
    #[derive(Deserialize)]
    struct Inventory {
        installed: Vec<Box<RawValue>>,
    }
    #[derive(Deserialize)]
    struct Entry {
        name: Option<Box<RawValue>>,
        version: Option<Box<RawValue>>,
    }
    let parsed: Inventory = serde_json::from_str(raw.get()).map_err(|_| Error::Output {})?;
    let mut packages = vec![];
    for entry in parsed.installed {
        if !entry.get().starts_with('{') {
            return Err(Error::Output {});
        }
        let entry: Entry = serde_json::from_str(entry.get()).map_err(|_| Error::Output {})?;
        let Some(name) = entry
            .name
            .and_then(|raw| serde_json::from_str::<String>(raw.get()).ok())
            .filter(|s| valid_package(s))
        else {
            continue;
        };
        let version = entry
            .version
            .and_then(|raw| serde_json::from_str::<String>(raw.get()).ok())
            .filter(|v| safe_version(v));
        packages.push(Package { name, version });
    }
    Ok(InventoryOutput { packages })
}
fn operation(
    host: &mut impl Host,
    package: &str,
    operation: &str,
    dry_run: bool,
) -> Result<incus::Output> {
    validate(package)?;
    let target = if operation == "require" {
        format!("{package}:*")
    } else {
        package.into()
    };
    let mut args = vec!["global", operation, &target];
    if dry_run {
        args.push("--dry-run");
    }
    args.extend(FLAGS);
    run(host, &args)
}
fn combined(output: &incus::Output) -> String {
    [&output.stdout, &output.stderr]
        .into_iter()
        .filter(|s| !s.is_empty())
        .map(String::as_str)
        .collect::<Vec<_>>()
        .join("\n")
}
fn missing(output: &str, package: &str) -> bool {
    if output.is_empty() || output.len() > MAX_CANDIDATE {
        return false;
    }
    let text = output.split_whitespace().collect::<Vec<_>>().join(" ");
    text.contains(&format!("Could not find a matching version of package {package}. Check the package spelling, your version constraint and that the package is available in a stability which matches your minimum-stability"))
}
fn dry_run_version(text: &str, package: &str) -> Result<Option<String>> {
    if text.is_empty() || text.len() > MAX_CANDIDATE {
        return Err(Error::Output {});
    }
    let simple = regex::Regex::new(&format!(
        r"\A\s*-\s+(Locking|Installing)\s+({PACKAGE})\s+\(([^\s()]+)\)\s*\z"
    ))
    .unwrap();
    let transition = regex::Regex::new(&format!(
        r"\A\s*-\s+(Upgrading|Downgrading)\s+({PACKAGE})\s+\([^\s()]+\s+=>\s+([^\s()]+)\)\s*\z"
    ))
    .unwrap();
    let malformed = regex::Regex::new(
        r"\A\s*-\s+(?:Locking|Installing|Upgrading|Downgrading|Removing)(?:\s|\z)",
    )
    .unwrap();
    let (mut operations, mut no_op) = (0, 0);
    let mut targets = vec![];
    for line in lines(text) {
        if line == "Nothing to install, update or remove" {
            no_op += 1;
            continue;
        }
        if let Some(record) = simple.captures(line).or_else(|| transition.captures(line)) {
            operations += 1;
            if &record[2] == package {
                targets.push((record[1].to_owned(), record[3].to_owned()));
            }
        } else if malformed.is_match(line) {
            return Err(Error::Output {});
        }
    }
    if no_op == 0 && !targets.is_empty() {
        let result = match targets.as_slice() {
            [(_, version)] => Some(version),
            [(lock, a), (install, b)] if lock == "Locking" && install == "Installing" && a == b => {
                Some(b)
            }
            _ => None,
        }
        .filter(|v| safe_version(v))
        .ok_or(Error::Output {})?;
        return Ok(Some(result.clone()));
    }
    if no_op == 1 && operations == 0 {
        Ok(None)
    } else {
        Err(Error::Output {})
    }
}
fn candidate(host: &mut impl Host, input: CandidateInput) -> Result<CandidateOutput> {
    let op = match input.operation {
        CandidateOperation::Install => "require",
        CandidateOperation::Update => "update",
    };
    let output = operation(host, &input.package, op, true)?;
    let text = combined(&output);
    if output.code != 0 {
        return if missing(&text, &input.package) {
            Ok(CandidateOutput { version: None })
        } else {
            Err(Error::Failed {})
        };
    }
    if let Some(version) = dry_run_version(&text, &input.package)? {
        return Ok(CandidateOutput {
            version: Some(version),
        });
    }
    let packages = inventory(host)?.packages;
    let targets: Vec<_> = packages
        .iter()
        .filter(|p| p.name == input.package)
        .collect();
    let [package] = targets.as_slice() else {
        return Err(Error::Output {});
    };
    Ok(CandidateOutput {
        version: Some(
            package
                .version
                .clone()
                .filter(|v| safe_version(v))
                .ok_or(Error::Output {})?,
        ),
    })
}
fn mutate(host: &mut impl Host, package: &str, op: &str) -> Result<Done> {
    success(operation(host, package, op, false)?)?;
    Ok(Done {})
}
fn plan(host: &mut impl Host, package: &str) -> Result<PlanOutput> {
    success(operation(host, package, "remove", true)?)?;
    Ok(PlanOutput {
        packages: vec![package.into()],
    })
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
            fn privilege(platform: Platform) -> Option<Privilege> {
                super::linux_only(platform, Privilege::Account)
            }
            fn run(input: Self::Input, context: &Context) -> Result<Self::Output> {
                ($run)(input, &mut Native(context))
            }
        }
    };
}
read_command!(
    Version,
    "tool.composer-version",
    "Read the Composer manager version.",
    Empty,
    VersionOutput,
    |_: Empty, host: &mut Native<'_>| version(host)
);
read_command!(
    Candidate,
    "tool.composer-candidate",
    "Read one package's Composer dry-run candidate.",
    CandidateInput,
    CandidateOutput,
    |input: CandidateInput, host: &mut Native<'_>| candidate(host, input)
);
read_command!(
    Inventory,
    "tool.composer-inventory",
    "Read installed Composer global package observations.",
    Empty,
    InventoryOutput,
    |_: Empty, host: &mut Native<'_>| inventory(host)
);
read_command!(
    Plan,
    "tool.composer-removal-plan",
    "Check removal of one Composer root requirement.",
    Input,
    PlanOutput,
    |input: Input, host: &mut Native<'_>| plan(host, &input.package)
);
macro_rules! mutation {
    ($name:ident,$wire:literal,$summary:literal,$op:literal) => {
        pub struct $name;
        impl Command for $name {
            const NAME: &'static str = $wire;
            const SUMMARY: &'static str = $summary;
            const MUTATES: bool = true;
            const TIMEOUT: Duration = Duration::from_secs(900);
            type Input = Input;
            type Output = Done;
            type Error = Error;
            fn privilege(platform: Platform) -> Option<Privilege> {
                super::linux_only(platform, Privilege::Account)
            }
            fn target(_: &Input) -> Option<String> {
                Some("tools:composer".into())
            }
            fn run(input: Input, context: &Context) -> Result<Done> {
                mutate(&mut Native(context), &input.package, $op)
            }
        }
    };
}
mutation!(
    Install,
    "tool.composer-install",
    "Add one Composer global root requirement.",
    "require"
);
mutation!(
    Update,
    "tool.composer-update",
    "Update one Composer global root and its dependencies.",
    "update"
);
mutation!(
    Remove,
    "tool.composer-remove",
    "Remove one Composer global root requirement.",
    "remove"
);
#[cfg(test)]
mod tests;
