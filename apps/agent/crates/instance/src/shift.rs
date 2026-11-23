//! Shifts a copy's files back from a map's host ids to its namespace ids: owners, file capabilities and POSIX ACLs.

use std::ffi::CString;
use std::io;
use std::os::unix::ffi::OsStrExt as _;
use std::os::unix::fs::{MetadataExt as _, PermissionsExt as _};
use std::path::Path;

/// One range of an ID map.
pub(crate) trait Map {
    /// The namespace id of a host id in this range, or None when the id is outside it.
    fn back(&self, id: u32) -> Option<u32>;
}

const CAPABILITY: &str = "security.capability";
const ACCESS_ACL: &str = "system.posix_acl_access";
const DEFAULT_ACL: &str = "system.posix_acl_default";
/// A version 3 file capability: 20 bytes and the root id it applies to.
const CAPABILITY_V3: u32 = 0x0300_0000;
const ACL_USER: u16 = 0x02;
const ACL_GROUP: u16 = 0x08;

/// Shifts every file under `root`, and `root` itself, without following a link. Answers whether a file changed.
pub(crate) fn back<M: Map>(root: &Path, uids: &[&M], gids: &[&M]) -> io::Result<bool> {
    if !std::fs::symlink_metadata(root)?.is_dir() {
        return Err(io::Error::other("rootfs is not a directory"));
    }
    let mut changed = false;
    let mut pending = vec![root.to_path_buf()];
    while let Some(path) = pending.pop() {
        changed |= one(&path, uids, gids)?;
        let meta = std::fs::symlink_metadata(&path)?;
        if meta.is_dir() {
            for entry in std::fs::read_dir(&path)? {
                pending.push(entry?.path());
            }
        }
    }
    Ok(changed)
}

fn shifted<M: Map>(id: u32, maps: &[&M]) -> u32 {
    maps.iter().find_map(|map| map.back(id)).unwrap_or(id)
}

fn one<M: Map>(path: &Path, uids: &[&M], gids: &[&M]) -> io::Result<bool> {
    let held = std::fs::symlink_metadata(path)?;
    let link = held.file_type().is_symlink();
    let (uid, gid) = (shifted(held.uid(), uids), shifted(held.gid(), gids));
    let owner = (uid, gid) != (held.uid(), held.gid());
    let attributes = if link {
        Vec::new()
    } else {
        let mut read = Vec::new();
        for name in [CAPABILITY, ACCESS_ACL, DEFAULT_ACL] {
            if let Some(value) = get(path, name)? {
                read.push((name, value));
            }
        }
        read
    };
    let mut changed = false;
    if owner {
        std::os::unix::fs::lchown(path, Some(uid), Some(gid))?;
        if !link {
            // A chown clears set-id bits: the mode goes back as it was.
            std::fs::set_permissions(path, std::fs::Permissions::from_mode(held.mode() & 0o7777))?;
        }
        changed = true;
    }
    for (name, value) in attributes {
        let rewritten = if name == CAPABILITY { capability(&value, uids) } else { acl(&value, uids, gids) };
        if owner || rewritten != value {
            set(path, name, &rewritten)?;
            changed = true;
        }
    }
    Ok(changed)
}

/// A version 3 capability's root id shifted back; any other capability as it is.
fn capability<M: Map>(value: &[u8], uids: &[&M]) -> Vec<u8> {
    let word = |at: usize| value.get(at..at + 4).and_then(|bytes| bytes.try_into().ok()).map(u32::from_le_bytes);
    match (value.len(), word(0), word(20)) {
        (24, Some(magic), Some(root)) if magic & 0xff00_0000 == CAPABILITY_V3 => {
            let mut out = value.to_vec();
            out[20..24].copy_from_slice(&shifted(root, uids).to_le_bytes());
            out
        }
        _ => value.to_vec(),
    }
}

