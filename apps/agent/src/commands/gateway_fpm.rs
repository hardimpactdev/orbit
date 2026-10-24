//! Gateway pool publication and connection observation. The Gateway sends configuration intent only.
use super::{
    gateway_start_hooks::{self, HookInput},
    php_fpm,
    recipe::{self, Files, Host, Invocation, Native},
};
use crate::{
    command::{registry, Command, CommandError, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    collections::BTreeSet,
    fs,
    os::unix::fs::MetadataExt,
    path::{Path, PathBuf},
    time::Duration,
};
const POOLS: &str = "/etc/php/8.5/fpm/pool.d";
const POOL: &str = "/etc/php/8.5/fpm/pool.d/orbit-gateway.conf";
command_errors! {
    pub enum Error {
        Invalid {} => "gateway.fpm_invalid", "The Gateway PHP-FPM configuration is invalid.";
        Unsafe {} => "gateway.fpm_unsafe", "Gateway PHP-FPM needs owned regular configuration files and directories.";
        Failed {stage:String} => "gateway.fpm_failed", "Gateway PHP-FPM convergence failed.";
        HookFailed {cause:String} => "gateway.fpm_hook_failed", "A Gateway startup hook refused before pool activation.";
        RecoveryFailed {} => "gateway.fpm_recovery_failed", "The previous Gateway pool or service state could not be restored.";
        ObservationFailed {} => "gateway.fpm_observation_failed", "PHP-FPM connections could not be observed; the pool is not known to be idle.";
    }
}
impl From<php_fpm::Error> for Error {
    fn from(error: php_fpm::Error) -> Self {
        match error {
            php_fpm::Error::Invalid => Self::Invalid {},
            php_fpm::Error::Unsafe => Self::Unsafe {},
            php_fpm::Error::Failed(stage) => failed(stage),
        }
    }
}
fn failed(stage: &str) -> Error {
    Error::Failed {
        stage: stage.into(),
    }
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub pool: String,
    pub hooks: Vec<HookInput>,
}
#[derive(Debug, Serialize)]
pub struct Output {
    pub changed: bool,
    pub activated: bool,
}
fn metadata(files: &Files, path: &Path, directory: bool) -> Result<Option<fs::Metadata>, Error> {
    Ok(php_fpm::metadata(path, files.uid, directory)?)
}
fn directory(files: &Files) -> Result<PathBuf, Error> {
    let path = files.path(POOLS);
    for parent in path.ancestors() {
        metadata(files, parent, true)?.ok_or(Error::Unsafe {})?;
        if parent == files.root {
            break;
        }
    }
    Ok(path)
}
fn validate(input: &Input) -> Result<(), Error> {
    if input.pool.len() > 1024 * 1024 || input.pool.contains('\0') {
        return Err(Error::Invalid {});
    }
    let mut sections = 0;
    for line in input.pool.lines().map(str::trim) {
        if line.starts_with('[') {
            if line != "[orbit-gateway]" {
                return Err(Error::Invalid {});
            }
            sections += 1;
        } else if sections == 0 && !line.is_empty() && !line.starts_with([';', '#']) {
            return Err(Error::Invalid {});
        }
    }
    if sections != 1 {
        return Err(Error::Invalid {});
    }
    gateway_start_hooks::validate(
        &gateway_start_hooks::Input {
            hooks: input.hooks.clone(),
            run: false,
        },
        registry::hooks(),
    )
    .map_err(|_| Error::HookFailed {
        cause: "gateway.start_hooks_invalid".into(),
    })?;
    Ok(())
}
fn call(
    host: &mut impl Host,
    args: &[&str],
    stage: &str,
    required: bool,
) -> Result<recipe::Output, Error> {
    recipe::run(host, &Invocation::new(args), required).map_err(|_| failed(stage))
}
fn control(host: &mut impl Host, action: &str) -> Result<(), Error> {
    call(
        host,
        &["/usr/bin/systemctl", action, "php8.5-fpm"],
        action,
        true,
    )?;
    Ok(())
}
fn state(host: &mut impl Host, action: &str) -> Result<bool, Error> {
    let result = call(
        host,
        &["/usr/bin/systemctl", action, "--quiet", "php8.5-fpm"],
        action,
        false,
    )?;
    match result.code {
        0 => Ok(true),
        1 if action == "is-enabled" => Ok(false),
        3 if action == "is-active" => Ok(false),
        _ => Err(failed(action)),
    }
}
fn converge(
    files: &Files,
    host: &mut impl Host,
    input: &Input,
    mut hooks: impl FnMut(bool) -> Result<bool, Error>,
) -> Result<Output, Error> {
    validate(input)?;
    directory(files)?;
    let path = files.path(POOL);
    let prior = metadata(files, &path, false)?;
    let before = prior
        .as_ref()
        .map(|_| fs::read(&path).map_err(|_| failed("read")))
        .transpose()?;
    let same = before.as_deref() == Some(input.pool.as_bytes());
    let active = state(host, "is-active")?;
    let enabled = state(host, "is-enabled")?;
    let activate = !same || !active;
    if activate {
        php_fpm::validate(files, host, "8.5", "orbit-gateway.conf", &input.pool)?;
    }
    // The nested command holds the startup target only until it returns. A service start must be
    // able to acquire that same target from ExecStartPre without deadlocking this publication.
    let hooks_changed = hooks(activate)?;
    let publish = !same
        || prior
            .as_ref()
            .is_none_or(|m| m.gid() != files.gid || m.mode() & 0o7777 != 0o644);
    let mut activation_attempted = false;
    let operation = (|| {
        if publish {
            files
                .publish_root(&path, input.pool.as_bytes())
                .map_err(|_| failed("publish"))?;
        }
        if !enabled {
            control(host, "enable")?;
        }
        if activate {
            activation_attempted = true;
            control(host, "reload-or-restart")?;
        }
        Ok(())
    })();
    if let Err(error) = operation {
        let mut recovered = true;
        if publish {
            recovered &= if let (Some(bytes), Some(meta)) = (&before, &prior) {
                files
                    .publish(
                        &path,
                        bytes,
                        meta.mode() & 0o7777,
                        meta.uid(),
                        meta.gid(),
                        false,
                    )
                    .is_ok()
            } else {
                match fs::remove_file(&path) {
                    Ok(()) => true,
                    Err(e) => e.kind() == std::io::ErrorKind::NotFound,
                }
            };
        }
        if !enabled {
            recovered &= control(host, "disable").is_ok();
        }
        if activation_attempted {
            recovered &= control(host, if active { "reload-or-restart" } else { "stop" }).is_ok();
        }
        return Err(if recovered {
            error
        } else {
            Error::RecoveryFailed {}
        });
    }
    Ok(Output {
        changed: publish || activate || !enabled || hooks_changed,
        activated: activate,
    })
}
pub struct Converge;
impl Command for Converge {
    const NAME: &'static str = "gateway.converge-fpm";
    const SUMMARY: &'static str =
        "Validate and activate the Gateway pool and startup hooks, preserving unchanged requests.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(180);
    const RESOURCES: &'static [&'static str] = &["php-fpm"];
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("gateway-fpm".into())
    }
    fn run(input: Input, context: &Context) -> Result<Output, Error> {
        converge(&Files::system(), &mut Native(context), &input, |run| {
            gateway_start_hooks::before_activation(
                gateway_start_hooks::Input {
                    hooks: input.hooks.clone(),
                    run,
                },
                context,
            )
            .map(|result| result.changed)
            .map_err(|error| Error::HookFailed {
                cause: error.code().into(),
            })
        })
    }
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Empty {}
#[derive(Debug, Serialize)]
pub struct ConnectionCount {
    pub connections: usize,
}
fn connections(files: &Files) -> Result<ConnectionCount, Error> {
    let observe = || Err(Error::ObservationFailed {});
    let pools = directory(files).map_err(|_| Error::ObservationFailed {})?;
    let mut sockets = BTreeSet::new();
    let mut ports = BTreeSet::new();
    for entry in fs::read_dir(pools).map_err(|_| Error::ObservationFailed {})? {
        let path = entry.map_err(|_| Error::ObservationFailed {})?.path();
        if path.extension().is_none_or(|s| s != "conf") {
            continue;
        }
        metadata(files, &path, false)
            .map_err(|_| Error::ObservationFailed {})?
            .ok_or(Error::ObservationFailed {})?;
        for line in fs::read_to_string(path)
            .map_err(|_| Error::ObservationFailed {})?
            .lines()
        {
            let Some((key, value)) = line.trim().split_once('=') else {
                continue;
            };
            if key.trim() != "listen" {
                continue;
            }
            let listen = value
                .split(';')
                .next()
                .unwrap_or_default()
                .trim()
                .trim_matches('"');
            if listen.starts_with('/') {
                sockets.insert(listen.to_owned());
            } else if let Ok(port) = listen.rsplit(':').next().unwrap_or_default().parse::<u16>() {
                if port == 0 {
                    return observe();
                }
                ports.insert(format!("{port:04X}"));
            } else {
                return observe();
            }
        }
    }
    let mut count = 0;
    for table in ["unix", "tcp", "tcp6"] {
        let text = fs::read_to_string(files.path(&format!("/proc/net/{table}")))
            .map_err(|_| Error::ObservationFailed {})?;
        if text.lines().next().is_none() {
            return observe();
        }
        for line in text.lines().skip(1).filter(|line| !line.trim().is_empty()) {
            let fields: Vec<_> = line.split_whitespace().collect();
            if table == "unix" {
                if fields.len() < 7 {
                    return observe();
                }
                if fields[5] == "03" && fields.len() > 7 && sockets.contains(&fields[7..].join(" "))
                {
                    count += 1;
                }
            } else {
                if fields.len() < 4 {
                    return observe();
                }
                let Some((_, port)) = fields[1].rsplit_once(':') else {
                    return observe();
                };
                if fields[3] == "01" && ports.contains(port) {
                    count += 1;
                }
            }
        }
    }
    Ok(ConnectionCount { connections: count })
}
pub struct Connections;
impl Command for Connections {
    const NAME: &'static str = "gateway.inspect-fpm-connections";
    const SUMMARY: &'static str =
        "Count accepted connections on every enabled Gateway PHP-FPM pool socket.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(10);
    type Input = Empty;
    type Output = ConnectionCount;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(_: Empty, _: &Context) -> Result<ConnectionCount, Error> {
        connections(&Files::system())
    }
}
#[cfg(test)]
mod tests;
