//! File writes shared by the primitives, lifted from the route and dns families: a file is written beside its final name
//! and moved in place, written only when its bytes differ, and put back byte for byte on a rollback.

use std::fs::{self, File};
use std::io::{Read as _, Write as _};
use std::os::unix::fs::{fchown, MetadataExt as _, OpenOptionsExt as _, PermissionsExt as _};
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::{SystemTime, UNIX_EPOCH};

use crate::Ids;

/// `O_NOFOLLOW`: a link in a file's place is replaced, never followed.
pub(crate) const NOFOLLOW: i32 = 0o400_000;

/// What a path held before a primitive replaced it: a plain file's bytes and metadata, or nothing.
#[derive(Debug, Clone, PartialEq, Eq)]
pub(crate) struct Previous {
    pub(crate) content: Option<(Vec<u8>, Ids)>,
}

/// A file's device and inode: the same file under any of its names.
pub(crate) type Identity = (u64, u64);

/// Why a write refused: the destination is the file it must leave alone, under another name.
#[derive(Debug)]
pub(crate) struct Alias;

impl std::fmt::Display for Alias {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        formatter.write_str("the destination is the source file")
    }
}

impl std::error::Error for Alias {}

impl Alias {
    /// Whether `error` is this refusal.
    pub(crate) fn is(error: &std::io::Error) -> bool {
        error.get_ref().is_some_and(<dyn std::error::Error + Send + Sync>::is::<Self>)
    }
}

/// The plain file at `dest` with its owner and mode, `None` when nothing is there, and an error for anything that is not
/// a plain file or cannot be read.
pub(crate) fn read(dest: &Path) -> std::io::Result<Option<(Vec<u8>, Ids)>> {
    Ok(read_held(dest)?.map(|(content, ids, _)| (content, ids)))
}

/// [`read`] with the identity of the file it read: owner, mode and identity come from the opened file, not its name.
pub(crate) fn read_held(dest: &Path) -> std::io::Result<Option<(Vec<u8>, Ids, Identity)>> {
    let meta = match fs::symlink_metadata(dest) {
        Ok(meta) => meta,
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(None),
        Err(error) => return Err(error),
    };
    if !meta.file_type().is_file() {
        return Err(std::io::Error::other("not a plain file"));
    }
    let mut stream = File::options().read(true).custom_flags(NOFOLLOW).open(dest)?;
    let held = stream.metadata()?;
    if !held.file_type().is_file() {
        return Err(std::io::Error::other("not a plain file"));
    }
    let mut content = Vec::new();
    stream.read_to_end(&mut content)?;
    Ok(Some((content, Ids { uid: held.uid(), gid: held.gid(), mode: held.mode() & 0o7777 }, (held.dev(), held.ino()))))
}

/// Writes a file only when its bytes differ, and repairs owner and mode on an unchanged one. Answers whether it changed.
pub(crate) fn ensure(dest: &Path, content: &[u8], ids: Ids) -> std::io::Result<bool> {
    ensure_apart(dest, content, ids, None)
}

/// [`ensure`] that never keeps or changes the file `apart` names: when the opened destination is that file, it refuses
/// with [`Alias`]. Every decision and change acts on the opened file, never on a second lookup of its name.
pub(crate) fn ensure_apart(dest: &Path, content: &[u8], ids: Ids, apart: Option<Identity>) -> std::io::Result<bool> {
    if let Ok(meta) = fs::symlink_metadata(dest) {
        if meta.file_type().is_file() {
            let mut stream = File::options().read(true).custom_flags(NOFOLLOW).open(dest)?;
            let meta = stream.metadata()?;
            if apart == Some((meta.dev(), meta.ino())) {
                return Err(std::io::Error::other(Alias));
            }
            let mut current = Vec::new();
            stream.read_to_end(&mut current)?;
            if meta.file_type().is_file() && current == content {
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

/// A name beside `dest` that nothing holds yet, for scratch work: the file's own name, a tag, and a suffix unique to this
/// process and moment. `make` creates the path exclusively (`O_EXCL`, or a link); a name some other file already holds is
/// never reused, so scratch work never touches a file it did not make.
pub(crate) fn scratch<T>(dest: &Path, tag: &str, mut make: impl FnMut(&Path) -> std::io::Result<T>) -> std::io::Result<(PathBuf, T)> {
    static COUNTER: AtomicU64 = AtomicU64::new(0);
    let parent = dest.parent().unwrap_or_else(|| Path::new("/"));
    let name = dest.file_name().map_or_else(String::new, |name| name.to_string_lossy().into_owned());
    let nanos = SystemTime::now().duration_since(UNIX_EPOCH).map_or(0, |elapsed| elapsed.subsec_nanos());
    for _ in 0..64 {
        let count = COUNTER.fetch_add(1, Ordering::Relaxed);
        let path = parent.join(format!(".{name}.{tag}-{}-{nanos:08x}{count:04x}", std::process::id()));
        match make(&path) {
            Ok(value) => return Ok((path, value)),
            Err(error) if error.kind() == std::io::ErrorKind::AlreadyExists => {}
            Err(error) => return Err(error),
        }
    }
    Err(std::io::Error::other("no free scratch name"))
}

/// Writes `content` with its owner and mode to a new file beside `dest` that this run creates, then moves it in place.
pub(crate) fn replace(dest: &Path, content: &[u8], ids: Ids) -> std::io::Result<()> {
    let (temporary, mut stream) = scratch(dest, "partial", |path| File::options().write(true).create_new(true).custom_flags(NOFOLLOW).mode(0o600).open(path))?;
    let result = (|| {
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

/// Writes a file of the executor's own (owner unchanged), through a temporary file moved in place.
pub(crate) fn write_new(dest: &Path, content: &[u8], ids: Ids) -> std::io::Result<()> {
    let (temporary, mut stream) = scratch(dest, "partial", |path| File::options().write(true).create_new(true).custom_flags(NOFOLLOW).mode(ids.mode).open(path))?;
    let result = stream.write_all(content).and_then(|()| stream.sync_all()).and_then(|()| fs::rename(&temporary, dest));
    if result.is_err() {
        let _ = fs::remove_file(&temporary);
    }
    result
}
