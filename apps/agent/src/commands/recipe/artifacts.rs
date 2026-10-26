//! Verified artifact downloads and native archive extraction, shared by recipe consumers.
use super::{
    run,
    types::{Artifact, Input, Tool},
    Failure, Host, Invocation,
};
use sha2::{Digest, Sha256, Sha512};
use std::{
    fs,
    io::{self, Read},
    os::unix::fs::{MetadataExt, PermissionsExt},
    path::{Component, Path, PathBuf},
};
const MAX_ARCHIVE: u64 = 512 * 1024 * 1024;
const MAX_EXPANDED: u64 = 2 * 1024 * 1024 * 1024;

pub fn candidates(name: &str, artifact: &Artifact, mirror: &str) -> Vec<String> {
    let Some(url) = &artifact.url else {
        return vec![];
    };
    let mut candidates = vec![];
    if !mirror.is_empty() && url.ends_with(".deb") {
        if let Some(digest) = artifact.sha256.as_ref().or(artifact.sha512.as_ref()) {
            candidates.push(format!("{mirror}/{name}_{digest}.deb"));
        }
    }
    if !candidates.contains(url) {
        candidates.push(url.clone());
    }
    candidates
}
pub fn verify(path: &Path, artifact: &Artifact) -> Result<(), Failure> {
    let mut file = fs::File::open(path).map_err(|e| Failure::io("download", path, e))?;
    if file
        .metadata()
        .map_err(|e| Failure::io("download", path, e))?
        .len()
        > MAX_ARCHIVE
    {
        return Err(Failure::new("download", "Artifact exceeds its size bound"));
    }
    let mut sha256 = Sha256::new();
    let mut sha512 = Sha512::new();
    let mut buffer = [0; 65536];
    loop {
        let size = file
            .read(&mut buffer)
            .map_err(|e| Failure::io("download", path, e))?;
        if size == 0 {
            break;
        }
        sha256.update(&buffer[..size]);
        sha512.update(&buffer[..size]);
    }
    let valid = match &artifact.sha256 {
        Some(digest) => *digest == format!("{:x}", sha256.finalize()),
        None => artifact
            .sha512
            .as_ref()
            .is_some_and(|digest| *digest == format!("{:x}", sha512.finalize())),
    };
    if !valid {
        return Err(Failure::new(
            "download",
            "Artifact digest differs from its recipe pin",
        ));
    }
    Ok(())
}
pub fn download(
    host: &mut impl Host,
    name: &str,
    pin: &Tool,
    input: &Input,
    destination: &Path,
) -> Result<(), Failure> {
    let artifact = pin
        .artifacts
        .get(input.architecture.name())
        .ok_or_else(|| Failure::new("download", "Artifact architecture is absent"))?;
    let mut last = Failure::new("download", "Artifact has no download URL");
    for url in candidates(name, artifact, &input.artifact_mirror) {
        if !super::types::https(&url) {
            return Err(Failure::new("download", "Artifact downloads require HTTPS"));
        }
        let invocation = Invocation::new(&[
            "curl",
            "--fail",
            "--silent",
            "--show-error",
            "--location",
            "--proto",
            "=https",
            "--proto-redir",
            "=https",
            "--connect-timeout",
            "15",
            "--max-time",
            "600",
            "--max-filesize",
            &MAX_ARCHIVE.to_string(),
            "--output",
            &destination.display().to_string(),
            "--",
            &url,
        ]);
        match run(host, &invocation, true) {
            Ok(_) => return verify(destination, artifact),
            Err(error) => last = error.at("download"),
        }
    }
    Err(last)
}
pub fn verify_deb(
    host: &mut impl Host,
    path: &Path,
    name: &str,
    pin: &Tool,
    input: &Input,
) -> Result<(), Failure> {
    let output = run(
        host,
        &Invocation::new(&[
            "dpkg-deb",
            "-f",
            &path.display().to_string(),
            "Package",
            "Version",
            "Architecture",
        ]),
        true,
    )?;
    let fields: Vec<_> = output
        .stdout
        .lines()
        .map(|line| line.split_once(": ").map_or(line, |(_, v)| v))
        .collect();
    if fields.len() != 3
        || fields[0] != name
        || fields[1] != pin.version
        || ![input.architecture.name(), "all"].contains(&fields[2])
    {
        return Err(Failure::new(
            "download",
            "Debian artifact name, complete version, or architecture differs from its pin",
        ));
    }
    Ok(())
}
fn normal(path: &Path) -> bool {
    !path.as_os_str().is_empty()
        && path
            .components()
            .all(|c| matches!(c, Component::Normal(_) | Component::CurDir))
}
fn safe_link(path: &Path) -> bool {
    let mut depth = 0usize;
    for component in path.components() {
        match component {
            Component::Normal(_) => depth += 1,
            Component::CurDir => {}
            Component::ParentDir if depth > 0 => depth -= 1,
            _ => return false,
        }
    }
    true
}
fn tar_reader(path: &Path) -> io::Result<Box<dyn Read>> {
    let mut header = [0u8; 6];
    fs::File::open(path)?.read_exact(&mut header)?;
    let file = fs::File::open(path)?;
    if header.starts_with(&[0x1f, 0x8b]) {
        Ok(Box::new(flate2::read::GzDecoder::new(file)))
    } else if header == [0xfd, b'7', b'z', b'X', b'Z', 0] {
        Ok(Box::new(xz2::read::XzDecoder::new(file)))
    } else {
        Ok(Box::new(file))
    }
}
fn extract_tar(archive: &Path, destination: &Path) -> io::Result<()> {
    let mut size = 0u64;
    for entry in tar::Archive::new(tar_reader(archive)?).entries()? {
        let entry = entry?;
        let path = entry.path()?;
        let kind = entry.header().entry_type();
        if !normal(&path)
            || !(kind.is_file() || kind.is_dir() || kind.is_symlink() || kind.is_hard_link())
        {
            return Err(io::Error::other("Unsafe archive entry"));
        }
        size = size
            .checked_add(entry.size())
            .ok_or_else(|| io::Error::other("Archive size overflow"))?;
        if size > MAX_EXPANDED {
            return Err(io::Error::other("Archive exceeds expanded size bound"));
        }
        if kind.is_symlink() || kind.is_hard_link() {
            let link = entry
                .link_name()?
                .ok_or_else(|| io::Error::other("Missing archive link"))?;
            let target = if kind.is_symlink() {
                path.parent().unwrap_or(Path::new("")).join(link)
            } else {
                link.into_owned()
            };
            if !safe_link(&target) {
                return Err(io::Error::other("Unsafe archive link"));
            }
        }
    }
    let mut reader = tar::Archive::new(tar_reader(archive)?);
    reader.set_mask(0o7022);
    reader.set_preserve_ownerships(false);
    for entry in reader.entries()? {
        if !entry?.unpack_in(destination)? {
            return Err(io::Error::other("Archive entry escaped its destination"));
        }
    }
    Ok(())
}
fn extract_zip(archive: &Path, destination: &Path) -> io::Result<()> {
    let mut zip = zip::ZipArchive::new(fs::File::open(archive)?)?;
    let mut size = 0u64;
    for index in 0..zip.len() {
        let entry = zip.by_index(index)?;
        if !normal(Path::new(entry.name()?.as_ref()))
            || entry.enclosed_name().is_none()
            || entry
                .unix_mode()
                .is_some_and(|mode| mode & 0o170000 == 0o120000)
        {
            return Err(io::Error::other("Unsafe ZIP entry"));
        }
        size = size
            .checked_add(entry.size())
            .ok_or_else(|| io::Error::other("Archive size overflow"))?;
        if size > MAX_EXPANDED {
            return Err(io::Error::other("Archive exceeds expanded size bound"));
        }
    }
    for index in 0..zip.len() {
        let mut entry = zip.by_index(index)?;
        let path = destination.join(
            entry
                .enclosed_name()
                .ok_or_else(|| io::Error::other("Unsafe ZIP entry"))?,
        );
        if entry.is_dir() {
            fs::create_dir_all(&path)?;
        } else {
            fs::create_dir_all(path.parent().unwrap())?;
            let mut file = fs::OpenOptions::new()
                .write(true)
                .create_new(true)
                .open(&path)?;
            io::copy(&mut entry, &mut file)?;
            fs::set_permissions(
                &path,
                fs::Permissions::from_mode(entry.unix_mode().unwrap_or(0o644) & 0o755),
            )?;
        }
    }
    Ok(())
}
pub fn extract(archive: &Path, destination: &Path) -> Result<(), Failure> {
    let result = (|| -> io::Result<()> {
        fs::create_dir(destination)?;
        let mut magic = [0u8; 4];
        fs::File::open(archive)?.read_exact(&mut magic)?;
        if magic.starts_with(b"PK") {
            extract_zip(archive, destination)
        } else {
            extract_tar(archive, destination)
        }
    })();
    result.map_err(|e| Failure::io("tools", archive, e))
}
/// Preserve archive links without following them while assigning the managed account.
pub fn own_tree(path: &Path, uid: u32, gid: u32) -> Result<(), Failure> {
    let meta = fs::symlink_metadata(path).map_err(|e| Failure::io("tools", path, e))?;
    rustix::fs::chownat(
        rustix::fs::CWD,
        path,
        Some(rustix::fs::Uid::from_raw(uid)),
        Some(rustix::fs::Gid::from_raw(gid)),
        rustix::fs::AtFlags::SYMLINK_NOFOLLOW,
    )
    .map_err(|e| Failure::io("tools", path, e))?;
    if !meta.is_symlink() {
        fs::set_permissions(path, fs::Permissions::from_mode(meta.mode() & 0o755))
            .map_err(|e| Failure::io("tools", path, e))?;
    }
    if meta.is_dir() {
        for entry in fs::read_dir(path).map_err(|e| Failure::io("tools", path, e))? {
            own_tree(
                &entry.map_err(|e| Failure::io("tools", path, e))?.path(),
                uid,
                gid,
            )?;
        }
    }
    Ok(())
}
pub fn copy_tree(source: &Path, target: &Path) -> io::Result<()> {
    let meta = fs::symlink_metadata(source)?;
    if meta.is_symlink() {
        std::os::unix::fs::symlink(fs::read_link(source)?, target)
    } else if meta.is_dir() {
        fs::create_dir(target)?;
        fs::set_permissions(target, meta.permissions())?;
        for entry in fs::read_dir(source)? {
            let entry = entry?;
            copy_tree(&entry.path(), &target.join(entry.file_name()))?;
        }
        Ok(())
    } else if meta.is_file() {
        fs::copy(source, target)?;
        Ok(())
    } else {
        Err(io::Error::other("Unsupported runtime entry type"))
    }
}
pub fn contents(path: &Path) -> io::Result<BTreePaths> {
    fn walk(root: &Path, path: &Path, result: &mut BTreePaths) -> io::Result<()> {
        result.insert(path.strip_prefix(root).unwrap().to_path_buf());
        if fs::symlink_metadata(path)?.is_dir() {
            for entry in fs::read_dir(path)? {
                walk(root, &entry?.path(), result)?;
            }
        }
        Ok(())
    }
    let mut result = BTreePaths::new();
    walk(path, path, &mut result)?;
    Ok(result)
}
type BTreePaths = std::collections::BTreeSet<PathBuf>;

#[cfg(test)]
mod tests;
