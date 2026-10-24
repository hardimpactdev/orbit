//! The Gateway's static web release directory. Release contents are prepared separately.
use super::{caddy, recipe::Files};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    fs, io,
    os::unix::fs::{DirBuilderExt, MetadataExt, PermissionsExt},
    path::Path,
    time::Duration,
};

command_errors! {
    pub enum Error {
        Invalid {} => "gateway.web_directory_invalid", "The Gateway web directory must be a direct child of /home/orbit.";
        Failed {} => "gateway.web_directory_failed", "Gateway web directory convergence failed.";
    }
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub path: String,
}
#[derive(Debug, Serialize)]
pub struct Output {
    pub changed: bool,
}

fn validate(path: &str) -> Result<(), Error> {
    let name = path.strip_prefix("/home/orbit/").ok_or(Error::Invalid {})?;
    if name.is_empty()
        || !name.as_bytes()[0].is_ascii_alphanumeric() && !matches!(name.as_bytes()[0], b'_' | b'-')
        || !name
            .bytes()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, b'_' | b'-' | b'.'))
    {
        return Err(Error::Invalid {});
    }
    Ok(())
}
fn directory(path: &Path) -> Result<Option<fs::Metadata>, Error> {
    match fs::symlink_metadata(path) {
        Ok(meta) if meta.is_dir() => Ok(Some(meta)),
        Ok(_) => Err(Error::Failed {}),
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(None),
        Err(_) => Err(Error::Failed {}),
    }
}
fn prepare(
    files: &Files,
    input: &Input,
    owner: impl FnOnce() -> Result<(u32, u32), Error>,
) -> Result<Output, Error> {
    validate(&input.path)?;
    let home = files.path("/home/orbit");
    for parent in home.ancestors() {
        directory(parent)?.ok_or(Error::Failed {})?;
    }
    let web = files.path(&input.path);
    let releases = web.join("releases");
    let paths = [&web, &releases];
    let metadata = paths
        .map(|path| directory(path))
        .into_iter()
        .collect::<Result<Vec<_>, _>>()?;
    let (uid, gid) = owner()?;
    let mut changed = false;
    for (path, meta) in paths.into_iter().zip(metadata) {
        if meta.as_ref().is_some_and(|meta| {
            meta.uid() == uid && meta.gid() == gid && meta.mode() & 0o7777 == 0o750
        }) {
            continue;
        }
        if meta.is_none() {
            fs::DirBuilder::new()
                .mode(0o750)
                .create(path)
                .map_err(|_| Error::Failed {})?;
        }
        caddy::chown(path, uid, gid).map_err(|_| Error::Failed {})?;
        fs::set_permissions(path, fs::Permissions::from_mode(0o750))
            .map_err(|_| Error::Failed {})?;
        changed = true;
    }
    Ok(Output { changed })
}

pub struct PrepareDirectory;
impl Command for PrepareDirectory {
    const NAME: &'static str = "gateway.prepare-web-directory";
    const SUMMARY: &'static str = "Prepare the Gateway web release directories for Caddy.";
    const MUTATES: bool = true;
    const RESOURCES: &'static [&'static str] = &["gateway-web"];
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("gateway-web-directory".into())
    }
    fn run(input: Input, _: &Context) -> Result<Output, Error> {
        prepare(&Files::system(), &input, || {
            let account =
                super::recipe::store::Account::named("orbit").map_err(|_| Error::Failed {})?;
            let gid = caddy::group_id("caddy").ok_or(Error::Failed {})?;
            Ok((account.uid, gid))
        })
    }
}

#[cfg(test)]
mod tests;
