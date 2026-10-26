//! Gateway checkout access, including the immutable release layout.
use super::{
    caddy,
    recipe::{store::Account, Files},
};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    fs, io,
    os::unix::fs::{MetadataExt, PermissionsExt},
    path::{Component, Path, PathBuf},
    time::Duration,
};

command_errors! {
    pub enum Error {
        Invalid {} => "gateway.checkout_invalid", "The Gateway checkout or one of its paths is unsafe.";
        Failed { stage: String } => "gateway.checkout_failed", "Gateway checkout access could not be prepared.";
        RecoveryFailed {} => "gateway.checkout_recovery_failed", "Prior Gateway checkout permissions could not be restored.";
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub path: String,
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
fn valid_path(path: &str) -> bool {
    path.starts_with("/home/orbit/")
        && !path.bytes().any(|b| b.is_ascii_control())
        && path
            .split('/')
            .skip(1)
            .all(|c| !c.is_empty() && c != "." && c != "..")
}
fn meta(path: &Path, uid: u32) -> Result<fs::Metadata, Error> {
    let m = fs::symlink_metadata(path).map_err(|_| Error::Invalid {})?;
    if m.uid() != 0 && m.uid() != uid {
        return Err(Error::Invalid {});
    }
    Ok(m)
}
fn directories(files: &Files, path: &Path, uid: u32) -> Result<(), Error> {
    let home = files.path("/home/orbit");
    if !path.starts_with(&home) {
        return Err(Error::Invalid {});
    }
    for parent in path.ancestors() {
        if parent == files.root {
            break;
        }
        if !meta(parent, uid)?.is_dir() {
            return Err(Error::Invalid {});
        }
    }
    Ok(())
}
fn target(files: &Files, link: &Path) -> Result<PathBuf, Error> {
    let to = fs::read_link(link).map_err(|_| Error::Invalid {})?;
    let to = if to.is_absolute() {
        files.path(to.to_str().ok_or(Error::Invalid {})?)
    } else {
        link.parent().unwrap().join(to)
    };
    let mut normalized = PathBuf::new();
    for c in to.components() {
        match c {
            Component::ParentDir => {
                if !normalized.pop() {
                    return Err(Error::Invalid {});
                }
            }
            Component::CurDir => {}
            _ => normalized.push(c.as_os_str()),
        }
    }
    Ok(normalized)
}
struct Layout {
    application: PathBuf,
    ancestors: Vec<PathBuf>,
    environment: PathBuf,
    release: bool,
}
fn inspect(files: &Files, input: &Input, uid: u32) -> Result<Layout, Error> {
    if !valid_path(&input.path) {
        return Err(Error::Invalid {});
    }
    let requested = files.path(&input.path);
    let home = files.path("/home/orbit");
    let mut application = requested.clone();
    let mut ancestors = vec![];
    let mut release = false;
    let mut shared = None;
    if input.path.ends_with("/apps/gateway") {
        let current = requested.parent().unwrap().parent().unwrap();
        if meta(current, uid)?.is_symlink() {
            let base = current.parent().ok_or(Error::Invalid {})?;
            directories(files, base, uid)?;
            let to = target(files, current)?;
            let releases = base.join("releases");
            let id = to
                .strip_prefix(&releases)
                .ok()
                .and_then(|p| p.to_str())
                .ok_or(Error::Invalid {})?;
            if id.len() != 12
                || !id
                    .bytes()
                    .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
            {
                return Err(Error::Invalid {});
            }
            let raw = fs::read_link(current).map_err(|_| Error::Invalid {})?;
            let absolute = Path::new(&input.path)
                .parent()
                .unwrap()
                .parent()
                .unwrap()
                .parent()
                .unwrap()
                .join("releases")
                .join(id);
            if raw != Path::new("releases").join(id) && raw != absolute {
                return Err(Error::Invalid {});
            }
            application = to.join("apps/gateway");
            ancestors = vec![base.to_path_buf(), releases];
            shared = Some(base.join("shared/gateway.env"));
            release = true;
        }
    }
    directories(files, &application, uid)?;
    let public = application.join("public");
    if !meta(&public, uid)?.is_dir() {
        return Err(Error::Invalid {});
    }
    let env = application.join(".env");
    let environment = if meta(&env, uid)?.is_symlink() {
        target(files, &env)?
    } else {
        env
    };
    directories(files, environment.parent().ok_or(Error::Invalid {})?, uid)?;
    if !meta(&environment, uid)?.is_file()
        || shared
            .as_ref()
            .is_some_and(|expected| expected != &environment)
    {
        return Err(Error::Invalid {});
    }
    if !release {
        let mut path = home.clone();
        ancestors.push(path.clone());
        for part in requested
            .strip_prefix(&home)
            .map_err(|_| Error::Invalid {})?
            .components()
        {
            path.push(part);
            ancestors.push(path.clone());
        }
    }
    Ok(Layout {
        application,
        ancestors,
        environment,
        release,
    })
}
#[derive(Clone, Copy, PartialEq)]
struct Access {
    uid: u32,
    gid: u32,
    mode: u32,
}
impl Access {
    fn of(m: &fs::Metadata) -> Self {
        Self {
            uid: m.uid(),
            gid: m.gid(),
            mode: m.mode() & 0o7777,
        }
    }
}
struct Change {
    path: PathBuf,
    prior: Access,
    desired: Access,
    stage: &'static str,
}
fn add(
    changes: &mut Vec<Change>,
    path: &Path,
    uid: u32,
    desired: Access,
    stage: &'static str,
) -> Result<(), Error> {
    let prior = Access::of(&meta(path, uid)?);
    if prior != desired {
        changes.push(Change {
            path: path.into(),
            prior,
            desired,
            stage,
        });
    }
    Ok(())
}
fn public_tree(changes: &mut Vec<Change>, path: &Path, uid: u32, gid: u32) -> Result<(), Error> {
    let m = meta(path, uid)?;
    if m.is_symlink() {
        return Ok(());
    }
    let mode = if m.is_dir() {
        0o750
    } else if m.is_file() {
        0o640
    } else {
        return Err(Error::Invalid {});
    };
    add(changes, path, uid, Access { uid, gid, mode }, "access")?;
    if m.is_dir() {
        let mut entries = fs::read_dir(path)
            .map_err(|_| Error::Invalid {})?
            .map(|e| e.map(|e| e.path()))
            .collect::<Result<Vec<_>, _>>()
            .map_err(|_| Error::Invalid {})?;
        entries.sort();
        for child in entries {
            public_tree(changes, &child, uid, gid)?;
        }
    }
    Ok(())
}
fn plan(files: &Files, input: &Input, uid: u32, gid: u32) -> Result<Vec<Change>, Error> {
    let layout = inspect(files, input, uid)?;
    let mut changes = vec![];
    for path in layout.ancestors {
        add(
            &mut changes,
            &path,
            uid,
            Access {
                uid,
                gid,
                mode: 0o710,
            },
            "access",
        )?;
    }
    if !layout.release {
        public_tree(&mut changes, &layout.application.join("public"), uid, gid)?;
    }
    let m = meta(&layout.environment, uid)?;
    add(
        &mut changes,
        &layout.environment,
        uid,
        Access {
            uid: m.uid(),
            gid: m.gid(),
            mode: 0o600,
        },
        "environment",
    )?;
    Ok(changes)
}
fn set(path: &Path, access: Access) -> io::Result<()> {
    caddy::chown(path, access.uid, access.gid)?;
    fs::set_permissions(path, fs::Permissions::from_mode(access.mode))
}
fn apply(
    changes: &[Change],
    mut publish: impl FnMut(&Path, Access) -> io::Result<()>,
) -> Result<Output, Error> {
    for (index, change) in changes.iter().enumerate() {
        if publish(&change.path, change.desired).is_err() {
            let mut restored = true;
            for prior in changes[..=index].iter().rev() {
                restored &= publish(&prior.path, prior.prior).is_ok();
            }
            return Err(if restored {
                failed(change.stage)
            } else {
                Error::RecoveryFailed {}
            });
        }
    }
    Ok(Output {
        changed: !changes.is_empty(),
    })
}
pub struct Check;
impl Command for Check {
    const NAME: &'static str = "gateway.check-checkout";
    const SUMMARY: &'static str =
        "Check the Gateway checkout and managed release link before convergence.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Input, _: &Context) -> Result<Output, Error> {
        let account = Account::named("orbit").map_err(|_| failed("account"))?;
        inspect(&Files::system(), &input, account.uid)?;
        Ok(Output { changed: false })
    }
}
pub struct Prepare;
impl Command for Prepare {
    const NAME: &'static str = "gateway.prepare-checkout";
    const SUMMARY: &'static str =
        "Grant Caddy checkout access while keeping private files and immutable releases protected.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("gateway-checkout".into())
    }
    fn run(input: Input, _: &Context) -> Result<Output, Error> {
        let account = Account::named("orbit").map_err(|_| failed("account"))?;
        let gid = caddy::group_id("caddy").ok_or_else(|| failed("group"))?;
        apply(&plan(&Files::system(), &input, account.uid, gid)?, set)
    }
}
#[cfg(test)]
mod tests;
