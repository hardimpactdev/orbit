//! `posix.acl.ensure`: one user or group entry of the access or default list of one path, or, with `scope: tree`, of one
//! folder tree: the root and every folder and file below it. The tree scope is the one exception to "one resource" (Nick,
//! 2026-11-28; docs/reference/executor.md#primitives). The subject, the path, the permissions and the scope come from input.
//!
//! Every path is opened without following a link, relative to its parent's descriptor from `/` down, and its list is read
//! and written as the `system.posix_acl_*` attribute of that descriptor, so the path that was checked is the one that
//! changes. No command runs, and names stay the bytes the folder holds.

use std::ffi::{CString, OsStr};
use std::fmt::Write as _;
use std::os::fd::{AsFd, BorrowedFd, OwnedFd};
use std::os::unix::ffi::OsStrExt as _;
use std::path::PathBuf;
use std::sync::LazyLock;

use regex::Regex;
use rustix::fs::{AtFlags, Dir, FileType, Mode, OFlags, Stat};
use serde::{Deserialize, Serialize};

use crate::{absolute, invalid, parse, Failure, System, NAME};

static PERMS: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[r-][w-][xX-]$").expect("a valid pattern"));

/// The access list's attribute.
pub(crate) const ACCESS: &str = "system.posix_acl_access";
/// The default list's attribute, on folders only.
pub(crate) const DEFAULT: &str = "system.posix_acl_default";

const USER_OBJ: u16 = 0x01;
const USER: u16 = 0x02;
const GROUP_OBJ: u16 = 0x04;
const GROUP: u16 = 0x08;
const MASK: u16 = 0x10;
const OTHER: u16 = 0x20;
const UNDEFINED: u32 = u32::MAX;
/// The only version of the kernel's `posix_acl_xattr` format.
const VERSION: u32 = 2;

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    path: String,
    /// The user the entry is for; exactly one of `user` and `group`.
    #[serde(default)]
    user: Option<String>,
    /// The group the entry is for; exactly one of `user` and `group`.
    #[serde(default)]
    group: Option<String>,
    /// `rwx` form; `X` grants search on folders, and on files only when some execute bit is set.
    perms: String,
    /// The default entry that new folders and files inherit, instead of the access entry.
    #[serde(default)]
    default: bool,
    /// A missing path is left out and changes nothing, instead of failing.
    #[serde(default)]
    optional: bool,
    /// The one path, or the folder tree below it too.
    #[serde(default)]
    scope: Scope,
}

/// What the entry is converged on.
#[derive(Debug, Default, Clone, Copy, PartialEq, Eq, Deserialize)]
#[serde(rename_all = "snake_case")]
enum Scope {
    /// The path alone, a folder or a plain file; nothing below it changes.
    #[default]
    Path,
    /// The folder and every folder below it, and for an access entry every plain file below it, on the root's file system.
    Tree,
}

/// What an order changed and what its walk left out.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq, Serialize)]
pub struct Acl {
    /// Paths whose list changed; `0` on a repeat.
    pub changes: u32,
    /// Folders and files on another mount than the root, such as a bind mount, that the walk did not enter or change.
    pub skipped_mounts: u32,
    /// Plain files with more than one name, which may be outside the tree, that the walk left as they were.
    pub skipped_hard_links: u32,
}

/// One list: `(tag, id, perm)` entries, sorted by tag and id as the kernel requires.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
struct List(Vec<(u16, u32, u16)>);

impl List {
    /// The list a path's mode stands for when it has no access attribute.
    fn from_mode(mode: u32) -> Self {
        let bits = |shift: u32| u16::try_from((mode >> shift) & 7).unwrap_or(0);
        Self(vec![(USER_OBJ, UNDEFINED, bits(6)), (GROUP_OBJ, UNDEFINED, bits(3)), (OTHER, UNDEFINED, bits(0))])
    }

