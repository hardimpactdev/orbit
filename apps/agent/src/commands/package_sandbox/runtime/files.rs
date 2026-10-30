//! Privileged transfer uses descriptors, never package-controlled pathname checks.
use super::{Account, Error, MAX_BYTES};
use rustix::fs::{Mode, OFlags};
use std::{
    fs::File,
    io::{Read, Write},
    os::{
        fd::{AsFd, OwnedFd},
        unix::fs::MetadataExt,
    },
    path::Path,
};

#[cfg(target_os = "linux")]
fn beneath(fd: impl AsFd, path: &Path, flags: OFlags) -> Result<OwnedFd, rustix::io::Errno> {
    rustix::fs::openat2(
        fd,
        path,
        flags | OFlags::CLOEXEC | OFlags::NOFOLLOW | OFlags::NONBLOCK,
        if flags.contains(OFlags::CREATE) {
            Mode::RUSR | Mode::WUSR
        } else {
            Mode::empty()
        },
        rustix::fs::ResolveFlags::BENEATH
            | rustix::fs::ResolveFlags::NO_SYMLINKS
            | rustix::fs::ResolveFlags::NO_MAGICLINKS,
    )
}
#[cfg(not(target_os = "linux"))]
fn beneath(_: impl AsFd, _: &Path, _: OFlags) -> Result<OwnedFd, rustix::io::Errno> {
    Err(rustix::io::Errno::NOSYS)
}
fn work(root: &Path) -> Result<File, Error> {
    let root = File::open(root).map_err(|_| Error::Failed {})?;
    beneath(&root, Path::new("work"), OFlags::RDONLY | OFlags::DIRECTORY)
        .map(File::from)
        .map_err(|_| Error::Unavailable {})
}
fn parent(root: &File, path: &Path, account: &Account) -> Result<File, Error> {
    let mut fd = root.try_clone().map_err(|_| Error::Failed {})?;
    for component in path.parent().ok_or(Error::Invalid {})?.components() {
        let name = Path::new(component.as_os_str());
        match rustix::fs::mkdirat(&fd, name, Mode::RWXU) {
            Ok(()) => (),
            Err(rustix::io::Errno::EXIST) => (),
            Err(_) => return Err(Error::Failed {}),
        }
        fd = beneath(&fd, name, OFlags::RDONLY | OFlags::DIRECTORY)
            .map(File::from)
            .map_err(|_| Error::Failed {})?;
        rustix::fs::fchown(
            &fd,
            Some(rustix::fs::Uid::from_raw(account.uid)),
            Some(rustix::fs::Gid::from_raw(account.gid)),
        )
        .map_err(|_| Error::Failed {})?;
    }
    Ok(fd)
}
pub(super) fn write(root: &Path, name: &str, value: &str, account: &Account) -> Result<(), Error> {
    let root = work(root)?;
    let path = Path::new(name);
    let parent = parent(&root, path, account)?;
    let mut file = beneath(
        &parent,
        Path::new(path.file_name().ok_or(Error::Invalid {})?),
        OFlags::RDWR | OFlags::CREATE,
    )
    .map(File::from)
    .map_err(|_| Error::Failed {})?;
    let m = file.metadata().map_err(|_| Error::Failed {})?;
    if !m.is_file() || m.nlink() != 1 {
        return Err(Error::Failed {});
    }
    file.set_len(0).map_err(|_| Error::Failed {})?;
    file.write_all(value.as_bytes())
        .map_err(|_| Error::Failed {})?;
    rustix::fs::fchown(
        &file,
        Some(rustix::fs::Uid::from_raw(account.uid)),
        Some(rustix::fs::Gid::from_raw(account.gid)),
    )
    .map_err(|_| Error::Failed {})?;
    Ok(())
}
pub(super) fn read(root: &Path, name: &str) -> Result<Option<String>, Error> {
    let root = work(root)?;
    let file = match beneath(&root, Path::new(name), OFlags::RDONLY) {
        Ok(fd) => File::from(fd),
        Err(rustix::io::Errno::NOENT) => return Ok(None),
        Err(_) => return Err(Error::Failed {}),
    };
    let m = file.metadata().map_err(|_| Error::Failed {})?;
    if !m.is_file() || m.nlink() != 1 {
        return Err(Error::Failed {});
    }
    let mut value = String::new();
    file.take(MAX_BYTES as u64 + 1)
        .read_to_string(&mut value)
        .map_err(|_| Error::Failed {})?;
    Ok(Some(value))
}
