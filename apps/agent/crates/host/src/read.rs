//! `file.read`: the digest, type, owner and mode of one path, read only. It never follows a final link and never returns
//! content: the Gateway sends the digest back as a guard (`from_sha256`) on the write that follows.

use std::fs as stdfs;
use std::os::unix::fs::{FileTypeExt as _, MetadataExt as _, OpenOptionsExt as _};

use serde::{Deserialize, Serialize};

use crate::fs::NOFOLLOW;
use crate::{absolute, invalid, parse, Failure, System};

/// `O_PATH`: the descriptor names the path itself, a link included, and opens nothing for reading.
const PATH: i32 = 0o10_000_000;
/// `O_NONBLOCK`: a pipe swapped in for the file never holds the open.
const NONBLOCK: i32 = 0o4000;

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    path: String,
}

/// What the path holds. `sha256` is set for a plain file only; owner, group and mode are those of the path itself, also of
/// a link.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(tag = "state", rename_all = "snake_case")]
#[non_exhaustive]
pub enum Read {
    /// Nothing is at the path.
    Absent,
    /// Something is at the path.
    Present {
        /// `file`, `directory`, `symlink`, `fifo`, `socket`, `char_device` or `block_device`.
        #[serde(rename = "type")]
        kind: &'static str,
        /// The SHA-256 of a plain file's bytes, in lower-case hex; `None` for anything else.
        #[serde(skip_serializing_if = "Option::is_none")]
        sha256: Option<String>,
        /// The owner's user id.
        uid: u32,
        /// The owner's group id.
        gid: u32,
        /// Octal permission bits, such as `0644`.
        mode: String,
    },
}

/// Reads the path without following a final link and answers what it holds. Changes nothing.
///
/// # Errors
///
/// `host.input_invalid` for an input outside the admitted shape, and `file.read_failed` for any read that fails: only a
/// path that is not there answers absent.
pub fn file_read(system: &impl System, input: &serde_json::Value) -> Result<Read, Failure> {
    let input: Input = parse(input)?;
    if !absolute(&input.path) {
        return Err(invalid("path"));
    }
    let shown = &input.path;
    let failed = |error: std::io::Error| Failure::new("file.read_failed", format!("{shown}: {error}"));
    let path = system.path(shown);
    let held = match stdfs::File::options().read(true).custom_flags(PATH | NOFOLLOW).open(&path) {
        Ok(held) => held,
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(Read::Absent),
        Err(error) => return Err(failed(error)),
    };
    let meta = held.metadata().map_err(failed)?;
    let kind = meta.file_type();
    let kind = if kind.is_file() {
        "file"
    } else if kind.is_dir() {
        "directory"
    } else if kind.is_symlink() {
        "symlink"
    } else if kind.is_fifo() {
        "fifo"
    } else if kind.is_socket() {
        "socket"
    } else if kind.is_char_device() {
        "char_device"
    } else {
        "block_device"
    };
    let sha256 = if kind == "file" {
        // A path descriptor cannot be read: the file opens again, and it must still be the file just looked at.
        let mut stream = stdfs::File::options().read(true).custom_flags(NOFOLLOW | NONBLOCK).open(&path).map_err(failed)?;
        let again = stream.metadata().map_err(failed)?;
        if (again.dev(), again.ino()) != (meta.dev(), meta.ino()) {
            return Err(failed(std::io::Error::other("the path changed during the read")));
        }
        Some(digest(&mut stream).map_err(failed)?)
    } else {
        None
    };
    Ok(Read::Present { kind, sha256, uid: meta.uid(), gid: meta.gid(), mode: format!("{:04o}", meta.mode() & 0o7777) })
}

/// The lower-case hex SHA-256 of everything `stream` holds, read in pieces so a large file never sits in memory.
fn digest(stream: &mut stdfs::File) -> std::io::Result<String> {
    use sha2::{Digest as _, Sha256};
    use std::fmt::Write as _;
    let mut hasher = Sha256::new();
    std::io::copy(stream, &mut hasher)?;
    Ok(hasher.finalize().iter().fold(String::with_capacity(64), |mut text, byte| {
        let _ = write!(text, "{byte:02x}");
        text
    }))
}
