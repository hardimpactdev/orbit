//! The Gateway's CA-verified certificate, published as one version under the Caddy lock.
use super::recipe::{self, Files, Host, Invocation, Native, WorkDir};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    fs, io,
    os::unix::fs::{symlink, MetadataExt, PermissionsExt},
    path::{Path, PathBuf},
    time::Duration,
};
const VERSIONS: &str = "/etc/caddy/orbit-cert-versions";
const CURRENT: &str = "/etc/caddy/orbit-cert-current";
const NAMES: [&str; 3] = ["gateway.pem", "gateway.key", "root-ca.pem"];
command_errors! {
    pub enum Error {
        Invalid {} => "gateway.certificate_invalid", "The Gateway certificate, key, or CA is invalid.";
        Unsafe {} => "gateway.certificate_unsafe", "The Gateway certificate paths are not owned by Orbit.";
        Failed { stage: String } => "gateway.certificate_failed", "Gateway certificate publication failed.";
        RecoveryFailed {} => "gateway.certificate_recovery_failed", "The prior Gateway certificate or service could not be restored.";
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub certificate: String,
    pub key: String,
    pub root_certificate: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Output {
    pub changed: bool,
}
fn failed(stage: &str) -> Error {
    Error::Failed {
        stage: stage.into(),
    }
}
fn metadata(path: &Path) -> Result<Option<fs::Metadata>, Error> {
    match fs::symlink_metadata(path) {
        Ok(meta) => Ok(Some(meta)),
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(None),
        Err(_) => Err(failed("inspect")),
    }
}
fn directory(path: &Path, uid: u32, group: Option<u32>) -> Result<bool, Error> {
    let Some(meta) = metadata(path)? else {
        return Ok(false);
    };
    if !meta.is_dir()
        || meta.uid() != uid
        || group.is_some_and(|gid| meta.gid() != gid || meta.mode() & 0o7777 != 0o750)
    {
        return Err(Error::Unsafe {});
    }
    Ok(true)
}
fn contents(input: &Input) -> [&str; 3] {
    [&input.certificate, &input.key, &input.root_certificate]
}
type Previous = (PathBuf, Vec<Vec<u8>>);
fn previous(files: &Files, gid: u32) -> Result<Option<Previous>, Error> {
    let path = files.path(CURRENT);
    let Some(meta) = metadata(&path)? else {
        return Ok(None);
    };
    if !meta.is_symlink() || meta.uid() != files.uid || meta.gid() != files.gid {
        return Err(Error::Unsafe {});
    }
    let target = fs::read_link(&path).map_err(|_| failed("inspect"))?;
    let Some(name) = target
        .to_str()
        .and_then(|s| s.strip_prefix(&format!("{VERSIONS}/")))
    else {
        return Err(Error::Unsafe {});
    };
    if name.len() != 16
        || !name
            .bytes()
            .all(|b| b.is_ascii_hexdigit() && !b.is_ascii_uppercase())
    {
        return Err(Error::Unsafe {});
    }
    let resolved = files.path(target.to_str().unwrap());
    if !directory(&resolved, files.uid, Some(gid))? {
        return Err(Error::Unsafe {});
    }
    let mut bytes = vec![];
    for name in NAMES {
        let file = resolved.join(name);
        let meta = metadata(&file)?.ok_or(Error::Unsafe {})?;
        if !meta.is_file()
            || meta.uid() != files.uid
            || meta.gid() != gid
            || meta.mode() & 0o7777 != 0o640
        {
            return Err(Error::Unsafe {});
        }
        bytes.push(fs::read(file).map_err(|_| failed("read"))?);
    }
    Ok(Some((target, bytes)))
}
fn validate(files: &Files, input: &Input, host: &mut impl Host) -> Result<(), Error> {
    if contents(input)
        .iter()
        .any(|s| s.is_empty() || s.len() > 1024 * 1024 || s.contains('\0'))
    {
        return Err(Error::Invalid {});
    }
    let work = WorkDir::new().map_err(|_| failed("validate"))?;
    for (name, bytes) in NAMES.into_iter().zip(contents(input)) {
        files
            .publish(
                &work.0.join(name),
                bytes.as_bytes(),
                0o600,
                files.uid,
                files.gid,
                false,
            )
            .map_err(|_| failed("validate"))?;
    }
    let certificate = work.0.join("gateway.pem");
    let key = work.0.join("gateway.key");
    let ca = work.0.join("root-ca.pem");
    let mut read = |argv: &[&str]| {
        recipe::run(host, &Invocation::new(argv), true)
            .map(|r| r.stdout)
            .map_err(|_| Error::Invalid {})
    };
    read(&[
        "openssl",
        "verify",
        "-CAfile",
        ca.to_str().unwrap(),
        certificate.to_str().unwrap(),
    ])?;
    let public = read(&[
        "openssl",
        "x509",
        "-in",
        certificate.to_str().unwrap(),
        "-pubkey",
        "-noout",
    ])?;
    let private = read(&["openssl", "pkey", "-in", key.to_str().unwrap(), "-pubout"])?;
    if public.trim().is_empty() || public.trim() != private.trim() {
        return Err(Error::Invalid {});
    }
    Ok(())
}
fn control(host: &mut impl Host, action: &str) -> Result<(), Error> {
    recipe::run(
        host,
        &Invocation::new(&["systemctl", action, "caddy"]),
        true,
    )
    .map_err(|_| failed("reload"))?;
    Ok(())
}
fn point(files: &Files, target: &Path) -> Result<(), Error> {
    let staged = files.path(&format!(
        "{CURRENT}.candidate.{:016x}",
        rand::random::<u64>()
    ));
    symlink(target, &staged).map_err(|_| failed("publish"))?;
    let result = fs::rename(&staged, files.path(CURRENT)).map_err(|_| failed("publish"));
    if result.is_err() {
        let _ = fs::remove_file(&staged);
    }
    result
}
fn publish(files: &Files, gid: u32, input: &Input, host: &mut impl Host) -> Result<Output, Error> {
    for name in ["/etc", "/etc/caddy"] {
        if !directory(&files.path(name), files.uid, None)? {
            return Err(Error::Unsafe {});
        }
    }
    let versions = files.path(VERSIONS);
    let had_versions = directory(&versions, files.uid, Some(gid))?;
    let prior = previous(files, gid)?;
    validate(files, input, host)?;
    if prior.as_ref().is_some_and(|(_, bytes)| {
        bytes
            .iter()
            .zip(contents(input))
            .all(|(a, b)| a == b.as_bytes())
    }) {
        return Ok(Output { changed: false });
    }
    let active = recipe::run(
        host,
        &Invocation::new(&["systemctl", "is-active", "--quiet", "caddy"]),
        false,
    )
    .map_err(|_| failed("state"))?;
    let active = match active.code {
        0 => true,
        3 => false,
        _ => return Err(failed("state")),
    };
    let target = Path::new(VERSIONS).join(format!("{:016x}", rand::random::<u64>()));
    let version = files.path(target.to_str().unwrap());
    let mut created = false;
    let mut switched = false;
    let result = (|| {
        if !had_versions {
            files
                .directory(&versions, files.uid, gid)
                .map_err(|_| failed("stage"))?;
            fs::set_permissions(&versions, fs::Permissions::from_mode(0o750))
                .map_err(|_| failed("stage"))?;
        }
        fs::create_dir(&version).map_err(|_| failed("stage"))?;
        created = true;
        super::caddy::chown(&version, files.uid, gid).map_err(|_| failed("stage"))?;
        fs::set_permissions(&version, fs::Permissions::from_mode(0o750))
            .map_err(|_| failed("stage"))?;
        for (name, bytes) in NAMES.into_iter().zip(contents(input)) {
            files
                .publish(
                    &version.join(name),
                    bytes.as_bytes(),
                    0o640,
                    files.uid,
                    gid,
                    false,
                )
                .map_err(|_| failed("stage"))?;
        }
        point(files, &target)?;
        switched = true;
        if active {
            control(host, "reload-or-restart")?;
        }
        Ok(Output { changed: true })
    })();
    if result.is_err() {
        if switched {
            let restored = match &prior {
                Some((target, _)) => point(files, target),
                None => fs::remove_file(files.path(CURRENT)).map_err(|_| failed("restore")),
            };
            // Keep the candidate if restoring the link failed: it may still serve the live site.
            if restored.is_err() {
                return Err(Error::RecoveryFailed {});
            }
        }
        let service_restored = !switched || !active || control(host, "restart").is_ok();
        let removed = !created || fs::remove_dir_all(&version).is_ok();
        let directory_removed =
            had_versions || !versions.exists() || fs::remove_dir(&versions).is_ok();
        if !service_restored || !removed || !directory_removed {
            return Err(Error::RecoveryFailed {});
        }
    }
    result
}
pub struct Publish;
impl Command for Publish {
    const NAME: &'static str = "gateway.publish-certificate";
    const SUMMARY: &'static str =
        "Validate and publish the Gateway certificate with Caddy activation recovery.";
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
    fn run(input: Input, context: &Context) -> Result<Output, Error> {
        let _lock = super::caddy::lock().map_err(|_| failed("lock"))?;
        let gid = super::caddy::group_id("caddy").ok_or_else(|| failed("group"))?;
        publish(&Files::system(), gid, &input, &mut Native(context))
    }
}
#[cfg(test)]
mod tests;
