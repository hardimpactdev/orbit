//! Homebrew observations use one verified scope and fixed executables on each platform.
pub(super) mod cask;
pub(super) mod formula;
pub(super) mod scan;
use super::{homebrew_scope, recipe, tool_output};
use crate::command::{Command, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use std::{collections::BTreeMap, path::Path, time::Duration};

crate::command_errors! {
    pub enum Error {
        Invalid {} => "tool.homebrew_input_invalid", "The Homebrew command input is invalid.";
        Absent {} => "tool.homebrew_scope_absent", "The Homebrew prefix is absent for the enrolled account.";
        Conflict {} => "tool.homebrew_scope_conflict", "The Homebrew prefix ownership or origin conflicts with the enrolled account.";
        Unreadable {} => "tool.homebrew_scope_unreadable", "The Homebrew prefix cannot be inspected.";
        Failed {} => "tool.homebrew_operation_failed", "The Homebrew operation failed.";
        Output {} => "tool.homebrew_output_invalid", "Homebrew returned invalid or incomplete output.";
        Version {} => "tool.homebrew_version_unsupported", "The Homebrew manager has an unsupported version.";
        Bottle {} => "tool.homebrew_bottle_unavailable", "The Node has no supported Homebrew bottle tag.";
        Protected {} => "tool.homebrew_package_protected", "The Homebrew package is a protected Node prerequisite.";
        Candidate {} => "tool.homebrew_candidate_invalid", "The Homebrew formula did not provide a compatible verified bottle.";
        CaskSource {} => "tool.homebrew_cask_source", "The Homebrew cask is not an official Homebrew cask.";
        CaskArtifact {} => "tool.homebrew_cask_artifact", "The Homebrew cask artifact is not supported.";
        CaskAuthorization {} => "tool.homebrew_cask_authorization", "The Homebrew cask requires interactive or administrator authorization.";
        CaskChecksum {} => "tool.homebrew_cask_checksum", "The Homebrew cask checksum policy was not satisfied.";
        CaskVersion {} => "tool.homebrew_cask_version", "The Homebrew cask version cannot be read.";
    }
}
type Result<T> = std::result::Result<T, Error>;
impl From<homebrew_scope::Error> for Error {
    fn from(error: homebrew_scope::Error) -> Self {
        match error {
            homebrew_scope::Error::Invalid {} => Self::Invalid {},
            homebrew_scope::Error::Absent {} => Self::Absent {},
            homebrew_scope::Error::Conflict {} => Self::Conflict {},
            homebrew_scope::Error::Unreadable {} => Self::Unreadable {},
            homebrew_scope::Error::Failed {} => Self::Failed {},
        }
    }
}
#[derive(schemars::JsonSchema, Clone, Copy, Debug, PartialEq, Serialize)]
#[serde(rename_all = "snake_case")]
pub(super) enum Block {
    UnsupportedSource,
    Protected,
    Dependency,
    VersionUnreadable,
    UnsupportedArtifact,
    BottleUnavailable,
    AuthorizationRequired,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Empty {}
#[derive(schemars::JsonSchema, Clone, Copy, Deserialize)]
pub enum Architecture {
    #[serde(rename = "arm64")]
    Arm64,
    #[serde(rename = "x86_64")]
    X86_64,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct BottleInput {
    architecture: Option<Architecture>,
}
#[derive(schemars::JsonSchema, Debug, PartialEq, Serialize)]
pub struct VersionOutput {
    version: String,
}
#[derive(Debug, PartialEq, Serialize)]
pub struct BottleOutput {
    tag: String,
}
#[derive(schemars::JsonSchema, Debug, Default, PartialEq, Serialize)]
pub struct Package {
    name: String,
    versions: Vec<String>,
    unverifiable: bool,
}
#[derive(schemars::JsonSchema, Debug, PartialEq, Serialize)]
pub struct InventoryOutput {
    packages: Vec<Package>,
}

fn brew_invocation(
    prefix: &Path,
    home: &Path,
    refresh: bool,
    args: &[&str],
) -> Result<recipe::Invocation> {
    let binary = prefix.join("bin/brew");
    let mut argv = vec![binary.to_str().ok_or(Error::Unreadable {})?];
    argv.extend_from_slice(args);
    let mut call = recipe::Invocation::new(&argv)
        .env("HOME", home.to_str().ok_or(Error::Unreadable {})?)
        .env("PATH", &format!("{}/bin:/usr/bin:/bin", prefix.display()));
    for key in [
        "HOMEBREW_NO_AUTO_UPDATE",
        "HOMEBREW_NO_ANALYTICS",
        "HOMEBREW_NO_ENV_HINTS",
        "HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK",
        "HOMEBREW_NO_INSTALL_CLEANUP",
    ] {
        call = call.env(key, "1");
    }
    if refresh {
        call = call.env("HOMEBREW_FORCE_API_AUTO_UPDATE", "1");
    }
    Ok(call)
}
fn execute(
    host: &mut impl recipe::Host,
    call: &recipe::Invocation,
    bound: usize,
) -> Result<String> {
    let output = host.execute(call).map_err(|_| Error::Failed {})?;
    if output.truncated || output.stdout.len() > bound || output.stderr.len() > bound {
        return Err(Error::Output {});
    }
    if output.code != 0 {
        return Err(Error::Failed {});
    }
    Ok(output.stdout)
}
fn version(
    host: &mut impl recipe::Host,
    scope: &homebrew_scope::Output,
    platform: Platform,
) -> Result<VersionOutput> {
    let output = execute(
        host,
        &brew_invocation(&scope.prefix, &scope.home, false, &["--version"])?,
        131_072,
    )?;
    let version = tool_output::lines(&output).next().unwrap_or_default();
    if !tool_output::safe_text(version) {
        return Err(Error::Output {});
    }
    match platform {
        Platform::Linux if version != homebrew_scope::VERSION => return Err(Error::Version {}),
        Platform::Macos
            if !regex::Regex::new(r"\AHomebrew [0-9]+\.[0-9]+\.[0-9]+\z")
                .unwrap()
                .is_match(version) =>
        {
            return Err(Error::Output {})
        }
        _ => {}
    }
    Ok(VersionOutput {
        version: version.into(),
    })
}
fn bottle_tag(
    host: &mut impl recipe::Host,
    platform: Platform,
    architecture: Option<Architecture>,
) -> Result<BottleOutput> {
    if platform == Platform::Macos && architecture.is_none() {
        return Err(Error::Invalid {});
    }
    let argv = match platform {
        Platform::Linux => ["/usr/bin/uname", "-m"],
        Platform::Macos => ["/usr/bin/sw_vers", "-productVersion"],
    };
    let output = execute(host, &recipe::Invocation::new(&argv), 131_072)?;
    let value = tool_output::lines(&output).next().unwrap_or_default();
    let tag = match platform {
        Platform::Linux => match value {
            "x86_64" => "x86_64_linux".into(),
            "arm64" | "aarch64" => "arm64_linux".into(),
            _ => return Err(Error::Bottle {}),
        },
        Platform::Macos => {
            if !tool_output::safe_text(value)
                || !regex::Regex::new(r"\A[0-9]+(?:\.[0-9]+){0,2}\z")
                    .unwrap()
                    .is_match(value)
            {
                return Err(Error::Output {});
            }
            let symbol = match value
                .split('.')
                .next()
                .and_then(|major| major.parse::<u32>().ok())
            {
                Some(27) => "golden_gate",
                Some(26) => "tahoe",
                Some(15) => "sequoia",
                Some(14) => "sonoma",
                Some(13) => "ventura",
                Some(12) => "monterey",
                Some(11) => "big_sur",
                _ => return Err(Error::Bottle {}),
            };
            format!(
                "{}{symbol}",
                if matches!(architecture, Some(Architecture::Arm64)) {
                    "arm64_"
                } else {
                    ""
                }
            )
        }
    };
    Ok(BottleOutput { tag })
}
fn valid_package(name: &str) -> bool {
    name.len() <= 255
        && regex::Regex::new(r"\A[a-z0-9](?:[a-z0-9@+._-]*[a-z0-9])?\+*\z")
            .unwrap()
            .is_match(name)
}
fn safe_version(value: &str) -> bool {
    tool_output::safe_text(value) && !value.chars().any(char::is_whitespace)
}
fn formula_inventory(
    host: &mut impl recipe::Host,
    scope: &homebrew_scope::Output,
) -> Result<InventoryOutput> {
    let output = execute(
        host,
        &brew_invocation(
            &scope.prefix,
            &scope.home,
            false,
            &["list", "--versions", "--formula"],
        )?,
        1_048_576,
    )?;
    let mut packages = BTreeMap::<String, Package>::new();
    for line in output
        .trim_end_matches('\n')
        .split('\n')
        .filter(|line| !line.is_empty())
    {
        let mut fields = line.split(' ');
        let name = fields.next().unwrap_or_default();
        if !valid_package(name) {
            continue;
        }
        let versions: Vec<_> = fields.collect();
        let entry = packages.entry(name.into()).or_insert_with(|| Package {
            name: name.into(),
            ..Default::default()
        });
        if versions.is_empty() || !versions.iter().all(|version| safe_version(version)) {
            entry.unverifiable = true;
        } else {
            entry
                .versions
                .extend(versions.into_iter().map(String::from));
        }
    }
    Ok(InventoryOutput {
        packages: packages.into_values().collect(),
    })
}
macro_rules! scope_read {
    ($type:ident, $name:literal, $summary:literal, $output:ty, $run:expr) => {
        pub struct $type;
        impl Command for $type {
            const NAME: &'static str = $name;
            const SUMMARY: &'static str = $summary;
            const MUTATES: bool = false;
            const TIMEOUT: Duration = Duration::from_secs(60);
            type Input = Empty;
            type Output = $output;
            type Error = Error;
            fn privilege(_: Platform) -> Option<Privilege> {
                Some(Privilege::Account)
            }
            fn run(_: Empty, context: &Context) -> Result<Self::Output> {
                let scope = homebrew_scope::Inspect::run(homebrew_scope::Empty {}, context)?;
                ($run)(&mut recipe::Native(context), &scope, context.platform)
            }
        }
    };
}
scope_read!(
    Version,
    "tool.homebrew-version",
    "Read the verified Homebrew manager version.",
    VersionOutput,
    version
);
scope_read!(
    FormulaInventory,
    "tool.homebrew-formula-inventory",
    "Read installed formula versions without loading formula definitions.",
    InventoryOutput,
    |host: &mut recipe::Native<'_>, scope: &homebrew_scope::Output, _: Platform| formula_inventory(
        host, scope
    )
);
#[cfg(test)]
mod tests;
