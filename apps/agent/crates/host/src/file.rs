//! `file.ensure`: one file, folder or link, with its owner and mode, or its absence. Lifted from `Site::file`, `Site::link`
//! and `replace` of the route family. A file's content is a blob, or the bytes of another plain file on the same host.

use std::fs as stdfs;
use std::io::Read as _;
use std::os::unix::fs::{fchown, MetadataExt as _, OpenOptionsExt as _, PermissionsExt as _};
use std::path::Path;

use serde::Deserialize;

use crate::fs;
use crate::{absolute, invalid, parse, sha256, BlobRef, Failure, Owner, System, DIGEST};

/// `O_DIRECTORY`: the open fails unless the path is a folder.
const DIRECTORY: i32 = 0o200_000;
/// `O_NONBLOCK`: a pipe swapped in for a source never holds the open.
const NONBLOCK: i32 = 0o4000;
/// `ELOOP`: an `O_NOFOLLOW` open met a link.
const LOOP: i32 = 40;

#[derive(Debug, Deserialize)]
#[serde(tag = "state", rename_all = "snake_case", deny_unknown_fields)]
enum Input {
    /// A plain file with this content: a blob in `content`, or the bytes of the plain file at `source_path`, which must hash
    /// to `source_sha256` when it is set. Exactly one of the two is set. When `from_sha256` is set, a live file must still
    /// hold that digest, or already the content, else nothing changes.
    File {
        path: String,
        content: Option<BlobRef>,
        source_path: Option<String>,
        source_sha256: Option<String>,
        owner: Owner,
        from_sha256: Option<String>,
    },
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
/// read, `file.source_missing`, `file.source_link`, `file.source_not_file`, `file.source_alias` or `file.source_mismatch`
/// for a source path that is not a plain file of its own with the named digest, `owned_revision_conflict` when a guarded
/// file changed outside the render, and `file.ensure_failed` for any failed change, read or read-back.
pub fn file_ensure(system: &impl System, input: &serde_json::Value) -> Result<u32, Failure> {
    let input: Input = parse(input)?;
    let failed = |detail: String| Failure::new("file.ensure_failed", detail);
    match input {
        Input::File { path, content, source_path, source_sha256, owner, from_sha256 } => {
            let digest = |value: &Option<String>| value.as_ref().is_some_and(|digest| !DIGEST.is_match(digest));
            if !absolute(&path) || !owner.valid() || digest(&from_sha256) || digest(&source_sha256) {
                return Err(invalid("file"));
            }
            let dest = system.path(&path);
            let bytes = match (content, source_path) {
                (Some(content), None) if source_sha256.is_none() => content.bytes(system)?,
                (None, Some(source_path)) if absolute(&source_path) => {
                    let bytes = source(&system.path(&source_path), &dest, &source_path)?;
                    if source_sha256.as_ref().is_some_and(|expected| sha256(&bytes) != *expected) {
                        return Err(Failure::new("file.source_mismatch", format!("{source_path} does not hash to source_sha256")));
                    }
                    bytes
                }
                _ => return Err(invalid("file content: exactly one of content and source_path")),
            };
            let ids = owner.ids(system, "file.ensure_failed")?;
            if let Some(from) = &from_sha256 {
                let live = fs::read(&dest).map_err(|error| failed(error.to_string()))?;
                // A guard names bytes the file must still hold: a missing file holds none of them.
                if live.as_ref().is_none_or(|(current, _)| *current != bytes && sha256(current) != *from) {
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
            match stdfs::symlink_metadata(&dest) {
                Ok(meta) if meta.file_type().is_dir() => {}
                // A link, even to a folder, or a file in the folder's place is not the folder: nothing behind it changes.
                Ok(_) => return Err(failed(format!("{path} is not a folder"))),
                Err(error) if error.kind() == std::io::ErrorKind::NotFound => {
                    stdfs::create_dir_all(&dest).map_err(|error| failed(error.to_string()))?;
                    changes += 1;
                }
                Err(error) => return Err(failed(error.to_string())),
            }
            // Owner and mode change through the folder itself, opened without following a link.
            let folder = stdfs::File::options().read(true).custom_flags(fs::NOFOLLOW | DIRECTORY).open(&dest).map_err(|error| failed(error.to_string()))?;
            let meta = folder.metadata().map_err(|error| failed(error.to_string()))?;
            if (meta.uid(), meta.gid()) != (ids.uid, ids.gid) {
                fchown(&folder, Some(ids.uid), Some(ids.gid)).map_err(|error| failed(error.to_string()))?;
                changes += 1;
            }
            if meta.mode() & 0o7777 != ids.mode {
                folder.set_permissions(stdfs::Permissions::from_mode(ids.mode)).map_err(|error| failed(error.to_string()))?;
                changes += 1;
            }
            let held = stdfs::symlink_metadata(&dest).map_err(|error| failed(error.to_string()))?;
            if !held.file_type().is_dir() || (held.uid(), held.gid(), held.mode() & 0o7777) != (ids.uid, ids.gid, ids.mode) {
                return Err(failed(format!("{path} read-back")));
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
            let meta = match stdfs::symlink_metadata(&dest) {
                Ok(meta) => meta,
                Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(0),
                Err(error) => return Err(failed(error.to_string())),
            };
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

/// The bytes of the plain file at `source`, opened without following a link, and never the file at `dest` by another
/// name. A source that cannot be read fails; only a missing one answers `file.source_missing`.
fn source(source: &Path, dest: &Path, shown: &str) -> Result<Vec<u8>, Failure> {
    let failed = |error: std::io::Error| Failure::new("file.ensure_failed", format!("{shown}: {error}"));
    let missing = || Failure::new("file.source_missing", format!("{shown} is missing"));
    let linked = || Failure::new("file.source_link", format!("{shown} is a link"));
    let not_file = || Failure::new("file.source_not_file", format!("{shown} is not a plain file"));
    match stdfs::symlink_metadata(source) {
        Ok(meta) if meta.file_type().is_symlink() => return Err(linked()),
        Ok(meta) if !meta.file_type().is_file() => return Err(not_file()),
        Ok(_) => {}
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Err(missing()),
        Err(error) => return Err(failed(error)),
    }
    // The path can change between the look and the open: the open itself refuses a link, and the opened file must be plain.
    let mut stream = stdfs::File::options().read(true).custom_flags(fs::NOFOLLOW | NONBLOCK).open(source).map_err(|error| match error.raw_os_error() {
        Some(LOOP) => linked(),
        _ if error.kind() == std::io::ErrorKind::NotFound => missing(),
        _ => failed(error),
    })?;
    let held = stream.metadata().map_err(failed)?;
    if !held.file_type().is_file() {
        return Err(not_file());
    }
    // One file by two names, by a hard link or the same spelling: its own copy would replace the source.
    match stdfs::symlink_metadata(dest) {
        Ok(meta) if (meta.dev(), meta.ino()) == (held.dev(), held.ino()) => {
            return Err(Failure::new("file.source_alias", format!("{shown} is the destination")));
        }
        Ok(_) => {}
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => {}
        Err(error) => return Err(failed(error)),
    }
    let mut content = Vec::new();
    stream.read_to_end(&mut content).map_err(failed)?;
    Ok(content)
}

fn link(system: &impl System, dest: &Path, target: &str, uid: u32, gid: u32) -> Result<u32, String> {
    let mut changes = 0;
    let current = stdfs::symlink_metadata(dest).ok();
    let points = current.as_ref().is_some_and(|meta| meta.file_type().is_symlink()) && stdfs::read_link(dest).is_ok_and(|link| link == Path::new(target));
    if !points {
        if current.as_ref().is_some_and(stdfs::Metadata::is_dir) {
            stdfs::remove_dir(dest).map_err(|error| error.to_string())?;
        }
        let (temporary, ()) = fs::scratch(dest, "link", |path| std::os::unix::fs::symlink(target, path)).map_err(|error| error.to_string())?;
        if let Err(error) = stdfs::rename(&temporary, dest) {
            let _ = stdfs::remove_file(&temporary);
            return Err(error.to_string());
        }
        changes += 1;
    }
    let meta = stdfs::symlink_metadata(dest).map_err(|error| error.to_string())?;
    if (meta.uid(), meta.gid()) != (uid, gid) {
        system.lchown(dest, uid, gid)?;
        changes += 1;
    }
    Ok(changes)
}
