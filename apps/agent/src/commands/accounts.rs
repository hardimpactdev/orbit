//! `accounts.lookup`: a local account's name, primary group, home, and shell, as `getent passwd` and `id -gn` print
//! them.

use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{ffi::CStr, time::Duration};

command_errors! {
    pub enum AccountError {
        Missing {} => "accounts.missing", "The Node has no such account.";
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct LookupInput {
    pub user: String,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Account {
    pub user: String,
    pub uid: u32,
    pub group: String,
    pub home: String,
    pub shell: String,
}

fn text(pointer: *const libc::c_char) -> String {
    if pointer.is_null() {
        return String::new();
    }
    // SAFETY: a non-null field of a passwd or group entry is a NUL-terminated string.
    unsafe { CStr::from_ptr(pointer) }
        .to_string_lossy()
        .into_owned()
}

pub fn lookup(input: &LookupInput) -> Result<Account, AccountError> {
    let name = std::ffi::CString::new(input.user.as_str()).map_err(|_| AccountError::Missing {})?;
    // SAFETY: getpwnam reads a NUL-terminated name and returns null or an entry valid until the next call.
    let entry = unsafe { libc::getpwnam(name.as_ptr()) };
    if entry.is_null() {
        return Err(AccountError::Missing {});
    }
    // SAFETY: a non-null result points at a passwd entry, read before any other lookup.
    let (uid, gid, home, shell) = unsafe {
        (
            (*entry).pw_uid,
            (*entry).pw_gid,
            text((*entry).pw_dir),
            text((*entry).pw_shell),
        )
    };
    // SAFETY: getgrgid returns null or a group entry valid until the next call.
    let group = unsafe { libc::getgrgid(gid) };
    if group.is_null() {
        return Err(AccountError::Missing {});
    }
    // SAFETY: a non-null result points at a group entry.
    let group = text(unsafe { (*group).gr_name });
    Ok(Account {
        user: input.user.clone(),
        uid,
        group,
        home,
        shell,
    })
}

pub struct LookupAccount;

impl Command for LookupAccount {
    const NAME: &'static str = "accounts.lookup";
    const SUMMARY: &'static str = "Read a local account's primary group, home, and shell.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(10);
    type Input = LookupInput;
    type Output = Account;
    type Error = AccountError;

    /// Every account may read the account database.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(input: LookupInput, _: &Context) -> Result<Account, AccountError> {
        lookup(&input)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn root_resolves_and_a_missing_account_refuses() {
        let root = lookup(&LookupInput {
            user: "root".into(),
        })
        .unwrap();
        assert_eq!((root.uid, root.group.as_str()), (0, "root"));
        assert_eq!(
            lookup(&LookupInput {
                user: "orbit-no-such-user".into()
            }),
            Err(AccountError::Missing {})
        );
    }
}
