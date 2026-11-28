//! `account.read`: the uid, primary group and home of one account, read only through the host's NSS. It runs the host's
//! `getent` with a fixed argv, so every source `nsswitch.conf` names answers in its order (files, systemd, sss, ldap). It never
//! answers a password or shadow data.

use serde::{Deserialize, Serialize};

use crate::{invalid, parse, strings, Failure, System, NAME};

const GETENT: &str = "/usr/bin/getent";
/// `getent`'s exit code when the database holds no such key: the only answer that makes an account absent.
const MISSING: i32 = 2;

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
        /// The home folder, as NSS answers it.
        home: String,
    },
}

/// Reads one account by name: `getent passwd -- <user>`, then `getent group -- <gid>`. Changes nothing.
///
/// # Errors
///
/// `host.input_invalid` for an input other than `{"user": "<name>"}`, and `account.read_failed` for a lookup that fails, times
/// out or answers a line it cannot parse, and for a primary group NSS cannot resolve: only `getent passwd` answering that the
/// user does not exist answers absent.
pub fn account_read(system: &impl System, input: &serde_json::Value) -> Result<Account, Failure> {
    if !input.is_object() {
        return Err(invalid("input"));
    }
    let input: Input = parse(input)?;
    if !NAME.is_match(&input.user) {
        return Err(invalid("user"));
    }
    let user = &input.user;
    let failed = |detail: String| Failure::new("account.read_failed", format!("{user}: {detail}"));
    let Some(line) = getent(system, "passwd", user).map_err(failed)? else {
        return Ok(Account::Absent);
    };
    let (uid, gid, home) = passwd(&line, user).ok_or_else(|| failed("an unreadable passwd entry".to_owned()))?;
    let line = getent(system, "group", &gid.to_string()).map_err(failed)?.ok_or_else(|| failed(format!("no group {gid}")))?;
    let group = group(&line, gid).ok_or_else(|| failed("an unreadable group entry".to_owned()))?;
    Ok(Account::Present { uid, gid, group, home })
}

/// The one line `getent <database> -- <key>` answers, or `None` when the database holds no such key.
fn getent(system: &impl System, database: &str, key: &str) -> Result<Option<String>, String> {
    let (code, out) = system.status(10, &strings(&[GETENT, database, "--", key]))?;
    match code {
        Some(0) => Ok(Some(out)),
        Some(MISSING) => Ok(None),
        Some(code) => Err(format!("getent {database} exited {code}")),
        None => Err(format!("getent {database} was killed")),
    }
}

/// The uid, gid and home of a passwd line that names `user`: `name:password:uid:gid:gecos:home:shell`.
fn passwd(out: &str, user: &str) -> Option<(u32, u32, String)> {
    let fields = only_line(out)?.split(':').collect::<Vec<_>>();
    let [name, _, uid, gid, _, home, _] = fields.as_slice() else { return None };
    (*name == user).then_some(())?;
    Some((uid.parse().ok()?, gid.parse().ok()?, (*home).to_owned()))
}

/// The name of a group line for `gid`: `name:password:gid:members`.
fn group(out: &str, gid: u32) -> Option<String> {
    let fields = only_line(out)?.split(':').collect::<Vec<_>>();
    let [name, _, id, _] = fields.as_slice() else { return None };
    (!name.is_empty() && id.parse::<u32>().ok()? == gid).then(|| (*name).to_owned())
}

/// The output's single line, without its line break.
fn only_line(out: &str) -> Option<&str> {
    let line = out.strip_suffix('\n').unwrap_or(out);
    (!line.is_empty() && !line.contains('\n')).then_some(line)
}
