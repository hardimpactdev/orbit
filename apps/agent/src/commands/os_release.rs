//! `node.os-release`: the `ID` and `VERSION_CODENAME` of `/etc/os-release`. Each must appear once, with an optional
//! matching pair of quotes, as letters, digits, `.`, `_`, and `-`. The Gateway decides which releases it supports.

use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::time::Duration;

const OS_RELEASE: &str = "/etc/os-release";

command_errors! {
    pub enum OsReleaseError {
        Unreadable {} => "node.os_release_unreadable", "/etc/os-release is missing or malformed.";
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct OsReleaseInput {}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct OsRelease {
    pub id: String,
    pub codename: String,
}

fn value(raw: &str) -> Option<&str> {
    let unquoted = match raw.as_bytes().first() {
        Some(quote @ (b'"' | b'\'')) => raw
            .strip_prefix(*quote as char)?
            .strip_suffix(*quote as char)?,
        _ => raw,
    };
    (!unquoted.is_empty()
        && unquoted
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'.' | b'_' | b'-')))
    .then_some(unquoted)
}

pub fn parse(text: &str) -> Result<OsRelease, OsReleaseError> {
    let (mut id, mut codename) = (None, None);
    for line in text.lines() {
        let slot = if let Some(raw) = line.strip_prefix("ID=") {
            (&mut id, raw)
        } else if let Some(raw) = line.strip_prefix("VERSION_CODENAME=") {
            (&mut codename, raw)
        } else {
            continue;
        };
        if slot.0.is_some() {
            return Err(OsReleaseError::Unreadable {});
        }
        *slot.0 = Some(
            value(slot.1)
                .ok_or(OsReleaseError::Unreadable {})?
                .to_owned(),
        );
    }
    match (id, codename) {
        (Some(id), Some(codename)) => Ok(OsRelease { id, codename }),
        _ => Err(OsReleaseError::Unreadable {}),
    }
}

pub struct ReadOsRelease;

impl Command for ReadOsRelease {
    const NAME: &'static str = "node.os-release";
    const SUMMARY: &'static str = "Read the operating system ID and codename of the Node.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(10);
    type Input = OsReleaseInput;
    type Output = OsRelease;
    type Error = OsReleaseError;

    /// Every account may read `/etc/os-release`.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(_: OsReleaseInput, _: &Context) -> Result<OsRelease, OsReleaseError> {
        parse(&std::fs::read_to_string(OS_RELEASE).map_err(|_| OsReleaseError::Unreadable {})?)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_the_id_and_codename_once_each() {
        assert_eq!(
            parse("# comment\nNAME=\"Ubuntu\"\nID=ubuntu\nVERSION_CODENAME='resolute'\n"),
            Ok(OsRelease {
                id: "ubuntu".into(),
                codename: "resolute".into()
            })
        );
        for malformed in [
            "ID=ubuntu\n",
            "ID=ubuntu\nID=debian\nVERSION_CODENAME=x\n",
            "ID=\"ubuntu\nVERSION_CODENAME=x\n",
            "ID=ubu ntu\nVERSION_CODENAME=x\n",
            "ID=\nVERSION_CODENAME=x\n",
        ] {
            assert!(parse(malformed).is_err(), "{malformed}");
        }
    }
}
