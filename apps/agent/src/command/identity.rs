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

/// Makes a child process run as `user`, with that user's groups, before project-controlled code starts. A process
/// that already runs as `user` keeps its identity; root drops to it; any other identity refuses.
pub fn drop_to(command: &mut std::process::Command, user: &str) -> Result<(), String> {
    use std::os::unix::process::CommandExt;
    let name =
        std::ffi::CString::new(user).map_err(|_| "the user name holds a NUL byte".to_string())?;
    // SAFETY: getpwnam returns a pointer into static storage or null; its fields are copied at once.
    let (uid, gid, home) = unsafe {
        let entry = libc::getpwnam(name.as_ptr());
        if entry.is_null() {
            return Err(format!("the user {user} does not exist"));
        }
        (
            (*entry).pw_uid,
            (*entry).pw_gid,
            std::ffi::CStr::from_ptr((*entry).pw_dir)
                .to_string_lossy()
                .into_owned(),
        )
    };
    let euid = effective_uid();
    if euid == uid {
        return Ok(());
    }
    if euid != 0 {
        return Err(format!("only root can run a command as {user}"));
    }
    let mut groups = vec![0 as libc::gid_t; 256];
    let mut count = groups.len() as libc::c_int;
    // SAFETY: groups has room for count entries; getgrouplist writes at most that many and updates count.
    if unsafe { libc::getgrouplist(name.as_ptr(), gid, groups.as_mut_ptr(), &mut count) } < 0 {
        return Err(format!("the groups of {user} do not fit"));
    }
    groups.truncate(count as usize);
    command
        .env("HOME", home)
        .env("USER", user)
        .env("LOGNAME", user);
    // SAFETY: setgroups is async-signal-safe and runs in the child between fork and exec.
    unsafe {
        command.pre_exec(move || {
            if libc::setgroups(groups.len() as _, groups.as_ptr()) != 0 {
                return Err(std::io::Error::last_os_error());
            }
            Ok(())
        });
    }
    command.gid(gid).uid(uid);
    Ok(())
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
