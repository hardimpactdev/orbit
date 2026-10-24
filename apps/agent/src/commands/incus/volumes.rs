use super::{json, read, Error, Host, Native};
use crate::command::{Command, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::time::Duration;

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Pool {
    pub(super) project: String,
    pub(super) pool: String,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub(super) location: Pool,
    pub(super) operation: Operation,
}
#[derive(Deserialize)]
#[serde(tag = "kind", rename_all = "snake_case", deny_unknown_fields)]
pub enum Operation {
    CloneSeed {
        vm: String,
        fingerprint: Option<String>,
    },
    FinishSeed {
        fingerprint: String,
    },
    Remove {
        name: String,
    },
}
#[derive(Debug, Serialize, PartialEq)]
pub struct Volumes {
    names: Vec<String>,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct Changed {
    pub(super) name: String,
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
fn seed(fingerprint: &str) -> String {
    format!("orbit-topology-seed-{}", &fingerprint[..12])
}
pub(super) fn change(host: &mut impl Host, input: &Input) -> Result<Changed, Error> {
    validate(&input.location)?;
    let target = match &input.operation {
        Operation::CloneSeed { vm, fingerprint } => {
            if !super::valid_name(vm) || fingerprint.as_ref().is_some_and(|v| !digest(v)) {
                return Err(Error::Invalid {});
            }
            format!("{vm}-topology-seed")
        }
        Operation::FinishSeed { fingerprint } => {
            if !digest(fingerprint) {
                return Err(Error::Invalid {});
            }
            seed(fingerprint)
        }
        Operation::Remove { name: target } => {
            if !name(target) {
                return Err(Error::Invalid {});
            }
            target.clone()
        }
    };
    let current = names(host, &input.location)?;
    let exists = current.contains(&target);
    let project = &input.location.project;
    let pool = &input.location.pool;
    let result = match &input.operation {
        Operation::CloneSeed { fingerprint, .. } if !exists => {
            let fingerprint = fingerprint.as_deref().ok_or(Error::SeedMissing {})?;
            let source = seed(fingerprint);
            if !current.contains(&source) {
                return Err(Error::SeedMissing {});
            }
            read(
                host,
                "storage volume copy",
                &[
                    "--project",
                    project,
                    "storage",
                    "volume",
                    "copy",
                    "--",
                    &format!("{pool}/{source}"),
                    &format!("{pool}/{target}"),
                ],
            )
        }
        Operation::FinishSeed { .. } if !exists => read(
            host,
            "storage volume rename",
            &[
                "--project",
                project,
                "storage",
                "volume",
                "rename",
                "--",
                pool,
                &format!("{target}-new"),
                &target,
            ],
        ),
        Operation::Remove { .. } if exists => read(
            host,
            "storage volume delete",
            &[
                "--project",
                project,
                "storage",
                "volume",
                "delete",
                "--",
                pool,
                &target,
            ],
        ),
        _ => return Ok(Changed { name: target }),
    };
    if let Err(error) = result {
        let observed = names(host, &input.location)?.contains(&target);
        let wanted = !matches!(input.operation, Operation::Remove { .. });
        if observed != wanted {
            return Err(error);
        }
    }
    Ok(Changed { name: target })
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
pub struct ChangeVolume;
impl Command for ChangeVolume {
    const NAME: &'static str = "incus.volume";
    const SUMMARY: &'static str =
        "Clone, finish, or remove an explicitly selected task VM topology volume.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(180);
    const RESOURCES: &'static [&'static str] = &["incus-volumes"];
    type Input = Input;
    type Output = Changed;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        crate::commands::linux_only(platform, Privilege::Root)
    }
    fn target(input: &Input) -> Option<String> {
        Some(format!(
            "incus-volumes:{}:{}",
            input.location.project, input.location.pool
        ))
    }
    fn run(input: Input, context: &Context) -> Result<Changed, Error> {
        change(&mut Native(context), &input)
    }
}

#[cfg(test)]
mod tests;
