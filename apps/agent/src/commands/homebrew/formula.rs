//! Official Core formula selection and mutations share the same bottle checks.
use super::*;
use serde_json::Value;

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    package: String,
    architecture: Option<Architecture>,
}
#[derive(schemars::JsonSchema, Debug, PartialEq, Serialize)]
pub struct CandidateOutput {
    version: Option<String>,
}
#[derive(schemars::JsonSchema, Debug, PartialEq, Serialize)]
pub struct Done {}
#[derive(schemars::JsonSchema, Debug, PartialEq, Serialize)]
pub struct AdoptionOutput {
    installed_version: Option<String>,
    block: Option<Block>,
}
#[derive(Clone, Copy)]
enum Operation {
    Install,
    Update,
    Remove,
}
fn validate(input: &Input, platform: Platform, mutation: bool, needs_bottle: bool) -> Result<()> {
    if !valid_package(&input.package)
        || (needs_bottle && platform == Platform::Macos && input.architecture.is_none())
    {
        return Err(Error::Invalid {});
    }
    if mutation && matches!(input.package.as_str(), "wireguard-go" | "wireguard-tools") {
        return Err(Error::Protected {});
    }
    Ok(())
}
fn parse_formula(output: &str) -> Result<Value> {
    let value: Value = serde_json::from_str(output).map_err(|_| Error::Output {})?;
    let formulae = value
        .get("formulae")
        .and_then(Value::as_array)
        .ok_or(Error::Output {})?;
    let casks = value
        .get("casks")
        .and_then(Value::as_array)
        .ok_or(Error::Output {})?;
    if formulae.len() != 1 || !casks.is_empty() || !formulae[0].is_object() {
        return Err(Error::Output {});
    }
    Ok(formulae[0].clone())
}
pub(super) fn official(formula: &Value, package: &str) -> bool {
    formula.get("name").and_then(Value::as_str) == Some(package)
        && formula.get("full_name").and_then(Value::as_str) == Some(package)
        && formula.get("tap").and_then(Value::as_str) == Some("homebrew/core")
}
pub(super) fn bottle_available(formula: &Value, tag: &str) -> bool {
    if formula.pointer("/versions/bottle").and_then(Value::as_bool) != Some(true) {
        return false;
    }
    let Some(files) = formula
        .pointer("/bottle/stable/files")
        .and_then(Value::as_object)
    else {
        return false;
    };
    let Some(file) = files.get(tag).or_else(|| files.get("all")) else {
        return false;
    };
    let Some(sha) = file.get("sha256").and_then(Value::as_str) else {
        return false;
    };
    let Some(url) = file.get("url").and_then(Value::as_str) else {
        return false;
    };
    sha.len() == 64
        && sha
            .bytes()
            .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
        && url.starts_with("https://ghcr.io/v2/homebrew/core/")
        && url.ends_with(&format!("sha256:{sha}"))
}
fn metadata(
    host: &mut impl recipe::Host,
    scope: &homebrew_scope::Output,
    platform: Platform,
    package: &str,
) -> Result<Option<Value>> {
    let coordinate = format!("homebrew/core/{package}");
    let call = brew_invocation(
        &scope.prefix,
        &scope.home,
        platform == Platform::Macos,
        &["info", "--json=v2", "--formula", &coordinate],
    )?;
    let output = host.execute(&call).map_err(|_| Error::Failed {})?;
    if output.truncated || output.stdout.len() > 131_072 || output.stderr.len() > 131_072 {
        return Err(Error::Output {});
    }
    if output.code != 0 {
        let combined = format!("{}\n{}", output.stdout, output.stderr);
        if output.code == 1
            && combined.len() <= 131_072
            && combined.contains(&format!(
                "No available formula with the name \"{coordinate}\""
            ))
        {
            return Ok(None);
        }
        return Err(Error::Failed {});
    }
    parse_formula(&output.stdout).map(Some)
}
fn candidate(
    host: &mut impl recipe::Host,
    scope: &homebrew_scope::Output,
    platform: Platform,
    input: &Input,
) -> Result<CandidateOutput> {
    validate(input, platform, false, true)?;
    let tag = bottle_tag(host, platform, input.architecture)?.tag;
    let Some(formula) = metadata(host, scope, platform, &input.package)? else {
        return Ok(CandidateOutput { version: None });
    };
    let stable = formula
        .pointer("/versions/stable")
        .and_then(Value::as_str)
        .filter(|v| safe_version(v));
    if !official(&formula, &input.package)
        || formula.get("disabled").and_then(Value::as_bool) != Some(false)
        || stable.is_none()
        || !bottle_available(&formula, &tag)
    {
        return Err(Error::Candidate {});
    }
    Ok(CandidateOutput {
        version: stable.map(String::from),
    })
}
fn mutate(
    host: &mut impl recipe::Host,
    scope: &homebrew_scope::Output,
    platform: Platform,
    input: &Input,
    operation: Operation,
) -> Result<Done> {
    let needs_bottle = !matches!(operation, Operation::Remove);
    validate(input, platform, true, needs_bottle)?;
    if needs_bottle && candidate(host, scope, platform, input)?.version.is_none() {
        return Err(Error::Candidate {});
    }
    let coordinate = format!("homebrew/core/{}", input.package);
    let args = match operation {
        Operation::Install => vec!["install", "--formula", "--force-bottle", &coordinate],
        Operation::Update => vec!["upgrade", "--formula", "--force-bottle", &coordinate],
        Operation::Remove => vec!["uninstall", "--formula", &coordinate],
    };
    let mut call = brew_invocation(
        &scope.prefix,
        &scope.home,
        needs_bottle && platform == Platform::Macos,
        &args,
    )?;
    call.timeout = Duration::from_secs(900);
    execute(host, &call, 1_048_576)?;
    Ok(Done {})
}
pub struct Candidate;
pub(super) fn kegs(formula: &Value) -> Result<(bool, Option<String>)> {
    let installed = formula
        .get("installed")
        .and_then(Value::as_array)
        .filter(|v| !v.is_empty())
        .ok_or(Error::Output {})?;
    let (mut explicit, mut readable) = (false, true);
    let mut versions = std::collections::BTreeSet::new();
    for keg in installed {
        let keg = keg.as_object().ok_or(Error::Output {})?;
        if let Some(requested) = keg.get("installed_on_request") {
            explicit |= requested.as_bool().ok_or(Error::Output {})?;
        }
        if let Some(version) = keg
            .get("version")
            .and_then(Value::as_str)
            .filter(|v| *v != "latest" && safe_version(v))
        {
            versions.insert(version);
        } else {
            readable = false;
        }
    }
    Ok((
        explicit,
        if readable && versions.len() == 1 {
            versions.first().map(|v| (*v).to_owned())
        } else {
            None
        },
    ))
}
fn adoption_fact(
    formula: &Value,
    package: &str,
    tag: &str,
    listed: String,
) -> Result<AdoptionOutput> {
    let blocked = |block| {
        Ok(AdoptionOutput {
            installed_version: Some(listed.clone()),
            block: Some(block),
        })
    };
    if !official(formula, package) {
        return blocked(Block::UnsupportedSource);
    }
    if matches!(package, "wireguard-go" | "wireguard-tools") {
        return blocked(Block::Protected);
    }
    let (explicit, version) = kegs(formula)?;
    if !explicit {
        return blocked(Block::Dependency);
    }
    if version.is_none() {
        return Ok(AdoptionOutput {
            installed_version: None,
            block: Some(Block::VersionUnreadable),
        });
    }
    if version.as_deref() != Some(listed.as_str()) {
        return Err(Error::Output {});
    }
    if let Some(disabled) = formula.get("disabled") {
        if disabled.as_bool().ok_or(Error::Output {})? {
            return blocked(Block::UnsupportedArtifact);
        }
    }
    if !bottle_available(formula, tag) {
        return blocked(Block::BottleUnavailable);
    }
    Ok(AdoptionOutput {
        installed_version: Some(listed),
        block: None,
    })
}
fn adoption(
    host: &mut impl recipe::Host,
    scope: &homebrew_scope::Output,
    platform: Platform,
    input: &Input,
) -> Result<AdoptionOutput> {
    validate(input, platform, false, true)?;
    let inventory = formula_inventory(host, scope)?;
    let Some(entry) = inventory
        .packages
        .into_iter()
        .find(|p| p.name == input.package)
    else {
        return Ok(AdoptionOutput {
            installed_version: None,
            block: None,
        });
    };
    if entry.unverifiable || entry.versions.len() != 1 {
        return Err(Error::Output {});
    }
    let listed = entry.versions.into_iter().next().unwrap();
    let tag = bottle_tag(host, platform, input.architecture)?.tag;
    let Some(formula) = metadata(host, scope, platform, &input.package)? else {
        return Ok(AdoptionOutput {
            installed_version: Some(listed),
            block: Some(Block::UnsupportedSource),
        });
    };
    adoption_fact(&formula, &input.package, &tag, listed)
}
pub struct Adoption;
impl Command for Adoption {
    const NAME: &'static str = "tool.homebrew-formula-adoption";
    const SUMMARY: &'static str =
        "Inspect one installed formula for explicit adoption without changing packages.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Input;
    type Output = AdoptionOutput;
    type Error = Error;
    fn privilege(_: Platform) -> Option<Privilege> {
        Some(Privilege::Account)
    }
    fn run(input: Input, context: &Context) -> Result<AdoptionOutput> {
        validate(&input, context.platform, false, true)?;
        let scope = homebrew_scope::Inspect::run(homebrew_scope::Empty {}, context)?;
        adoption(
            &mut recipe::Native(context),
            &scope,
            context.platform,
            &input,
        )
    }
}
impl Command for Candidate {
    const NAME: &'static str = "tool.homebrew-formula-candidate";
    const SUMMARY: &'static str = "Read one official Core formula's verified bottle candidate.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Input;
    type Output = CandidateOutput;
    type Error = Error;
    fn privilege(_: Platform) -> Option<Privilege> {
        Some(Privilege::Account)
    }
    fn run(input: Input, context: &Context) -> Result<CandidateOutput> {
        validate(&input, context.platform, false, true)?;
        let scope = homebrew_scope::Inspect::run(homebrew_scope::Empty {}, context)?;
        candidate(
            &mut recipe::Native(context),
            &scope,
            context.platform,
            &input,
        )
    }
}
macro_rules! mutation {
    ($name:ident, $wire:literal, $summary:literal, $operation:ident) => {
        pub struct $name;
        impl Command for $name {
            const NAME: &'static str = $wire;
            const SUMMARY: &'static str = $summary;
            const MUTATES: bool = true;
            const TIMEOUT: Duration = Duration::from_secs(900);
            type Input = Input;
            type Output = Done;
            type Error = Error;
            fn privilege(_: Platform) -> Option<Privilege> {
                Some(Privilege::Account)
            }
            fn target(_: &Input) -> Option<String> {
                Some("tools:homebrew".into())
            }
            fn run(input: Input, context: &Context) -> Result<Done> {
                validate(
                    &input,
                    context.platform,
                    true,
                    !matches!(Operation::$operation, Operation::Remove),
                )?;
                let scope = homebrew_scope::Inspect::run(homebrew_scope::Empty {}, context)?;
                mutate(
                    &mut recipe::Native(context),
                    &scope,
                    context.platform,
                    &input,
                    Operation::$operation,
                )
            }
        }
    };
}
mutation!(
    Install,
    "tool.homebrew-formula-install",
    "Install one verified Core bottle.",
    Install
);
mutation!(
    Update,
    "tool.homebrew-formula-update",
    "Upgrade one verified Core bottle without unpinning it.",
    Update
);
mutation!(
    Remove,
    "tool.homebrew-formula-remove",
    "Remove exactly one unprotected Core formula.",
    Remove
);

#[cfg(test)]
mod tests;
