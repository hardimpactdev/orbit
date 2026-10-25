//! APT Tool operations. The Gateway owns intent; fixed commands and package observations live here.
use super::tool_output::{lines, safe_text as safe_version};
use crate::command::{Command, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use std::time::Duration;
crate::command_errors! {
    pub enum Error {
        Invalid {} => "tools.apt_input_invalid", "The APT package name is invalid.";
        Failed {} => "tools.apt_operation_failed", "The APT operation failed.";
        Output {} => "tools.apt_output_invalid", "APT returned invalid or incomplete output.";
    }
}
type Result<T> = std::result::Result<T, Error>;
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Empty {}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    package: String,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct VersionOutput {
    version: String,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct CandidateOutput {
    version: Option<String>,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct Package {
    name: String,
    version: Option<String>,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct InventoryOutput {
    packages: Vec<Package>,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct PlanOutput {
    packages: Vec<String>,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct Done {}
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
fn validate(name: &str) -> Result<()> {
    if valid_package(name) {
        Ok(())
    } else {
        Err(Error::Invalid {})
    }
}
fn version(host: &mut impl Host) -> Result<VersionOutput> {
    let text = host.run("/usr/bin/apt-get", &["--version"])?;
    let version = lines(&text).next().unwrap_or_default();
    if !safe_version(version) {
        return Err(Error::Output {});
    }
    Ok(VersionOutput {
        version: version.into(),
    })
}
fn candidate(host: &mut impl Host, package: &str) -> Result<CandidateOutput> {
    validate(package)?;
    let text = host.run("/usr/bin/apt-cache", &["policy", "--", package])?;
    let candidates: Vec<_> = lines(&text)
        .filter_map(|line| line.trim_start().strip_prefix("Candidate:"))
        .collect();
    let [candidate] = candidates.as_slice() else {
        return Err(Error::Output {});
    };
    if !candidate.starts_with(char::is_whitespace) {
        return Err(Error::Output {});
    }
    let version = candidate.trim();
    if !safe_version(version) || version.chars().any(char::is_whitespace) {
        return Err(Error::Output {});
    }
    Ok(CandidateOutput {
        version: (version != "(none)").then(|| version.to_owned()),
    })
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
fn plan(host: &mut impl Host, package: &str) -> Result<PlanOutput> {
    validate(package)?;
    let text = host.run("/usr/bin/apt-get", &["--simulate", "remove", "--", package])?;
    let mut packages = vec![];
    let record =
        regex::Regex::new(r"\ARemv\s+([a-z0-9][a-z0-9+.-]*(?::[a-z0-9][a-z0-9-]*)?)(?:\s|$)")
            .unwrap();
    for line in lines(&text) {
        let Some(rest) = line.trim_start().strip_prefix("Remv") else {
            continue;
        };
        if !rest.is_empty() && !rest.starts_with(char::is_whitespace) {
            continue;
        }
        let captures = record.captures(line).ok_or(Error::Output {})?;
        let observed = &captures[1];
        let name = if observed
            .strip_prefix(package)
            .is_some_and(|suffix| suffix.starts_with(':'))
        {
            package
        } else {
            observed
        };
        if !packages.iter().any(|p| p == name) {
            packages.push(name.to_owned());
        }
    }
    Ok(PlanOutput { packages })
}
fn mutate(host: &mut impl Host, package: &str, remove: bool) -> Result<Done> {
    validate(package)?;
    let mut args = vec![
        "-o",
        "DPkg::Lock::Timeout=300",
        if remove { "remove" } else { "install" },
        "--yes",
    ];
    if !remove {
        args.push("--no-install-recommends");
    }
    args.extend(["--", package]);
    host.run("/usr/bin/apt-get", &args)?;
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
    "tools.apt-version",
    "Read the APT manager version.",
    Empty,
    VersionOutput,
    |_: Empty, host: &mut Native<'_>| version(host)
);
read_command!(
    Candidate,
    "tools.apt-candidate",
    "Read APT's normal candidate for one package.",
    Input,
    CandidateOutput,
    |input: Input, host: &mut Native<'_>| candidate(host, &input.package)
);
read_command!(
    Inventory,
    "tools.apt-inventory",
    "Read installed APT package versions and unverifiable states.",
    Empty,
    InventoryOutput,
    |_: Empty, host: &mut Native<'_>| inventory(host)
);
read_command!(
    Plan,
    "tools.apt-removal-plan",
    "Read every package APT would remove.",
    Input,
    PlanOutput,
    |input: Input, host: &mut Native<'_>| plan(host, &input.package)
);
macro_rules! mutation {
    ($name:ident,$wire:literal,$summary:literal,$remove:literal) => {
        pub struct $name;
        impl Command for $name {
            const NAME: &'static str = $wire;
            const SUMMARY: &'static str = $summary;
            const MUTATES: bool = true;
            const TIMEOUT: Duration = Duration::from_secs(900);
            const RESOURCES: &'static [&'static str] = &["packages"];
            type Input = Input;
            type Output = Done;
            type Error = Error;
            fn privilege(platform: Platform) -> Option<Privilege> {
                super::linux_only(platform, Privilege::Root)
            }
            fn target(_: &Input) -> Option<String> {
                Some("tools:apt".into())
            }
            fn run(input: Input, context: &Context) -> Result<Done> {
                mutate(&mut Native(context), &input.package, $remove)
            }
        }
    };
}
mutation!(
    Install,
    "tools.apt-install",
    "Install exactly the requested APT package with no recommendations.",
    false
);
mutation!(
    Update,
    "tools.apt-update",
    "Update exactly the requested APT package to its normal candidate.",
    false
);
mutation!(
    Remove,
    "tools.apt-remove",
    "Remove the requested APT package without purging or autoremoving.",
    true
);
#[cfg(test)]
mod tests;