/// A POSIX ACL with its named user and group entries shifted back. The header is 4 bytes; each entry is a tag, a permission
/// and an id.
fn acl<M: Map>(value: &[u8], uids: &[&M], gids: &[&M]) -> Vec<u8> {
    let mut out = value.to_vec();
    let mut at = 4;
    while at + 8 <= value.len() {
        let tag = u16::from_le_bytes([value[at], value[at + 1]]);
        let id = u32::from_le_bytes([value[at + 4], value[at + 5], value[at + 6], value[at + 7]]);
        let mapped = match tag {
            ACL_USER => Some(shifted(id, uids)),
            ACL_GROUP => Some(shifted(id, gids)),
            _ => None,
        };
        if let Some(mapped) = mapped {
            out[at + 4..at + 8].copy_from_slice(&mapped.to_le_bytes());
        }
        at += 8;
    }
    out
}

fn c_path(path: &Path) -> io::Result<CString> {
    CString::new(path.as_os_str().as_bytes()).map_err(io::Error::other)
}

/// An extended attribute of the path itself, or None when it has none or its file system keeps none.
fn get(path: &Path, name: &str) -> io::Result<Option<Vec<u8>>> {
    let (path, name) = (c_path(path)?, CString::new(name).map_err(io::Error::other)?);
    let mut buffer = vec![0_u8; 256];
    loop {
        // SAFETY: both names are NUL-terminated strings that outlive the call; the buffer pointer and length describe one
        // live, writable allocation.
        let read = unsafe { libc::lgetxattr(path.as_ptr(), name.as_ptr(), buffer.as_mut_ptr().cast(), buffer.len()) };
        if let Ok(length) = usize::try_from(read) {
            buffer.truncate(length);
            return Ok(Some(buffer));
        }
        let error = io::Error::last_os_error();
        match error.raw_os_error() {
            Some(libc::ENODATA | libc::EOPNOTSUPP) => return Ok(None),
            Some(libc::ERANGE) if buffer.len() < 65_536 => buffer.resize(buffer.len() * 4, 0),
            _ => return Err(error),
        }
    }
}

fn set(path: &Path, name: &str, value: &[u8]) -> io::Result<()> {
    let (path, name) = (c_path(path)?, CString::new(name).map_err(io::Error::other)?);
    // SAFETY: both names are NUL-terminated strings that outlive the call; the value pointer and length describe one live
    // allocation that the call only reads.
    let done = unsafe { libc::lsetxattr(path.as_ptr(), name.as_ptr(), value.as_ptr().cast(), value.len(), 0) };
    if done == 0 { Ok(()) } else { Err(io::Error::last_os_error()) }
}

#[cfg(test)]
mod tests {
    use super::*;

    struct Range(u32, u32, u32);

    impl Map for Range {
        fn back(&self, id: u32) -> Option<u32> {
            (self.0 <= id && id - self.0 < self.2).then(|| id - self.0 + self.1)
        }
    }

    #[test]
    fn ids_inside_a_range_go_back_and_others_stay() {
        let map = Range(131_072, 0, 65_536);
        assert_eq!(shifted(131_072 + 2750, &[&map]), 2750);
        assert_eq!(shifted(42, &[&map]), 42);
    }

    #[test]
    fn a_v3_capability_gets_its_root_id_back_and_an_acl_its_named_ids() {
        let map = Range(131_072, 0, 65_536);
        let mut cap = 0x0300_0000_u32.to_le_bytes().to_vec();
        cap.extend([0; 16]);
        cap.extend(131_072_u32.to_le_bytes());
        assert_eq!(&capability(&cap, &[&map])[20..], &0_u32.to_le_bytes());
        let mut entries = vec![2, 0, 0, 0];
        for (tag, id) in [(0x01_u16, 0_u32), (ACL_USER, 131_072 + 2750), (ACL_GROUP, 131_072 + 2760)] {
            entries.extend(tag.to_le_bytes());
            entries.extend(7_u16.to_le_bytes());
            entries.extend(id.to_le_bytes());
        }
        let shifted = acl(&entries, &[&map], &[&map]);
        assert_eq!(&shifted[16..20], &2750_u32.to_le_bytes());
        assert_eq!(&shifted[24..28], &2760_u32.to_le_bytes());
    }
}
