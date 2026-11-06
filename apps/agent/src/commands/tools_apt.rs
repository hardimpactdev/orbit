//! APT Tool operations. The Gateway owns intent; fixed commands and package observations live here.
use super::tool_output::safe_text as safe_version;
use crate::command::{Command, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use std::time::Duration;
crate::command_errors! {
    pub enum Error {
        Invalid {} => "tool.apt_input_invalid", "The APT package name is invalid.";
        Failed {} => "tool.apt_operation_failed", "The APT operation failed.";
        Output {} => "tool.apt_output_invalid", "APT returned invalid or incomplete output.";
    }
}
type Result<T> = std::result::Result<T, Error>;
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
    fn run(&mut self, binary: &str, args: &[&str]) -> Result<String>;
}
struct Native<'a>(&'a Context);
impl Host for Native<'_> {
    fn run(&mut self, binary: &str, args: &[&str]) -> Result<String> {
        if self.0.remaining().is_zero() {
            return Err(Error::Failed {});
        }
        let mut argv = vec!["LC_ALL=C", binary];
        argv.extend_from_slice(args);
        let output = super::incus::bounded(self.0, "/usr/bin/env", &argv, None);
        if output.code != 0 {
            return Err(Error::Failed {});
        }
        if output.truncated {
            return Err(Error::Output {});
        }
        Ok(output.stdout)
    }
}
fn valid_package(name: &str) -> bool {
    (2..=128).contains(&name.len())
        && name.as_bytes()[0].is_ascii_alphanumeric()
        && name
            .bytes()
            .all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b"+.-".contains(&b))
        && name.bytes().any(|b| b.is_ascii_lowercase())
}
fn inventory(host: &mut impl Host) -> Result<InventoryOutput> {
    let text = host.run(
        "/usr/bin/dpkg-query",
        &[
            "--show",
            "--showformat=${Package}\\t${Status}\\t${Version}\\n",
        ],
    )?;
    let mut packages = vec![];
    for line in text.split('\n').filter(|line| !line.is_empty()) {
        let fields: Vec<_> = line.split('\t').collect();
        let [name, status, version] = fields.as_slice() else {
            return Err(Error::Output {});
        };
        if !valid_package(name)
            || matches!(
                *status,
                "deinstall ok config-files"
                    | "deinstall ok not-installed"
                    | "purge ok config-files"
                    | "purge ok not-installed"
                    | "unknown ok not-installed"
            )
        {
            continue;
        }
        packages.push(Package {
            name: (*name).into(),
            version: (*status == "install ok installed" && safe_version(version))
                .then(|| (*version).into()),
        });
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
    "tool.apt-inventory",
    "Read installed APT package versions and unverifiable states.",
    Empty,
    InventoryOutput,
    |_: Empty, host: &mut Native<'_>| inventory(host)
);
#[cfg(test)]
mod tests;
