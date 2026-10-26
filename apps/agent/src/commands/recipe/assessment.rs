use super::{
    apt::{self, Snapshots},
    run, sources,
    types::{Assessment, Input, Source, Status},
    Failure, Files, Host, Invocation,
};
use std::collections::{BTreeMap, BTreeSet};

pub type Versions = BTreeMap<String, Option<String>>;
pub type Report = BTreeMap<String, Assessment>;
pub fn platform(host: &mut impl Host, files: &Files, input: &Input) -> Result<(), Failure> {
    input.validate()?;
    let text = std::fs::read_to_string(files.path("/etc/os-release"))
        .map_err(|e| Failure::new("platform:unknown:unknown", e))?;
    let release = crate::commands::os_release::parse(&text).map_err(|_| {
        Failure::new(
            "platform:unknown:unknown",
            "Invalid operating system identity",
        )
    })?;
    if release.id != "ubuntu" || release.codename != "resolute" {
        return Err(Failure::new(
            &format!("platform:{}:{}", release.id, release.codename),
            "Unsupported operating system",
        ));
    }
    if apt::architecture(host)? != input.architecture.name() {
        return Err(Failure::new(
            "architecture",
            "The observed architecture differs from the recipe",
        ));
    }
    if apt::packages(host)?.contains_key("docker-ce") {
        return Err(Failure::new(
            "docker-provider:docker-ce",
            "Docker CE requires an explicit planned-window migration",
        ));
    }
    Ok(())
}
pub fn assess(
    host: &mut impl Host,
    files: &Files,
    input: &Input,
    versions: &Versions,
    options: Option<&[String]>,
    snapshots: &mut Snapshots,
) -> Result<Report, Failure> {
    let mut report = Report::new();
    let git_names: BTreeSet<_> = input.git().into_keys().collect();
    let incus_names: BTreeSet<_> = input.incus().into_keys().collect();
    for (name, pin) in &input.tools {
        let installed = versions
            .get(name)
            .ok_or_else(|| Failure::new("inventory", "A selected tool has no inventory result"))?
            .clone();
        let mut status = if installed.as_ref() == Some(&pin.version) {
            Status::Current
        } else if installed.is_none() {
            Status::Missing
        } else {
            Status::Different
        };
        let mut reason = String::new();
        if let Some(version) = installed.as_ref().filter(|v| **v != pin.version) {
            if git_names.contains(name)
                && pin.source == Source::Artifact
                && !sources::artifact_owned(files, name, version, &git_names, "git")
            {
                match apt::ubuntu_origin(host, files, name, version, options, snapshots) {
                    Ok(true) => {}
                    Ok(false) => status = Status::UnsupportedNewer,
                    Err(e) => {
                        status = Status::UnsupportedNewer;
                        reason = format!(":{}", e.stage);
                    }
                }
            }
            if incus_names.contains(name) && pin.source == Source::Artifact {
                let owned = sources::artifact_owned(files, name, version, &incus_names, "incus")
                    || apt::ubuntu_origin(host, files, name, version, options, snapshots)?;
                let comparison = run(
                    host,
                    &Invocation::new(&["dpkg", "--compare-versions", version, "gt", &pin.version]),
                    false,
                )?;
                if comparison.code != 0 && comparison.code != 1 {
                    return Err(Failure::new(
                        &format!("inventory:{name}"),
                        "Version comparison failed",
                    ));
                }
                if !owned || comparison.code == 0 {
                    status = Status::UnsupportedNewer;
                }
            }
            if pin.source == Source::Ubuntu {
                let comparison = run(
                    host,
                    &Invocation::new(&["dpkg", "--compare-versions", version, "gt", &pin.version]),
                    false,
                )?;
                match comparison.code {
                    0 => match apt::ubuntu_origin(
                        host,
                        files,
                        pin.package
                            .as_deref()
                            .ok_or_else(|| Failure::new("input", "Ubuntu pin has no package"))?,
                        version,
                        options,
                        snapshots,
                    ) {
                        Ok(true) => status = Status::NewerThanRecipe,
                        Ok(false) => status = Status::UnsupportedNewer,
                        Err(e) => {
                            status = Status::UnsupportedNewer;
                            reason = format!(":{}", e.stage);
                        }
                    },
                    1 => status = Status::OlderThanRecipe,
                    _ => {
                        return Err(Failure::new(
                            &format!("inventory:{name}"),
                            "Version comparison failed",
                        ))
                    }
                }
            }
        }
        let changed = !matches!(
            status,
            Status::Current | Status::NewerThanRecipe | Status::UnsupportedNewer
        );
        let refusal =
            (status == Status::UnsupportedNewer).then(|| format!("origin:{name}{reason}"));
        report.insert(
            name.clone(),
            Assessment {
                source: pin.source,
                version: pin.version.clone(),
                installed,
                changed,
                status,
                refusal,
            },
        );
    }
    Ok(report)
}
pub fn supported(report: &Report) -> Result<(), Failure> {
    if let Some(tool) = report
        .values()
        .find(|tool| tool.status == Status::UnsupportedNewer)
    {
        return Err(Failure::new(
            tool.refusal.as_deref().unwrap_or("origin"),
            "The installed package does not have a supported origin",
        ));
    }
    Ok(())
}
pub fn newer(report: &Report) -> BTreeMap<String, BTreeMap<String, String>> {
    report
        .iter()
        .filter(|(_, tool)| tool.status == Status::NewerThanRecipe)
        .map(|(name, tool)| {
            (
                name.clone(),
                [
                    (
                        "installed".into(),
                        tool.installed.clone().unwrap_or_default(),
                    ),
                    ("version".into(), tool.version.clone()),
                ]
                .into(),
            )
        })
        .collect()
}

#[cfg(test)]
mod tests;
