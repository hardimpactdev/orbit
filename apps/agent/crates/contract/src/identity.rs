//! The identity a command runs as. Each command declares one privilege per platform; the command checks
//! its effective identity before any change.

pub use crate::{Platform, Privilege};

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

/// Runs one reentrant lookup, growing its buffer while libc reports `ERANGE`, up to 1 MiB, so a large entry such as
/// a group with many members is found rather than read as absent.
fn reentrant<E, R>(
    mut lookup: impl FnMut(&mut E, *mut libc::c_char, usize, *mut *mut E) -> libc::c_int,
    read: impl FnOnce(&E) -> R,
) -> Option<R> {
    let mut size = 16 * 1024;
    loop {
        let mut buffer = vec![0 as libc::c_char; size];
        // SAFETY: the C passwd and group entries are plain structs for which all-zero bytes are valid.
        let mut entry: E = unsafe { std::mem::zeroed() };
        let mut result = std::ptr::null_mut();
        let status = lookup(&mut entry, buffer.as_mut_ptr(), buffer.len(), &mut result);
        if status == libc::ERANGE && size < 1024 * 1024 {
            size *= 2;
            continue;
        }
        // The entry's string fields point into `buffer`, which lives until `read` returns.
        return (status == 0 && !result.is_null()).then(|| read(&entry));
    }
}

pub fn passwd(user: &str) -> Option<Passwd> {
    let name = std::ffi::CString::new(user).ok()?;
    // SAFETY: every pointer refers to live storage of the given size.
    reentrant(
        |entry, buffer, size, result| unsafe { libc::getpwnam_r(name.as_ptr(), entry, buffer, size, result) },
        |entry: &libc::passwd| Passwd {
            uid: entry.pw_uid,
            gid: entry.pw_gid,
            home: text(entry.pw_dir),
            shell: text(entry.pw_shell),
        },
    )
}

/// The gid of a group name, or the name of a gid, with the reentrant group lookups.
pub fn group_id(group: &str) -> Option<u32> {
    let name = std::ffi::CString::new(group).ok()?;
    // SAFETY: every pointer refers to live storage of the given size.
    reentrant(
        |entry, buffer, size, result| unsafe { libc::getgrnam_r(name.as_ptr(), entry, buffer, size, result) },
        |entry: &libc::group| entry.gr_gid,
    )
}

pub fn group_name(gid: u32) -> Option<String> {
    // SAFETY: every pointer refers to live storage of the given size.
    reentrant(
        |entry, buffer, size, result| unsafe { libc::getgrgid_r(gid, entry, buffer, size, result) },
        |entry: &libc::group| text(entry.gr_name),
    )
}

pub fn user_name(uid: u32) -> Option<String> {
    // SAFETY: every pointer refers to live storage of the given size.
    reentrant(
        |entry, buffer, size, result| unsafe { libc::getpwuid_r(uid, entry, buffer, size, result) },
        |entry: &libc::passwd| text(entry.pw_name),
    )
}

/// The supplementary groups of a user whose primary group is `gid`. The list grows until it fits, up to 65,536 groups,
/// so a member of many groups is never refused for a fixed buffer.
pub fn group_list(name: &std::ffi::CStr, gid: u32) -> Option<Vec<libc::gid_t>> {
    let mut groups = vec![0 as libc::gid_t; 64];
    loop {
        let mut count = groups.len() as libc::c_int;
        // SAFETY: groups has room for count entries; getgrouplist writes at most that many and updates count.
        // Darwin declares group IDs here as c_int, while gid_t is u32. Both have the same 32-bit ABI.
        if unsafe { libc::getgrouplist(name.as_ptr(), gid as _, groups.as_mut_ptr().cast(), &mut count) } >= 0 {
            groups.truncate(count.max(0) as usize);
            return Some(groups);
        }
        // Linux reports the size it needs; other systems only say the list is too small.
        let next = (count.max(0) as usize).max(groups.len() * 2);
        if next > 65_536 {
            return None;
        }
        groups.resize(next, 0);
    }
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
    let groups = group_list(&name, gid).ok_or_else(|| format!("the groups of {user} do not fit"))?;
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

    #[test]
    fn the_group_list_holds_the_primary_group() {
        let uid = effective_uid();
        let name = std::ffi::CString::new(user_name(uid).unwrap()).unwrap();
        let gid = passwd(name.to_str().unwrap()).unwrap().gid;
        assert!(group_list(&name, gid).unwrap().contains(&gid));
    }

    #[test]
    fn a_lookup_grows_its_buffer_on_erange_and_gives_up_after_one_mebibyte() {
        let mut sizes = Vec::new();
        let found = reentrant(
            |entry: &mut libc::group, _, size, result| {
                sizes.push(size);
                if size < 64 * 1024 {
                    return libc::ERANGE;
                }
                entry.gr_gid = 42;
                // SAFETY: `result` points at the caller's live result slot.
                unsafe { *result = entry };
                0
            },
            |entry| entry.gr_gid,
        );
        assert_eq!(found, Some(42));
        assert_eq!(sizes, [16 * 1024, 32 * 1024, 64 * 1024]);

        let mut calls = 0;
        let missing = reentrant(
            |_: &mut libc::group, _, _, _| {
                calls += 1;
                libc::ERANGE
            },
            |entry| entry.gr_gid,
        );
        assert_eq!(missing, None);
        assert_eq!(calls, 7);
    }

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
                "identity::tests::drop_as_root_helper",
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
