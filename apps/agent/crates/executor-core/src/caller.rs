//! The caller check: only the observer or the one relay command may hand the executor an order.
//!
//! The kernel names the peer through `SO_PEERCRED` and `SO_PEERPIDFD`. The executor reads the peer's cgroup
//! and executable through `/proc`, then sends signal 0 to the pidfd: a live pidfd proves the PID was not
//! reused while it read them (ADR 0266).

use std::fs;
use std::io;
use std::os::fd::{AsFd, AsRawFd as _, FromRawFd as _, OwnedFd};
use std::os::unix::net::UnixStream;
use std::path::{Path, PathBuf};

/// The observer's exact cgroup line in `/proc/<pid>/cgroup`.
pub const OBSERVER_CGROUP: &str = "0::/system.slice/orbit-agent.service";

/// Who the kernel says the peer is, read before the policy decides.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Peer {
    /// The peer's user id.
    pub uid: u32,
    /// The peer's process id.
    pub pid: i32,
    /// The peer's `/proc/<pid>/cgroup`, trimmed.
    pub cgroup: String,
    /// The peer's executable, when the kernel shows it.
    pub exe: Option<PathBuf>,
}

/// Which allowed caller connected.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
#[non_exhaustive]
pub enum Caller {
    /// The observer, `orbit-agent.service`.
    Observer,
    /// The relay command, run as `orbit-executor-relay`.
    Relay,
}

/// Who may connect. Production uses [`Policy::production`]; tests name their own process.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Policy {
    /// The observer's user id: root.
    pub observer_uid: u32,
    /// The observer's exact cgroup line.
    pub observer_cgroup: String,
    /// The relay user's id, when that user exists on this Node.
    pub relay_uid: Option<u32>,
    /// The executor binary, which the relay user must be running.
    pub relay_exe: PathBuf,
}

impl Policy {
    /// The Node's policy: root in the observer's unit, or the relay user running `relay_exe`.
    pub fn production(relay_uid: Option<u32>, relay_exe: PathBuf) -> Self {
        Self {
            observer_uid: 0,
            observer_cgroup: OBSERVER_CGROUP.to_owned(),
            relay_uid,
            relay_exe,
        }
    }

    /// Decides whether `peer` is an allowed caller.
    ///
    /// # Errors
    ///
    /// Returns the [`CallerRefusal`] that names why the peer is not the observer or the relay.
    pub fn admit(&self, peer: &Peer) -> Result<Caller, CallerRefusal> {
        if peer.uid == self.observer_uid {
            return if peer.cgroup == self.observer_cgroup {
                Ok(Caller::Observer)
            } else {
                Err(CallerRefusal::OutsideObserverUnit)
            };
        }
        if Some(peer.uid) == self.relay_uid {
            return if peer.exe.as_deref() == Some(self.relay_exe.as_path()) {
                Ok(Caller::Relay)
            } else {
                Err(CallerRefusal::RelayOtherProgram)
            };
        }
        Err(CallerRefusal::OtherUser)
    }
}

