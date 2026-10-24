use super::*;
use std::{
    io,
    os::unix::fs::{symlink, MetadataExt, PermissionsExt},
    path::Component,
};

fn failure(error: impl ToString) -> Failure {
    Failure::new("rust", error)
}
fn paths(directory: &Path) -> Result<Vec<PathBuf>, Failure> {
    let mut paths = fs::read_dir(directory)
        .map_err(failure)?
        .map(|entry| entry.map(|entry| entry.path()))
        .collect::<io::Result<Vec<_>>>()
        .map_err(failure)?;
    paths.sort();
    Ok(paths)
}
pub fn pins(input: &Input) -> serde_json::Value {
    serde_json::Value::Object(
        input
            .rust
            .iter()
            .map(|(name, pin)| {
                (
                    name.clone(),
                    serde_json::to_value(&pin.artifacts[input.recipe.architecture.name()]).unwrap(),
                )
            })
            .collect(),
    )
}
fn relative(path: &str) -> Result<&Path, Failure> {
    let path = Path::new(path);
    if path.as_os_str().is_empty()
        || path
            .components()
            .any(|c| !matches!(c, Component::Normal(_)))
    {
        return Err(failure("Invalid Rust component manifest path"));
    }
    Ok(path)
}
fn real_ancestors(root: &Path, path: &Path) -> Result<(), Failure> {
    for ancestor in path.ancestors().take_while(|p| *p != root) {
        if !fs::symlink_metadata(ancestor).map_err(failure)?.is_dir() {
            return Err(failure("Rust manifest traverses a non-directory"));
        }
    }
    Ok(())
}
fn merge(source: &Path, target: &Path) -> Result<(), Failure> {
    let source_meta = fs::symlink_metadata(source).map_err(failure)?;
    match fs::symlink_metadata(target) {
        Ok(target_meta) if source_meta.is_dir() && target_meta.is_dir() => {
            for child in paths(source)? {
                merge(&child, &target.join(child.file_name().unwrap()))?;
            }
            Ok(())
        }
        Ok(target_meta)
            if source_meta.is_file()
                && target_meta.is_file()
                && source_meta.mode() == target_meta.mode()
                && fs::read(source).map_err(failure)? == fs::read(target).map_err(failure)? =>
        {
            Ok(())
        }
        Ok(target_meta)
            if source_meta.is_symlink()
                && target_meta.is_symlink()
                && fs::read_link(source).map_err(failure)?
                    == fs::read_link(target).map_err(failure)? =>
        {
            Ok(())
        }
        Ok(_) => Err(failure("Rust components declare conflicting destinations")),
        Err(e) if e.kind() == io::ErrorKind::NotFound => {
            artifacts::copy_tree(source, target).map_err(failure)
        }
        Err(e) => Err(failure(e)),
    }
}
fn parents(prefix: &Path, parent: &Path) -> Result<(), Failure> {
    let mut current = prefix.to_path_buf();
    for part in parent.strip_prefix(prefix).map_err(failure)?.components() {
        current.push(part);
        match fs::symlink_metadata(&current) {
            Ok(meta) if meta.is_dir() => {}
            Ok(_) => return Err(failure("Rust destination traverses a non-directory")),
            Err(e) if e.kind() == io::ErrorKind::NotFound => {
                fs::create_dir(&current).map_err(failure)?
            }
            Err(e) => return Err(failure(e)),
        }
    }
    Ok(())
}
/// Distribution manifests declare file and directory trees; no upstream install.sh is executed.
pub fn component(unpacked: &Path, prefix: &Path) -> Result<(), Failure> {
    let roots = paths(unpacked)?;
    let [root] = roots.as_slice() else {
        return Err(failure("Rust archive must have one distribution root"));
    };
    real_ancestors(unpacked, root)?;
    let components = fs::read_to_string(root.join("components")).map_err(failure)?;
    if components.trim().is_empty() {
        return Err(failure("Rust archive has no components"));
    }
    let mut declared = vec![];
    for name in components.lines() {
        let directory = root.join(relative(name)?);
        real_ancestors(root, &directory)?;
        for entry in fs::read_to_string(directory.join("manifest.in"))
            .map_err(failure)?
            .lines()
        {
            let (kind, path) = entry
                .split_once(':')
                .ok_or_else(|| failure("Malformed Rust manifest"))?;
            let path = relative(path)?;
            let source = directory.join(path);
            real_ancestors(&directory, source.parent().unwrap())?;
            let meta = fs::symlink_metadata(&source).map_err(failure)?;
            if !matches!(
                (kind, meta.is_dir(), meta.is_file() || meta.is_symlink()),
                ("dir", true, _) | ("file", _, true)
            ) {
                return Err(failure("Rust manifest type does not match its member"));
            }
            declared.push((source, prefix.join(path)));
        }
    }
    for (source, target) in declared {
        parents(prefix, target.parent().unwrap())?;
        merge(&source, &target)?;
    }
    Ok(())
}
fn validate_tree(prefix: &Path) -> Result<(), Failure> {
    let canonical = prefix.canonicalize().map_err(failure)?;
    for relative in artifacts::contents(prefix).map_err(failure)? {
        let path = prefix.join(relative);
        if fs::symlink_metadata(&path).map_err(failure)?.is_symlink()
            && !path
                .canonicalize()
                .map_err(failure)?
                .starts_with(&canonical)
        {
            return Err(failure("Rust component link escapes its installed prefix"));
        }
    }
    for binary in paths(&prefix.join("bin"))? {
        let meta = fs::metadata(binary).map_err(failure)?;
        if !meta.is_file() || meta.mode() & 0o111 == 0 {
            return Err(failure("Rust bin entry is not executable"));
        }
    }
    Ok(())
}
fn link_destinations(files: &Files, account: &Account) -> Result<[PathBuf; 2], Failure> {
    let cargo = account.home.join(".cargo/bin");
    for path in [account.home.join(".cargo"), cargo.clone()] {
        files.directory(&path, account.uid, account.gid)?;
    }
    let local = files.path("/usr/local/bin");
    directories(files, &local)?;
    Ok([cargo, local])
}
fn check_target(target: &Path, uid: u32) -> Result<(), Failure> {
    match fs::symlink_metadata(target) {
        Ok(meta) if (meta.is_file() || meta.is_symlink()) && meta.uid() == uid => Ok(()),
        Ok(_) => Err(failure("Rust binary link destination is not an owned file")),
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(()),
        Err(e) => Err(failure(e)),
    }
}
fn links(files: &Files, prefix: &Path, account: &Account, publish: bool) -> Result<(), Failure> {
    let destinations = link_destinations(files, account)?;
    for binary in paths(&prefix.join("bin"))? {
        for (directory, uid, gid) in [
            (&destinations[0], account.uid, account.gid),
            (&destinations[1], files.uid, files.gid),
        ] {
            let target = directory.join(binary.file_name().unwrap());
            check_target(&target, uid)?;
            let installed = files
                .path("/opt/orbit/rust/bin")
                .join(binary.file_name().unwrap());
            if !publish || fs::read_link(&target).ok().as_ref() == Some(&installed) {
                continue;
            }
            let temporary = directory.join(format!(".orbit-rust-{:016x}", rand::random::<u64>()));
            let result = (|| -> io::Result<()> {
                symlink(&installed, &temporary)?;
                rustix::fs::chownat(
                    rustix::fs::CWD,
                    &temporary,
                    Some(rustix::fs::Uid::from_raw(uid)),
                    Some(rustix::fs::Gid::from_raw(gid)),
                    rustix::fs::AtFlags::SYMLINK_NOFOLLOW,
                )?;
                fs::rename(&temporary, &target)
            })();
            let _ = fs::remove_file(&temporary);
            result.map_err(failure)?;
        }
    }
    Ok(())
}
fn publish(files: &Files, candidate: &Path, work: &Path, account: &Account) -> Result<(), Failure> {
    let prefix = files.path("/opt/orbit/rust");
    validate_tree(candidate)?;
    artifacts::own_tree(candidate, files.uid, files.gid)?;
    links(files, candidate, account, false)?;
    let previous = work.join("previous");
    let had_previous = match fs::symlink_metadata(&prefix) {
        Ok(meta) if meta.is_dir() && meta.uid() == files.uid => {
            fs::rename(&prefix, &previous).map_err(failure)?;
            true
        }
        Ok(_) => return Err(failure("Rust prefix is not an owned directory")),
        Err(e) if e.kind() == io::ErrorKind::NotFound => false,
        Err(e) => return Err(failure(e)),
    };
    if let Err(error) = fs::rename(candidate, &prefix) {
        if had_previous {
            fs::rename(&previous, &prefix).map_err(failure)?;
        }
        return Err(failure(error));
    }
    links(files, &prefix, account, true)?;
    // Only remove obsolete links that still point at this toolchain.
    if had_previous && previous.join("bin").is_dir() {
        for binary in paths(&previous.join("bin"))? {
            let name = binary.file_name().unwrap();
            let installed = prefix.join("bin").join(name);
            if fs::symlink_metadata(&installed).is_ok() {
                continue;
            }
            for directory in link_destinations(files, account)? {
                let target = directory.join(name);
                if fs::read_link(&target).ok().as_ref() == Some(&installed) {
                    fs::remove_file(target).map_err(failure)?;
                }
            }
        }
    }
    Ok(())
}
pub fn install(
    host: &mut impl Host,
    files: &Files,
    input: &Input,
    account: &Account,
) -> Result<(), Failure> {
    if input.rust.is_empty() {
        return Ok(());
    }
    let prefix = files.path("/opt/orbit/rust");
    let receipt = prefix.join(".recipe-artifacts.json");
    match fs::symlink_metadata(&prefix) {
        Ok(meta) if meta.is_dir() && meta.uid() == files.uid => {}
        Ok(_) => return Err(failure("Rust prefix is not an owned directory")),
        Err(e) if e.kind() == io::ErrorKind::NotFound => {}
        Err(e) => return Err(failure(e)),
    }
    let pins = pins(input);
    if recipe::store::json(&receipt)? == pins {
        validate_tree(&prefix)?;
        return links(files, &prefix, account, true);
    }
    let parent = files.path("/opt/orbit");
    directories(files, &parent)?;
    let work = parent.join(format!(".rust-{:016x}", rand::random::<u64>()));
    fs::create_dir(&work).map_err(failure)?;
    fs::set_permissions(&work, fs::Permissions::from_mode(0o700)).map_err(failure)?;
    let work = WorkDir(work);
    let candidate = work.0.join("candidate");
    fs::create_dir(&candidate).map_err(failure)?;
    for name in RUST {
        if let Some(pin) = input.rust.get(name) {
            let archive = work.0.join(format!("{name}.archive"));
            artifacts::download(host, name, pin, &input.recipe, &archive)?;
            let unpacked = work.0.join(name);
            artifacts::extract(&archive, &unpacked)?;
            component(&unpacked, &candidate)?;
        }
    }
    publish(files, &candidate, &work.0, account)?;
    files.publish_root(&receipt, &serde_json::to_vec(&pins).unwrap())
}

#[cfg(test)]
mod tests;
