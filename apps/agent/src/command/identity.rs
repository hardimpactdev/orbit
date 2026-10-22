//! The identity a command runs as. Each command declares one privilege per platform; the command checks
//! its effective identity before any change.

use serde::Serialize;

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
#[serde(rename_all = "lowercase")]
pub enum Platform {
    Linux,
    Macos,
}
impl Platform {
    pub fn current() -> Self {
        if cfg!(target_os = "macos") {
            Self::Macos
        } else {
            Self::Linux
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
#[serde(rename_all = "lowercase")]
pub enum Privilege {
    /// The managed account on Linux, the enrolled account on macOS.
    Account,
    /// Starts as root on Linux (`sudo -n`) and drops to the recorded owner before project code.
    Owner,
    /// Root through `sudo -n` on Linux.
    Root,
}
impl Privilege {
    /// Whether the runner starts the command through `sudo -n` on this platform.
    pub fn needs_sudo(self, platform: Platform) -> bool {
        platform == Platform::Linux && matches!(self, Self::Owner | Self::Root)
    }

    /// Whether an effective user ID fits this privilege on this platform.
    pub fn accepts(self, platform: Platform, euid: u32) -> bool {
        match (platform, self) {
            (Platform::Linux, Self::Owner | Self::Root) => euid == 0,
            (_, Self::Account | Self::Owner) => euid != 0,
            (Platform::Macos, Self::Root) => false,
        }
    }
}

pub fn effective_uid() -> u32 {
    rustix::process::geteuid().as_raw()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn linux_root_and_owner_commands_need_root_and_account_commands_refuse_it() {
        assert!(Privilege::Root.accepts(Platform::Linux, 0));
        assert!(Privilege::Owner.accepts(Platform::Linux, 0));
        assert!(!Privilege::Root.accepts(Platform::Linux, 1000));
        assert!(!Privilege::Owner.accepts(Platform::Linux, 1000));
        assert!(Privilege::Account.accepts(Platform::Linux, 1000));
        assert!(!Privilege::Account.accepts(Platform::Linux, 0));
    }

    #[test]
    fn macos_runs_as_the_enrolled_account_and_never_as_root() {
        assert!(Privilege::Account.accepts(Platform::Macos, 501));
        assert!(Privilege::Owner.accepts(Platform::Macos, 501));
        assert!(!Privilege::Account.accepts(Platform::Macos, 0));
        assert!(!Privilege::Root.accepts(Platform::Macos, 0));
        assert!(!Privilege::Root.needs_sudo(Platform::Macos));
        assert!(Privilege::Root.needs_sudo(Platform::Linux));
        assert!(!Privilege::Account.needs_sudo(Platform::Linux));
    }
}
