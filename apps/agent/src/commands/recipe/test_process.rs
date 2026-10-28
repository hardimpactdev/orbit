//! Owned subprocesses for signal tests: bounded waits and cleanup on assertion failure.
use std::{
    io,
    os::unix::process::CommandExt,
    process::{Child, Command, ExitStatus},
    time::{Duration, Instant},
};

pub(super) const WAIT: Duration = Duration::from_secs(5);

pub(super) struct Worker {
    child: Child,
    pub(super) command_group: Option<i32>,
}
impl Worker {
    pub(super) fn spawn(command: &mut Command) -> Self {
        Self {
            child: command
                .process_group(0)
                .spawn()
                .expect("start signal fixture"),
            command_group: None,
        }
    }

    pub(super) fn id(&self) -> i32 {
        self.child.id() as i32
    }

    pub(super) fn wait(&mut self, timeout: Duration) -> io::Result<ExitStatus> {
        let deadline = Instant::now() + timeout;
        loop {
            if let Some(status) = self.child.try_wait()? {
                return Ok(status);
            }
            let remaining = deadline.saturating_duration_since(Instant::now());
            if remaining.is_zero() {
                return Err(io::Error::new(
                    io::ErrorKind::TimedOut,
                    format!(
                        "signal fixture {} did not exit within {timeout:?}",
                        self.id()
                    ),
                ));
            }
            std::thread::sleep(remaining.min(Duration::from_millis(5)));
        }
    }
}
impl Drop for Worker {
    fn drop(&mut self) {
        let mut groups = Vec::new();
        groups.extend(self.command_group);
        // The native runner creates its command group before that command reports
        // readiness. Find it even when the readiness datagram never arrives.
        #[cfg(target_os = "linux")]
        if let Ok(tasks) = std::fs::read_dir(format!("/proc/{}/task", self.id())) {
            for task in tasks.flatten() {
                if let Ok(children) = std::fs::read_to_string(task.path().join("children")) {
                    groups.extend(
                        children
                            .split_whitespace()
                            .filter_map(|pid| pid.parse::<i32>().ok()),
                    );
                }
            }
        }
        for group in groups {
            // SAFETY: these command groups belong to this isolated fixture.
            unsafe {
                libc::kill(-group, libc::SIGKILL);
            }
        }
        if self.child.try_wait().ok().flatten().is_none() {
            // SAFETY: this group was created by spawn for this fixture.
            unsafe {
                libc::kill(-self.id(), libc::SIGKILL);
            }
            if let Err(error) = self.wait(WAIT) {
                if !std::thread::panicking() {
                    panic!("signal fixture cleanup exceeded its deadline: {error}");
                }
                eprintln!("signal fixture cleanup failed during unwinding: {error}");
            }
        }
    }
}

#[test]
fn a_stalled_fixture_times_out_and_is_reaped_on_drop() {
    let mut worker = Worker::spawn(Command::new("/usr/bin/sleep").arg("60"));
    let pid = worker.id();
    let error = worker.wait(Duration::from_millis(10)).unwrap_err();
    assert_eq!(error.kind(), io::ErrorKind::TimedOut);
    assert!(error.to_string().contains("did not exit within"));
    drop(worker);
    // SAFETY: signal zero only tests whether this process still exists.
    assert_eq!(unsafe { libc::kill(pid, 0) }, -1);
    assert_eq!(io::Error::last_os_error().raw_os_error(), Some(libc::ESRCH));
}
