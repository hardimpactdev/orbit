use super::{json, Error, Host, Native};
use crate::command::{Command, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::time::Duration;

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Pool {
    pub(super) project: String,
    pub(super) pool: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Volumes {
    names: Vec<String>,
}

fn name(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 254
        && value.as_bytes()[0].is_ascii_alphanumeric()
        && value
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || b"._-".contains(&b))
}
pub(super) fn digest(value: &str) -> bool {
    value.len() == 64
        && value
            .bytes()
            .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
}
fn validate(pool: &Pool) -> Result<(), Error> {
    if !super::valid_name(&pool.project) || !name(&pool.pool) || pool.pool.len() > 63 {
        return Err(Error::Invalid {});
    }
    Ok(())
}
fn names(host: &mut impl Host, input: &Pool) -> Result<Vec<String>, Error> {
    validate(input)?;
    let mut names = vec![];
    for row in json(
        host,
        "storage volume list",
        &[
            "--project",
            &input.project,
            "storage",
            "volume",
            "list",
            "--format",
            "json",
            "--",
            &input.pool,
        ],
    )? {
        let kind = row.get("type").and_then(Value::as_str);
        let name = row.get("name").and_then(Value::as_str);
        let (Some(kind), Some(name)) = (kind, name) else {
            return Err(super::invalid_output(
                "storage volume list",
                "a volume has no name or type",
            ));
        };
        if kind == "custom" {
            names.push(name.into());
        }
    }
    Ok(names)
}
pub struct ReadVolumes;
impl Command for ReadVolumes {
    const NAME: &'static str = "incus.volumes";
    const SUMMARY: &'static str = "Read custom volume names in one Incus project and pool.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Pool;
    type Output = Volumes;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        crate::commands::linux_only(platform, Privilege::Root)
    }
    fn run(input: Pool, context: &Context) -> Result<Volumes, Error> {
        Ok(Volumes {
            names: names(&mut Native(context), &input)?,
        })
    }
}
#[cfg(test)]
mod tests;
