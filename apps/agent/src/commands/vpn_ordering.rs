//! Fixed systemd ordering for services that share the managed WireGuard interface.
use super::recipe::{self, Files, Host, Invocation, Native};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    fs, io,
    os::unix::fs::{MetadataExt, PermissionsExt},
    path::Path,
    time::Duration,
};

const CONTENT: &[u8] =
    b"# Managed by Orbit.\n[Unit]\nAfter=wg-quick@orbit.service\nWants=wg-quick@orbit.service\n";
command_errors! {
    pub enum Error {
        Unsafe { path: String } => "services.ordering_unsafe", "Service ordering needs an owned regular file and directories.";
        Failed { stage: String } => "services.ordering_failed", "Service ordering failed.";
        RecoveryFailed {} => "services.ordering_recovery_failed", "Service ordering failed and its prior state could not be restored.";
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Output {
    pub changed: bool,
}

fn failed(stage: &str) -> Error {
    Error::Failed {
        stage: stage.into(),
    }
}
fn inspect(path: &Path, uid: u32, directory: bool) -> Result<Option<fs::Metadata>, Error> {
    match fs::symlink_metadata(path) {
        Ok(meta)
            if meta.uid() == uid
                && if directory {
                    meta.is_dir()
                } else {
                    meta.is_file()
                } =>
        {
            Ok(Some(meta))
        }
        Ok(_) => Err(Error::Unsafe {
            path: path.display().to_string(),
        }),
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(None),
        Err(_) => Err(failed("inspect")),
    }
}
fn control(host: &mut impl Host, args: &[&str]) -> Result<(), Error> {
    let mut argv = vec!["systemctl"];
    argv.extend_from_slice(args);
    recipe::run(host, &Invocation::new(&argv), true).map_err(|_| failed(args[0]))?;
    Ok(())
}
fn active(host: &mut impl Host, service: &str) -> Result<bool, Error> {
    let result = recipe::run(
        host,
        &Invocation::new(&["systemctl", "is-active", "--quiet", service]),
        false,
    )
    .map_err(|_| failed("is-active"))?;
    match result.code {
        0 => Ok(true),
        3 => Ok(false),
        _ => Err(failed("is-active")),
    }
}
fn mode(path: &Path, meta: &fs::Metadata) -> Result<(), Error> {
    super::caddy::chown(path, meta.uid(), meta.gid()).map_err(|_| failed("ownership"))?;
    fs::set_permissions(path, fs::Permissions::from_mode(meta.mode() & 0o7777))
        .map_err(|_| failed("permissions"))
}
fn converge(files: &Files, host: &mut impl Host, install: bool) -> Result<Output, Error> {
    let service = if install { "caddy" } else { "dnsmasq" };
    // Check every fixed ancestor before reading or writing through it. Test roots are private.
    for name in ["/etc", "/etc/systemd", "/etc/systemd/system"] {
        inspect(&files.path(name), files.uid, true)?.ok_or_else(|| failed("directory"))?;
    }
    let directory = files.path(&format!("/etc/systemd/system/{service}.service.d"));
    let path = directory.join("orbit-vpn.conf");
    let prior_directory = inspect(&directory, files.uid, true)?;
    let prior = inspect(&path, files.uid, false)?;
    if !install && prior.is_none() {
        return Ok(Output { changed: false });
    }
    let bytes = prior
        .as_ref()
        .map(|_| fs::read(&path).map_err(|_| failed("read")))
        .transpose()?;
    let was_active = active(host, service)?;
    let file_changed = if install {
        bytes.as_deref() != Some(CONTENT)
            || prior
                .as_ref()
                .is_none_or(|m| m.gid() != files.gid || m.mode() & 0o7777 != 0o644)
    } else {
        true
    };
    let directory_changed = install
        && prior_directory
            .as_ref()
            .is_none_or(|m| m.gid() != files.gid || m.mode() & 0o7777 != 0o755);
    if !file_changed && !directory_changed && was_active {
        return Ok(Output { changed: false });
    }
    let operation = (|| {
        if install {
            files
                .directory(&directory, files.uid, files.gid)
                .map_err(|_| failed("directory"))?;
            if directory_changed {
                super::caddy::chown(&directory, files.uid, files.gid)
                    .map_err(|_| failed("ownership"))?;
                fs::set_permissions(&directory, fs::Permissions::from_mode(0o755))
                    .map_err(|_| failed("permissions"))?;
            }
            if file_changed {
                files
                    .publish_root(&path, CONTENT)
                    .map_err(|_| failed("publish"))?;
            }
        } else {
            fs::remove_file(&path).map_err(|_| failed("remove"))?;
            if fs::read_dir(&directory)
                .map_err(|_| failed("directory"))?
                .next()
                .is_none()
            {
                fs::remove_dir(&directory).map_err(|_| failed("directory"))?;
            }
        }
        if file_changed {
            control(host, &["daemon-reload"])?;
        }
        if file_changed || !was_active {
            control(host, &["restart", service])?;
        }
        Ok(Output { changed: true })
    })();
    if operation.is_err() {
        // A failed activation must not leave the new dependency or a changed service state behind.
        let restore = (|| -> Result<(), Error> {
            if let Some(meta) = &prior_directory {
                files
                    .directory(&directory, files.uid, meta.gid())
                    .map_err(|_| failed("restore-directory"))?;
            }
            if let (Some(meta), Some(bytes)) = (&prior, &bytes) {
                files
                    .publish(
                        &path,
                        bytes,
                        meta.mode() & 0o7777,
                        meta.uid(),
                        meta.gid(),
                        false,
                    )
                    .map_err(|_| failed("restore-file"))?;
            } else if path.exists() {
                fs::remove_file(&path).map_err(|_| failed("restore-file"))?;
            }
            if let Some(meta) = &prior_directory {
                mode(&directory, meta)?;
            } else if directory.exists() {
                fs::remove_dir(&directory).map_err(|_| failed("restore-directory"))?;
            }
            if file_changed {
                control(host, &["daemon-reload"])?;
            }
            control(
                host,
                &[if was_active { "restart" } else { "stop" }, service],
            )?;
            Ok(())
        })();
        if restore.is_err() {
            return Err(Error::RecoveryFailed {});
        }
    }
    operation
}

pub struct OrderCaddy;
impl Command for OrderCaddy {
    const NAME: &'static str = "caddy.order-after-vpn";
    const SUMMARY: &'static str = "Order Caddy after the managed WireGuard interface.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("caddy".into())
    }
    fn run(_: Input, context: &Context) -> Result<Output, Error> {
        let _lock = super::caddy::lock().map_err(|_| failed("lock"))?;
        converge(&Files::system(), &mut Native(context), true)
    }
}
pub struct RemoveDnsOrdering;
impl Command for RemoveDnsOrdering {
    const NAME: &'static str = "wireguard.remove-dns-ordering";
    const SUMMARY: &'static str =
        "Remove the dnsmasq dependency that creates a WireGuard boot cycle.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("wireguard-hub".into())
    }
    fn run(_: Input, context: &Context) -> Result<Output, Error> {
        converge(&Files::system(), &mut Native(context), false)
    }
}
#[cfg(test)]
mod tests;
