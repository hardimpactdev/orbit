//! Repository and private environment preparation for release adoption.
use super::{directory, hex, owned, Layout, StorageInput};
use crate::commands::recipe::{self, Host, Invocation, Native, Output};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    fs,
    io::Write,
    os::unix::fs::{DirBuilderExt, OpenOptionsExt},
    path::{Path, PathBuf},
    time::Duration,
};

command_errors! {
    pub enum Error {
        Invalid {} => "gateway.release_layout_invalid", "The Gateway release adoption input is invalid.";
        Conflict {} => "gateway.release_adopt_conflict", "An adoption path has unexpected type or ownership.";
        Unsupported {} => "gateway.release_adopt_unsupported", "The checkout is a link rather than an in-place checkout.";
        NotCheckout {} => "gateway.release_adopt_not_checkout", "The path is not an in-place Orbit checkout with a commit.";
        Changes { count: usize, path: String } => "gateway.release_adopt_local_changes", "The checkout has tracked changes. Commit, move, or discard them before adoption.";
        RepositoryConflict {} => "gateway.release_adopt_repository_conflict", "The shared repository does not hold the checkout's commit.";
        RepositoryFailed {} => "gateway.release_adopt_repository_failed", "The release adoption repository could not be prepared.";
        EnvMissing {} => "gateway.release_adopt_env_missing", "The checkout has no regular environment file.";
        EnvConflict {} => "gateway.release_adopt_env_conflict", "The environment paths conflict with the checkout's original environment.";
        EnvFailed {} => "gateway.release_adopt_env_failed", "The private adoption environment could not be published.";
    }
}
impl From<super::Error> for Error {
    fn from(error: super::Error) -> Self {
        match error {
            super::Error::Invalid {} => Self::Invalid {},
            _ => Self::Conflict {},
        }
    }
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RepositoryInput {
    pub checkout: String,
    pub sha: String,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct TargetInput {
    pub checkout: String,
    pub target: String,
    pub source: Option<String>,
}
#[derive(Debug, Serialize)]
pub struct HeadOutput {
    pub sha: String,
}
#[derive(Debug, Serialize)]
pub struct OriginOutput {
    pub sha: Option<String>,
}
#[derive(Debug, Serialize)]
pub struct RepositoryOutput {
    pub repository: &'static str,
}
#[derive(Debug, Serialize)]
pub struct EnvironmentOutput {
    pub env: &'static str,
    pub env_link: &'static str,
    pub app_version_removed: bool,
    pub env_backups: Vec<String>,
}
#[derive(Debug, Serialize)]
pub struct RestoreOutput {
    pub environment: &'static str,
}
fn git(host: &mut impl Host, at: &Path, args: &[&str], required: bool) -> Result<Output, Error> {
    let mut argv = vec![
        "/usr/bin/git",
        "--no-optional-locks",
        "-C",
        at.to_str().ok_or(Error::Invalid {})?,
    ];
    argv.extend(args);
    recipe::run(host, &Invocation::new(&argv), required).map_err(|_| Error::RepositoryFailed {})
}
fn inspect_checkout(layout: &Layout, host: &mut impl Host) -> Result<String, Error> {
    if owned(&layout.current)?.is_some_and(|m| m.is_symlink()) {
        return Err(Error::Unsupported {});
    }
    for path in [
        &layout.current,
        &layout.current.join(".git"),
        &layout.current.join("apps"),
        &layout.current.join("apps/gateway"),
    ] {
        if !owned(path)?.is_some_and(|m| m.is_dir()) {
            return Err(Error::NotCheckout {});
        }
    }
    let sha = commit_at(&layout.current, "HEAD").ok_or(Error::NotCheckout {})?;
    let status = git(
        host,
        &layout.current,
        &[
            "status",
            "--porcelain=v1",
            "--untracked-files=no",
            "--ignore-submodules=none",
        ],
        false,
    )?;
    if status.code != 0 {
        return Err(Error::NotCheckout {});
    }
    let changes: Vec<_> = status
        .stdout
        .lines()
        .filter(|s| !s.trim().is_empty())
        .collect();
    if let Some(first) = changes.first() {
        return Err(Error::Changes {
            count: changes.len(),
            path: first.get(3..).unwrap_or("").trim().into(),
        });
    }
    Ok(sha)
}
fn check(input: &StorageInput, host: &mut impl Host) -> Result<HeadOutput, Error> {
    Ok(HeadOutput {
        sha: inspect_checkout(&Layout::new(&input.checkout)?, host)?,
    })
}
fn repository(input: &RepositoryInput, host: &mut impl Host) -> Result<RepositoryOutput, Error> {
    if !hex(&input.sha, 40) {
        return Err(Error::Invalid {});
    }
    let layout = Layout::new(&input.checkout)?;
    if inspect_checkout(&layout, host)? != input.sha {
        return Err(Error::RepositoryConflict {});
    }
    let shared = layout.shared();
    if owned(&shared)?.is_some() {
        directory(&shared)?;
    }
    let repository = shared.join("orbit.git");
    if owned(&repository)?.is_some() {
        directory(&repository)?;
        let repo =
            git2::Repository::open_bare(&repository).map_err(|_| Error::RepositoryConflict {})?;
        if !repo.is_bare()
            || repo
                .find_commit(git2::Oid::from_str(&input.sha).unwrap())
                .is_err()
        {
            return Err(Error::RepositoryConflict {});
        }
        return Ok(RepositoryOutput {
            repository: "existing",
        });
    }
    let partial = shared.join("orbit.git.partial");
    if owned(&partial)?.is_some() {
        // Only a bare clone that this adoption started can be discarded and retried.
        directory(&partial)?;
        let clone =
            git2::Repository::open_bare(&partial).map_err(|_| Error::RepositoryConflict {})?;
        if !clone.is_bare() {
            return Err(Error::RepositoryConflict {});
        }
    }
    if !shared.exists() {
        fs::DirBuilder::new()
            .mode(0o750)
            .create(&shared)
            .map_err(|_| Error::RepositoryFailed {})?;
    }
    if partial.exists() {
        fs::remove_dir_all(&partial).map_err(|_| Error::RepositoryFailed {})?;
    }
    recipe::run(
        host,
        &Invocation::new(&[
            "/usr/bin/git",
            "clone",
            "--bare",
            "--quiet",
            "--",
            layout.current.to_str().unwrap(),
            partial.to_str().unwrap(),
        ]),
        true,
    )
    .map_err(|_| Error::RepositoryFailed {})?;
    let source = git2::Repository::open(&layout.current).map_err(|_| Error::RepositoryFailed {})?;
    let cloned = git2::Repository::open_bare(&partial).map_err(|_| Error::RepositoryFailed {})?;
    let origin = source
        .find_remote("origin")
        .ok()
        .and_then(|r| r.url().map(str::to_owned));
    if let Some(origin) = origin.filter(|s| !s.is_empty()) {
        cloned
            .remote_set_url("origin", &origin)
            .map_err(|_| Error::RepositoryFailed {})?;
        git(
            host,
            &partial,
            &[
                "fetch",
                "--quiet",
                "--no-tags",
                "--",
                layout.current.to_str().unwrap(),
                "+refs/remotes/origin/*:refs/remotes/origin/*",
            ],
            true,
        )?;
    }
    cloned
        .reference(
            "refs/orbit/pre-adopt",
            git2::Oid::from_str(&input.sha).unwrap(),
            true,
            "release adoption",
        )
        .map_err(|_| Error::RepositoryFailed {})?;
    drop(cloned);
    fs::rename(&partial, &repository).map_err(|_| Error::RepositoryFailed {})?;
    Ok(RepositoryOutput {
        repository: "created",
    })
}
pub(crate) fn commit_at(path: &Path, revision: &str) -> Option<String> {
    git2::Repository::open(path)
        .ok()?
        .revparse_single(revision)
        .ok()?
        .peel_to_commit()
        .ok()
        .map(|c| c.id().to_string())
}
fn origin(input: &StorageInput) -> Result<OriginOutput, Error> {
    let layout = Layout::new(&input.checkout)?;
    Ok(OriginOutput {
        sha: commit_at(&layout.shared().join("orbit.git"), "refs/orbit/pre-adopt"),
    })
}
fn target(input: &TargetInput, host: &mut impl Host) -> Result<HeadOutput, Error> {
    if !(7..=40).contains(&input.target.len()) || !hex(&input.target, input.target.len()) {
        return Err(Error::Invalid {});
    }
    let layout = Layout::new(&input.checkout)?;
    let repository = layout.shared().join("orbit.git");
    directory(&layout.shared())?;
    directory(&repository)?;
    if let Some(source) = &input.source {
        let source = Path::new(source);
        if !source.is_absolute()
            || source
                .components()
                .any(|c| matches!(c, std::path::Component::ParentDir))
            || source
                .to_string_lossy()
                .bytes()
                .any(|b| b.is_ascii_control())
        {
            return Err(Error::Invalid {});
        }
        if source.is_dir()
            && commit_at(source, "HEAD").is_some_and(|sha| sha.starts_with(&input.target))
        {
            git(
                host,
                &repository,
                &[
                    "fetch",
                    "--quiet",
                    "--no-tags",
                    "--",
                    source.to_str().unwrap(),
                    "+HEAD:refs/orbit/adopt-target",
                ],
                true,
            )?;
        }
    }
    Ok(HeadOutput {
        sha: commit_at(&repository, &input.target)
            .filter(|sha| sha.starts_with(&input.target))
            .unwrap_or_else(|| input.target.clone()),
    })
}

fn regular(path: &Path) -> Result<Option<Vec<u8>>, Error> {
    match owned(path)? {
        None => Ok(None),
        Some(m) if m.is_file() => fs::read(path).map(Some).map_err(|_| Error::EnvFailed {}),
        _ => Err(Error::EnvConflict {}),
    }
}
fn private(path: &Path, bytes: &[u8]) -> Result<(), Error> {
    let next = path.with_file_name(format!(
        ".{}.{}",
        path.file_name().unwrap().to_string_lossy(),
        rand::random::<u64>()
    ));
    let result = (|| {
        let mut file = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(&next)?;
        file.write_all(bytes)?;
        fs::rename(&next, path)
    })();
    if result.is_err() {
        let _ = fs::remove_file(&next);
    }
    result.map_err(|_| Error::EnvFailed {})
}
fn environment(input: &StorageInput) -> Result<EnvironmentOutput, Error> {
    let layout = Layout::new(&input.checkout)?;
    directory(&layout.current)?;
    directory(&layout.current.join("apps"))?;
    let app = layout.current.join("apps/gateway");
    directory(&app)?;
    directory(&layout.shared())?;
    let source = app.join(".env");
    let saved = app.join(".env.pre-adopt");
    let target = layout.shared().join("gateway.env");
    let linked = owned(&source)?.is_some_and(|m| m.is_symlink());
    let original = if linked {
        if fs::read_link(&source).map_err(|_| Error::EnvConflict {})? != target {
            return Err(Error::EnvConflict {});
        }
        regular(&saved)?.ok_or(Error::EnvConflict {})?
    } else {
        regular(&source)?.ok_or(Error::EnvMissing {})?
    };
    // Keep bytes outside APP_VERSION unchanged, including non-UTF8 secret values.
    let version = regex::bytes::Regex::new(r"^\s*(export\s+)?APP_VERSION\s*=\s*\S").unwrap();
    let mut contents = vec![];
    let mut removed = false;
    for line in original.split_inclusive(|b| *b == b'\n') {
        if version.is_match(line) {
            contents.extend_from_slice(b"# ");
            contents.extend_from_slice(line.strip_suffix(b"\n").unwrap_or(line));
            contents.extend_from_slice(
                b" (left out by gateway:release:adopt: each release reports its REVISION)\n",
            );
            removed = true;
        } else {
            contents.extend_from_slice(line);
        }
    }
    let prior = regular(&target)?;
    if prior.as_ref().is_some_and(|b| b != &contents) {
        return Err(Error::EnvConflict {});
    }
    let kept = regular(&saved)?;
    if kept.as_ref().is_some_and(|b| b != &original) {
        return Err(Error::EnvConflict {});
    }
    let next = app.join(".env.adopt-link");
    if owned(&next)?.is_some() && !super::link(&next, &target)? {
        return Err(Error::EnvConflict {});
    }
    let backups = layout.shared().join("env-backups");
    if owned(&backups)?.is_some() {
        directory(&backups)?;
    }
    let mut copies: Vec<(PathBuf, Vec<u8>, bool)> = vec![];
    for entry in fs::read_dir(&app).map_err(|_| Error::EnvFailed {})? {
        let entry = entry.map_err(|_| Error::EnvFailed {})?;
        if !entry.file_name().to_string_lossy().starts_with(".env.bak")
            || !owned(&entry.path())?.is_some_and(|m| m.is_file())
        {
            continue;
        }
        let bytes = regular(&entry.path())?.ok_or(Error::EnvFailed {})?;
        let copy = backups.join(entry.file_name());
        let existing = regular(&copy)?;
        if existing.as_ref().is_some_and(|b| b != &bytes) {
            return Err(Error::EnvConflict {});
        }
        copies.push((copy, bytes, existing.is_some()));
    }
    copies.sort_by(|a, b| a.0.cmp(&b.0));
    if prior.is_none() {
        private(&target, &contents)?;
    }
    if !copies.is_empty() && !backups.exists() {
        fs::DirBuilder::new()
            .mode(0o700)
            .create(&backups)
            .map_err(|_| Error::EnvFailed {})?;
    }
    for (copy, bytes, exists) in &copies {
        if !exists {
            private(copy, bytes)?;
        }
    }
    if !linked {
        if kept.is_none() {
            private(&saved, &original)?;
        }
        if owned(&next)?.is_none() {
            std::os::unix::fs::symlink(&target, &next).map_err(|_| Error::EnvFailed {})?;
        }
        fs::rename(&next, &source).map_err(|_| Error::EnvFailed {})?;
    }
    Ok(EnvironmentOutput {
        env: if prior.is_some() {
            "existing"
        } else {
            "created"
        },
        env_link: if linked { "existing" } else { "linked" },
        app_version_removed: removed,
        env_backups: copies
            .into_iter()
            .map(|(p, _, _)| p.to_string_lossy().into())
            .collect(),
    })
}
fn restore_environment(input: &StorageInput) -> Result<RestoreOutput, Error> {
    let layout = Layout::new(&input.checkout)?;
    let app = layout.current.join("apps/gateway");
    let source = app.join(".env");
    let saved = app.join(".env.pre-adopt");
    // Nothing to restore until adoption has linked the environment. A refusal before that is a no-op.
    if !owned(&layout.current)?.is_some_and(|m| m.is_dir()) {
        return Ok(RestoreOutput {
            environment: "unchanged",
        });
    }
    for path in [layout.current.join("apps"), app] {
        if owned(&path)?.is_none() {
            return Ok(RestoreOutput {
                environment: "unchanged",
            });
        }
        directory(&path)?;
    }
    if !owned(&source)?.is_some_and(|m| m.is_symlink())
        || !owned(&saved)?.is_some_and(|m| m.is_file())
    {
        return Ok(RestoreOutput {
            environment: "unchanged",
        });
    }
    if fs::read_link(&source).map_err(|_| Error::EnvConflict {})?
        != layout.shared().join("gateway.env")
    {
        return Err(Error::EnvConflict {});
    }
    Ok(RestoreOutput {
        environment: if fs::rename(&saved, &source).is_ok() {
            "restored"
        } else {
            "link_kept"
        },
    })
}
macro_rules! command {
    ($name:ident, $command:literal, $input:ty, $output:ty, $mutation:literal, $seconds:literal, $run:expr) => {
        pub struct $name;
        impl Command for $name {
            const NAME: &'static str = $command;
            const SUMMARY: &'static str =
                "Prepare or inspect a Gateway release adoption repository and private environment.";
            const MUTATES: bool = $mutation;
            const TIMEOUT: Duration = Duration::from_secs($seconds);
            type Input = $input;
            type Output = $output;
            type Error = Error;
            fn privilege(platform: Platform) -> Option<Privilege> {
                crate::commands::linux_only(platform, Privilege::Account)
            }
            fn target(input: &Self::Input) -> Option<String> {
                Self::MUTATES.then(|| format!("gateway-release:{}", input.checkout))
            }
            fn run(input: Self::Input, context: &Context) -> Result<Self::Output, Error> {
                ($run)(input, context)
            }
        }
    };
}
command!(
    Check,
    "gateway.check-release-adoption",
    StorageInput,
    HeadOutput,
    false,
    60,
    |input: StorageInput, context: &Context| check(&input, &mut Native(context))
);
command!(
    Repository,
    "gateway.adopt-release-repository",
    RepositoryInput,
    RepositoryOutput,
    true,
    600,
    |input: RepositoryInput, context: &Context| repository(&input, &mut Native(context))
);
command!(
    Origin,
    "gateway.inspect-adoption-origin",
    StorageInput,
    OriginOutput,
    false,
    60,
    |input: StorageInput, _: &Context| origin(&input)
);
command!(
    Target,
    "gateway.resolve-adoption-target",
    TargetInput,
    HeadOutput,
    true,
    600,
    |input: TargetInput, context: &Context| target(&input, &mut Native(context))
);
command!(
    Environment,
    "gateway.adopt-release-environment",
    StorageInput,
    EnvironmentOutput,
    true,
    60,
    |input: StorageInput, _: &Context| environment(&input)
);
command!(
    RestoreEnvironment,
    "gateway.restore-adoption-environment",
    StorageInput,
    RestoreOutput,
    true,
    60,
    |input: StorageInput, _: &Context| restore_environment(&input)
);
#[cfg(test)]
mod tests;
