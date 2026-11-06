//! Host image operations. Build records select retention; Incus proves current use.
use super::{invalid_output, json, project, read, volumes, Error, Host, Native, Project};
use crate::command::{Command, Context, Platform, Privilege};
use serde::Serialize;
use serde_json::Value;
use std::{collections::BTreeMap, time::Duration};

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct InstanceImages {
    instances: BTreeMap<String, Option<String>>,
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
#[cfg(test)]
mod tests;
