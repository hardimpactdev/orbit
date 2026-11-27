//! `file.ensure`: one file, folder or link, with its owner and mode, or its absence. Lifted from `Site::file`, `Site::link`
//! and `replace` of the route family.

use std::fs as stdfs;
use std::os::unix::fs::{MetadataExt as _, PermissionsExt as _};
use std::path::Path;

use serde::Deserialize;

use crate::fs;
use crate::{absolute, invalid, parse, sha256, BlobRef, Failure, Owner, System, DIGEST};

#[derive(Debug, Deserialize)]
#[serde(tag = "state", rename_all = "snake_case", deny_unknown_fields)]
enum Input {
    /// A plain file with this content. When `from_sha256` is set, a live file must still hold that digest, or already the
    /// content, else nothing changes.
    File { path: String, content: BlobRef, owner: Owner, from_sha256: Option<String> },
    /// A folder, made with its parents when missing.
    Directory { path: String, owner: Owner },
    /// A link to `target`, replaced atomically when it differs; an empty folder in its place is removed, a full one fails.
    Link { path: String, target: String, user: String, group: String },
    /// Nothing at the path. A folder goes with its content only when `recursive` is set.
    Absent {
        path: String,
        #[serde(default)]
        recursive: bool,
    },
}

/// Converges the path and answers how many changes it made: `0` on a repeat.
///
/// # Errors
///
/// `host.input_invalid` for an input outside the admitted shape, `host.content_missing` for a blob the executor did not
/// read, `owned_revision_conflict` when a guarded file changed outside the render, and `file.ensure_failed` for any failed
/// change or read-back.
pub fn file_ensure(system: &impl System, input: &serde_json::Value) -> Result<u32, Failure> {
    let input: Input = parse(input)?;
    let failed = |detail: String| Failure::new("file.ensure_failed", detail);
    match input {
        Input::File { path, content, owner, from_sha256 } => {
            if !absolute(&path) || !owner.valid() || from_sha256.as_ref().is_some_and(|from| !DIGEST.is_match(from)) {
                return Err(invalid("file"));
            }
            let bytes = content.bytes(system)?;
            let ids = owner.ids(system, "file.ensure_failed")?;
            let dest = system.path(&path);
            if let Some(from) = &from_sha256 {
                let live = fs::read(&dest).map_err(|error| failed(error.to_string()))?;
                if live.as_ref().is_some_and(|(current, _)| *current != bytes && sha256(current) != *from) {
                    return Err(Failure::new("owned_revision_conflict", format!("{path} changed outside this render")));
                }
            }
            let changed = fs::ensure(&dest, &bytes, ids).map_err(|error| failed(error.to_string()))?;
            if fs::read(&dest).map_err(|error| failed(error.to_string()))?.is_none_or(|(current, held)| current != bytes || held != ids) {
                return Err(failed(format!("{path} read-back")));
            }
            Ok(u32::from(changed))
        }
        Input::Directory { path, owner } => {
            if !absolute(&path) || !owner.valid() {
                return Err(invalid("directory"));
            }
            let ids = owner.ids(system, "file.ensure_failed")?;
            let dest = system.path(&path);
            let mut changes = 0;
            if !stdfs::symlink_metadata(&dest).is_ok_and(|meta| meta.file_type().is_dir()) {
                stdfs::create_dir_all(&dest).map_err(|error| failed(error.to_string()))?;
                changes += 1;
            }
            let meta = stdfs::symlink_metadata(&dest).map_err(|error| failed(error.to_string()))?;
            if (meta.uid(), meta.gid()) != (ids.uid, ids.gid) {
                system.lchown(&dest, ids.uid, ids.gid).map_err(failed)?;
                changes += 1;
            }
            if meta.mode() & 0o7777 != ids.mode {
                stdfs::set_permissions(&dest, stdfs::Permissions::from_mode(ids.mode)).map_err(|error| failed(error.to_string()))?;
                changes += 1;
            }
            Ok(changes)
        }
        Input::Link { path, target, user, group } => {
            if !absolute(&path) || target.is_empty() || target.contains(['\n', '\0']) || !crate::NAME.is_match(&user) || !crate::NAME.is_match(&group) {
                return Err(invalid("link"));
            }
            let uid = system.user(&user).map_err(failed)?;
            let gid = system.group(&group).map_err(failed)?;
            link(system, &system.path(&path), &target, uid, gid).map_err(failed)
        }
        Input::Absent { path, recursive } => {
            if !absolute(&path) {
                return Err(invalid("absent"));
            }
            let dest = system.path(&path);
            let Ok(meta) = stdfs::symlink_metadata(&dest) else { return Ok(0) };
            let removed = if meta.file_type().is_dir() {
                if recursive { stdfs::remove_dir_all(&dest) } else { stdfs::remove_dir(&dest) }
            } else {
                stdfs::remove_file(&dest)
            };
            removed.map_err(|error| failed(error.to_string()))?;
            Ok(1)
        }
    }
}

fn link(system: &impl System, dest: &Path, target: &str, uid: u32, gid: u32) -> Result<u32, String> {
    let mut changes = 0;
    let current = stdfs::symlink_metadata(dest).ok();
    let points = current.as_ref().is_some_and(|meta| meta.file_type().is_symlink()) && stdfs::read_link(dest).is_ok_and(|link| link == Path::new(target));
    if !points {
        if current.as_ref().is_some_and(stdfs::Metadata::is_dir) {
            stdfs::remove_dir(dest).map_err(|error| error.to_string())?;
        }
        let parent = dest.parent().ok_or_else(|| "no parent".to_owned())?;
        let temporary = parent.join(format!(".link.partial-{}", std::process::id()));
        let _ = stdfs::remove_file(&temporary);
        std::os::unix::fs::symlink(target, &temporary).map_err(|error| error.to_string())?;
        stdfs::rename(&temporary, dest).map_err(|error| error.to_string())?;
        changes += 1;
    }
    let meta = stdfs::symlink_metadata(dest).map_err(|error| error.to_string())?;
    if (meta.uid(), meta.gid()) != (uid, gid) {
        system.lchown(dest, uid, gid)?;
        changes += 1;
    }
    Ok(changes)
}