    fn decode(bytes: &[u8]) -> Result<Self, String> {
        let word = |at: usize| u32::from_le_bytes([bytes[at], bytes[at + 1], bytes[at + 2], bytes[at + 3]]);
        if bytes.len() < 4 || !(bytes.len() - 4).is_multiple_of(8) || word(0) != VERSION {
            return Err("an access list in an unknown format".to_owned());
        }
        let mut entries = Vec::new();
        for at in (4..bytes.len()).step_by(8) {
            let tag = u16::from_le_bytes([bytes[at], bytes[at + 1]]);
            let perm = u16::from_le_bytes([bytes[at + 2], bytes[at + 3]]);
            if ![USER_OBJ, USER, GROUP_OBJ, GROUP, MASK, OTHER].contains(&tag) || perm > 7 {
                return Err("an access list entry in an unknown format".to_owned());
            }
            entries.push((tag, word(at + 4), perm));
        }
        let mut list = Self(entries);
        list.0.sort_unstable();
        Ok(list)
    }

    fn encode(&self) -> Vec<u8> {
        let mut bytes = VERSION.to_le_bytes().to_vec();
        for (tag, id, perm) in &self.0 {
            bytes.extend(tag.to_le_bytes());
            bytes.extend(perm.to_le_bytes());
            bytes.extend(id.to_le_bytes());
        }
        bytes
    }

    fn get(&self, tag: u16, id: u32) -> Option<u16> {
        self.0.iter().find(|(held, at, _)| *held == tag && *at == id).map(|(_, _, perm)| *perm)
    }

    fn set(&mut self, tag: u16, id: u32, perm: u16) {
        self.0.retain(|(held, at, _)| !(*held == tag && *at == id));
        self.0.push((tag, id, perm));
        self.0.sort_unstable();
    }

    fn mask(&self) -> Option<u16> {
        self.get(MASK, UNDEFINED)
    }

    /// Whether `subject` holds exactly `want`, also after the mask.
    fn holds(&self, subject: (u16, u32), want: u16) -> bool {
        self.get(subject.0, subject.1).is_some_and(|perm| perm == want && self.mask().is_none_or(|mask| perm & mask == want))
    }

    /// Every group-class entry but the subject's, with its permissions.
    fn others(&self, subject: (u16, u32)) -> Vec<(u16, u32, u16)> {
        self.0.iter().copied().filter(|(tag, id, _)| [GROUP_OBJ, USER, GROUP].contains(tag) && (*tag, *id) != subject).collect()
    }
}

/// The subject's tag and id, the wanted bits as given, and what the walk applies them to.
struct Run<'a, S: System> {
    system: &'a S,
    subject: (u16, u32),
    perms: &'a str,
    default: bool,
    tree: bool,
    /// The root's file system and mount; the walk never leaves them.
    device: u64,
    mount: u64,
    /// Each changed path, with what it held before, so a failed order puts every one back.
    changed: Vec<Changed>,
    acl: Acl,
}

/// A changed path: its descriptor, the attribute that changed, its bytes and the mode before.
struct Changed {
    fd: OwnedFd,
    attribute: &'static str,
    prior: Option<Vec<u8>>,
    mode: u32,
}

/// Sets the entry on each path where it differs and answers how many paths changed: `0` on a repeat. Each path is read
/// first; a path changes when its entry differs from the wanted permissions, `X` resolved as `setfacl` does, or when its mask
/// cuts the entry. Every change is read back through the same descriptor. A failed write or read-back puts every changed path
/// back to its prior bytes and mode, so a failed order leaves no write behind.
///
/// # Errors
///
/// `host.input_invalid` for an input outside the admitted shape or a tree root that is `/`, a plain file or anything but a
/// folder, and `acl.ensure_failed` for a missing path, a link among the ancestors, or a failed read, walk, change or
/// read-back anywhere in the tree.
pub fn acl_ensure(system: &impl System, input: &serde_json::Value) -> Result<Acl, Failure> {
    let input: Input = parse(input)?;
    let ((Some(name), None) | (None, Some(name))) = (&input.user, &input.group) else {
        return Err(invalid("acl subject"));
    };
    if !absolute(&input.path) || !NAME.is_match(name) || !PERMS.is_match(&input.perms) {
        return Err(invalid("acl"));
    }
    let failed = |detail: String| Failure::new("acl.ensure_failed", detail);
    let subject = if input.user.is_some() { (USER, system.user(name).map_err(failed)?) } else { (GROUP, system.group(name).map_err(failed)?) };
    let tree = input.scope == Scope::Tree;
    let (fd, stat) = match open_root(system, &input.path).map_err(|error| failed(format!("{}: {error}", input.path)))? {
        Root::Missing if input.optional => return Ok(Acl::default()),
        Root::Missing => return Err(failed(format!("{}: not found", input.path))),
        Root::Link => return Ok(Acl::default()),
        Root::Found(fd, stat) => (fd, stat),
    };
    let kind = FileType::from_raw_mode(stat.st_mode);
    if tree {
        let top = rustix::fs::stat(system.root()).map_err(|error| failed(error.to_string()))?;
        if !kind.is_dir() || (top.st_dev, top.st_ino) == (stat.st_dev, stat.st_ino) {
            return Err(invalid("acl tree root"));
        }
    } else if !kind.is_dir() && !kind.is_file() {
        return Err(failed(format!("{}: neither a folder nor a plain file", input.path)));
    }
    let mount = mount_of(&fd, c"").map_err(|error| failed(format!("{}: {error}", input.path)))?;
    let mut run = Run { system, subject, perms: &input.perms, default: input.default, tree, device: stat.st_dev, mount, changed: Vec::new(), acl: Acl::default() };
    let root = PathBuf::from(&input.path);
    let result = if kind.is_dir() { run.folder(&fd, &stat, &root) } else { run.path(&fd, &stat, &root) };
    match result {
        Ok(()) => {
            run.acl.changes = u32::try_from(run.changed.len()).unwrap_or(u32::MAX);
            Ok(run.acl)
        }
        Err(detail) => Err(failed(run.restore(detail))),
    }
}

