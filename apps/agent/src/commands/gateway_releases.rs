//! Release selection and Git observations. All filesystem work stays on the Node.
use super::gateway_adoption::{self, directory, hex, owned, Layout};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    collections::BTreeMap,
    fs, io,
    os::unix::fs::{symlink, MetadataExt},
    path::{Path, PathBuf},
    time::Duration,
};

command_errors! {
    pub enum Error {
        Invalid {} => "gateway.release_layout_invalid", "The Gateway release layout or commit is invalid.";
        Conflict {} => "gateway.release_path_conflict", "A release path has unexpected type or ownership.";
        NotPrepared {} => "gateway.release_not_prepared", "The requested release is not prepared from its named commit.";
        NotAdopted {} => "gateway.release_not_adopted", "The current path is an in-place checkout or a foreign link. Run gateway:release:adopt first.";
        SwitchFailed {} => "gateway.release_switch_failed", "The current release link could not be switched.";
        Downgrade { sha: String, current_sha: String } => "gateway.release_downgrade", "The requested commit does not descend from the current release. Use rollback or force.";
        SourceUnknown {} => "gateway.release_source_unknown", "The shared repository has no readable credential-free origin.";
    }
}
impl From<gateway_adoption::Error> for Error {
    fn from(error: gateway_adoption::Error) -> Self {
        match error {
            gateway_adoption::Error::Invalid {} => Self::Invalid {},
            _ => Self::Conflict {},
        }
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct SwitchInput {
    pub checkout: String,
    pub release: String,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ForwardInput {
    pub checkout: String,
    pub sha: Option<String>,
    pub force: bool,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct SourceInput {
    pub checkout: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct SwitchOutput {
    previous: Option<String>,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct ForwardOutput {
    source: Option<String>,
    source_sha: Option<String>,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct SourceOutput {
    origin: String,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct InventoryOutput {
    current: Option<String>,
    state: &'static str,
    superseded: bool,
    commits: BTreeMap<String, String>,
    incomplete: BTreeMap<String, i64>,
    retained: Vec<String>,
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InventoryInput {
    pub checkout: String,
    pub running_application: Option<PathBuf>,
}

fn inventory(input: &InventoryInput) -> Result<InventoryOutput, Error> {
    if input.running_application.as_ref().is_some_and(|path| {
        !path.is_absolute()
            || path
                .components()
                .any(|p| matches!(p, std::path::Component::ParentDir))
    }) {
        return Err(Error::Invalid {});
    }
    let layout = Layout::new(&input.checkout)?;
    let current_meta = owned(&layout.current)?;
    let superseded = current_meta.as_ref().is_some_and(|m| m.is_symlink())
        && input.running_application.as_ref().is_some_and(|running| {
            match (
                fs::canonicalize(layout.current.join("apps/gateway")),
                fs::canonicalize(running),
            ) {
                (Ok(serving), Ok(running)) => serving != running,
                _ => false,
            }
        });
    let mut result = InventoryOutput {
        current: release_id(&layout, &layout.current)?,
        state: if current_meta.is_some_and(|m| m.is_dir()) {
            "in_place"
        } else {
            "unreadable"
        },
        superseded,
        commits: BTreeMap::new(),
        incomplete: BTreeMap::new(),
        retained: Vec::new(),
    };
    let releases = layout.base.join("releases");
    match owned(&releases)? {
        None => return Ok(result),
        Some(m) if m.is_dir() => {}
        _ => return Err(Error::Conflict {}),
    }
    let mut retained = Vec::new();
    for entry in fs::read_dir(&releases).map_err(|_| Error::Conflict {})? {
        let entry = entry.map_err(|_| Error::Conflict {})?;
        let Some(id) = entry
            .file_name()
            .to_str()
            .filter(|id| hex(id, 12))
            .map(str::to_owned)
        else {
            continue;
        };
        if !owned(&entry.path())?.is_some_and(|m| m.is_dir()) {
            continue;
        }
        let revision = entry.path().join("REVISION");
        if let Some(sha) = prepared(&layout, &id)? {
            let modified = owned(&revision)?.ok_or(Error::Conflict {})?.mtime();
            retained.push((modified, id.clone()));
            result.commits.insert(id, sha);
        } else if owned(&revision)?.is_none() {
            result
                .incomplete
                .insert(id, owned(&entry.path())?.ok_or(Error::Conflict {})?.mtime());
        }
    }
    if result
        .current
        .as_ref()
        .is_some_and(|id| result.commits.contains_key(id))
    {
        result.state = "adopted";
    }
    retained.sort_by(|a, b| b.cmp(a));
    result.retained = retained.into_iter().map(|(_, id)| id).collect();
    Ok(result)
}

pub(super) fn release_id(layout: &Layout, path: &Path) -> Result<Option<String>, Error> {
    if !owned(path)?.is_some_and(|m| m.is_symlink()) {
        return Ok(None);
    }
    let target = fs::read_link(path).map_err(|_| Error::Conflict {})?;
    let Some(target) = target.to_str() else {
        return Ok(None);
    };
    for prefix in [
        format!("{}/releases/", layout.base.display()),
        "releases/".into(),
    ] {
        if let Some(id) = target.strip_prefix(&prefix).filter(|id| hex(id, 12)) {
            return Ok(Some(id.into()));
        }
    }
    Ok(None)
}
pub(super) fn prepared(layout: &Layout, id: &str) -> Result<Option<String>, Error> {
    if !hex(id, 12) {
        return Err(Error::Invalid {});
    }
    let releases = layout.base.join("releases");
    for path in [&releases, &releases.join(id)] {
        match owned(path)? {
            None => return Ok(None),
            Some(m) if m.is_dir() => {}
            _ => return Err(Error::Conflict {}),
        }
    }
    let revision = releases.join(id).join("REVISION");
    if !owned(&revision)?.is_some_and(|m| m.is_file() && m.len() <= 128) {
        return Ok(None);
    }
    let sha = fs::read_to_string(revision).map_err(|_| Error::Conflict {})?;
    Ok((hex(sha.trim(), 40) && sha.trim().starts_with(id)).then(|| sha.trim().into()))
}
fn switch(
    input: &SwitchInput,
    rename: impl FnOnce(&Path, &Path) -> io::Result<()>,
) -> Result<SwitchOutput, Error> {
    let layout = Layout::new(&input.checkout)?;
    if prepared(&layout, &input.release)?.is_none() {
        return Err(Error::NotPrepared {});
    }
    let previous = release_id(&layout, &layout.current)?;
    if previous.as_ref() == Some(&input.release) {
        return Ok(SwitchOutput { previous });
    }
    if owned(&layout.current)?.is_some() && previous.is_none() {
        return Err(Error::NotAdopted {});
    }
    let next = layout.current.with_file_name(format!(
        "{}.next",
        layout.current.file_name().unwrap().to_string_lossy()
    ));
    let target = PathBuf::from("releases").join(&input.release);
    if owned(&next)?.is_some() {
        if release_id(&layout, &next)?.as_ref() != Some(&input.release) {
            return Err(Error::Conflict {});
        }
    } else {
        symlink(&target, &next).map_err(|_| Error::SwitchFailed {})?;
    }
    if rename(&next, &layout.current).is_err() {
        fs::remove_file(&next).map_err(|_| Error::SwitchFailed {})?;
        return Err(Error::SwitchFailed {});
    }
    Ok(SwitchOutput { previous })
}
fn repository(layout: &Layout) -> Result<git2::Repository, Error> {
    directory(&layout.shared())?;
    let path = layout.shared().join("orbit.git");
    directory(&path)?;
    let repo = git2::Repository::open_bare(path).map_err(|_| Error::SourceUnknown {})?;
    Ok(repo)
}
fn forward(input: &ForwardInput) -> Result<ForwardOutput, Error> {
    if input.sha.as_ref().is_some_and(|sha| !hex(sha, 40)) {
        return Err(Error::Invalid {});
    }
    let layout = Layout::new(&input.checkout)?;
    let source = release_id(&layout, &layout.current)?;
    let source_sha = source
        .as_ref()
        .map(|id| prepared(&layout, id))
        .transpose()?
        .flatten();
    if let (Some(sha), Some(current)) = (&input.sha, &source_sha) {
        if !input.force && sha != current {
            let descendant = repository(&layout)
                .ok()
                .and_then(|repo| {
                    repo.graph_descendant_of(
                        git2::Oid::from_str(sha).ok()?,
                        git2::Oid::from_str(current).ok()?,
                    )
                    .ok()
                })
                .unwrap_or(false);
            if !descendant {
                return Err(Error::Downgrade {
                    sha: sha.clone(),
                    current_sha: current.clone(),
                });
            }
        }
    }
    Ok(ForwardOutput { source, source_sha })
}
fn source(input: &SourceInput) -> Result<SourceOutput, Error> {
    let layout = Layout::new(&input.checkout)?;
    let repo = repository(&layout)?;
    let remote = repo
        .find_remote("origin")
        .map_err(|_| Error::SourceUnknown {})?;
    let origin = remote.url().ok_or(Error::SourceUnknown {})?;
    // The control plane applies repository policy; never return credentials from Git config.
    if super::source::repository_identity(origin).is_none() {
        return Err(Error::SourceUnknown {});
    }
    Ok(SourceOutput {
        origin: origin.into(),
    })
}
macro_rules! command {
    ($type:ident, $name:literal, $input:ty, $output:ty, $mutates:literal, $run:expr) => {
        pub struct $type;
        impl Command for $type {
            const NAME: &'static str = $name;
            const SUMMARY: &'static str =
                "Select a prepared Gateway release or inspect its Git identity.";
            const MUTATES: bool = $mutates;
            const TIMEOUT: Duration = Duration::from_secs(30);
            type Input = $input;
            type Output = $output;
            type Error = Error;
            fn privilege(platform: Platform) -> Option<Privilege> {
                super::linux_only(platform, Privilege::Account)
            }
            fn target(input: &Self::Input) -> Option<String> {
                $mutates.then(|| format!("gateway-release:{}", input.checkout))
            }
            fn run(input: Self::Input, _: &Context) -> Result<Self::Output, Error> {
                ($run)(&input)
            }
        }
    };
}
command!(
    Switch,
    "gateway.switch-release",
    SwitchInput,
    SwitchOutput,
    true,
    |input| switch(input, |a, b| fs::rename(a, b))
);
command!(
    Forward,
    "gateway.check-release-forward",
    ForwardInput,
    ForwardOutput,
    false,
    forward
);
command!(
    Source,
    "gateway.inspect-release-source",
    SourceInput,
    SourceOutput,
    false,
    source
);
command!(
    Inventory,
    "gateway.inspect-releases",
    InventoryInput,
    InventoryOutput,
    false,
    inventory
);

#[cfg(test)]
mod tests;
