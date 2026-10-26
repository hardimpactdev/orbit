use super::*;
use std::{
    ffi::OsString,
    path::{Component, PathBuf},
};
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    artifacts: Vec<Artifact>,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Seeded {
    ready: bool,
}
fn copy(source: &Path, target: &Path) -> io::Result<()> {
    if target.exists() {
        return Ok(());
    }
    fs::create_dir_all(target.parent().unwrap())?;
    let temporary = target.with_extension(format!("seed-{:016x}", rand::random::<u64>()));
    let result = (|| {
        fs::copy(source, &temporary)?;
        fs::set_permissions(&temporary, fs::Permissions::from_mode(0o644))?;
        fs::rename(&temporary, target)
    })();
    let _ = fs::remove_file(temporary);
    result
}
fn unpack(source: &Path, target: &Path) -> io::Result<()> {
    if target.exists() {
        return Ok(());
    }
    fs::create_dir_all(target.parent().unwrap())?;
    let work = target
        .parent()
        .unwrap()
        .join(format!(".orbit-extract-{:016x}", rand::random::<u64>()));
    fs::create_dir(&work)?;
    let result = (|| {
        let mut archive = tar::Archive::new(flate2::read::GzDecoder::new(fs::File::open(source)?));
        let mut root: Option<OsString> = None;
        for entry in archive.entries()? {
            let mut entry = entry?;
            let path = entry.path()?.into_owned();
            let mut components = path.components();
            let Some(Component::Normal(first)) = components.next() else {
                return Err(io::Error::other("Registry archive has an unexpected root"));
            };
            if root.as_ref().is_some_and(|r| r != first)
                || components.any(|c| !matches!(c, Component::Normal(_) | Component::CurDir))
            {
                return Err(io::Error::other("Registry archive has an unsafe path"));
            }
            root = Some(first.to_owned());
            let kind = entry.header().entry_type();
            if !(kind.is_file() || kind.is_dir() || kind.is_symlink() || kind.is_hard_link()) {
                return Err(io::Error::other("Registry archive has an unsafe entry"));
            }
            if kind.is_symlink() || kind.is_hard_link() {
                let link = entry
                    .link_name()?
                    .ok_or_else(|| io::Error::other("Registry archive has no link target"))?;
                let mut resolved = if kind.is_symlink() {
                    path.parent()
                        .unwrap()
                        .components()
                        .filter_map(|c| {
                            if let Component::Normal(p) = c {
                                Some(p.to_owned())
                            } else {
                                None
                            }
                        })
                        .collect::<Vec<_>>()
                } else {
                    vec![]
                };
                for part in link.components() {
                    match part {
                        Component::Normal(value) => resolved.push(value.to_owned()),
                        Component::CurDir => {}
                        Component::ParentDir if !resolved.is_empty() => {
                            resolved.pop();
                        }
                        _ => {
                            return Err(io::Error::other(
                                "Registry archive link escapes its package",
                            ))
                        }
                    }
                }
                if resolved.first() != root.as_ref() {
                    return Err(io::Error::other(
                        "Registry archive link escapes its package",
                    ));
                }
            }
            if !entry.unpack_in(&work)? {
                return Err(io::Error::other("Registry archive escapes the cache"));
            }
            if kind.is_dir() || kind.is_file() {
                // Strip trailing slashes so metadata cannot follow a directory symlink.
                let target = work.join(path.components().collect::<PathBuf>());
                let metadata = fs::symlink_metadata(&target)?;
                if (kind.is_dir() && !metadata.is_dir()) || (kind.is_file() && !metadata.is_file())
                {
                    return Err(io::Error::other(
                        "Registry archive entry has an unexpected type",
                    ));
                }
                let mode = if kind.is_dir() || entry.header().mode().unwrap_or(0o644) & 0o111 != 0 {
                    0o755
                } else {
                    0o644
                };
                fs::set_permissions(target, fs::Permissions::from_mode(mode))?;
            }
        }
        let extracted =
            work.join(root.ok_or_else(|| io::Error::other("Registry archive is empty"))?);
        if !fs::symlink_metadata(&extracted)?.is_dir() {
            return Err(io::Error::other(
                "Registry archive has no package directory",
            ));
        }
        fs::rename(extracted, target)
    })();
    let _ = fs::remove_dir_all(work);
    result
}
fn artifact_failed(source: &Path, artifact: &Artifact, error: io::Error) -> Error {
    use std::error::Error as _;
    let mut reasons = vec![error.to_string()];
    let mut cause = error.source();
    while let Some(error) = cause {
        reasons.push(error.to_string());
        cause = error.source();
    }
    // Keep the cause before archive context so the reason's size limit cannot hide it.
    reasons.reverse();
    failed(
        "seed",
        source,
        format!(
            "{}@{} [{}]: {}",
            artifact.package,
            artifact.version,
            artifact.key,
            reasons.join(": ")
        ),
    )
}
fn seed(shared: &Path, home: &Path, artifacts: &[Artifact]) -> Result<(), Error> {
    let inventory = inventory(shared).map_err(|e| failed("seed", shared, e))?;
    for a in artifacts {
        let integrity =
            validate(a).map_err(|reason| artifact_failed(shared, a, io::Error::other(reason)))?;
        let source = shared.join(&a.key);
        let Some(digest) = inventory.get(&a.key) else {
            continue;
        };
        if !source.is_file() {
            continue;
        }
        if hex(&hash(&source, "sha256").map_err(|e| artifact_failed(&source, a, e))?) != *digest {
            continue;
        }
        if a.manager == "composer" {
            copy(
                &source,
                &home
                    .join(".cache/composer/files")
                    .join(a.key.strip_prefix("composer/").unwrap()),
            )
            .map_err(|e| artifact_failed(&source, a, e))?;
        } else {
            let integrity = integrity.unwrap();
            let content = hex(&integrity.wanted);
            let target = home
                .join(".npm/_cacache/content-v2")
                .join(&integrity.algorithm)
                .join(&content[..2])
                .join(&content[2..4])
                .join(&content[4..]);
            copy(&source, &target).map_err(|e| artifact_failed(&source, a, e))?;
            let target = home
                .join(".bun/install/cache")
                .join(format!("{}@{}@@@1", a.package, a.version));
            unpack(&source, &target).map_err(|e| artifact_failed(&source, a, e))?;
        }
    }
    Ok(())
}
fn await_mount(shared: &Path, mut wait: impl FnMut()) -> Result<(), Error> {
    let marker = shared.join(".orbit-owned");
    for _ in 0..100 {
        match fs::metadata(&marker) {
            Ok(meta) if meta.is_file() => return Ok(()),
            Err(e) if e.kind() != io::ErrorKind::NotFound => {
                return Err(failed("seed", &marker, e))
            }
            _ => wait(),
        }
    }
    Err(failed(
        "seed",
        &marker,
        "Read-only package cache marker did not arrive",
    ))
}
pub struct SeedCache;
impl Command for SeedCache {
    const NAME: &'static str = "package-cache.seed";
    const SUMMARY: &'static str =
        "Seed private Composer, npm and Bun caches from the read-only host mount.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(600);
    const RESOURCES: &'static [&'static str] = &["package-cache-seed"];
    type Input = Input;
    type Output = Seeded;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        crate::commands::linux_only(platform, Privilege::Account)
    }
    fn run(input: Input, _: &Context) -> Result<Seeded, Error> {
        let home = crate::command::identity::passwd("orbit")
            .filter(|p| p.uid == rustix::process::geteuid().as_raw())
            .map(|p| PathBuf::from(p.home))
            .ok_or_else(|| failed("seed", Path::new(GUEST), "The guest account is not orbit"))?;
        await_mount(Path::new(GUEST), || {
            std::thread::sleep(Duration::from_millis(100))
        })?;
        seed(Path::new(GUEST), &home, &input.artifacts)?;
        Ok(Seeded { ready: true })
    }
}
#[cfg(all(test, target_os = "linux"))]
mod managers;
#[cfg(test)]
mod tests;
