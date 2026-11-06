//! Composer global package execution and parsing, confined to Orbit's fixed shared scope.
use super::{
    incus,
    tool_output::{json_depth, safe_text},
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
const MAX_STREAM: usize = 65_536;
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Empty {}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Package {
    name: String,
    version: Option<String>,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct InventoryOutput {
    packages: Vec<Package>,
}
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
    Inventory,
    "tool.composer-inventory",
    "Read installed Composer global package observations.",
    Empty,
    InventoryOutput,
    |_: Empty, host: &mut Native<'_>| inventory(host)
);
#[cfg(test)]
mod tests;
