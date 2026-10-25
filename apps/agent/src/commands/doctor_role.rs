//! Read-only role prerequisite observations. The Gateway supplies its package and service intent.
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    io,
    path::Path,
    process::{Command as Process, Stdio},
    time::Duration,
};

command_errors! {
    pub enum Error {
        Invalid {} => "doctor.role_input_invalid", "Role inspection input is invalid.";
        Failed {} => "doctor.role_inspection_failed", "Role prerequisites could not be inspected.";
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    packages: Vec<String>,
    services: Vec<String>,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Observation {
    packages: bool,
    services: bool,
    caddy_version: Option<String>,
}
fn name(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 255
        && value.as_bytes()[0].is_ascii_alphanumeric()
        && value
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || b"._+@:-".contains(&b))
}
fn inspect(
    input: &Input,
    docker: &Path,
    mut run: impl FnMut(&str, &[&str]) -> io::Result<(bool, String)>,
) -> Result<Observation, Error> {
    if input.packages.len() > 256
        || input.services.len() > 256
        || !input
            .packages
            .iter()
            .chain(&input.services)
            .all(|s| name(s))
    {
        return Err(Error::Invalid {});
    }
    let mut packages = true;
    for package in &input.packages {
        if package == "docker.io" && super::role_prerequisites::docker_ce_matches(docker, &mut run)
        {
            continue;
        }
        let (ok, status) = run("dpkg-query", &["-W", "-f=${Status}", "--", package])
            .map_err(|_| Error::Failed {})?;
        packages &= ok && status.trim() == "install ok installed";
    }
    let mut services = true;
    for service in &input.services {
        services &= run("systemctl", &["is-active", "--quiet", service])
            .map_err(|_| Error::Failed {})?
            .0;
    }
    let caddy_version = if input.packages.iter().any(|s| s == "caddy") {
        match run("caddy", &["version"]) {
            Ok((_, text)) => text
                .lines()
                .next()
                .and_then(|s| s.split_whitespace().next())
                .map(str::to_owned),
            Err(e) if e.kind() == io::ErrorKind::NotFound => None,
            Err(_) => return Err(Error::Failed {}),
        }
    } else {
        None
    };
    Ok(Observation {
        packages,
        services,
        caddy_version,
    })
}
pub struct InspectRole;
impl Command for InspectRole {
    const NAME: &'static str = "doctor.inspect-role-runtime";
    const SUMMARY: &'static str =
        "Inspect role packages, services, and the installed Caddy version without repairs.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = Input;
    type Output = Observation;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(input: Input, _: &Context) -> Result<Observation, Error> {
        inspect(&input, Path::new("/usr/bin/docker"), |program, args| {
            let output = Process::new(program)
                .args(args)
                .env("LC_ALL", "C")
                .stdin(Stdio::null())
                .stderr(Stdio::null())
                .output()?;
            Ok((
                output.status.success(),
                String::from_utf8_lossy(&output.stdout).into_owned(),
            ))
        })
    }
}
#[cfg(test)]
mod tests {
    use super::*;
    use std::{fs, os::unix::fs::PermissionsExt};
    #[test]
    fn docker_ce_requires_every_package_an_executable_and_an_active_service() {
        let root =
            std::env::temp_dir().join(format!("orbit-doctor-role-{:016x}", rand::random::<u64>()));
        fs::create_dir_all(&root).unwrap();
        let docker = root.join("docker");
        fs::write(&docker, b"binary fixture").unwrap();
        for missing in [
            "",
            "docker-ce",
            "docker-ce-cli",
            "containerd.io",
            "binary",
            "docker",
            "git",
        ] {
            fs::set_permissions(
                &docker,
                fs::Permissions::from_mode(if missing == "binary" { 0o644 } else { 0o755 }),
            )
            .unwrap();
            for _ in 0..2 {
                let observation = inspect(
                    &Input {
                        packages: vec!["docker.io".into(), "git".into()],
                        services: vec!["docker".into()],
                    },
                    &docker,
                    |program, args| {
                        let item = *args.last().unwrap();
                        assert!(
                            program == "dpkg-query"
                                || (program == "systemctl" && args[0] == "is-active")
                        );
                        let ok = item != missing && item != "docker.io";
                        Ok((
                            ok,
                            if ok {
                                "install ok installed".into()
                            } else {
                                String::new()
                            },
                        ))
                    },
                )
                .unwrap();
                assert_eq!(observation.packages, missing.is_empty(), "{missing}");
                assert_eq!(observation.services, missing != "docker");
            }
        }
        fs::remove_dir_all(root).unwrap();
    }
    #[test]
    fn reports_independent_missing_requirements_and_the_first_caddy_version_word() {
        let input = Input {
            packages: vec!["caddy".into(), "git".into()],
            services: vec!["caddy".into(), "docker".into()],
        };
        let value = inspect(&input, Path::new("/absent"), |program, args| {
            Ok(match (program, args) {
                ("dpkg-query", [_, _, "--", "caddy"]) => (true, "install ok installed\n".into()),
                ("dpkg-query", [_, _, "--", "git"]) => (false, "".into()),
                ("systemctl", ["is-active", "--quiet", "caddy"]) => (true, "".into()),
                ("systemctl", ["is-active", "--quiet", "docker"]) => (false, "".into()),
                ("caddy", ["version"]) => (true, "v2.11.4 h1:digest\nother line".into()),
                _ => panic!("unexpected command"),
            })
        })
        .unwrap();
        assert_eq!(
            value,
            Observation {
                packages: false,
                services: false,
                caddy_version: Some("v2.11.4".into())
            }
        );
        assert_eq!(
            inspect(
                &Input {
                    packages: vec!["caddy".into()],
                    services: vec![]
                },
                Path::new("/absent"),
                |program, _| {
                    if program == "caddy" {
                        Err(io::ErrorKind::NotFound.into())
                    } else {
                        Ok((false, String::new()))
                    }
                }
            )
            .unwrap()
            .caddy_version,
            None
        );
    }
    #[test]
    fn refuses_invalid_names_before_observation_and_reports_unreadable_state() {
        for value in ["-option", "name;program", "", "name\nnext"] {
            assert_eq!(
                inspect(
                    &Input {
                        packages: vec![value.into()],
                        services: vec![]
                    },
                    Path::new("/absent"),
                    |_, _| panic!("must not execute")
                ),
                Err(Error::Invalid {})
            );
        }
        assert_eq!(
            inspect(
                &Input {
                    packages: vec!["git".into()],
                    services: vec![]
                },
                Path::new("/absent"),
                |_, _| Err(io::ErrorKind::PermissionDenied.into())
            ),
            Err(Error::Failed {})
        );
    }
}
