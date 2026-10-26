//! One native scope read and independent formula/cask scans; names omitted by info stay visible.
use super::*;
use serde_json::Value;
use std::collections::BTreeSet;
#[derive(schemars::JsonSchema, Debug, PartialEq, Serialize)]
#[serde(rename_all = "snake_case")]
enum State {
    Complete,
    Incomplete,
    Unsupported,
}
#[derive(schemars::JsonSchema, Debug, PartialEq, Serialize)]
pub struct Observation {
    name: String,
    installed_version: Option<String>,
    dependency: bool,
    block: Option<Block>,
}
#[derive(schemars::JsonSchema, Debug, PartialEq, Serialize)]
pub struct Inventory {
    state: State,
    packages: Vec<Observation>,
}
#[derive(schemars::JsonSchema, Debug, PartialEq, Serialize)]
pub struct Output {
    formulae: Inventory,
    casks: Inventory,
}
fn formula_observations(data: &Value, tag: &str) -> Result<Vec<Observation>> {
    let formulae = data
        .get("formulae")
        .and_then(Value::as_array)
        .ok_or(Error::Output {})?;
    if data.get("casks") != Some(&Value::Array(vec![])) {
        return Err(Error::Output {});
    }
    let mut seen = BTreeSet::new();
    let mut packages = vec![];
    for value in formulae {
        let name = value
            .get("name")
            .and_then(Value::as_str)
            .filter(|n| valid_package(n))
            .ok_or(Error::Output {})?;
        if !seen.insert(name) {
            return Err(Error::Output {});
        }
        let (explicit, installed_version) = formula::kegs(value)?;
        let block = if !formula::official(value, name) {
            Some(Block::UnsupportedSource)
        } else if matches!(name, "wireguard-go" | "wireguard-tools") {
            Some(Block::Protected)
        } else if !explicit {
            Some(Block::Dependency)
        } else {
            let disabled = match value.get("disabled") {
                Some(v) => v.as_bool().ok_or(Error::Output {})?,
                None => false,
            };
            if disabled {
                Some(Block::UnsupportedArtifact)
            } else if !formula::bottle_available(value, tag) {
                Some(Block::BottleUnavailable)
            } else if installed_version.is_none() {
                Some(Block::VersionUnreadable)
            } else {
                None
            }
        };
        packages.push(Observation {
            name: name.into(),
            installed_version,
            dependency: !explicit,
            block,
        });
    }
    Ok(packages)
}
fn names(text: &str) -> Result<BTreeSet<String>> {
    let trimmed = text.trim_end_matches(['\r', '\n']);
    if trimmed.is_empty() {
        return Ok(BTreeSet::new());
    }
    let mut names = BTreeSet::new();
    // CRLF is one separator, as in Homebrew's text output contract.
    for name in tool_output::lines(&trimmed.replace("\r\n", "\n")) {
        if !valid_package(name) || !names.insert(name.into()) {
            return Err(Error::Output {});
        }
    }
    Ok(names)
}
fn inventory(
    host: &mut impl recipe::Host,
    scope: &homebrew_scope::Output,
    tag: &str,
    is_formula: bool,
) -> Result<Vec<Observation>> {
    let kind = if is_formula { "--formula" } else { "--cask" };
    let output = execute(
        host,
        &brew_invocation(
            &scope.prefix,
            &scope.home,
            false,
            &["info", "--json=v2", kind, "--installed"],
        )?,
        1_048_576,
    )?;
    if tool_output::json_depth(output.as_bytes()) >= 64 {
        return Err(Error::Output {});
    }
    let data: Value = serde_json::from_str(&output).map_err(|_| Error::Output {})?;
    let mut packages = if is_formula {
        formula_observations(&data, tag)?
    } else {
        cask::discoveries(&data, scope, tag)?
            .into_iter()
            .map(|v| Observation {
                name: v.name,
                installed_version: v.installed_version,
                dependency: false,
                block: v.block,
            })
            .collect()
    };
    let output = execute(
        host,
        &brew_invocation(&scope.prefix, &scope.home, false, &["list", kind, "-1"])?,
        131_072,
    )?;
    let mut names = names(&output)?;
    for package in &packages {
        if !names.remove(&package.name) {
            return Err(Error::Output {});
        }
    }
    for name in names {
        let block = if is_formula && matches!(name.as_str(), "wireguard-go" | "wireguard-tools") {
            Block::Protected
        } else {
            Block::UnsupportedSource
        };
        packages.push(Observation {
            name,
            installed_version: None,
            dependency: false,
            block: Some(block),
        });
    }
    packages.sort_by(|a, b| a.name.cmp(&b.name));
    Ok(packages)
}
fn observed(result: Result<Vec<Observation>>) -> Inventory {
    match result {
        Ok(packages) => Inventory {
            state: State::Complete,
            packages,
        },
        Err(_) => Inventory {
            state: State::Incomplete,
            packages: vec![],
        },
    }
}
fn scan(
    host: &mut impl recipe::Host,
    scope: &homebrew_scope::Output,
    platform: Platform,
    input: BottleInput,
) -> Result<Output> {
    let tag = bottle_tag(host, platform, input.architecture)?.tag;
    let formulae = observed(inventory(host, scope, &tag, true));
    let casks = if platform == Platform::Macos {
        observed(inventory(host, scope, &tag, false))
    } else {
        Inventory {
            state: State::Unsupported,
            packages: vec![],
        }
    };
    Ok(Output { formulae, casks })
}
pub struct Scan;
impl Command for Scan {
    const NAME: &'static str = "tool.homebrew-scan";
    const SUMMARY: &'static str =
        "Read formula and cask inventories independently without changing Tool intent.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(180);
    type Input = BottleInput;
    type Output = Output;
    type Error = Error;
    fn privilege(_: Platform) -> Option<Privilege> {
        Some(Privilege::Account)
    }
    fn run(input: BottleInput, context: &Context) -> Result<Output> {
        if context.platform == Platform::Macos && input.architecture.is_none() {
            return Err(Error::Invalid {});
        }
        let scope = homebrew_scope::Inspect::run(homebrew_scope::Empty {}, context)?;
        scan(
            &mut recipe::Native(context),
            &scope,
            context.platform,
            input,
        )
    }
}
#[cfg(test)]
mod tests;
