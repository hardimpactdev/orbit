//! `nodes.prepare-storage-root`: checks, and optionally creates, a Node storage root that the managed user owns. Every
//! existing component must be a real directory, with no symlink on the way. Missing components are created as the
//! managed user with mode `0755`. The root itself must belong to the managed user and its group, with no write bit for
//! the group or other users.

use super::caddy::{chown, group_id};
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    fs,
    os::unix::fs::{DirBuilderExt, MetadataExt},
    path::{Component, Path, PathBuf},
    time::Duration,
};

command_errors! {
    pub enum StorageRootError {
        Unusable { reason: String } => "nodes.storage_root_unusable", "The storage root is not a directory the managed user owns.";
    }
}

fn unusable(reason: &str) -> StorageRootError {
    StorageRootError::Unusable {
        reason: reason.into(),
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct StorageRootInput {
    pub root: PathBuf,
    pub user: String,
    pub group: String,
    /// Create missing components; otherwise a missing component fails.
    pub create_missing: bool,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct StorageRoot {}

fn user_id(name: &str) -> Option<u32> {
    crate::command::identity::passwd(name).map(|entry| entry.uid)
}

/// A directory that is no symlink and whose canonical path is itself.
fn real_directory(path: &Path) -> bool {
    fs::symlink_metadata(path).is_ok_and(|metadata| metadata.is_dir())
        && fs::canonicalize(path).ok().as_deref() == Some(path)
}

/// Owner `rwx`, and neither group nor other users may write.
fn mode_closed(mode: u32) -> bool {
    mode & 0o700 == 0o700 && mode & 0o022 == 0
}

pub fn prepare(input: &StorageRootInput) -> Result<StorageRoot, StorageRootError> {
    let root = &input.root;
    if !root.is_absolute()
        || root
            .components()
            .any(|part| !matches!(part, Component::RootDir | Component::Normal(_)))
    {
        return Err(unusable("the root is an absolute path"));
    }
    let (Some(uid), Some(gid)) = (user_id(&input.user), group_id(&input.group)) else {
        return Err(unusable("the managed account exists"));
    };
    let mut current = PathBuf::from("/");
    for part in root.components().skip(1) {
        current.push(part);
        if fs::symlink_metadata(&current).is_ok() {
            if !real_directory(&current) {
                return Err(unusable("every component is a real directory"));
            }
            continue;
        }
        if !input.create_missing {
            return Err(unusable("the root exists"));
        }
        fs::DirBuilder::new()
            .mode(0o755)
            .create(&current)
            .and_then(|()| chown(&current, uid, gid))
            .map_err(|_| unusable("a missing component could be created"))?;
    }
    let metadata = fs::symlink_metadata(root).map_err(|_| unusable("the root exists"))?;
    if metadata.uid() != uid || metadata.gid() != gid {
        return Err(unusable("the managed user and group own the root"));
    }
    if !mode_closed(metadata.mode() & 0o777) {
        return Err(unusable("only the managed user may write the root"));
    }
    Ok(StorageRoot {})
}

pub struct PrepareStorageRoot;

impl Command for PrepareStorageRoot {
    const NAME: &'static str = "nodes.prepare-storage-root";
    const SUMMARY: &'static str =
        "Check a storage root the managed user owns, and create its missing directories.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = StorageRootInput;
    type Output = StorageRoot;
    type Error = StorageRootError;

    /// A root outside the managed home needs root to create and hand over.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(input: StorageRootInput, _: &Context) -> Result<StorageRoot, StorageRootError> {
        prepare(&input)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::os::unix::fs::PermissionsExt;

    fn me() -> (String, String) {
        let id = |flag: &str| {
            String::from_utf8(
                std::process::Command::new("id")
                    .arg(flag)
                    .output()
                    .unwrap()
                    .stdout,
            )
            .unwrap()
            .trim()
            .to_string()
        };
        (id("-un"), id("-gn"))
    }

    fn input(root: PathBuf, create_missing: bool) -> StorageRootInput {
        let (user, group) = me();
        StorageRootInput {
            root,
            user,
            group,
            create_missing,
        }
    }

    #[test]
    fn creates_missing_directories_and_refuses_them_when_inspecting() {
        let base = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!("orbit-storage-{:016x}", rand::random::<u64>()));
        let root = base.join("apps/one");

        assert!(prepare(&input(root.clone(), false)).is_err());
        assert_eq!(prepare(&input(root.clone(), true)), Ok(StorageRoot {}));
        assert_eq!(fs::metadata(&root).unwrap().mode() & 0o777, 0o755);
        assert_eq!(prepare(&input(root.clone(), false)), Ok(StorageRoot {}));

        fs::set_permissions(&root, fs::Permissions::from_mode(0o775)).unwrap();
        assert!(prepare(&input(root.clone(), false)).is_err());
        let _ = fs::remove_dir_all(&base);
    }

    #[test]
    fn refuses_a_symlinked_component_and_relative_roots() {
        let base = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!("orbit-storage-{:016x}", rand::random::<u64>()));
        fs::create_dir_all(base.join("real")).unwrap();
        std::os::unix::fs::symlink(base.join("real"), base.join("link")).unwrap();

        assert!(prepare(&input(base.join("link/apps"), true)).is_err());
        assert!(!base.join("real/apps").exists());
        assert!(prepare(&input(PathBuf::from("apps"), true)).is_err());
        assert!(prepare(&input(base.join("a/../b"), true)).is_err());
        let _ = fs::remove_dir_all(&base);
    }

    #[test]
    fn a_closed_mode_lets_only_the_owner_write() {
        assert!(mode_closed(0o755) && mode_closed(0o700) && mode_closed(0o751));
        assert!(!mode_closed(0o775) && !mode_closed(0o757) && !mode_closed(0o555));
    }
}
