//! `instances.*-development-*` for a Laravel application in a development checkout: its Composer metadata, and its
//! `APP_URL` in `.env` and in a cached configuration. These run as the managed account, which owns the checkout, and
//! replace the Bash and Python of `RemoteDevelopmentInstanceConfigurator`, rule for rule. The `.env` line rules are
//! shared with the production writer.

use super::production::ReleaseError;
use super::production_home::{composer_profile, Profile};
use super::source::{exists, is_symlink, realpath};
use crate::command::{identity, Command, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use std::{
    fs,
    io::Write,
    os::unix::fs::{MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    time::Duration,
};

/// The `KEY=` lines of an environment file.
pub(super) fn entries(text: &[u8], key: &str) -> Vec<std::ops::Range<usize>> {
    regex::bytes::Regex::new(&format!(r"(?m)^{key}=[^\r\n]*"))
        .map(|pattern| pattern.find_iter(text).map(|found| found.range()).collect())
        .unwrap_or_default()
}

/// The value of the first line, without its quotes.
pub(super) fn plain<'a>(text: &'a [u8], found: &[std::ops::Range<usize>], key: &str) -> &'a [u8] {
    let Some(range) = found.first() else {
        return b"";
    };
    let value = text[range.start + key.len() + 1..range.end].trim_ascii();
    if value.len() >= 2
        && value[0] == value[value.len() - 1]
        && (value[0] == b'"' || value[0] == b'\'')
    {
        &value[1..value.len() - 1]
    } else {
        value
    }
}

/// Replaces the first line, or appends one.
pub(super) fn put(text: &[u8], found: &[std::ops::Range<usize>], line: &[u8]) -> Vec<u8> {
    if let Some(range) = found.first() {
        return [&text[..range.start], line, &text[range.end..]].concat();
    }
    let mut updated = text.to_vec();
    if !updated.is_empty() && !updated.ends_with(b"\n") {
        updated.push(b'\n');
    }
    updated.extend_from_slice(line);
    updated.push(b'\n');
    updated
}

// ---- the Composer profile ------------------------------------------------------------------------------------------

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ProfileInput {
    pub application: PathBuf,
    /// The account that must own `composer.json`.
    pub owner: String,
    pub entry_point: String,
}

pub fn profile(input: &ProfileInput) -> Result<Profile, ReleaseError> {
    let (uid, _, _) = identity::account(&input.owner)
        .map_err(|_| super::production::invalid("the owner exists"))?;
    if input.application.is_dir()
        && realpath(&input.application).as_deref() != Some(input.application.as_path())
    {
        return Ok(Profile {
            kind: super::production_home::ProfileKind::Unsafe,
            entry_point: None,
            composer: None,
        });
    }
    composer_profile(&input.application, &input.entry_point, uid)
}

pub struct InspectProfile;

impl Command for InspectProfile {
    const NAME: &'static str = "instances.inspect-development-profile";
    const SUMMARY: &'static str =
        "Read a development checkout's Composer metadata, refusing a link or another owner.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = ProfileInput;
    type Output = Profile;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(input: ProfileInput, _: &Context) -> Result<Profile, ReleaseError> {
        profile(&input)
    }
}

