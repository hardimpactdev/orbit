//! Host image operations. Build records select retention; Incus proves current use.
use super::{
    images, invalid_output, json, project, read, read_input, volumes, Error, Host, Native, Project,
};
use crate::command::{Command, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::{collections::BTreeMap, time::Duration};

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    project: String,
    operation: Operation,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(tag = "kind", rename_all = "snake_case", deny_unknown_fields)]
enum Operation {
    PrepareMetadata {
        name: String,
    },
    Publish {
        name: String,
        alias: String,
        properties: BTreeMap<String, String>,
    },
    Promote {
        alias: String,
        fingerprint: String,
    },
    Prune {
        pool: String,
        obsolete_aliases: Vec<String>,
        retired_aliases: Vec<String>,
        protected_fingerprints: Vec<String>,
    },
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Changed {
    ready: bool,
    fingerprint: Option<String>,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct InstanceImages {
    instances: BTreeMap<String, Option<String>>,
}
fn alias(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 254
        && value.as_bytes()[0].is_ascii_alphanumeric()
        && value
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || b"._/-".contains(&b))
}
fn validate(input: &Input) -> Result<(), Error> {
    project(&Project {
        project: input.project.clone(),
    })?;
    let valid = match &input.operation {
        Operation::PrepareMetadata { name } => super::valid_name(name),
        Operation::Publish {
            name,
            alias: target,
            properties,
        } => {
            super::valid_name(name)
                && alias(target)
                && properties.iter().all(|(key, value)| {
                    matches!(key.as_str(), "orbit.recipe" | "orbit.profile")
                        && !value.is_empty()
                        && value.len() <= 256
                        && !value.chars().any(char::is_control)
                })
        }
        Operation::Promote {
            alias: target,
            fingerprint,
        } => alias(target) && volumes::digest(fingerprint),
        Operation::Prune {
            pool,
            obsolete_aliases,
            retired_aliases,
            protected_fingerprints,
        } => {
            !pool.is_empty()
                && pool.len() <= 63
                && pool.as_bytes()[0].is_ascii_alphanumeric()
                && pool
                    .bytes()
                    .all(|b| b.is_ascii_alphanumeric() || b"._-".contains(&b))
                && obsolete_aliases.iter().all(|s| alias(s))
                && retired_aliases.iter().all(|s| obsolete_aliases.contains(s))
                && protected_fingerprints.iter().all(|s| volumes::digest(s))
        }
    };
    if valid {
        Ok(())
    } else {
        Err(Error::Invalid {})
    }
}
fn instance_images(host: &mut impl Host, input: &Project) -> Result<InstanceImages, Error> {
    project(input)?;
    // A missing project must not turn an image still in use into a deletion candidate.
    read(
        host,
        "project show",
        &[
            "--project",
            &input.project,
            "project",
            "show",
            "--",
            &input.project,
        ],
    )?;
    let mut instances = BTreeMap::new();
    for row in json(
        host,
        "list",
        &["--project", &input.project, "list", "--format", "json"],
    )? {
        let name = row
            .get("name")
            .and_then(Value::as_str)
            .filter(|s| super::valid_name(s))
            .ok_or_else(|| invalid_output("list", "an instance has no valid name"))?;
        let config = row
            .get("config")
            .and_then(Value::as_object)
            .ok_or_else(|| invalid_output("list", "an instance has no config"))?;
        let fingerprint = match config.get("volatile.base_image") {
            None | Some(Value::Null) => None,
            Some(Value::String(s)) if volumes::digest(s) => Some(s.clone()),
            _ => {
                return Err(invalid_output(
                    "list",
                    "an instance has an invalid image fingerprint",
                ))
            }
        };
        if instances.insert(name.into(), fingerprint).is_some() {
            return Err(invalid_output("list", "an instance appears more than once"));
        }
    }
    Ok(InstanceImages { instances })
}
fn target(host: &mut impl Host, project: &str, alias: &str) -> Result<Option<String>, Error> {
    let matches: Vec<_> = images(
        host,
        &Project {
            project: project.into(),
        },
    )?
    .images
    .into_iter()
    .filter(|(_, aliases)| aliases.iter().any(|s| s == alias))
    .map(|(pin, _)| pin)
    .collect();
    if matches.len() > 1 {
        return Err(invalid_output(
            "image list",
            "an alias has more than one target",
        ));
    }
    Ok(matches.into_iter().next())
}
fn query(
    host: &mut impl Host,
    project: &str,
    method: &str,
    path: &str,
    data: Option<&str>,
) -> Result<String, Error> {
    let path = format!("{path}?project={project}");
    let mut args = vec!["query", "-X", method];
    if let Some(data) = data {
        args.extend(["--data", data]);
    }
    args.extend(["--", &path]);
    read(host, "query", &args)
}
pub(super) fn prepare_metadata(
    host: &mut impl Host,
    project: &str,
    name: &str,
) -> Result<(), Error> {
    let path = format!("/1.0/instances/{name}/metadata");
    let mut value: Value = serde_json::from_str(&query(host, project, "GET", &path, None)?)
        .map_err(|_| invalid_output("metadata", "the metadata is not complete JSON"))?;
    let templates = value
        .get_mut("templates")
        .and_then(Value::as_object_mut)
        .ok_or_else(|| invalid_output("metadata", "the image metadata has no templates"))?;
    templates.retain(|path, _| !path.starts_with("/var/lib/cloud/seed/"));
    query(host, project, "PUT", &path, Some(&value.to_string()))?;
    Ok(())
}
fn change(host: &mut impl Host, input: &Input) -> Result<Changed, Error> {
    validate(input)?;
    let project = &input.project;
    let mut fingerprint = None;
    match &input.operation {
        Operation::PrepareMetadata { name } => {
            prepare_metadata(host, project, name)?;
        }
        Operation::Publish {
            name,
            alias,
            properties,
        } => {
            if let Some(pin) = target(host, project, alias)? {
                return Ok(Changed {
                    ready: true,
                    fingerprint: Some(pin),
                });
            }
            if let Err(error) = read(host, "stop", &["--project", project, "stop", "--", name]) {
                let rows = json(
                    host,
                    "list",
                    &["--project", project, "list", "--format", "json"],
                )?;
                let found: Vec<_> = rows
                    .iter()
                    .filter(|v| v.get("name").and_then(Value::as_str) == Some(name))
                    .collect();
                if found.len() != 1
                    || found[0].get("status").and_then(Value::as_str) != Some("Stopped")
                {
                    return Err(error);
                }
            }
            let properties: Vec<_> = properties.iter().map(|(k, v)| format!("{k}={v}")).collect();
            let mut args = vec![
                "--project",
                project,
                "publish",
                "--compression",
                "none",
                "--alias",
                alias,
                "--",
                name,
            ];
            args.extend(properties.iter().map(String::as_str));
            read_input(host, "publish", &args, None)?;
            fingerprint =
                Some(target(host, project, alias)?.ok_or_else(|| {
                    invalid_output("publish", "the published image has no alias")
                })?);
        }
        Operation::Promote {
            alias,
            fingerprint: wanted,
        } => {
            match target(host, project, alias)? {
                Some(current) if &current == wanted => {}
                Some(_) => {
                    query(
                        host,
                        project,
                        "PATCH",
                        &format!("/1.0/images/aliases/{}", alias.replace('/', "%2F")),
                        Some(&json!({"target":wanted}).to_string()),
                    )?;
                }
                None => {
                    read(
                        host,
                        "image alias",
                        &[
                            "--project",
                            project,
                            "image",
                            "alias",
                            "create",
                            "--",
                            alias,
                            wanted,
                        ],
                    )?;
                }
            }
            fingerprint = Some(wanted.clone());
        }
        Operation::Prune {
            pool,
            obsolete_aliases,
            retired_aliases,
            protected_fingerprints,
        } => {
            let project_input = Project {
                project: project.clone(),
            };
            let in_use: Vec<_> = instance_images(host, &project_input)?
                .instances
                .into_values()
                .flatten()
                .collect();
            for (pin, aliases) in images(host, &project_input)?.images {
                if in_use.contains(&pin) {
                    continue;
                }
                if aliases.is_empty()
                    || aliases.iter().any(|a| !obsolete_aliases.contains(a))
                    || protected_fingerprints.contains(&pin)
                {
                    for alias in aliases.iter().filter(|a| retired_aliases.contains(a)) {
                        read(
                            host,
                            "image alias delete",
                            &[
                                "--project",
                                project,
                                "image",
                                "alias",
                                "delete",
                                "--",
                                alias,
                            ],
                        )?;
                    }
                    continue;
                }
                for name in [
                    format!("orbit-topology-seed-{}", &pin[..12]),
                    format!("orbit-topology-seed-{}-new", &pin[..12]),
                ] {
                    volumes::change(
                        host,
                        &volumes::Input {
                            location: volumes::Pool {
                                project: project.clone(),
                                pool: pool.clone(),
                            },
                            operation: volumes::Operation::Remove { name },
                        },
                    )?;
                }
                read(
                    host,
                    "image delete",
                    &["--project", project, "image", "delete", "--", &pin],
                )?;
            }
        }
    }
    Ok(Changed {
        ready: true,
        fingerprint,
    })
}
pub struct ReadInstanceImages;
impl Command for ReadInstanceImages {
    const NAME: &'static str = "incus.instance-images";
    const SUMMARY: &'static str =
        "Read the original image fingerprint of every instance in a project.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Project;
    type Output = InstanceImages;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Project, context: &Context) -> Result<InstanceImages, Error> {
        instance_images(&mut Native(context), &input)
    }
}
pub struct ChangeImage;
impl Command for ChangeImage {
    const NAME: &'static str = "incus.image";
    const SUMMARY: &'static str =
        "Prepare builder metadata, publish, promote, or prune selected images.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    const RESOURCES: &'static [&'static str] = &["incus-volumes"];
    type Input = Input;
    type Output = Changed;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::super::linux_only(platform, Privilege::Root)
    }
    fn target(input: &Input) -> Option<String> {
        Some(format!("incus-images:{}", input.project))
    }
    fn run(input: Input, context: &Context) -> Result<Changed, Error> {
        change(&mut Native(context), &input)
    }
}

#[cfg(test)]
mod tests;
