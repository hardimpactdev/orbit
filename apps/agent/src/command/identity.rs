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

/// A passwd entry, read with the reentrant `getpwnam_r`, so parallel lookups never share a static buffer.
pub struct Passwd {
    pub uid: u32,
    pub gid: u32,
    pub home: String,
    pub shell: String,
}

fn text(pointer: *const libc::c_char) -> String {
    if pointer.is_null() {
        return String::new();
    }
    // SAFETY: a non-null field of an entry is a NUL-terminated string inside the caller's buffer.
    unsafe { std::ffi::CStr::from_ptr(pointer) }
        .to_string_lossy()
        .into_owned()
}

pub fn passwd(user: &str) -> Option<Passwd> {
    let name = std::ffi::CString::new(user).ok()?;
    let mut buffer = vec![0 as libc::c_char; 16 * 1024];
    // SAFETY: an all-zero passwd is a valid value for getpwnam_r to fill.
    let mut entry: libc::passwd = unsafe { std::mem::zeroed() };
    let mut result = std::ptr::null_mut();
    // SAFETY: every pointer refers to live storage of the given size; the entry's fields point into `buffer`.
    let status = unsafe {
        libc::getpwnam_r(
            name.as_ptr(),
            &mut entry,
            buffer.as_mut_ptr(),
            buffer.len(),
            &mut result,
        )
    };
    (status == 0 && !result.is_null()).then(|| Passwd {
        uid: entry.pw_uid,
        gid: entry.pw_gid,
        home: text(entry.pw_dir),
        shell: text(entry.pw_shell),
    })
}

/// The gid of a group name, or the name of a gid, with the reentrant group lookups.
pub fn group_id(group: &str) -> Option<u32> {
    let name = std::ffi::CString::new(group).ok()?;
    let mut buffer = vec![0 as libc::c_char; 16 * 1024];
    // SAFETY: an all-zero group is a valid value for getgrnam_r to fill.
    let mut entry: libc::group = unsafe { std::mem::zeroed() };
    let mut result = std::ptr::null_mut();
    // SAFETY: every pointer refers to live storage of the given size.
    let status = unsafe {
        libc::getgrnam_r(
            name.as_ptr(),
            &mut entry,
            buffer.as_mut_ptr(),
            buffer.len(),
            &mut result,
        )
    };
    (status == 0 && !result.is_null()).then_some(entry.gr_gid)
}

pub fn group_name(gid: u32) -> Option<String> {
    let mut buffer = vec![0 as libc::c_char; 16 * 1024];
    // SAFETY: an all-zero group is a valid value for getgrgid_r to fill.
    let mut entry: libc::group = unsafe { std::mem::zeroed() };
    let mut result = std::ptr::null_mut();
    // SAFETY: every pointer refers to live storage of the given size; the name points into `buffer`.
    let status = unsafe {
        libc::getgrgid_r(
            gid,
            &mut entry,
            buffer.as_mut_ptr(),
            buffer.len(),
            &mut result,
        )
    };
    (status == 0 && !result.is_null()).then(|| text(entry.gr_name))
}

pub fn user_name(uid: u32) -> Option<String> {
    let mut buffer = vec![0 as libc::c_char; 16 * 1024];
    // SAFETY: an all-zero passwd is a valid value for getpwuid_r to fill.
    let mut entry: libc::passwd = unsafe { std::mem::zeroed() };
    let mut result = std::ptr::null_mut();
    // SAFETY: every pointer refers to live storage of the given size; the name points into `buffer`.
    let status = unsafe {
        libc::getpwuid_r(
            uid,
            &mut entry,
            buffer.as_mut_ptr(),
            buffer.len(),
            &mut result,
        )
    };
    (status == 0 && !result.is_null()).then(|| text(entry.pw_name))
}

/// The uid, gid, and home of a user.
pub fn account(user: &str) -> Result<(u32, u32, String), String> {
    if user.contains('\0') {
        return Err("the user name holds a NUL byte".to_string());
    }
    passwd(user)
        .map(|entry| (entry.uid, entry.gid, entry.home))
        .ok_or_else(|| format!("the user {user} does not exist"))
}

/// Makes a child process run as `user`, with that user's groups, before project-controlled code starts. A process
/// that already runs as `user` keeps its identity; root drops to it; any other identity refuses. It never runs a child
/// as root.
pub fn drop_to(command: &mut std::process::Command, user: &str) -> Result<(), String> {
    use std::os::unix::process::CommandExt;
    let name =
        std::ffi::CString::new(user).map_err(|_| "the user name holds a NUL byte".to_string())?;
    let (uid, gid, home) = account(user)?;
    if uid == 0 {
        return Err(format!("{user} is root; project code never runs as root"));
    }
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
    // The standard library changes the uid before it runs `pre_exec` closures, so all three changes happen here, in
    // the order that keeps the right to make the next one.
    // SAFETY: setgroups, setgid, and setuid are async-signal-safe and run in the child between fork and exec.
    unsafe {
        command.pre_exec(move || {
            if libc::setgroups(groups.len() as _, groups.as_ptr()) != 0
                || libc::setgid(gid) != 0
                || libc::setuid(uid) != 0
            {
                return Err(std::io::Error::last_os_error());
            }
            Ok(())
        });
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Runs as root through `drop_tests_as_root_and_keeps_the_target_groups`: drops to `nobody` and reports its identity.
    #[test]
    #[ignore = "runs as root from another test"]
    fn drop_as_root_helper() {
        let mut command = std::process::Command::new("id");
        drop_to(&mut command, "nobody").unwrap();
        let output = command.output().unwrap();
        assert!(
            output.status.success(),
            "{}",
            String::from_utf8_lossy(&output.stderr)
        );
        print!("ORBIT-ID {}", String::from_utf8_lossy(&output.stdout));
    }

    #[test]
    fn drops_from_root_to_the_target_user_and_its_groups() {
        if !std::process::Command::new("sudo")
            .args(["-n", "true"])
            .status()
            .is_ok_and(|status| status.success())
        {
            eprintln!("skipped: this test needs passwordless sudo");
            return;
        }
        let output = std::process::Command::new("sudo")
            .arg("-n")
            .arg(std::env::current_exe().unwrap())
            .args([
                "--ignored",
                "--exact",
                "command::identity::tests::drop_as_root_helper",
                "--nocapture",
            ])
            .output()
            .unwrap();
        let stdout = String::from_utf8_lossy(&output.stdout);
        assert!(
            output.status.success(),
            "{stdout}{}",
            String::from_utf8_lossy(&output.stderr)
        );
        let line = stdout
            .lines()
            .find(|line| line.contains("ORBIT-ID "))
            .unwrap();
        assert!(
            line.contains("uid=65534(nobody)") && line.contains("gid=65534"),
            "{line}"
        );
        assert!(
            !line.contains("groups=0") && !line.contains("(root)"),
            "{line}"
        );
    }

    #[test]
    fn never_runs_a_child_as_root() {
        let mut command = std::process::Command::new("id");
        assert!(drop_to(&mut command, "root")
            .unwrap_err()
            .contains("never runs as root"));
    }

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
    }
}
