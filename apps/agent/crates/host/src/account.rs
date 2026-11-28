//! `account.read`: the uid, primary group and home of one account, read only through NSS. It never answers a password or
//! shadow data, and runs no command.

use serde::{Deserialize, Serialize};

use crate::{invalid, parse, Failure, System, NAME};

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    user: String,
}

/// What the account database holds for one user name.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(tag = "state", rename_all = "snake_case")]
#[non_exhaustive]
pub enum Account {
    /// NSS has no such user.
    Absent,
    /// The user exists.
    Present {
        /// The user id.
        uid: u32,
        /// The primary group id.
        gid: u32,
        /// The primary group's name.
        group: String,
        /// The home folder, as the account database holds it.
        home: String,
    },
}

/// Reads one account by name. Changes nothing.
///
/// # Errors
///
/// `host.input_invalid` for an input outside the admitted shape, and `account.read_failed` for a lookup that fails or a
/// primary group NSS cannot name: only a user NSS answers does not exist answers absent.
pub fn account_read(system: &impl System, input: &serde_json::Value) -> Result<Account, Failure> {
    let input: Input = parse(input)?;
    if !NAME.is_match(&input.user) {
        return Err(invalid("user"));
    }
    let user = &input.user;
    let failed = |detail: String| Failure::new("account.read_failed", format!("{user}: {detail}"));
    let Some(entry) = system.account(user).map_err(failed)? else {
        return Ok(Account::Absent);
    };
    let group = system.group_name(entry.gid).map_err(failed)?.ok_or_else(|| failed(format!("no group {}", entry.gid)))?;
    Ok(Account::Present { uid: entry.uid, gid: entry.gid, group, home: entry.home })
}