// ---- the URL -----------------------------------------------------------------------------------------------------

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct UrlInput {
    pub directory: PathBuf,
    /// The account that must own `.env` and the cached configuration.
    pub owner: String,
    pub url: String,
    /// The `APP_KEY=` line a new file takes, or that fills an empty key.
    pub app_key: String,
    /// Whether the key is the Instance's stored one, which a new file takes over the template's.
    pub app_key_stored: bool,
    pub app_name: String,
    pub app_name_stored: bool,
    /// Another application directory without `artisan` is left alone.
    pub require_artisan: bool,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Written {
    pub written: bool,
}

fn refused(check: &str) -> ReleaseError {
    super::production::invalid(check)
}

/// A regular file of the owner, or nothing; a link or another kind refuses.
fn safe_regular(path: &Path, uid: u32) -> Result<(), ReleaseError> {
    if is_symlink(path) || (exists(path) && !path.is_file()) {
        return Err(refused("a Laravel file is a regular file"));
    }
    if fs::metadata(path).is_ok_and(|metadata| metadata.uid() != uid) {
        return Err(refused("the owner owns the Laravel file"));
    }
    Ok(())
}

/// Writes a candidate beside the file with `mode`, then renames it over the file.
fn atomic(path: &Path, value: &[u8], mode: u32) -> Result<(), ReleaseError> {
    let candidate = path.with_file_name(format!(".orbit-url-{:016x}", rand::random::<u64>()));
    let written = (|| {
        let mut file = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(&candidate)?;
        fs::set_permissions(&candidate, fs::Permissions::from_mode(mode))?;
        file.write_all(value)?;
        fs::rename(&candidate, path)
    })();
    if written.is_err() {
        let _ = fs::remove_file(&candidate);
    }
    written.map_err(|_| super::production::failed("write a Laravel file"))
}

/// The one `'url' => '...'` string of the `'app'` array in a cached configuration, read as literal-array structure
/// without evaluating the application's PHP.
fn cached_url(source: &[u8]) -> Option<std::ops::Range<usize>> {
    let pattern = regex::bytes::Regex::new(
        r#"(?s)'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|/\*.*?\*/|//[^\n]*|\#[^\n]*|=>|[()\[\],]|[A-Za-z_][A-Za-z0-9_]*|\S"#,
    )
    .ok()?;
    let tokens: Vec<regex::bytes::Match> = pattern
        .find_iter(source)
        .filter(|token| {
            !(token.as_bytes().starts_with(b"/*")
                || token.as_bytes().starts_with(b"//")
                || token.as_bytes().starts_with(b"#"))
        })
        .collect();
    let quoted = |value: &[u8]| {
        value
            .first()
            .is_some_and(|first| *first == b'\'' || *first == b'"')
    };
    // The key path of each open array, the key that waits for its value, and each `app.url` string.
    let mut stack: Vec<Vec<Vec<u8>>> = Vec::new();
    let mut pending: Option<Vec<u8>> = None;
    let mut matches: Vec<std::ops::Range<usize>> = Vec::new();
    for (index, token) in tokens.iter().enumerate() {
        let value = token.as_bytes();
        match value {
            b"(" | b"[" => {
                let mut path = stack.last().cloned().unwrap_or_default();
                path.extend(pending.take());
                stack.push(path);
            }
            b")" | b"]" => {
                stack.pop()?;
                pending = None;
            }
            b"," => pending = None,
            _ if quoted(value)
                && tokens
                    .get(index + 1)
                    .is_some_and(|next| next.as_bytes() == b"=>") =>
            {
                let key = value[1..value.len() - 1].to_vec();
                if stack.len() == 2
                    && stack
                        .last()
                        .is_some_and(|path| path.len() == 1 && path[0] == b"app")
                    && key == b"url"
                {
                    let candidate = tokens.get(index + 2)?;
                    if !quoted(candidate.as_bytes()) {
                        return None;
                    }
                    matches.push(candidate.range());
                }
                pending = Some(key);
            }
            _ => {}
        }
    }
    (stack.is_empty() && matches.len() == 1).then(|| matches.remove(0))
}

/// Writes `APP_URL` into the application's `.env`, from its `.env.example` when it has none, and into the `app.url`
/// of a cached configuration.
pub fn write_url(input: &UrlInput) -> Result<Written, ReleaseError> {
    let (uid, _, _) = identity::account(&input.owner).map_err(|_| refused("the owner exists"))?;
    let root = &input.directory;
    if realpath(root).as_deref() != Some(root.as_path()) {
        return Err(refused("the application directory is canonical"));
    }
    let artisan = root.join("artisan");
    if input.require_artisan && (is_symlink(&artisan) || !artisan.is_file()) {
        return Ok(Written { written: false });
    }
    let environment = root.join(".env");
    safe_regular(&environment, uid)?;
    let template = root.join(".env.example");
    let created = !exists(&environment);
    let (original, mode) = if created {
        safe_regular(&template, uid)?;
        match fs::metadata(&template) {
            Ok(metadata) => (
                fs::read(&template).map_err(|_| refused("the template is readable"))?,
                metadata.mode() & 0o777,
            ),
            Err(_) => (Vec::new(), 0o600),
        }
    } else {
        let metadata = fs::metadata(&environment).map_err(|_| refused("the .env is readable"))?;
        (
            fs::read(&environment).map_err(|_| refused("the .env is readable"))?,
            metadata.mode() & 0o777,
        )
    };
    let found = entries(&original, "APP_URL");
    if found.len() > 1 {
        return Err(refused("the .env names APP_URL once"));
    }
    let mut updated = put(
        &original,
        &found,
        format!("APP_URL={}", input.url).as_bytes(),
    );
    // Laravel cannot boot with an empty key. A new file takes the stored key, the template's own key, or the given
    // one; an existing file only fills an empty key. Duplicate key or name lines stay as they are.
    let found = entries(&updated, "APP_KEY");
    let empty = plain(&updated, &found, "APP_KEY").is_empty();
    if found.len() < 2
        && ((created && (empty || input.app_key_stored)) || (!found.is_empty() && empty))
    {
        updated = put(&updated, &found, input.app_key.as_bytes());
    }
    // A new file takes the stored name, or the Project name over the framework default.
    let found = entries(&updated, "APP_NAME");
    let name = plain(&updated, &found, "APP_NAME");
    if created
        && found.len() < 2
        && (input.app_name_stored || name.is_empty() || name == b"Laravel")
    {
        updated = put(&updated, &found, input.app_name.as_bytes());
    }
    // Validate every existing cache ancestor before publishing either file.
    for relative in ["bootstrap", "bootstrap/cache"] {
        let directory = root.join(relative);
        match fs::symlink_metadata(&directory) {
            Ok(metadata)
                if metadata.is_dir()
                    && realpath(&directory).as_deref() == Some(directory.as_path()) => {}
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => {}
            _ => return Err(refused("the cache directories are canonical directories")),
        }
    }
    let cache = root.join("bootstrap/cache/config.php");
    safe_regular(&cache, uid)?;
    let prepared_cache = if exists(&cache) {
        let original =
            fs::read(&cache).map_err(|_| refused("the cached configuration is readable"))?;
        let range = cached_url(&original)
            .ok_or_else(|| refused("the cached configuration names app.url once"))?;
        let escaped = input.url.replace('\\', "\\\\").replace('\'', "\\'");
        let updated = [
            &original[..range.start],
            format!("'{escaped}'").as_bytes(),
            &original[range.end..],
        ]
        .concat();
        if updated != original {
            let mode = fs::metadata(&cache)
                .map_err(|_| refused("the cached configuration is readable"))?
                .mode()
                & 0o777;
            Some((updated, mode))
        } else {
            None
        }
    } else {
        None
    };

    // Other local users, the Node agent included, never read an Instance's environment.
    let mode = mode & 0o770;
    let mut written = false;
    if updated != original || created {
        atomic(&environment, &updated, mode)?;
        written = true;
    } else if fs::metadata(&environment).is_ok_and(|metadata| metadata.mode() & 0o007 != 0) {
        fs::set_permissions(&environment, fs::Permissions::from_mode(mode))
            .map_err(|_| refused("the .env mode can be set"))?;
    }

    if let Some((updated, mode)) = prepared_cache {
        atomic(&cache, &updated, mode)?;
        written = true;
    }
    Ok(Written { written })
}

pub struct WriteUrl;

impl Command for WriteUrl {
    const NAME: &'static str = "instances.write-development-url";
    const SUMMARY: &'static str =
        "Write APP_URL into a development application's .env and cached configuration.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = UrlInput;
    type Output = Written;
    type Error = ReleaseError;

    /// Root holds the same `environment:` lock as `instances.write-environment`; the write itself runs as the owner.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn target(input: &UrlInput) -> Option<String> {
        Some(format!("environment:{}", input.directory.display()))
    }

    fn run(input: UrlInput, _: &Context) -> Result<Written, ReleaseError> {
        let owner = input.owner.clone();
        identity::on_account_thread(&owner, move || write_url(&input))
            .map_err(|_| refused("the owner identity can be taken"))?
    }
}

#[cfg(test)]
#[path = "laravel_tests.rs"]
mod tests;