/// The root as the walk from `/` found it.
enum Root {
    Missing,
    Link,
    Found(OwnedFd, Stat),
}

/// Opens every ancestor from the system's root with `O_NOFOLLOW`, then the path itself: a link among the ancestors fails, a
/// link at the path answers [`Root::Link`].
fn open_root(system: &impl System, path: &str) -> std::io::Result<Root> {
    let mut at = rustix::fs::open(system.root(), OFlags::RDONLY | OFlags::DIRECTORY | OFlags::CLOEXEC, Mode::empty())?;
    let parts: Vec<&str> = path.trim_start_matches('/').split('/').collect();
    let (last, ancestors) = parts.split_last().ok_or_else(|| std::io::Error::other("no path"))?;
    for part in ancestors {
        at = match rustix::fs::openat(&at, *part, OFlags::RDONLY | OFlags::DIRECTORY | OFlags::NOFOLLOW | OFlags::CLOEXEC, Mode::empty()) {
            Ok(next) => next,
            Err(rustix::io::Errno::NOENT) => return Ok(Root::Missing),
            Err(rustix::io::Errno::LOOP) => return Err(std::io::Error::other(format!("{part} is a link among the ancestors"))),
            Err(error) => return Err(error.into()),
        };
    }
    let stat = match rustix::fs::statat(&at, *last, AtFlags::SYMLINK_NOFOLLOW) {
        Ok(stat) => stat,
        Err(rustix::io::Errno::NOENT) => return Ok(Root::Missing),
        Err(error) => return Err(error.into()),
    };
    let kind = FileType::from_raw_mode(stat.st_mode);
    if kind == FileType::Symlink {
        return Ok(Root::Link);
    }
    if !kind.is_dir() && !kind.is_file() {
        return Ok(Root::Found(at, stat));
    }
    Ok(Root::Found(open(&at, OsStr::new(last), &stat)?, stat))
}

/// The mount `name` relative to `at` is on, never through a link; `""` is `at` itself.
fn mount_of(at: impl AsFd, name: &std::ffi::CStr) -> std::io::Result<u64> {
    let flags = AtFlags::SYMLINK_NOFOLLOW | if name.is_empty() { AtFlags::EMPTY_PATH } else { AtFlags::empty() };
    let held = rustix::fs::statx(at, name, flags, rustix::fs::StatxFlags::MNT_ID)?;
    if held.stx_mask & rustix::fs::StatxFlags::MNT_ID.bits() == 0 {
        return Err(std::io::Error::other("the kernel reports no mount id"));
    }
    Ok(held.stx_mnt_id)
}

