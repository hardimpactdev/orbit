//! `account.lookup`: a local account's name, primary group, home, and shell, as `getent passwd` and `id -gn` print
//! them.

use crate::command::{identity, Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::time::Duration;

command_errors! {
    pub enum AccountError {
        Missing {} => "account.missing", "The Node has no such account.";
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct LookupInput {
    pub user: String,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Account {
    pub user: String,
    pub uid: u32,
    pub group: String,
    pub home: String,
    pub shell: String,
}

pub fn lookup(input: &LookupInput) -> Result<Account, AccountError> {
    let entry = identity::passwd(&input.user).ok_or(AccountError::Missing {})?;
    let group = identity::group_name(entry.gid).ok_or(AccountError::Missing {})?;
    Ok(Account {
        user: input.user.clone(),
        uid: entry.uid,
        group,
        home: entry.home,
        shell: entry.shell,
    })
}

pub struct LookupAccount;

impl Command for LookupAccount {
    const NAME: &'static str = "account.lookup";
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
