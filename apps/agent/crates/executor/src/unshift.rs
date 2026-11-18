//! SPIKE: maps a copied rootfs's shifted owners back through its source's ID map, keeping modes, file capabilities and
//! POSIX ACLs (chown can clear setuid/setgid and capabilities, so they are captured first and written back). A port of
//! `unshift_clone_rootfs` in instance_clone.py.

use std::os::unix::fs::{MetadataExt as _, PermissionsExt as _};
use std::path::Path;

use rustix::fs::{chownat, lgetxattr, lsetxattr, AtFlags, Gid, Uid, XattrFlags, CWD};

type Map = [(u32, u32, u32)];

fn back(id: u32, maps: &Map) -> u32 {
    maps.iter().find(|(host, _, size)| *host <= id && id < host + size).map_or(id, |(host, ns, _)| id - host + ns)
}

fn xattr(path: &Path, name: &str) -> std::io::Result<Option<Vec<u8>>> {
    let mut buf = vec![0u8; 4096];
    match lgetxattr(path, name, &mut buf) {
        Ok(len) => {
            buf.truncate(len);
            Ok(Some(buf))
        }
        Err(rustix::io::Errno::NODATA | rustix::io::Errno::OPNOTSUPP) => Ok(None),
        Err(error) => Err(error.into()),
    }
}

fn acl(value: &[u8], uids: &Map, gids: &Map) -> Vec<u8> {
    let mut out = value.to_vec();
    let mut at = 4;
    while at + 8 <= value.len() {
        let tag = u16::from_le_bytes([value[at], value[at + 1]]);
        if tag == 0x02 || tag == 0x08 {
            let id = u32::from_le_bytes(value[at + 4..at + 8].try_into().unwrap_or([0; 4]));
            let mapped = back(id, if tag == 0x02 { uids } else { gids });
            out[at + 4..at + 8].copy_from_slice(&mapped.to_le_bytes());
        }
        at += 8;
    }
    out
}

fn one(path: &Path, uids: &Map, gids: &Map) -> std::io::Result<bool> {
    let meta = std::fs::symlink_metadata(path)?;
    let link = meta.file_type().is_symlink();
    let (uid, gid) = (back(meta.uid(), uids), back(meta.gid(), gids));
    let owner_changed = (uid, gid) != (meta.uid(), meta.gid());
    let names = ["security.capability", "system.posix_acl_access", "system.posix_acl_default"];
    let attrs: Vec<(&str, Option<Vec<u8>>)> =
        if link { Vec::new() } else { names.iter().map(|name| Ok((*name, xattr(path, name)?))).collect::<std::io::Result<_>>()? };
    let mut changed = false;
    if owner_changed {
        chownat(CWD, path, Some(Uid::from_raw(uid)), Some(Gid::from_raw(gid)), AtFlags::SYMLINK_NOFOLLOW)?;
        if !link {
            std::fs::set_permissions(path, std::fs::Permissions::from_mode(meta.mode() & 0o7777))?;
        }
        changed = true;
    }
    for (name, value) in attrs {
        let Some(value) = value else { continue };
        let rewritten = if name == "security.capability" {
            if value.len() == 24 && u32::from_le_bytes(value[0..4].try_into().unwrap_or([0; 4])) & 0xff00_0000 == 0x0300_0000 {
                let root = u32::from_le_bytes(value[20..24].try_into().unwrap_or([0; 4]));
                [&value[..20], &back(root, uids).to_le_bytes()[..]].concat()
            } else {
                value.clone()
            }
        } else {
            acl(&value, uids, gids)
        };
        if owner_changed || rewritten != value {
            lsetxattr(path, name, &rewritten, XattrFlags::empty())?;
            changed = true;
        }
    }
    Ok(changed)
}

/// Walks the rootfs without following links; returns whether any file changed.
pub(crate) fn walk(root: &Path, uids: &Map, gids: &Map) -> std::io::Result<bool> {
    if !std::fs::symlink_metadata(root)?.is_dir() {
        return Err(std::io::Error::other("rootfs is not a directory"));
    }
    let mut changed = one(root, uids, gids)?;
    let mut stack = vec![root.to_path_buf()];
    while let Some(dir) = stack.pop() {
        for entry in std::fs::read_dir(&dir)? {
            let path = entry?.path();
            changed |= one(&path, uids, gids)?;
            if std::fs::symlink_metadata(&path)?.is_dir() {
                stack.push(path);
            }
        }
    }
    Ok(changed)
}
