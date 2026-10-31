//! `instance.*-development-*` for a Laravel application in a development checkout: its Composer metadata, and its
//! `APP_URL` in `.env` and in a cached configuration. These run as the managed account, which owns the checkout, and
//! replace the Bash and Python of `RemoteDevelopmentInstanceConfigurator`, rule for rule. The `.env` line rules are
//! shared with the production writer.

use super::production::ReleaseError;
use super::production_home::{composer_profile, Profile};
use super::source::realpath;
use crate::command::{identity, Command, Context, Platform, Privilege};
use serde::Deserialize;
use std::{path::PathBuf, time::Duration};

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
    const NAME: &'static str = "instance.inspect-development-profile";
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

#[cfg(test)]
#[path = "laravel_tests.rs"]
mod tests;
