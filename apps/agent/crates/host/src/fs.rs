//! File writes shared by the primitives, lifted from the route and dns families: a file is written beside its final name
//! and moved in place, written only when its bytes differ, and put back byte for byte on a rollback.

use std::fs::{self, File};
use std::io::{Read as _, Write as _};
use std::os::unix::fs::{fchown, MetadataExt as _, OpenOptionsExt as _, PermissionsExt as _};
use std::path::{Path, PathBuf};

use crate::Ids;

/// `O_NOFOLLOW`: a link in a file's place is replaced, never followed.
pub(crate) const NOFOLLOW: i32 = 0o400_000;

/// What a path held before a primitive replaced it: a plain file's bytes and metadata, or nothing.
#[derive(Debug, Clone, PartialEq, Eq)]
pub(crate) struct Previous {
    pub(crate) content: Option<(Vec<u8>, Ids)>,
}

/// The plain file at `dest` with its owner and mode, `None` when nothing is there, and an error for anything that is not
/// a plain file or cannot be read.
pub(crate) fn read(dest: &Path) -> std::io::Result<Option<(Vec<u8>, Ids)>> {
    let meta = match fs::symlink_metadata(dest) {
        Ok(meta) => meta,
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(None),
        Err(error) => return Err(error),
    };
    if !meta.file_type().is_file() {
        return Err(std::io::Error::other("not a plain file"));
    }
    let mut stream = File::options().read(true).custom_flags(NOFOLLOW).open(dest)?;
    let mut content = Vec::new();
    stream.read_to_end(&mut content)?;
    Ok(Some((content, Ids { uid: meta.uid(), gid: meta.gid(), mode: meta.mode() & 0o7777 })))
}

/// Writes a file only when its bytes differ, and repairs owner and mode on an unchanged one. Answers whether it changed.
pub(crate) fn ensure(dest: &Path, content: &[u8], ids: Ids) -> std::io::Result<bool> {
    if let Ok(meta) = fs::symlink_metadata(dest) {
        if meta.file_type().is_file() {
            let mut stream = File::options().read(true).custom_flags(NOFOLLOW).open(dest)?;
            let mut current = Vec::new();
            stream.read_to_end(&mut current)?;
            if current == content {
                let drift = (meta.uid(), meta.gid(), meta.mode() & 0o7777) != (ids.uid, ids.gid, ids.mode);
                if drift {
                    fchown(&stream, Some(ids.uid), Some(ids.gid))?;
                    stream.set_permissions(fs::Permissions::from_mode(ids.mode))?;
                }
                return Ok(drift);
            }
        }
    }
    replace(dest, content, ids)?;
    Ok(true)
}

/// Writes `content` with its owner and mode to a new file beside `dest`, then moves it in place.
pub(crate) fn replace(dest: &Path, content: &[u8], ids: Ids) -> std::io::Result<()> {
    let parent = dest.parent().unwrap_or_else(|| Path::new("/"));
    let name = dest.file_name().map_or_else(String::new, |name| name.to_string_lossy().into_owned());
    let temporary: PathBuf = parent.join(format!(".{name}.partial-{}", std::process::id()));
    // A temporary file a stopped run left behind under the same name goes first.
    let _ = fs::remove_file(&temporary);
    let result = (|| {
        let mut stream = File::options().write(true).create_new(true).mode(0o600).open(&temporary)?;
        fchown(&stream, Some(ids.uid), Some(ids.gid))?;
        stream.set_permissions(fs::Permissions::from_mode(ids.mode))?;
        stream.write_all(content)?;
        stream.sync_all()?;
        fs::rename(&temporary, dest)
    })();
    if result.is_err() {
        let _ = fs::remove_file(&temporary);
    }
    result
}

/// Puts back what each replaced file held before; a file that did not exist is removed. Every file is tried; the answer
/// names each one that could not be put back.
pub(crate) fn restore(replaced: &[(PathBuf, Previous)]) -> Result<(), String> {
    let mut failed = Vec::new();
    for (dest, previous) in replaced {
        let result = match &previous.content {
            Some((bytes, ids)) => replace(dest, bytes, *ids),
            None => match fs::remove_file(dest) {
                Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(()),
                other => other,
            },
        };
        if let Err(error) = result {
            failed.push(format!("{}: {error}", dest.display()));
        }
    }
    if failed.is_empty() { Ok(()) } else { Err(failed.join("; ")) }
}

/// The copy of a guarded file's last usable bytes, kept beside it from before its replacement until its set loads: a temporary
/// name derived from the file's own.
pub(crate) fn kept(dest: &Path) -> PathBuf {
    let name = dest.file_name().map_or_else(String::new, |name| name.to_string_lossy().into_owned());
    dest.with_file_name(format!(".{name}.previous"))
}
