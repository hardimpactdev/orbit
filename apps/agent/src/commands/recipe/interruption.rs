//! Graceful termination lets the recipe remove its temporary restart policy.
use super::Failure;
use std::sync::atomic::{AtomicBool, Ordering};
static INTERRUPTED: AtomicBool = AtomicBool::new(false);
extern "C" fn interrupted(_: libc::c_int) {
    INTERRUPTED.store(true, Ordering::Relaxed);
}
pub(crate) fn check() -> Result<(), Failure> {
    if INTERRUPTED.load(Ordering::Relaxed) {
        Err(Failure::new("interrupted", "The recipe was interrupted"))
    } else {
        Ok(())
    }
}
pub(crate) struct Guard {
    old: Vec<(libc::c_int, libc::sigaction)>,
}
impl Guard {
    pub(crate) fn install() -> Result<Self, Failure> {
        INTERRUPTED.store(false, Ordering::Relaxed);
        let mut guard = Self { old: vec![] };
        for signal in [libc::SIGTERM, libc::SIGINT] {
            // SAFETY: both sigactions are initialized, and the handler only writes a lock-free atomic.
            let mut action: libc::sigaction = unsafe { std::mem::zeroed() };
            action.sa_sigaction = interrupted as *const () as usize;
            let mut old: libc::sigaction = unsafe { std::mem::zeroed() };
            if unsafe {
                libc::sigemptyset(&mut action.sa_mask);
                libc::sigaction(signal, &action, &mut old)
            } != 0
            {
                return Err(Failure::new("preflight", std::io::Error::last_os_error()));
            }
            guard.old.push((signal, old));
        }
        Ok(guard)
    }
}
impl Drop for Guard {
    fn drop(&mut self) {
        for (signal, previous) in self.old.iter().rev() {
            // SAFETY: previous is the action returned by sigaction for this signal.
            unsafe {
                libc::sigaction(*signal, previous, std::ptr::null_mut());
            }
        }
        INTERRUPTED.store(false, Ordering::Relaxed);
    }
}
