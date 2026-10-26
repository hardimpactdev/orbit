//! Shared account lookup and child identity handling.
pub use orbit_agent_contract::identity::*;

/// Why work could not run on an account thread.
#[derive(Debug, PartialEq, Eq)]
pub enum AccountThreadError {
    /// The account is unknown, or the thread could not take its identity.
    Identity,
    /// The work panicked.
    Ended,
}

/// Runs `work` on a thread that permanently takes the uid, gid, and groups of `user`. Only that thread changes
/// identity, so the supervisor keeps root and nothing opens a file before the drop. Linux root only.
#[cfg(target_os = "linux")]
pub fn on_account_thread<T: Send + 'static>(
    user: &str,
    work: impl FnOnce() -> T + Send + 'static,
) -> Result<T, AccountThreadError> {
    let (uid, gid, _) = account(user).map_err(|_| AccountThreadError::Identity)?;
    // The thread never keeps root's user or primary group.
    if effective_uid() != 0 || uid == 0 || gid == 0 {
        return Err(AccountThreadError::Identity);
    }
    let name = std::ffi::CString::new(user).map_err(|_| AccountThreadError::Identity)?;
    let groups = group_list(&name, gid).ok_or(AccountThreadError::Identity)?;
    std::thread::spawn(move || {
        // SAFETY: all pointers are live; raw set* calls change only this thread's credentials.
        let dropped = unsafe {
            libc::syscall(libc::SYS_setgroups, groups.len(), groups.as_ptr()) == 0
                && libc::syscall(libc::SYS_setresgid, gid, gid, gid) == 0
                && libc::syscall(libc::SYS_setresuid, uid, uid, uid) == 0
        };
        dropped.then(work).ok_or(AccountThreadError::Identity)
    })
    .join()
    .map_err(|_| AccountThreadError::Ended)?
}

/// Other platforms have no per-thread identity; their commands already run as the account.
#[cfg(not(target_os = "linux"))]
pub fn on_account_thread<T: Send + 'static>(
    _user: &str,
    _work: impl FnOnce() -> T + Send + 'static,
) -> Result<T, AccountThreadError> {
    Err(AccountThreadError::Identity)
}
