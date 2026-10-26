//! The filesystem transitions of release adoption. Every path comes from one release layout.
pub mod preparation;

use super::recipe::{self, Host, Invocation, Native};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    fs, io,
    os::unix::fs::{symlink, MetadataExt},
    path::{Path, PathBuf},
    time::Duration,
};

command_errors! {
    pub enum Error {
        Invalid {} => "gateway.release_layout_invalid", "The Gateway release layout or adoption identity is invalid.";
        Unsafe {} => "gateway.release_adopt_conflict", "The adoption paths contain unexpected files, links, or ownership.";
        StorageMissing {} => "gateway.release_adopt_storage_missing", "The checkout has no storage directory.";
        StorageConflict {} => "gateway.release_adopt_storage_conflict", "The checkout and shared storage have conflicting paths.";
        NotPrepared {} => "gateway.release_not_prepared", "The requested release is not prepared from its named commit.";
        Failed { stage: String } => "gateway.release_adoption_failed", "A native release adoption step failed. The serving path remains available.";
    }
}
fn failed(stage: &str) -> Error {
    Error::Failed {
        stage: stage.into(),
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct StorageInput {
    pub checkout: String,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct SwitchInput {
    pub checkout: String,
    pub release: String,
    pub stamp: String,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct FinishInput {
    pub checkout: String,
    pub stamp: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct StorageOutput {
    storage: &'static str,
    #[serde(skip_serializing_if = "Option::is_none")]
    storage_method: Option<&'static str>,
    #[serde(skip_serializing_if = "Option::is_none")]
    storage_gap_us: Option<u64>,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct SwitchOutput {
    method: &'static str,
    gap_us: u64,
    from: String,
    to: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct RestoreOutput {
    changed: bool,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct FinishOutput {
    path: Option<String>,
}
pub(super) struct Layout {
    pub(super) current: PathBuf,
    pub(super) base: PathBuf,
}
pub(super) fn hex(s: &str, len: usize) -> bool {
    s.len() == len
        && s.bytes()
            .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
}
fn meta(path: &Path) -> Result<Option<fs::Metadata>, Error> {
    match fs::symlink_metadata(path) {
        Ok(m) => Ok(Some(m)),
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(None),
        Err(_) => Err(Error::Unsafe {}),
    }
}
pub(super) fn owned(path: &Path) -> Result<Option<fs::Metadata>, Error> {
    let m = meta(path)?;
    if m.as_ref()
        .is_some_and(|m| m.uid() != rustix::process::geteuid().as_raw())
    {
        return Err(Error::Unsafe {});
    }
    Ok(m)
}
pub(super) fn directory(path: &Path) -> Result<(), Error> {
    if !owned(path)?.is_some_and(|m| m.is_dir()) {
        return Err(Error::Unsafe {});
    }
    Ok(())
}
fn link(path: &Path, target: &Path) -> Result<bool, Error> {
    Ok(owned(path)?.is_some_and(|m| m.is_symlink())
        && fs::read_link(path).map_err(|_| Error::Unsafe {})? == target)
}
impl Layout {
    pub(super) fn new(checkout: &str) -> Result<Self, Error> {
        if !checkout.starts_with('/')
            || !checkout.ends_with("/apps/gateway")
            || checkout.bytes().any(|b| b.is_ascii_control())
            || checkout
                .split('/')
                .skip(1)
                .any(|c| c.is_empty() || c == "." || c == "..")
        {
            return Err(Error::Invalid {});
        }
        let current = Path::new(checkout)
            .parent()
            .unwrap()
            .parent()
            .unwrap()
            .to_path_buf();
        if matches!(
            current.file_name().and_then(|s| s.to_str()),
            Some("shared" | "releases")
        ) {
            return Err(Error::Invalid {});
        }
        let base = current
            .parent()
            .filter(|p| p.parent().is_some())
            .ok_or(Error::Invalid {})?
            .to_path_buf();
        for ancestor in base.ancestors() {
            let m = meta(ancestor)?.ok_or(Error::Unsafe {})?;
            if !m.is_dir() || (m.uid() != 0 && m.uid() != rustix::process::geteuid().as_raw()) {
                return Err(Error::Unsafe {});
            }
        }
        directory(&base)?;
        Ok(Self { current, base })
    }
    pub(super) fn shared(&self) -> PathBuf {
        self.base.join("shared")
    }
    fn next(&self) -> PathBuf {
        self.current.with_file_name(format!(
            "{}.adopt-next",
            self.current.file_name().unwrap().to_string_lossy()
        ))
    }
    fn kept(&self, stamp: &str) -> Result<PathBuf, Error> {
        if stamp.len() != 16
            || stamp.as_bytes()[8] != b'T'
            || stamp.as_bytes()[15] != b'Z'
            || !stamp
                .bytes()
                .enumerate()
                .all(|(i, b)| i == 8 || i == 15 || b.is_ascii_digit())
        {
            return Err(Error::Invalid {});
        }
        Ok(self.current.with_file_name(format!(
            "{}.pre-adopt-{stamp}",
            self.current.file_name().unwrap().to_string_lossy()
        )))
    }
    fn release(&self, id: &str) -> Result<PathBuf, Error> {
        if !hex(id, 12) {
            return Err(Error::Invalid {});
        }
        let releases = self.base.join("releases");
        directory(&releases)?;
        let release = releases.join(id);
        directory(&release)?;
        let revision = release.join("REVISION");
        if !owned(&revision)?.is_some_and(|m| m.is_file()) {
            return Err(Error::NotPrepared {});
        }
        let sha = fs::read_to_string(&revision).map_err(|_| Error::NotPrepared {})?;
        if !hex(sha.trim(), 40) || !sha.trim().starts_with(id) {
            return Err(Error::NotPrepared {});
        }
        Ok(Path::new("releases").join(id))
    }
    fn current_is(&self, target: &Path) -> Result<bool, Error> {
        Ok(link(&self.current, target)? || link(&self.current, &self.base.join(target))?)
    }
    fn checkout_release(&self, path: &Path, id: &str) -> Result<(), Error> {
        self.checkout(path)?;
        let repository = git2::Repository::open(path).map_err(|_| Error::Unsafe {})?;
        let head = repository
            .head()
            .and_then(|h| h.peel_to_commit())
            .map_err(|_| Error::Unsafe {})?;
        if !head.id().to_string().starts_with(id) {
            return Err(Error::Unsafe {});
        }
        Ok(())
    }
    fn checkout(&self, path: &Path) -> Result<(), Error> {
        directory(path)?;
        directory(&path.join(".git"))?;
        directory(&path.join("apps"))?;
        directory(&path.join("apps/gateway"))
    }
}
fn exchange(first: &Path, second: &Path) -> io::Result<()> {
    rustix::fs::renameat_with(
        rustix::fs::CWD,
        first,
        rustix::fs::CWD,
        second,
        rustix::fs::RenameFlags::EXCHANGE,
    )
    .map_err(Into::into)
}
fn mark_storage(layout: &Layout, host: &mut impl Host) -> Result<Vec<String>, Error> {
    let current = layout.current.to_str().ok_or(Error::Invalid {})?;
    let result = recipe::run(
        host,
        &Invocation::new(&[
            "/usr/bin/git",
            "-C",
            current,
            "ls-files",
            "-v",
            "-z",
            "--",
            "apps/gateway/storage",
        ]),
        true,
    )
    .map_err(|_| failed("storage-index"))?;
    let paths: Vec<_> = result
        .stdout
        .split('\0')
        .filter(|s| !s.is_empty())
        .filter_map(|s| {
            let (flag, path) = s.split_once(' ')?;
            (!matches!(flag, "S" | "s")).then(|| path.to_owned())
        })
        .collect();
    storage_skip(layout, host, &paths, true)?;
    Ok(paths)
}
fn storage_skip(
    layout: &Layout,
    host: &mut impl Host,
    paths: &[String],
    skip: bool,
) -> Result<(), Error> {
    if !paths.is_empty() {
        let current = layout.current.to_str().ok_or(Error::Invalid {})?;
        let mut args = vec![
            "/usr/bin/git",
            "-C",
            current,
            "update-index",
            if skip {
                "--skip-worktree"
            } else {
                "--no-skip-worktree"
            },
            "--",
        ];
        args.extend(paths.iter().map(String::as_str));
        recipe::run(host, &Invocation::new(&args), true).map_err(|_| failed("storage-index"))?;
    }
    Ok(())
}
fn storage(
    input: &StorageInput,
    host: &mut impl Host,
    mut swap: impl FnMut(&Path, &Path) -> io::Result<()>,
) -> Result<StorageOutput, Error> {
    let layout = Layout::new(&input.checkout)?;
    layout.checkout(&layout.current)?;
    directory(&layout.shared())?;
    let source = layout.current.join("apps/gateway/storage");
    let target = layout.shared().join("gateway-storage");
    let parking = layout.current.join("apps/gateway/storage.adopt-link");
    let existing = StorageOutput {
        storage: "existing",
        storage_method: None,
        storage_gap_us: None,
    };
    let moved = StorageOutput {
        storage: "moved",
        storage_method: Some("exchange"),
        storage_gap_us: Some(0),
    };
    let source_meta = owned(&source)?;
    if source_meta.as_ref().is_some_and(|m| m.is_symlink()) {
        if !link(&source, &target)? {
            return Err(Error::StorageConflict {});
        }
        if link(&target, &parking)? && owned(&parking)?.is_some_and(|m| m.is_dir()) {
            swap(&target, &parking).map_err(|_| failed("storage-exchange"))?;
            fs::remove_file(&parking).map_err(|_| failed("storage-cleanup"))?;
            mark_storage(&layout, host)?;
            return Ok(moved);
        }
        if !owned(&target)?.is_some_and(|m| m.is_dir()) {
            return Err(Error::StorageConflict {});
        }
        // An interrupted final cleanup leaves only the link that this operation created.
        if owned(&parking)?.is_some() {
            if !link(&parking, &parking)? {
                return Err(Error::StorageConflict {});
            }
            fs::remove_file(&parking).map_err(|_| failed("storage-cleanup"))?;
        }
        mark_storage(&layout, host)?;
        return Ok(existing);
    }
    if !source_meta.is_some_and(|m| m.is_dir()) {
        return Err(Error::StorageMissing {});
    }
    // Validate both staging names before removing either one.
    if (owned(&target)?.is_some() && !link(&target, &parking)?)
        || (owned(&parking)?.is_some() && !link(&parking, &target)?)
    {
        return Err(Error::StorageConflict {});
    }
    if owned(&target)?.is_some() {
        fs::remove_file(&target).map_err(|_| failed("storage-links"))?;
    }
    if owned(&parking)?.is_some() {
        fs::remove_file(&parking).map_err(|_| failed("storage-links"))?;
    }
    symlink(&parking, &target).map_err(|_| failed("storage-links"))?;
    if let Err(e) = symlink(&target, &parking) {
        let _ = fs::remove_file(&target);
        return Err(failed(if e.kind() == io::ErrorKind::AlreadyExists {
            "storage-conflict"
        } else {
            "storage-links"
        }));
    }
    // The outer adoption refuses tracked changes before resuming. Mark placeholders before the
    // first exchange, so an interrupted move still passes that check. Undo only flags we changed
    // when the first exchange refuses and the original directory stays in place.
    let marked = match mark_storage(&layout, host) {
        Ok(paths) => paths,
        Err(error) => {
            fs::remove_file(&target).map_err(|_| failed("storage-cleanup"))?;
            fs::remove_file(&parking).map_err(|_| failed("storage-cleanup"))?;
            return Err(error);
        }
    };
    if swap(&source, &parking).is_err() {
        storage_skip(&layout, host, &marked, false).map_err(|_| failed("storage-index-recover"))?;
        fs::remove_file(&target).map_err(|_| failed("storage-cleanup"))?;
        fs::remove_file(&parking).map_err(|_| failed("storage-cleanup"))?;
        return Err(failed("storage-exchange"));
    }
    // On failure the source still reaches its directory through target and parking. A repeat finishes it.
    swap(&target, &parking).map_err(|_| failed("storage-exchange"))?;
    fs::remove_file(&parking).map_err(|_| failed("storage-cleanup"))?;
    Ok(moved)
}
fn switch(
    input: &SwitchInput,
    mut swap: impl FnMut(&Path, &Path) -> io::Result<()>,
) -> Result<SwitchOutput, Error> {
    let layout = Layout::new(&input.checkout)?;
    let kept = layout.kept(&input.stamp)?;
    let target = layout.release(&input.release)?;
    let next = layout.next();
    if layout.current_is(&target)? {
        if owned(&next)?.is_some() {
            finish(&FinishInput {
                checkout: input.checkout.clone(),
                stamp: input.stamp.clone(),
            })?;
        }
        layout.checkout_release(&kept, &input.release)?;
    } else {
        layout.checkout_release(&layout.current, &input.release)?;
        if owned(&kept)?.is_some() || (owned(&next)?.is_some() && !link(&next, &target)?) {
            return Err(Error::Unsafe {});
        }
        if owned(&next)?.is_none() {
            symlink(&target, &next).map_err(|_| failed("switch-link"))?;
        }
        if swap(&next, &layout.current).is_err() {
            fs::remove_file(&next).map_err(|_| failed("switch-cleanup"))?;
            return Err(failed("switch-exchange"));
        }
        fs::rename(&next, &kept).map_err(|_| failed("switch-keep"))?;
    }
    Ok(SwitchOutput {
        method: "exchange",
        gap_us: 0,
        from: kept.to_string_lossy().into(),
        to: target.to_string_lossy().into(),
    })
}
fn restore(
    input: &SwitchInput,
    mut swap: impl FnMut(&Path, &Path) -> io::Result<()>,
) -> Result<RestoreOutput, Error> {
    let layout = Layout::new(&input.checkout)?;
    let kept = layout.kept(&input.stamp)?;
    let target = layout.release(&input.release)?;
    if owned(&layout.current)?.is_some_and(|m| m.is_dir()) {
        layout.checkout_release(&layout.current, &input.release)?;
        if owned(&kept)?.is_some() {
            if !link(&kept, &target)? && !link(&kept, &layout.base.join(&target))? {
                return Err(Error::Unsafe {});
            }
            fs::remove_file(&kept).map_err(|_| failed("restore-cleanup"))?;
        }
        return Ok(RestoreOutput { changed: false });
    }
    if !layout.current_is(&target)? {
        return Err(Error::Unsafe {});
    }
    layout.checkout_release(&kept, &input.release)?;
    swap(&kept, &layout.current).map_err(|_| failed("restore-exchange"))?;
    fs::remove_file(&kept).map_err(|_| failed("restore-cleanup"))?;
    Ok(RestoreOutput { changed: true })
}
fn finish(input: &FinishInput) -> Result<FinishOutput, Error> {
    let layout = Layout::new(&input.checkout)?;
    let kept = layout.kept(&input.stamp)?;
    let next = layout.next();
    if owned(&next)?.is_none() {
        return Ok(FinishOutput { path: None });
    }
    let raw = fs::read_link(&layout.current).map_err(|_| Error::Unsafe {})?;
    let id = raw
        .file_name()
        .and_then(|s| s.to_str())
        .ok_or(Error::Unsafe {})?;
    let target = layout.release(id)?;
    if !layout.current_is(&target)? || owned(&kept)?.is_some() {
        return Err(Error::Unsafe {});
    }
    layout.checkout_release(&next, id)?;
    fs::rename(&next, &kept).map_err(|_| failed("switch-keep"))?;
    Ok(FinishOutput {
        path: Some(kept.to_string_lossy().into()),
    })
}
macro_rules! command {
    ($name:ident, $command:literal, $input:ty, $output:ty, $run:expr) => {
        pub struct $name;
        impl Command for $name {
            const NAME: &'static str = $command;
            const SUMMARY: &'static str =
                "Converge a Gateway release adoption transition without a missing serving path.";
            const MUTATES: bool = true;
            const TIMEOUT: Duration = Duration::from_secs(60);
            type Input = $input;
            type Output = $output;
            type Error = Error;
            fn privilege(platform: Platform) -> Option<Privilege> {
                super::linux_only(platform, Privilege::Account)
            }
            fn target(input: &Self::Input) -> Option<String> {
                Some(format!("gateway-release:{}", input.checkout))
            }
            fn run(input: Self::Input, context: &Context) -> Result<Self::Output, Error> {
                ($run)(input, context)
            }
        }
    };
}
command!(
    Storage,
    "gateway.adopt-release-storage",
    StorageInput,
    StorageOutput,
    |input: StorageInput, context: &Context| storage(&input, &mut Native(context), exchange)
);
command!(
    Switch,
    "gateway.switch-release-adoption",
    SwitchInput,
    SwitchOutput,
    |input: SwitchInput, _: &Context| switch(&input, exchange)
);
command!(
    Restore,
    "gateway.restore-release-adoption",
    SwitchInput,
    RestoreOutput,
    |input: SwitchInput, _: &Context| restore(&input, exchange)
);
command!(
    Finish,
    "gateway.finish-release-adoption",
    FinishInput,
    FinishOutput,
    |input: FinishInput, _: &Context| finish(&input)
);
#[cfg(test)]
mod tests;
