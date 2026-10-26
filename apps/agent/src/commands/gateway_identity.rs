//! Gateway host identity. CA authority and known-host records remain control-plane data.
use super::{
    incus::bounded,
    recipe::{Files, WorkDir},
};
use crate::{
    command::{identity, Command, Context, Platform, Privilege},
    command_errors,
};
use base64::{engine::general_purpose::STANDARD, Engine};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::{
    fs,
    os::unix::fs::{DirBuilderExt, MetadataExt, PermissionsExt},
    path::{Path, PathBuf},
    time::Duration,
};

command_errors! {
    pub enum Error {
        Invalid {} => "gateway.identity_invalid", "The Gateway identity paths or existing keys are invalid.";
        Directory {} => "gateway.directory_failed", "The Gateway identity directories could not be prepared.";
        Ssh {} => "gateway.ssh_key_failed", "The Gateway SSH key could not be prepared.";
        WireGuard {} => "vpn.key_generation_failed", "The Gateway WireGuard key could not be prepared.";
        SelfAccess {} => "gateway.self_access_failed", "The Gateway SSH self-access could not be prepared.";
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    orbit_home: PathBuf,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Output {
    wireguard_public_key: String,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct AccessInput {
    user: String,
    public_key: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct HostKey {
    key_type: String,
    value: String,
    fingerprint: String,
}

fn meta(path: &Path) -> Result<Option<fs::Metadata>, Error> {
    super::recipe::metadata(path).map_err(|_| Error::Invalid {})
}
fn directory(path: &Path, uid: u32, required: bool) -> Result<(), Error> {
    match meta(path)? {
        Some(m) if m.is_dir() && m.uid() == uid => Ok(()),
        None if !required => Ok(()),
        _ => Err(Error::Invalid {}),
    }
}
fn read(path: &Path, uid: u32) -> Result<Option<String>, Error> {
    match meta(path)? {
        None => Ok(None),
        Some(m) if m.is_file() && m.uid() == uid && m.len() <= 16_384 => fs::read_to_string(path)
            .map(Some)
            .map_err(|_| Error::Invalid {}),
        _ => Err(Error::Invalid {}),
    }
}
fn home(path: &Path, uid: u32) -> Result<(), Error> {
    if !path.is_absolute()
        || path.to_str().is_none_or(|value| {
            value
                .split('/')
                .skip(1)
                .any(|part| matches!(part, "" | "." | ".."))
        })
        || path.parent().is_none()
        || fs::canonicalize(path).ok().as_deref() != Some(path)
    {
        return Err(Error::Invalid {});
    }
    directory(path, uid, true)
}
fn make_directory(path: &Path) -> Result<(), Error> {
    if meta(path)?.is_none() {
        fs::DirBuilder::new()
            .mode(0o700)
            .create(path)
            .map_err(|_| Error::Directory {})?;
    }
    fs::set_permissions(path, fs::Permissions::from_mode(0o700)).map_err(|_| Error::Directory {})
}
fn publish(path: &Path, bytes: &[u8], mode: u32) -> Result<(), Error> {
    let uid = identity::effective_uid();
    let gid = rustix::process::getegid().as_raw();
    if read(path, uid)?.as_deref().map(str::as_bytes) != Some(bytes) {
        Files::system()
            .publish(path, bytes, mode, uid, gid, false)
            .map_err(|_| Error::Invalid {})?;
    } else {
        fs::set_permissions(path, fs::Permissions::from_mode(mode))
            .map_err(|_| Error::Invalid {})?;
    }
    Ok(())
}
fn ssh_public(text: &str) -> Result<HostKey, Error> {
    if text.len() > 16_384 {
        return Err(Error::Invalid {});
    }
    let parts: Vec<_> = text.split_whitespace().collect();
    if text.trim().contains(['\n', '\r']) || parts.len() < 2 || parts[0] != "ssh-ed25519" {
        return Err(Error::Invalid {});
    }
    let bytes = STANDARD.decode(parts[1]).map_err(|_| Error::Invalid {})?;
    if bytes.len() != 51 || &bytes[..19] != b"\0\0\0\x0bssh-ed25519\0\0\0\x20" {
        return Err(Error::Invalid {});
    }
    Ok(HostKey {
        key_type: parts[0].into(),
        value: parts[1].into(),
        fingerprint: format!(
            "SHA256:{}",
            STANDARD.encode(Sha256::digest(bytes)).trim_end_matches('=')
        ),
    })
}
fn wg_key(text: &str) -> bool {
    STANDARD
        .decode(text.trim())
        .is_ok_and(|bytes| bytes.len() == 32)
}
fn execute(
    context: &Context,
    executable: &str,
    args: &[&str],
    input: Option<&str>,
) -> Result<String, ()> {
    let out = bounded(context, executable, args, input);
    if out.code != 0 || out.truncated {
        return Err(());
    }
    Ok(out.stdout)
}
fn prepare(
    input: &Input,
    mut run: impl FnMut(&str, &[&str], Option<&str>) -> Result<String, ()>,
) -> Result<Output, Error> {
    let uid = identity::effective_uid();
    home(&input.orbit_home, uid)?;
    let ssh = input.orbit_home.join("ssh");
    let wireguard = input.orbit_home.join("wireguard");
    for path in [&ssh, &wireguard] {
        directory(path, uid, false)?;
    }
    let ssh_private_path = ssh.join("id_ed25519");
    let ssh_public_path = ssh.join("id_ed25519.pub");
    let wg_private_path = wireguard.join("private.key");
    let wg_public_path = wireguard.join("public.key");
    let ssh_private = read(&ssh_private_path, uid)?;
    let ssh_existing_public = read(&ssh_public_path, uid)?;
    let wg_private = read(&wg_private_path, uid)?;
    let wg_existing_public = read(&wg_public_path, uid)?;
    if (ssh_private.is_none() && ssh_existing_public.is_some())
        || (wg_private.is_none() && wg_existing_public.is_some())
    {
        return Err(Error::Invalid {});
    }
    // Derive and validate both pairs before changing live state. The scratch key is private even if
    // an existing key needs its permissions repaired, and private bytes never become diagnostics.
    let scratch = WorkDir::new().map_err(|_| Error::Ssh {})?;
    let candidate = scratch.0.join("key");
    if let Some(private) = &ssh_private {
        publish(&candidate, private.as_bytes(), 0o600).map_err(|_| Error::Ssh {})?;
    } else {
        run(
            "/usr/bin/ssh-keygen",
            &[
                "-q",
                "-t",
                "ed25519",
                "-N",
                "",
                "-C",
                "orbit-gateway",
                "-f",
                candidate.to_str().ok_or(Error::Invalid {})?,
            ],
            None,
        )
        .map_err(|_| Error::Ssh {})?;
    }
    let public = run(
        "/usr/bin/ssh-keygen",
        &[
            "-y",
            "-P",
            "",
            "-f",
            candidate.to_str().ok_or(Error::Invalid {})?,
        ],
        None,
    )
    .map_err(|_| Error::Ssh {})?;
    let derived = ssh_public(&public)?;
    if let Some(existing) = &ssh_existing_public {
        if ssh_public(existing)? != derived {
            return Err(Error::Invalid {});
        }
    }
    let private = match wg_private {
        Some(key) if wg_key(&key) => key,
        Some(_) => return Err(Error::Invalid {}),
        None => run("/usr/bin/wg", &["genkey"], None).map_err(|_| Error::WireGuard {})?,
    };
    if !wg_key(&private) {
        return Err(Error::WireGuard {});
    }
    let wg_public =
        run("/usr/bin/wg", &["pubkey"], Some(&private)).map_err(|_| Error::WireGuard {})?;
    if !wg_key(&wg_public) {
        return Err(Error::WireGuard {});
    }
    if wg_existing_public
        .as_ref()
        .is_some_and(|old| old.trim() != wg_public.trim())
    {
        return Err(Error::Invalid {});
    }
    let private_bytes = fs::read(&candidate).map_err(|_| Error::Ssh {})?;
    for path in [&ssh, &wireguard] {
        make_directory(path)?;
    }
    publish(&ssh_private_path, &private_bytes, 0o600).map_err(|_| Error::Ssh {})?;
    let public = ssh_existing_public
        .unwrap_or_else(|| format!("{} {} orbit-gateway\n", derived.key_type, derived.value));
    publish(&ssh_public_path, public.as_bytes(), 0o644).map_err(|_| Error::Ssh {})?;
    publish(&wg_private_path, private.as_bytes(), 0o600).map_err(|_| Error::WireGuard {})?;
    publish(
        &wg_public_path,
        format!("{}\n", wg_public.trim()).as_bytes(),
        0o644,
    )
    .map_err(|_| Error::WireGuard {})?;
    Ok(Output {
        wireguard_public_key: wg_public.trim().into(),
    })
}
fn self_access(
    input: &AccessInput,
    account_home: &Path,
    host_key_path: &Path,
) -> Result<HostKey, Error> {
    let uid = identity::effective_uid();
    home(account_home, uid)?;
    ssh_public(&input.public_key)?;
    let host_key = fs::read_to_string(host_key_path).map_err(|_| Error::SelfAccess {})?;
    let host_key = ssh_public(&host_key).map_err(|_| Error::SelfAccess {})?;
    let ssh = account_home.join(".ssh");
    directory(&ssh, uid, false)?;
    let path = ssh.join("authorized_keys");
    let mut contents = read(&path, uid)?.unwrap_or_default();
    let key = input.public_key.trim();
    if !contents.lines().any(|line| line == key) {
        if !contents.is_empty() && !contents.ends_with('\n') {
            contents.push('\n');
        }
        contents.push_str(key);
        contents.push('\n');
    }
    make_directory(&ssh)?;
    publish(&path, contents.as_bytes(), 0o600).map_err(|_| Error::SelfAccess {})?;
    Ok(host_key)
}
pub struct Prepare;
impl Command for Prepare {
    const NAME: &'static str = "gateway.prepare-identity";
    const SUMMARY: &'static str =
        "Prepare the Gateway account's SSH and WireGuard identity without replacing existing keys.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn target(input: &Input) -> Option<String> {
        Some(format!("gateway-identity:{}", input.orbit_home.display()))
    }
    fn run(input: Input, context: &Context) -> Result<Output, Error> {
        prepare(&input, |program, args, input| {
            execute(context, program, args, input)
        })
    }
}
pub struct SelfAccess;
impl Command for SelfAccess {
    const NAME: &'static str = "gateway.converge-self-access";
    const SUMMARY: &'static str =
        "Authorize the Gateway account's managed key and read its SSH host identity.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = AccessInput;
    type Output = HostKey;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn target(input: &AccessInput) -> Option<String> {
        Some(format!("gateway-self-access:{}", input.user))
    }
    fn run(input: AccessInput, _: &Context) -> Result<HostKey, Error> {
        let account = identity::passwd(&input.user)
            .filter(|a| a.uid == identity::effective_uid())
            .ok_or(Error::SelfAccess {})?;
        self_access(
            &input,
            Path::new(&account.home),
            Path::new("/etc/ssh/ssh_host_ed25519_key.pub"),
        )
    }
}
#[cfg(test)]
mod tests;