/// Opens the folder or plain file `name` relative to `at`, never through a link, and only when it is still what `stat` read.
fn open(at: impl AsFd, name: &OsStr, stat: &Stat) -> std::io::Result<OwnedFd> {
    let folder = FileType::from_raw_mode(stat.st_mode).is_dir();
    let flags = OFlags::RDONLY | OFlags::NOFOLLOW | OFlags::CLOEXEC | if folder { OFlags::DIRECTORY } else { OFlags::NONBLOCK | OFlags::NOCTTY };
    let fd = rustix::fs::openat(at, name, flags, Mode::empty())?;
    let held = rustix::fs::fstat(&fd)?;
    if (held.st_dev, held.st_ino) != (stat.st_dev, stat.st_ino) {
        return Err(std::io::Error::other("it changed while it was read"));
    }
    Ok(fd)
}

impl<S: System> Run<'_, S> {
    /// The folder `fd` at `name`, then, with the tree scope, every folder and plain file below it, depth first, in name order.
    fn folder(&mut self, fd: &OwnedFd, stat: &Stat, name: &std::path::Path) -> Result<(), String> {
        self.path(fd, stat, name)?;
        if !self.tree {
            return Ok(());
        }
        let shown = |error: &dyn std::fmt::Display| format!("{}: {error}", name.display());
        let mut children: Vec<CString> = Vec::new();
        let mut entries = Dir::read_from(fd).map_err(|error| shown(&error))?;
        while let Some(entry) = entries.read() {
            let entry = entry.map_err(|error| shown(&error))?;
            let child = entry.file_name();
            if child.to_bytes() != b"." && child.to_bytes() != b".." {
                children.push(child.to_owned());
            }
        }
        children.sort();
        for child in children {
            let named = name.join(OsStr::from_bytes(child.to_bytes()));
            let shown = |error: &dyn std::fmt::Display| format!("{}: {error}", named.display());
            let stat = rustix::fs::statat(fd, child.as_c_str(), AtFlags::SYMLINK_NOFOLLOW).map_err(|error| shown(&error))?;
            let kind = FileType::from_raw_mode(stat.st_mode);
            // Folders always; plain files for an access entry; links and every other type never.
            let applies = kind.is_dir() || (kind.is_file() && !self.default);
            if !applies {
                continue;
            }
            // A mount point, also a bind mount from the same file system, is not entered or changed.
            if stat.st_dev != self.device || mount_of(fd, child.as_c_str()).map_err(|error| shown(&error))? != self.mount {
                self.acl.skipped_mounts += 1;
                continue;
            }
            if kind.is_dir() {
                let below = open(fd, OsStr::from_bytes(child.to_bytes()), &stat).map_err(|error| shown(&error))?;
                self.folder(&below, &stat, &named)?;
            } else {
                if stat.st_nlink > 1 {
                    self.acl.skipped_hard_links += 1;
                    continue;
                }
                let file = open(fd, OsStr::from_bytes(child.to_bytes()), &stat).map_err(|error| shown(&error))?;
                self.path(&file, &stat, &named)?;
            }
        }
        Ok(())
    }

    /// One path: read its list, and where the entry differs, write it and read it back through the same descriptor.
    fn path(&mut self, fd: &OwnedFd, stat: &Stat, name: &std::path::Path) -> Result<(), String> {
        let shown = |error: &dyn std::fmt::Display| format!("{}: {error}", name.display());
        let folder = FileType::from_raw_mode(stat.st_mode).is_dir();
        if self.default && !folder {
            return Ok(());
        }
        let mode = stat.st_mode;
        let want = bits(self.perms, mode);
        let attribute = if self.default { DEFAULT } else { ACCESS };
        let access = self.system.xattr(fd.as_fd(), ACCESS).map_err(|error| shown(&error))?;
        let access_list = access.as_deref().map_or_else(|| Ok(List::from_mode(mode)), List::decode).map_err(|error| shown(&error))?;
        let prior = if self.default { self.system.xattr(fd.as_fd(), DEFAULT).map_err(|error| shown(&error))? } else { access };
        let list = match (&prior, self.default) {
            (Some(bytes), _) => List::decode(bytes).map_err(|error| shown(&error))?,
            (None, false) => access_list,
            // The first default entry of a folder starts a default list: it is seeded from the access list (owner, group,
            // other, and the group-class bits as the mask), so every other subject keeps what new files inherit.
            (None, true) => {
                let base = |tag: u16| access_list.get(tag, UNDEFINED).ok_or_else(|| shown(&"no owner, group or other entry"));
                let group = base(GROUP_OBJ)?;
                List(vec![(USER_OBJ, UNDEFINED, base(USER_OBJ)?), (GROUP_OBJ, UNDEFINED, group), (MASK, UNDEFINED, access_list.mask().unwrap_or(group)), (OTHER, UNDEFINED, base(OTHER)?)])
            }
        };
        if list.holds(self.subject, want) {
            return Ok(());
        }
        // The mask is shared by every group-class entry and is written explicitly. It only widens, by the bits this entry
        // needs. For one path it widens only where no other entry holds a bit the mask cut; a tree grant may widen it so the
        // entry takes effect, as `setfacl -R -m` does. Owner and other entries never change.
        let others = list.others(self.subject);
        let current = list.mask().unwrap_or_else(|| others.iter().fold(0, |held, (_, _, perm)| held | perm));
        let widened = current | want;
        if !self.tree && list.mask().is_some() && others.iter().any(|(_, _, perm)| perm & current != perm & widened) {
            return Err(shown(&"the mask would widen another entry's permissions"));
        }
        let mut wanted = list;
        wanted.set(self.subject.0, self.subject.1, want);
        wanted.set(MASK, UNDEFINED, widened);
        let fd = fd.try_clone().map_err(|error| shown(&error))?;
        self.changed.push(Changed { fd, attribute, prior, mode });
        let fd = &self.changed.last().expect("just pushed").fd;
        self.system.set_xattr(fd.as_fd(), attribute, Some(&wanted.encode())).map_err(|error| shown(&error))?;
        // Read back: the list is the one written, and the owner and other bits of the mode are as they were.
        let held = self.system.xattr(fd.as_fd(), attribute).map_err(|error| shown(&error))?;
        let after = rustix::fs::fstat(fd).map_err(|error| shown(&error))?;
        if held.as_deref().map(List::decode) != Some(Ok(wanted)) || after.st_mode & 0o7707 != mode & 0o7707 {
            return Err(shown(&"read-back"));
        }
        Ok(())
    }

    /// Puts every changed path back to its prior bytes and mode, last first, and answers the failure's detail.
    fn restore(&mut self, detail: String) -> String {
        let mut detail = detail;
        for changed in self.changed.drain(..).rev() {
            let back = self.system.set_xattr(changed.fd.as_fd(), changed.attribute, changed.prior.as_deref()).and_then(|()| {
                if changed.attribute == ACCESS {
                    rustix::fs::fchmod(&changed.fd, Mode::from_raw_mode(changed.mode & 0o7777)).map_err(std::io::Error::from)
                } else {
                    Ok(())
                }
            });
            if let Err(error) = back {
                let _ = write!(detail, "; a path could not be put back: {error}");
            }
        }
        detail
    }
}

