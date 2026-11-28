//! Reads of the system's account database through NSS, with the reentrant `getpwnam_r` and `getgrgid_r`, so every source
//! the host configures in `nsswitch.conf` answers and no command runs. A lookup that fails is an error, never a missing
//! entry: only a call that succeeds without an entry answers `None`.

use std::ffi::{c_char, c_int, CStr, CString};

/// The first buffer a lookup tries.
const FIRST: usize = 16 * 1024;
/// The largest buffer a lookup tries before an entry that still does not fit fails.
const LAST: usize = 1024 * 1024;

/// A passwd entry without its password field.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Passwd {
    /// The user id.
    pub uid: u32,
    /// The primary group id.
    pub gid: u32,
    /// The home folder.
    pub home: String,
}

/// The passwd entry of `name`, or `None` when NSS has no such user.
pub(crate) fn passwd(name: &str) -> Result<Option<Passwd>, String> {
    let name = CString::new(name).map_err(|_| "the name holds a NUL byte".to_owned())?;
    grow(|buffer| {
        // SAFETY: `passwd` is a plain C struct for which all-zero bytes are a valid value; `getpwnam_r` fills it.
        let mut entry: libc::passwd = unsafe { std::mem::zeroed() };
        let mut found = std::ptr::null_mut();
        // SAFETY: `name` is NUL-terminated, `entry` and `found` are live, and `buffer` holds `buffer.len()` bytes.
        let status = unsafe { libc::getpwnam_r(name.as_ptr(), &raw mut entry, buffer.as_mut_ptr(), buffer.len(), &raw mut found) };
        // The entry's strings point into `buffer`, which lives until this closure returns.
        let read = (status == 0 && !found.is_null()).then(|| Ok(Passwd { uid: entry.pw_uid, gid: entry.pw_gid, home: text(entry.pw_dir)? }));
        (status, read)
    })
}

/// The name of group `gid`, or `None` when NSS has no such group.
pub(crate) fn group_name(gid: u32) -> Result<Option<String>, String> {
    grow(|buffer| {
        // SAFETY: `group` is a plain C struct for which all-zero bytes are a valid value; `getgrgid_r` fills it.
        let mut entry: libc::group = unsafe { std::mem::zeroed() };
        let mut found = std::ptr::null_mut();
        // SAFETY: `entry` and `found` are live, and `buffer` holds `buffer.len()` bytes.
        let status = unsafe { libc::getgrgid_r(gid, &raw mut entry, buffer.as_mut_ptr(), buffer.len(), &raw mut found) };
        let read = (status == 0 && !found.is_null()).then(|| text(entry.gr_name));
        (status, read)
    })
}

/// Runs one reentrant lookup with a buffer that doubles while it answers `ERANGE`, up to [`LAST`]. A status of 0 answers
/// the entry it read, or `None`; any other status fails, also an `ERANGE` at the largest buffer.
pub(crate) fn grow<R>(mut call: impl FnMut(&mut [c_char]) -> (c_int, Option<Result<R, String>>)) -> Result<Option<R>, String> {
    let mut size = FIRST;
    loop {
        let mut buffer = vec![0; size];
        match call(&mut buffer) {
            (0, read) => return read.transpose(),
            (libc::ERANGE, _) if size < LAST => size *= 2,
            (status, _) => return Err(std::io::Error::from_raw_os_error(status).to_string()),
        }
    }
}

/// A string field of an entry NSS filled, which must be UTF-8.
fn text(pointer: *const c_char) -> Result<String, String> {
    if pointer.is_null() {
        return Err("an entry without a field".to_owned());
    }
    // SAFETY: a non-null field of an entry NSS filled is a NUL-terminated string inside the lookup's live buffer.
    let field = unsafe { CStr::from_ptr(pointer) };
    field.to_str().map(str::to_owned).map_err(|_| "a field that is not UTF-8".to_owned())
}
