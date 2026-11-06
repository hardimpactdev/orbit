//! Official macOS casks: inspect artifacts before any install, upgrade, or removal.
use super::*;
use serde_json::{Map, Value};

fn object(value: &Value) -> Result<&Map<String, Value>> {
    value.as_object().ok_or(Error::Output {})
}
fn string<'a>(value: &'a Value, key: &str) -> Option<&'a str> {
    value.get(key).and_then(Value::as_str)
}
fn decoded(output: &str) -> Result<Value> {
    serde_json::from_str(output).map_err(|_| Error::Output {})
}
fn cask_version(value: &str) -> bool {
    value != "latest" && safe_version(value)
}
fn installed_version(value: &Value) -> Option<String> {
    string(value, "installed")
        .filter(|v| cask_version(v))
        .map(String::from)
}
fn installed(
    host: &mut impl recipe::Host,
    scope: &homebrew_scope::Output,
    bound: usize,
) -> Result<Value> {
    let output = execute(
        host,
        &brew_invocation(
            &scope.prefix,
            &scope.home,
            false,
            &["info", "--json=v2", "--cask", "--installed"],
        )?,
        bound,
    )?;
    decoded(&output)
}
fn inventory(
    host: &mut impl recipe::Host,
    scope: &homebrew_scope::Output,
) -> Result<InventoryOutput> {
    let data = installed(host, scope, 1_048_576)?;
    let casks = data
        .get("casks")
        .and_then(Value::as_array)
        .ok_or(Error::Output {})?;
    let mut packages = BTreeMap::<String, Package>::new();
    for cask in casks {
        object(cask)?;
        let Some(name) = string(cask, "token").filter(|n| valid_package(n)) else {
            continue;
        };
        if string(cask, "tap") != Some("homebrew/cask") {
            continue;
        }
        let entry = packages.entry(name.into()).or_insert_with(|| Package {
            name: name.into(),
            ..Default::default()
        });
        if let Some(version) = installed_version(cask) {
            entry.versions.push(version);
        } else {
            entry.unverifiable = true;
        }
    }
    Ok(InventoryOutput {
        packages: packages.into_values().collect(),
    })
}
macro_rules! read {
    ($name:ident,$wire:literal,$summary:literal,$input:ty,$output:ty,$run:expr) => {
        pub struct $name;
        impl Command for $name {
            const NAME: &'static str = $wire;
            const SUMMARY: &'static str = $summary;
            const MUTATES: bool = false;
            const TIMEOUT: Duration = Duration::from_secs(60);
            type Input = $input;
            type Output = $output;
            type Error = Error;
            fn privilege(platform: Platform) -> Option<Privilege> {
                (platform == Platform::Macos).then_some(Privilege::Account)
            }
            fn run(input: Self::Input, context: &Context) -> Result<Self::Output> {
                ($run)(input, context)
            }
        }
    };
}
read!(
    Inventory,
    "tool.homebrew-cask-inventory",
    "Read installed official cask versions.",
    Empty,
    InventoryOutput,
    |_: Empty, context: &Context| {
        let scope = homebrew_scope::Inspect::run(homebrew_scope::Empty {}, context)?;
        inventory(&mut recipe::Native(context), &scope)
    }
);
#[cfg(test)]
mod tests;