/// `perms` as `r=4, w=2, x=1` for a path of `mode` (`st_mode`): `X` is search on a folder, and on a file only when some
/// execute bit is set.
fn bits(perms: &str, mode: u32) -> u16 {
    let search = mode & 0o170_000 == 0o040_000 || mode & 0o111 != 0;
    perms.chars().zip([4, 2, 1]).map(|(bit, value)| if bit == 'r' || bit == 'w' || bit == 'x' || (bit == 'X' && search) { value } else { 0 }).sum()
}

/// A descriptor's attribute, `None` when it has none.
pub(crate) fn read_xattr(fd: BorrowedFd<'_>, name: &str) -> std::io::Result<Option<Vec<u8>>> {
    let mut buffer = vec![0_u8; 65_536];
    match rustix::fs::fgetxattr(fd, name, &mut buffer[..]) {
        Ok(length) => {
            buffer.truncate(length);
            Ok(Some(buffer))
        }
        Err(rustix::io::Errno::NODATA) => Ok(None),
        Err(error) => Err(error.into()),
    }
}

/// Sets a descriptor's attribute to `value`, or removes it for `None`.
pub(crate) fn write_xattr(fd: BorrowedFd<'_>, name: &str, value: Option<&[u8]>) -> std::io::Result<()> {
    match value {
        Some(bytes) => rustix::fs::fsetxattr(fd, name, bytes, rustix::fs::XattrFlags::empty()).map_err(std::io::Error::from),
        None => match rustix::fs::fremovexattr(fd, name) {
            Ok(()) | Err(rustix::io::Errno::NODATA) => Ok(()),
            Err(error) => Err(error.into()),
        },
    }
}
