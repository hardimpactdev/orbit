//! The production home's `current` selection, its release layout and its directory URL.

use super::laravel::{entries, plain, put};
use super::production::{
    account, as_user, as_user_with_input, check, failed, invalid, none, relative_path, Layout,
    ReleaseError,
};
use super::source::{exists, is_symlink, realpath};
use crate::command::{Command, Context, Platform, Privilege};
use base64::Engine;
use serde::{Deserialize, Serialize};
use std::{
    fs,
    os::unix::fs::MetadataExt,
    path::{Path, PathBuf},
    time::Duration,
};

// ---- the Composer profile ------------------------------------------------------------------------------------------

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
#[serde(rename_all = "lowercase")]
pub enum ProfileKind {
    /// No `composer.json` and no entry point.
    None,
    /// An entry point without `composer.json`.
    Partial,
    /// A path that escapes the checkout, a file that is not the user's, or a link where a file belongs.
    Unsafe,
    Composer,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Profile {
    pub kind: ProfileKind,
    /// `absent`, `regular`, or `unsafe`, with `composer`.
    pub entry_point: Option<String>,
    /// The base64 of `composer.json`, with `composer`.
    pub composer: Option<String>,
}

fn unsafe_profile() -> Profile {
    Profile {
        kind: ProfileKind::Unsafe,
        entry_point: None,
        composer: None,
    }
}

/// The Composer metadata of an application directory, when only the user owns `composer.json` and neither it nor
/// the entry point is a link.
pub(super) fn composer_profile(
    application: &Path,
    entry_point: &str,
    uid: u32,
) -> Result<Profile, ReleaseError> {
    let composer = application.join("composer.json");
    let entry_point = application.join(entry_point);
    if is_symlink(&composer) || (exists(&composer) && !composer.is_file()) {
        return Ok(unsafe_profile());
    }
    if !exists(&composer) {
        return Ok(Profile {
            kind: if exists(&entry_point) {
                ProfileKind::Partial
            } else {
                ProfileKind::None
            },
            entry_point: None,
            composer: None,
        });
    }
    if fs::metadata(&composer).is_ok_and(|metadata| metadata.uid() != uid) {
        return Ok(unsafe_profile());
    }
    let kind = if is_symlink(&entry_point) {
        "unsafe"
    } else if entry_point.is_file() {
        "regular"
    } else if exists(&entry_point) {
        "unsafe"
    } else {
        "absent"
    };
    let content = fs::read(&composer).map_err(|_| invalid("composer.json is readable"))?;
    Ok(Profile {
        kind: ProfileKind::Composer,
        entry_point: Some(kind.into()),
        composer: Some(base64::engine::general_purpose::STANDARD.encode(content)),
    })
}

// ---- the APP_URL of a served directory ---------------------------------------------------------------------------

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct DirectoryUrlInput {
    pub user: String,
    pub home: PathBuf,
    /// The application directory relative to a release, or empty for the release itself.
    pub directory: String,
    pub url: String,
    /// The `APP_KEY=` line a new file without a key takes.
    pub app_key: String,
    /// The `APP_NAME=` line a new file takes.
    pub app_name: String,
    /// Whether the name is the Instance's stored one, which replaces any template name.
    pub app_name_stored: bool,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Written {
    pub written: bool,
}

/// Writes `APP_URL` into the stable `.env` of another application directory, `<home>/env/<directory>/.env`, which
/// each release links to. It reads `artisan` and `.env.example` from the selected release, and does nothing before
/// the first release or for a directory without `artisan`. A new file gets its own key and name.
pub fn write_directory_url(
    input: &DirectoryUrlInput,
    layout: &Layout,
) -> Result<Written, ReleaseError> {
    let (user, home) = (input.user.as_str(), input.home.as_path());
    let (uid, _) = account(user)?;
    let unchanged = Ok(Written { written: false });
    check(
        home == layout.home_root.join(user),
        "the home is the user's",
    )?;
    check(
        realpath(home).as_deref() == Some(home),
        "the home is canonical",
    )?;
    check(
        input.directory.is_empty() || relative_path(&input.directory),
        "the directory is relative",
    )?;
    let current = home.join("current");
    if !is_symlink(&current) {
        return unchanged;
    }
    let release = realpath(&current).ok_or_else(|| invalid("current names a release"))?;
    check(
        release.parent() == Some(home.join("releases").as_path()),
        "current names a release",
    )?;
    let application = if input.directory.is_empty() {
        release.clone()
    } else {
        release.join(&input.directory)
    };
    check(
        realpath(&application).as_deref() == Some(application.as_path()),
        "the application directory is canonical",
    )?;
    let artisan = application.join("artisan");
    if is_symlink(&artisan) || !artisan.is_file() {
        return unchanged;
    }

    let mut stable = home.join("env");
    let parts: Vec<&str> = if input.directory.is_empty() {
        Vec::new()
    } else {
        input.directory.split('/').collect()
    };
    for (index, part) in std::iter::once("").chain(parts).enumerate() {
        if index > 0 {
            stable.push(part);
        }
        check(
            !is_symlink(&stable) && (!exists(&stable) || stable.is_dir()),
            "the stable environment directory is a real directory",
        )?;
        if !exists(&stable) {
            as_user(
                user,
                "mkdir",
                &[
                    "-m".as_ref(),
                    "0700".as_ref(),
                    "--".as_ref(),
                    stable.as_os_str(),
                ],
                &none(),
            )
            .ok_or_else(|| failed("create the stable environment directory"))?;
        }
        check(
            fs::symlink_metadata(&stable).is_ok_and(|metadata| metadata.uid() == uid),
            "the user owns the stable environment directory",
        )?;
    }
    let environment = stable.join(".env");
    check(
        !is_symlink(&environment) && (!exists(&environment) || environment.is_file()),
        "the stable environment is a regular file",
    )?;
    check(
        fs::symlink_metadata(&environment).map_or(true, |metadata| metadata.uid() == uid),
        "the user owns the stable environment",
    )?;
    let created = !exists(&environment);
    let (original, mode) = if created {
        let template = application.join(".env.example");
        check(
            !is_symlink(&template) && (!exists(&template) || template.is_file()),
            "the environment template is a regular file",
        )?;
        (fs::read(&template).unwrap_or_default(), 0o600)
    } else {
        let metadata = fs::metadata(&environment)
            .map_err(|_| invalid("the stable environment is readable"))?;
        (
            fs::read(&environment).map_err(|_| invalid("the stable environment is readable"))?,
            metadata.mode() & 0o770,
        )
    };
    let found = entries(&original, "APP_URL");
    check(found.len() <= 1, "the environment names APP_URL once")?;
    let mut updated = put(
        &original,
        &found,
        format!("APP_URL={}", input.url).as_bytes(),
    );
    // Laravel cannot boot with an empty key. A new file keeps the template's key or takes the given one; an existing
    // file only fills an empty key.
    let found = entries(&updated, "APP_KEY");
    if found.len() < 2
        && (created || !found.is_empty())
        && plain(&updated, &found, "APP_KEY").is_empty()
    {
        updated = put(&updated, &found, input.app_key.as_bytes());
    }
    let found = entries(&updated, "APP_NAME");
    let name = plain(&updated, &found, "APP_NAME");
    if created
        && found.len() < 2
        && (input.app_name_stored || name.is_empty() || name == b"Laravel")
    {
        updated = put(&updated, &found, input.app_name.as_bytes());
    }
    if updated != original || created {
        let candidate = stable.join(format!(".orbit-url-{:016x}", rand::random::<u64>()));
        let mode = format!("{mode:o}");
        let written = as_user_with_input(
            user,
            "sh",
            &[
                "-c".as_ref(),
                "umask 077 && cat > \"$1\" && chmod \"$2\" \"$1\" && mv -fT -- \"$1\" \"$3\""
                    .as_ref(),
                "sh".as_ref(),
                candidate.as_os_str(),
                mode.as_ref(),
                environment.as_os_str(),
            ],
            &updated,
        );
        if !written {
            let _ = fs::remove_file(&candidate);
            return Err(failed("write the stable environment"));
        }
        return Ok(Written { written: true });
    }
    if fs::metadata(&environment).is_ok_and(|metadata| metadata.mode() & 0o007 != 0) {
        as_user(
            user,
            "chmod",
            &[
                format!("{mode:o}").as_ref(),
                "--".as_ref(),
                environment.as_os_str(),
            ],
            &none(),
        )
        .ok_or_else(|| failed("restrict the stable environment"))?;
    }
    unchanged
}

pub struct WriteDirectoryUrl;

impl Command for WriteDirectoryUrl {
    const NAME: &'static str = "instance.write-directory-url";
    const SUMMARY: &'static str =
        "Write APP_URL into the stable .env of a production Instance's served application directory.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = DirectoryUrlInput;
    type Output = Written;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn target(input: &DirectoryUrlInput) -> Option<String> {
        Some(format!("production-environment:{}", input.user))
    }

    fn run(input: DirectoryUrlInput, _: &Context) -> Result<Written, ReleaseError> {
        write_directory_url(&input, &Layout::system())
    }
}

#[cfg(test)]
#[path = "production_home_tests.rs"]
mod tests;
