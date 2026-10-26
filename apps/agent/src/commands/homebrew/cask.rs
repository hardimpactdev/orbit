//! Official macOS casks: inspect artifacts before any install, upgrade, or removal.
use super::*;
use serde_json::{Map, Value};
use std::collections::BTreeSet;

const PREFIX_KINDS: &[&str] = &[
    "binary",
    "manpage",
    "bash_completion",
    "fish_completion",
    "zsh_completion",
    "generate_completions_from_executable",
];
const HOME_KINDS: &[&str] = &[
    "font",
    "colorpicker",
    "dictionary",
    "internet_plugin",
    "prefpane",
    "qlplugin",
    "mdimporter",
    "screen_saver",
    "service",
    "audio_unit_plugin",
    "vst_plugin",
    "vst3_plugin",
];
const APP_KINDS: &[&str] = &["app", "suite"];
const PRIVILEGED_KINDS: &[&str] = &["pkg", "installer", "keyboard_layout", "input_method"];
const UNINSTALL_KEYS: &[&str] = &[
    "quit",
    "signal",
    "login_item",
    "trash",
    "delete",
    "rmdir",
    "pkgutil",
    "kext",
    "launchctl",
];
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    package: String,
    architecture: Architecture,
}
#[derive(schemars::JsonSchema, Debug, PartialEq, Serialize)]
pub struct CandidateOutput {
    version: Option<String>,
}
#[derive(schemars::JsonSchema, Debug, PartialEq, Serialize)]
pub struct AdoptionOutput {
    installed_version: Option<String>,
    block: Option<Block>,
}
#[derive(Debug, PartialEq, Serialize)]
pub(super) struct Discovery {
    pub(super) name: String,
    pub(super) installed_version: Option<String>,
    pub(super) block: Option<Block>,
}
#[derive(schemars::JsonSchema, Debug, PartialEq, Serialize)]
pub struct Done {}
#[derive(Clone, Copy)]
enum Operation {
    Install,
    Update,
    Remove,
}
fn validate(input: &Input) -> Result<()> {
    if valid_package(&input.package) {
        Ok(())
    } else {
        Err(Error::Invalid {})
    }
}
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
fn known_kind(kind: &str) -> bool {
    PREFIX_KINDS.contains(&kind)
        || HOME_KINDS.contains(&kind)
        || APP_KINDS.contains(&kind)
        || PRIVILEGED_KINDS.contains(&kind)
        || kind == "artifact"
}
#[derive(PartialEq)]
enum PathClass {
    User,
    Authorization,
    Unsupported,
}
fn path_class(path: &str, scope: &homebrew_scope::Output) -> PathClass {
    if path.is_empty() || path.contains("..") || path.bytes().any(|b| b < 32 || b == 127) {
        return PathClass::Unsupported;
    }
    let prefix = scope.prefix.to_string_lossy();
    let home = scope.home.to_string_lossy();
    let user = ["~", "$HOME", "/$HOME", "$HOMEBREW_PREFIX", prefix.as_ref()]
        .iter()
        .any(|base| path == *base || path.starts_with(&format!("{base}/")))
        || (home != "/"
            && !home.contains("..")
            && (path == home || path.starts_with(&format!("{home}/"))))
        || !path.contains('/');
    if user {
        PathClass::User
    } else if path.starts_with('/') || path.starts_with("$APPDIR") {
        PathClass::Authorization
    } else {
        PathClass::Unsupported
    }
}
fn destinations<'a>(kind: &str, artifact: &'a Map<String, Value>) -> Vec<&'a str> {
    let mut targets = vec![];
    if let Some(target) = artifact.get("target").and_then(Value::as_str) {
        targets.push(target);
    }
    if let Some(entries) = artifact.get(kind).and_then(Value::as_array) {
        for entry in entries {
            if let Some(target) = string(entry, "target") {
                targets.push(target);
            }
        }
    }
    if !targets.is_empty() {
        return targets;
    }
    vec![if APP_KINDS.contains(&kind) {
        "/Applications"
    } else if HOME_KINDS.contains(&kind) {
        "/$HOME"
    } else if PREFIX_KINDS.contains(&kind) {
        "$HOMEBREW_PREFIX"
    } else {
        "relative/missing-target"
    }]
}
fn uninstall_arbitrary(stanza: &Value) -> bool {
    let Some(directives) = stanza.as_array() else {
        return true;
    };
    directives.iter().any(|v| {
        v.as_object()
            .is_none_or(|o| o.keys().any(|k| !UNINSTALL_KEYS.contains(&k.as_str())))
    })
}
fn uninstall_authorization(stanza: &Value, scope: &homebrew_scope::Output) -> bool {
    let Some(directives) = stanza.as_array() else {
        return false;
    };
    for directive in directives.iter().filter_map(Value::as_object) {
        if ["pkgutil", "kext", "launchctl"]
            .iter()
            .any(|key| directive.contains_key(*key))
        {
            return true;
        }
        for key in ["trash", "delete", "rmdir"] {
            let Some(value) = directive.get(key) else {
                continue;
            };
            let paths: Option<Vec<_>> = if let Some(path) = value.as_str() {
                Some(vec![path])
            } else {
                value
                    .as_array()
                    .and_then(|v| v.iter().map(Value::as_str).collect())
            };
            if paths.is_none_or(|paths| {
                paths
                    .iter()
                    .any(|p| path_class(p, scope) != PathClass::User)
            }) {
                return true;
            }
        }
    }
    false
}
fn authorization(artifacts: &[Value], scope: &homebrew_scope::Output) -> bool {
    for artifact in artifacts.iter().filter_map(Value::as_object) {
        for (kind, value) in artifact {
            if kind == "target" || kind == "zap" {
                continue;
            }
            if kind == "uninstall" {
                if uninstall_authorization(value, scope) {
                    return true;
                }
            } else if PRIVILEGED_KINDS.contains(&kind.as_str())
                || (known_kind(kind)
                    && destinations(kind, artifact)
                        .iter()
                        .any(|p| path_class(p, scope) == PathClass::Authorization))
            {
                return true;
            }
        }
    }
    false
}
fn artifact_allowed(cask: &Value, artifacts: &[Value], scope: &homebrew_scope::Output) -> bool {
    if cask.get("disabled").and_then(Value::as_bool) == Some(true) {
        return false;
    }
    let mut installs = 0;
    for artifact in artifacts {
        let Some(artifact) = artifact.as_object() else {
            return false;
        };
        for (kind, value) in artifact {
            if kind == "target" || kind == "zap" {
                continue;
            }
            if kind == "uninstall" {
                if uninstall_arbitrary(value) {
                    return false;
                }
                continue;
            }
            if !known_kind(kind) {
                return false;
            }
            installs += 1;
            if !PRIVILEGED_KINDS.contains(&kind.as_str())
                && destinations(kind, artifact)
                    .iter()
                    .any(|p| path_class(p, scope) == PathClass::Unsupported)
            {
                return false;
            }
        }
    }
    installs > 0
}
fn assess(cask: &Value, package: &str, scope: &homebrew_scope::Output) -> Result<String> {
    cask.get("disabled")
        .and_then(Value::as_bool)
        .ok_or(Error::Output {})?;
    let artifacts = cask
        .get("artifacts")
        .and_then(Value::as_array)
        .ok_or(Error::Output {})?;
    if string(cask, "token") != Some(package)
        || string(cask, "full_token") != Some(package)
        || string(cask, "tap") != Some("homebrew/cask")
        || !string(cask, "url").is_some_and(|url| {
            url.starts_with("https://") && url.len() > 8 && !url.chars().any(char::is_whitespace)
        })
    {
        return Err(Error::CaskSource {});
    }
    if !artifact_allowed(cask, artifacts, scope) {
        return Err(Error::CaskArtifact {});
    }
    if authorization(artifacts, scope) {
        return Err(Error::CaskAuthorization {});
    }
    if !string(cask, "sha256").is_some_and(|sha| {
        sha.len() == 64
            && sha
                .bytes()
                .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
    }) {
        return Err(Error::CaskChecksum {});
    }
    string(cask, "version")
        .filter(|v| cask_version(v))
        .map(String::from)
        .ok_or(Error::CaskVersion {})
}
fn block(error: Error) -> Result<Block> {
    match error {
        Error::CaskSource {} => Ok(Block::UnsupportedSource),
        Error::CaskArtifact {} | Error::CaskChecksum {} => Ok(Block::UnsupportedArtifact),
        Error::CaskAuthorization {} => Ok(Block::AuthorizationRequired),
        Error::CaskVersion {} => Ok(Block::VersionUnreadable),
        error => Err(error),
    }
}
fn variation(cask: &Value, tag: &str) -> Result<Value> {
    let mut resolved = object(cask)?.clone();
    if let Some(variations) = cask.get("variations") {
        if let Some(variation) = object(variations)?.get(tag) {
            resolved.extend(object(variation)?.clone());
        }
    }
    Ok(Value::Object(resolved))
}
fn sole(output: &str) -> Result<Value> {
    let data = decoded(output)?;
    let formulae = data
        .get("formulae")
        .and_then(Value::as_array)
        .ok_or(Error::Output {})?;
    let casks = data
        .get("casks")
        .and_then(Value::as_array)
        .ok_or(Error::Output {})?;
    if !formulae.is_empty() || casks.len() != 1 || !casks[0].is_object() {
        return Err(Error::Output {});
    }
    Ok(casks[0].clone())
}
fn unavailable(output: &recipe::Output, package: &str) -> bool {
    let text = format!("{}\n{}", output.stdout, output.stderr);
    if output.code != 1 || output.truncated || text.len() > 131_072 {
        return false;
    }
    let coordinate = format!("homebrew/cask/{package}");
    (text.contains(&format!("Cask '{coordinate}' is unavailable."))
        && text.contains("This command requires the tap homebrew/cask."))
        || (text.contains("No Cask with this name exists")
            && [&coordinate, package].iter().any(|name| {
                text.contains(&format!("Cask '{name}'"))
                    || text.contains(&format!("Cask \"{name}\""))
            }))
}
fn metadata(
    host: &mut impl recipe::Host,
    scope: &homebrew_scope::Output,
    package: &str,
    refresh: bool,
) -> Result<Option<Value>> {
    let coordinate = format!("homebrew/cask/{package}");
    let call = brew_invocation(
        &scope.prefix,
        &scope.home,
        refresh,
        &["info", "--json=v2", "--cask", &coordinate],
    )?;
    let output = host.execute(&call).map_err(|_| Error::Failed {})?;
    if output.truncated || output.stdout.len() > 131_072 || output.stderr.len() > 131_072 {
        return Err(Error::Output {});
    }
    if output.code != 0 {
        return if unavailable(&output, package) {
            Ok(None)
        } else {
            Err(Error::Failed {})
        };
    }
    sole(&output.stdout).map(Some)
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
fn candidate(
    host: &mut impl recipe::Host,
    scope: &homebrew_scope::Output,
    input: &Input,
) -> Result<CandidateOutput> {
    validate(input)?;
    let tag = bottle_tag(host, Platform::Macos, Some(input.architecture))?.tag;
    let Some(cask) = metadata(host, scope, &input.package, true)? else {
        return Ok(CandidateOutput { version: None });
    };
    let cask = variation(&cask, &tag)?;
    assess(&cask, &input.package, scope).map(|version| CandidateOutput {
        version: Some(version),
    })
}
fn adoption(
    host: &mut impl recipe::Host,
    scope: &homebrew_scope::Output,
    input: &Input,
) -> Result<AdoptionOutput> {
    validate(input)?;
    let packages = inventory(host, scope)?.packages;
    let Some(entry) = packages.into_iter().find(|p| p.name == input.package) else {
        return Ok(AdoptionOutput {
            installed_version: None,
            block: None,
        });
    };
    if entry.unverifiable || entry.versions.len() != 1 {
        return Err(Error::Output {});
    }
    let installed_version = entry.versions.into_iter().next();
    let tag = bottle_tag(host, Platform::Macos, Some(input.architecture))?.tag;
    let Some(cask) = metadata(host, scope, &input.package, false)? else {
        return Ok(AdoptionOutput {
            installed_version,
            block: Some(Block::UnsupportedSource),
        });
    };
    let cask = variation(&cask, &tag)?;
    let block = match assess(&cask, &input.package, scope) {
        Ok(_) => None,
        Err(error) => Some(block(error)?),
    };
    Ok(AdoptionOutput {
        installed_version,
        block,
    })
}
fn removal_target(
    host: &mut impl recipe::Host,
    scope: &homebrew_scope::Output,
    input: &Input,
    tag: &str,
) -> Result<String> {
    let (cask, target) = if let Some(cask) = metadata(host, scope, &input.package, false)? {
        (
            variation(&cask, tag)?,
            format!("homebrew/cask/{}", input.package),
        )
    } else {
        let data = installed(host, scope, 131_072)?;
        let casks = data
            .get("casks")
            .and_then(Value::as_array)
            .ok_or(Error::Output {})?;
        let matches: Vec<_> = casks
            .iter()
            .filter(|v| string(v, "token") == Some(&input.package))
            .collect();
        if matches.len() != 1 {
            return Err(Error::Output {});
        }
        let cask = variation(matches[0], tag)?;
        if string(&cask, "tap") != Some("homebrew/cask") {
            return Err(Error::CaskSource {});
        }
        (cask, input.package.clone())
    };
    let artifacts = cask
        .get("artifacts")
        .and_then(Value::as_array)
        .ok_or(Error::Output {})?;
    for artifact in artifacts {
        let artifact = object(artifact)?;
        if artifact.get("uninstall").is_some_and(uninstall_arbitrary) {
            return Err(Error::CaskArtifact {});
        }
    }
    if authorization(artifacts, scope) {
        return Err(Error::CaskAuthorization {});
    }
    Ok(target)
}
fn mutate(
    host: &mut impl recipe::Host,
    scope: &homebrew_scope::Output,
    input: &Input,
    operation: Operation,
) -> Result<Done> {
    validate(input)?;
    let tag = bottle_tag(host, Platform::Macos, Some(input.architecture))?.tag;
    let (verb, target, refresh) = match operation {
        Operation::Remove => (
            "uninstall",
            removal_target(host, scope, input, &tag)?,
            false,
        ),
        Operation::Install | Operation::Update => {
            let cask = metadata(host, scope, &input.package, true)?.ok_or(Error::CaskSource {})?;
            assess(&variation(&cask, &tag)?, &input.package, scope)?;
            (
                if matches!(operation, Operation::Install) {
                    "install"
                } else {
                    "upgrade"
                },
                format!("homebrew/cask/{}", input.package),
                true,
            )
        }
    };
    let mut call = brew_invocation(
        &scope.prefix,
        &scope.home,
        refresh,
        &[verb, "--cask", &target],
    )?;
    call.timeout = Duration::from_secs(900);
    execute(host, &call, 1_048_576)?;
    Ok(Done {})
}
pub(super) fn discoveries(
    data: &Value,
    scope: &homebrew_scope::Output,
    tag: &str,
) -> Result<Vec<Discovery>> {
    let casks = data
        .get("casks")
        .and_then(Value::as_array)
        .ok_or(Error::Output {})?;
    data.get("formulae")
        .and_then(Value::as_array)
        .ok_or(Error::Output {})?;
    let mut packages = vec![];
    let mut seen = BTreeSet::new();
    for cask in casks {
        object(cask)?;
        let Some(name) = string(cask, "token").filter(|n| valid_package(n)) else {
            continue;
        };
        if !seen.insert(name) {
            return Err(Error::Output {});
        }
        let resolved = variation(cask, tag)?;
        let installed_version = installed_version(cask);
        let block = match assess(&resolved, name, scope) {
            Ok(_) if installed_version.is_none() => Some(Block::VersionUnreadable),
            Ok(_) => None,
            Err(error) => Some(block(error)?),
        };
        packages.push(Discovery {
            name: name.into(),
            installed_version,
            block,
        });
    }
    Ok(packages)
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
read!(
    Candidate,
    "tool.homebrew-cask-candidate",
    "Read the safe official cask candidate.",
    Input,
    CandidateOutput,
    |input: Input, context: &Context| {
        validate(&input)?;
        let scope = homebrew_scope::Inspect::run(homebrew_scope::Empty {}, context)?;
        candidate(&mut recipe::Native(context), &scope, &input)
    }
);
read!(
    Adoption,
    "tool.homebrew-cask-adoption",
    "Inspect an installed cask for explicit adoption.",
    Input,
    AdoptionOutput,
    |input: Input, context: &Context| {
        validate(&input)?;
        let scope = homebrew_scope::Inspect::run(homebrew_scope::Empty {}, context)?;
        adoption(&mut recipe::Native(context), &scope, &input)
    }
);
macro_rules! mutation {
    ($name:ident,$wire:literal,$summary:literal,$operation:ident) => {
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
                (platform == Platform::Macos).then_some(Privilege::Account)
            }
            fn target(_: &Input) -> Option<String> {
                Some("tools:homebrew".into())
            }
            fn run(input: Input, context: &Context) -> Result<Done> {
                validate(&input)?;
                let scope = homebrew_scope::Inspect::run(homebrew_scope::Empty {}, context)?;
                mutate(
                    &mut recipe::Native(context),
                    &scope,
                    &input,
                    Operation::$operation,
                )
            }
        }
    };
}
mutation!(
    Install,
    "tool.homebrew-cask-install",
    "Install one safe official cask.",
    Install
);
mutation!(
    Update,
    "tool.homebrew-cask-update",
    "Upgrade one safe official cask.",
    Update
);
mutation!(
    Remove,
    "tool.homebrew-cask-remove",
    "Remove one cask after checking uninstall safety.",
    Remove
);
#[cfg(test)]
mod tests;
