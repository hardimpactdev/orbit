//! Refuse unsupported cgroup network enforcement before releasing package code.
use super::Error;
use std::{
    fs,
    path::Path,
    time::{Duration, Instant},
};
#[cfg(target_os = "linux")]
fn attached(path: &Path, attach_type: u32) -> bool {
    use std::os::fd::AsRawFd;
    // Linux UAPI bpf_attr.query (BPF_PROG_QUERY). Query only this cgroup,
    // not ancestor programs: both directly attached systemd filters are required.
    #[repr(C)]
    struct Query {
        fd: u32,
        attach_type: u32,
        flags: u32,
        attach_flags: u32,
        ids: u64,
        count: u32,
        padding: u32,
    }
    let Ok(fd) = fs::File::open(path) else {
        return false;
    };
    let mut ids = [0u32; 16];
    let mut query = Query {
        fd: fd.as_raw_fd() as u32,
        attach_type,
        flags: 0,
        attach_flags: 0,
        ids: ids.as_mut_ptr() as u64,
        count: ids.len() as u32,
        padding: 0,
    };
    // SAFETY: kernel receives a valid aligned initialized UAPI buffer and bounded ids array.
    let result = unsafe {
        libc::syscall(
            libc::SYS_bpf,
            16u32,
            &mut query as *mut Query,
            std::mem::size_of::<Query>(),
        )
    };
    result == 0 && query.count > 0
}
#[cfg(not(target_os = "linux"))]
fn attached(_: &Path, _: u32) -> bool {
    false
}
fn namespaces(cgroup: &Path) -> bool {
    use std::os::unix::fs::MetadataExt;
    let Some(unit) = cgroup.file_name() else {
        return false;
    };
    let Ok(output) = std::process::Command::new("/usr/bin/systemctl")
        .args(["show", "--property=MainPID", "--value"])
        .arg(unit)
        .output()
    else {
        return false;
    };
    let Ok(pid) = String::from_utf8_lossy(&output.stdout)
        .trim()
        .parse::<u32>()
    else {
        return false;
    };
    if pid == 0 {
        return false;
    }
    ["pid", "ipc", "mnt"].iter().all(|name| {
        match (
            fs::metadata(format!("/proc/{pid}/ns/{name}")),
            fs::metadata(format!("/proc/self/ns/{name}")),
        ) {
            (Ok(child), Ok(parent)) => (child.dev(), child.ino()) != (parent.dev(), parent.ino()),
            _ => false,
        }
    })
}
pub(super) fn release(root: &Path, cgroup: &Path, deadline: Instant) -> Result<(), Error> {
    let deadline = deadline.min(Instant::now() + Duration::from_secs(8));
    loop {
        if attached(cgroup, 0) && attached(cgroup, 1) && namespaces(cgroup) {
            fs::write(
                root.parent().ok_or(Error::Failed {})?.join("network-ready"),
                b"ready\n",
            )
            .map_err(|_| Error::Failed {})?;
            return Ok(());
        }
        if Instant::now() >= deadline {
            return Err(Error::Unavailable {});
        }
        std::thread::sleep(Duration::from_millis(50));
    }
}
// The only process initially started in the package cage is this trusted gate.
// /run is root-owned and read-only in that cage. No package-controlled executable
// or interpreter input is evaluated before the supervisor verifies enforcement.
pub(super) const GUARD: &str = r#"import ctypes,errno,os,socket,sys,time
if os.getpid()!=1 or os.uname().machine!='x86_64': sys.exit(78)
status=dict(line.split(':',1) for line in open('/proc/self/status') if ':' in line)
if status.get('NoNewPrivs','').strip()!='1' or status.get('Seccomp','').strip()!='2': sys.exit(78)
if any(int(status.get(k,'1').strip(),16)!=0 for k in ['CapEff','CapPrm','CapAmb']): sys.exit(78)
for probe in [lambda:socket.socket(socket.AF_UNIX,socket.SOCK_DGRAM),lambda:socket.socketpair(socket.AF_UNIX,socket.SOCK_DGRAM)]:
 try: probe();sys.exit(78)
 except OSError as error:
  if error.errno not in [errno.EPERM,errno.EAFNOSUPPORT]:sys.exit(78)
libc=ctypes.CDLL(None,use_errno=True)
if libc.syscall(425,0,0)!=-1 or ctypes.get_errno()!=errno.EPERM:sys.exit(78)
for _ in range(200):
 if open('/run/network-ready').read()=='ready\n': os.execve(sys.argv[1],sys.argv[1:],dict(os.environ))
 time.sleep(.05)
sys.exit(78)
"#;

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn unavailable_network_backend_never_releases_the_gate() {
        let root =
            std::env::temp_dir().join(format!("package-gate-{:016x}", rand::random::<u64>()));
        fs::create_dir(&root).unwrap();
        fs::create_dir(root.join("run")).unwrap();
        assert!(release(&root, &root.join("no-cgroup"), Instant::now()).is_err());
        assert!(!root.join("network-ready").exists());
        fs::remove_dir_all(root).unwrap();
    }
}
