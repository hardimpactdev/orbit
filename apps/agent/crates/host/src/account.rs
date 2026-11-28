//! `account.read`: the canonical name, uid, primary group and home of one account, read only through the host's NSS. It runs
//! the host's `getent` with a fixed argv, so every source `nsswitch.conf` names answers in its order (files, systemd, sss,
//! ldap). It never answers a password or shadow data.
//!
//! `getent` exits 2 both for a user NSS does not hold and for a source that fails without saying so, such as an
//! `/etc/passwd` it cannot read: it writes nothing to standard error, and glibc's own lookups report the same. So absent
//! means "not resolvable now", and a flow must refuse on it, never remove or replace anything.

use serde::{Deserialize, Serialize};

use crate::{invalid, parse, strings, Failure, System, NAME};

const GETENT: &str = "/usr/bin/getent";
/// `getent`'s exit code when NSS answers no entry for the key: the only answer that makes an account absent.
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
        /// The name NSS answers, which may differ from the name asked for, such as a case-folded directory name.
        user: String,
        /// The user id.
        uid: u32,
        /// The primary group id.
        gid: u32,
        /// The primary group's name.
        group: String,
        /// The home folder, as NSS answers it, byte for byte.
        home: String,
    },
}

/// Reads one account by name: `getent -- passwd <user>`, then `getent -- group <gid>`. Changes nothing.
///
/// # Errors
///
/// `host.input_invalid` for an input other than `{"user": "<name>"}`, and `account.read_failed` for a lookup that fails, times
/// out or answers a line it cannot parse or that is not UTF-8, and for a primary group NSS cannot resolve: only `getent passwd`
/// exiting 2 answers absent.
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
    let (name, uid, gid, home) = passwd(&line).ok_or_else(|| failed("an unreadable passwd entry".to_owned()))?;
    let line = getent(system, "group", &gid.to_string()).map_err(failed)?.ok_or_else(|| failed(format!("no group {gid}")))?;
    let group = group(&line, gid).ok_or_else(|| failed("an unreadable group entry".to_owned()))?;
    Ok(Account::Present { user: name, uid, gid, group, home })
}

/// The argv that looks up one key: `--` comes first, so `getent` reads `database` and `key` as operands also under
/// `POSIXLY_CORRECT`, which ends option parsing at the first operand.
pub(crate) fn getent_argv(database: &str, key: &str) -> Vec<String> {
    strings(&[GETENT, "--", database, key])
}

/// The one line `getent -- <database> <key>` answers, or `None` when it exits 2. Output that is not UTF-8 fails rather than
/// change a byte.
fn getent(system: &impl System, database: &str, key: &str) -> Result<Option<String>, String> {
    let (code, out) = system.status(10, &getent_argv(database, key))?;
    match code {
        Some(0) => String::from_utf8(out).map(Some).map_err(|_| format!("getent {database} answered bytes that are not UTF-8")),
        Some(MISSING) => Ok(None),
        Some(code) => Err(format!("getent {database} exited {code}")),
        None => Err(format!("getent {database} was killed")),
    }
}

/// The canonical name, uid, gid and home of a passwd line: `name:password:uid:gid:gecos:home:shell`. The name is the one NSS
/// answers; a source may resolve the asked name to another, canonical one.
fn passwd(out: &str) -> Option<(String, u32, u32, String)> {
    let fields = only_line(out)?.split(':').collect::<Vec<_>>();
    let [name, _, uid, gid, _, home, _] = fields.as_slice() else { return None };
    (!name.is_empty()).then_some(())?;
    Some(((*name).to_owned(), uid.parse().ok()?, gid.parse().ok()?, (*home).to_owned()))
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