/// Why a connection is refused before its order is read.
#[derive(Debug, thiserror::Error)]
#[non_exhaustive]
pub enum CallerRefusal {
    /// The peer is neither root nor the relay user.
    #[error("caller is another user")]
    OtherUser,
    /// The peer is root outside the observer's exact cgroup, such as a look-alike unit.
    #[error("caller is root outside orbit-agent.service")]
    OutsideObserverUnit,
    /// The peer is the relay user running another program.
    #[error("relay user runs another program")]
    RelayOtherProgram,
    /// The kernel did not name the peer.
    #[error("peer credentials unavailable")]
    PeerUnknown(#[source] io::Error),
    /// The peer's `/proc` entries could not be read.
    #[error("peer process unreadable")]
    ProcUnreadable(#[source] io::Error),
    /// The peer exited during the check, so its PID may have been reused.
    #[error("peer exited during the check")]
    PeerGone(#[source] io::Error),
}

impl CallerRefusal {
    /// The stable code the executor logs.
    pub fn code(&self) -> &'static str {
        match self {
            Self::OtherUser => "caller_other_user",
            Self::OutsideObserverUnit => "caller_outside_observer_unit",
            Self::RelayOtherProgram => "caller_relay_other_program",
            Self::PeerUnknown(_) => "caller_unknown",
            Self::ProcUnreadable(_) => "caller_unreadable",
            Self::PeerGone(_) => "caller_gone",
        }
    }
}

/// Names the peer of `stream` through the kernel and `/proc`.
///
/// # Errors
///
/// Returns a [`CallerRefusal`] when the kernel cannot name the peer, its `/proc` entries cannot be read, or
/// it exited during the check.
pub fn identify(stream: &UnixStream) -> Result<Peer, CallerRefusal> {
    let credentials = rustix::net::sockopt::socket_peercred(stream)
        .map_err(|error| CallerRefusal::PeerUnknown(error.into()))?;
    let pidfd = peer_pidfd(stream).map_err(CallerRefusal::PeerUnknown)?;
    let pid = credentials.pid.as_raw_nonzero().get();
    let proc_dir = PathBuf::from(format!("/proc/{pid}"));
    let cgroup =
        fs::read_to_string(proc_dir.join("cgroup")).map_err(CallerRefusal::ProcUnreadable)?;
    let exe = read_exe(&proc_dir.join("exe")).map_err(CallerRefusal::ProcUnreadable)?;
    signal_zero(&pidfd).map_err(CallerRefusal::PeerGone)?;
    Ok(Peer {
        uid: credentials.uid.as_raw(),
        pid,
        cgroup: cgroup.trim().to_owned(),
        exe,
    })
}

fn read_exe(link: &Path) -> io::Result<Option<PathBuf>> {
    match fs::read_link(link) {
        Ok(path) => Ok(Some(path)),
        // A peer that root does not own still shows its link to root; another user gets no link at all.
        Err(error) if error.kind() == io::ErrorKind::PermissionDenied => Ok(None),
        Err(error) => Err(error),
    }
}

fn peer_pidfd(stream: &UnixStream) -> io::Result<OwnedFd> {
    let mut raw: libc::c_int = -1;
    let mut len = libc::socklen_t::try_from(std::mem::size_of::<libc::c_int>())
        .expect("an int fits socklen_t");
    let pointer: *mut libc::c_int = &mut raw;
    // SAFETY: the socket is a live descriptor borrowed from `stream`; `pointer` and `len` describe one
    // writable `c_int` that outlives the call, which is the buffer SO_PEERPIDFD fills. rustix has no
    // wrapper for this option.
    let status = unsafe {
        libc::getsockopt(
            stream.as_fd().as_raw_fd(),
            libc::SOL_SOCKET,
            libc::SO_PEERPIDFD,
            pointer.cast(),
            &mut len,
        )
    };
    if status != 0 {
        return Err(io::Error::last_os_error());
    }
    if raw < 0 {
        return Err(io::Error::other("SO_PEERPIDFD returned no descriptor"));
    }
    // SAFETY: on success the kernel gave this process a new pidfd that nothing else owns.
    Ok(unsafe { OwnedFd::from_raw_fd(raw) })
}

fn signal_zero(pidfd: &OwnedFd) -> io::Result<()> {
    let fd = pidfd.as_raw_fd();
    let no_info: *mut libc::siginfo_t = std::ptr::null_mut();
    let no_flags: libc::c_uint = 0;
    // SAFETY: `fd` is a live pidfd owned by `pidfd`; signal 0 sends nothing and only checks that the
    // process lives; a null siginfo with no flags is the documented default. rustix's `Signal` cannot
    // express signal 0.
    let status = unsafe { libc::syscall(libc::SYS_pidfd_send_signal, fd, 0, no_info, no_flags) };
    if status == 0 {
        Ok(())
    } else {
        Err(io::Error::last_os_error())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const RELAY: u32 = 990;

    fn policy() -> Policy {
        Policy::production(
            Some(RELAY),
            PathBuf::from("/usr/local/libexec/orbit-executor"),
        )
    }

    fn peer(uid: u32, cgroup: &str, exe: &str) -> Peer {
        Peer {
            uid,
            pid: 42,
            cgroup: cgroup.to_owned(),
            exe: Some(PathBuf::from(exe)),
        }
    }

    #[test]
    fn the_observer_and_the_relay_are_admitted() {
        assert_eq!(
            policy()
                .admit(&peer(0, OBSERVER_CGROUP, "/usr/local/bin/orbit-agent"))
                .ok(),
            Some(Caller::Observer)
        );
        assert_eq!(
            policy()
                .admit(&peer(
                    RELAY,
                    "0::/user.slice",
                    "/usr/local/libexec/orbit-executor"
                ))
                .ok(),
            Some(Caller::Relay)
        );
    }

    #[test]
    fn another_user_is_refused() {
        let refusal = policy().admit(&peer(
            1000,
            OBSERVER_CGROUP,
            "/usr/local/libexec/orbit-executor",
        ));
        assert!(matches!(refusal, Err(CallerRefusal::OtherUser)));
    }

    #[test]
    fn root_outside_the_unit_and_a_look_alike_unit_are_refused() {
        for cgroup in [
            "0::/user.slice/user-0.slice/session-1.scope",
            "0::/system.slice/orbit-agent.service.d",
            "0::/system.slice/orbit-agent.service/child",
        ] {
            let refusal = policy().admit(&peer(0, cgroup, "/usr/local/bin/orbit-agent"));
            assert!(
                matches!(refusal, Err(CallerRefusal::OutsideObserverUnit)),
                "{cgroup} must refuse"
            );
        }
    }

    #[test]
    fn the_relay_user_running_another_program_is_refused() {
        assert!(matches!(
            policy().admit(&peer(RELAY, "0::/", "/usr/bin/python3")),
            Err(CallerRefusal::RelayOtherProgram)
        ));
        let unknown = Peer {
            exe: None,
            ..peer(RELAY, "0::/", "")
        };
        assert!(matches!(
            policy().admit(&unknown),
            Err(CallerRefusal::RelayOtherProgram)
        ));
    }

    #[test]
    fn no_relay_user_means_only_the_observer() {
        let observer_only =
            Policy::production(None, PathBuf::from("/usr/local/libexec/orbit-executor"));
        assert!(matches!(
            observer_only.admit(&peer(RELAY, "0::/", "/usr/local/libexec/orbit-executor")),
            Err(CallerRefusal::OtherUser)
        ));
    }

    #[test]
    fn identify_names_this_process_through_the_kernel() {
        let (ours, theirs) = UnixStream::pair().expect("socket pair");
        let peer = identify(&ours).expect("the peer is this process");
        drop(theirs);
        assert_eq!(
            peer.pid,
            i32::try_from(std::process::id()).expect("pid fits i32")
        );
        assert_eq!(peer.uid, rustix::process::getuid().as_raw());
        assert_eq!(peer.exe, std::env::current_exe().ok());
        assert_eq!(
            peer.cgroup,
            fs::read_to_string("/proc/self/cgroup")
                .expect("cgroup")
                .trim()
        );
    }
}
