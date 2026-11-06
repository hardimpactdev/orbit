use super::super::test_process::{Worker, WAIT};
use super::*;
use crate::command::{Context, Platform};
use crate::commands::recipe::{
    interruption, Failure, Files, Host, Invocation, Native, Output, WorkDir,
};
use std::{
    fs,
    time::{Duration, Instant},
};

/// Records the invocations it is asked to run and runs none of them.
#[derive(Default)]
struct Recorder {
    calls: Vec<Invocation>,
}
impl Host for Recorder {
    fn execute(&mut self, invocation: &Invocation) -> Result<Output, Failure> {
        self.calls.push(invocation.clone());
        Ok(Output::default())
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("no retries")
    }
}

fn fixture() -> (WorkDir, Files, Recorder) {
    let work = WorkDir::new().unwrap();
    fs::create_dir_all(work.0.join("usr/sbin")).unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: rustix::process::getuid().as_raw(),
        gid: rustix::process::getgid().as_raw(),
    };
    (work, files, Recorder::default())
}

#[test]
fn the_restart_policy_exists_until_dropped_and_refuses_a_second_one() {
    let (_work, files, _) = fixture();
    let policy = Policy::install(&files).unwrap();
    let path = files.path("/usr/sbin/policy-rc.d");
    assert_eq!(fs::read(&path).unwrap(), b"#!/bin/sh\nexit 101\n");
    assert_eq!(Policy::install(&files).err().unwrap().stage, "preflight");
    assert!(path.exists());
    drop(policy);
    assert!(!path.exists());
}

#[test]
#[ignore = "subprocess fixture reports readiness only after exec"]
#[cfg(target_os = "linux")]
fn interruption_command_helper() {
    use std::os::unix::net::UnixDatagram;
    let socket = UnixDatagram::unbound().unwrap();
    socket.set_write_timeout(Some(WAIT)).unwrap();
    socket
        .connect(std::env::var_os("ORBIT_RECIPE_INTERRUPT_SOCKET").unwrap())
        .unwrap();
    // The native recipe runner establishes a process group before exec.
    let group = rustix::process::getpgrp().as_raw_pid();
    socket.send(&group.to_ne_bytes()).unwrap();
    std::thread::sleep(WAIT);
    panic!("the command fixture did not receive a terminating signal within {WAIT:?}");
}

// Hold the real handler behind a socket barrier without changing child signal masks.
#[cfg(target_os = "linux")]
mod delayed_handler {
    use super::WAIT;
    use std::{
        io::{Read, Write},
        os::{fd::AsRawFd, unix::net::UnixStream},
        sync::atomic::{AtomicI32, AtomicUsize, Ordering},
    };

    static SOCKET: AtomicI32 = AtomicI32::new(-1);
    static HANDLER: AtomicUsize = AtomicUsize::new(0);

    // The handler can itself be interrupted. Poll against one monotonic deadline
    // and use nonblocking I/O so EINTR never releases the barrier or renews its bound.
    fn transfer(fd: i32, bytes: &mut [u8], send: bool) -> bool {
        let mut time: libc::timespec = unsafe { std::mem::zeroed() };
        // SAFETY: clock_gettime, poll, send, and recv are async-signal-safe. All
        // pointers refer to live stack data, and this fixture owns the socket.
        unsafe {
            if libc::clock_gettime(libc::CLOCK_MONOTONIC, &mut time) != 0 {
                return false;
            }
            let milliseconds = |time: libc::timespec| time.tv_sec * 1000 + time.tv_nsec / 1_000_000;
            let deadline = milliseconds(time) + WAIT.as_millis() as i64;
            loop {
                if libc::clock_gettime(libc::CLOCK_MONOTONIC, &mut time) != 0 {
                    return false;
                }
                let remaining = deadline - milliseconds(time);
                if remaining <= 0 {
                    return false;
                }
                let mut event = libc::pollfd {
                    fd,
                    events: if send { libc::POLLOUT } else { libc::POLLIN },
                    revents: 0,
                };
                let ready = libc::poll(&mut event, 1, remaining as i32);
                let count = if ready > 0 {
                    if send {
                        libc::send(
                            fd,
                            bytes.as_ptr().cast(),
                            bytes.len(),
                            libc::MSG_DONTWAIT | libc::MSG_NOSIGNAL,
                        )
                    } else {
                        libc::recv(
                            fd,
                            bytes.as_mut_ptr().cast(),
                            bytes.len(),
                            libc::MSG_DONTWAIT,
                        )
                    }
                } else if ready == 0 {
                    return false;
                } else {
                    -1
                };
                if count >= 0 {
                    return count == bytes.len() as isize;
                }
                if !matches!(*libc::__errno_location(), libc::EINTR | libc::EAGAIN) {
                    return false;
                }
            }
        }
    }

    extern "C" fn delayed(signal: libc::c_int) {
        let fd = SOCKET.load(Ordering::Acquire);
        // SAFETY: gettid only reads the current thread ID.
        let mut thread = unsafe { libc::gettid() }.to_ne_bytes();
        let mut release = [0u8];
        // SAFETY: the saved action is the one-argument handler installed by
        // interruption::Guard. write and _exit are async-signal-safe.
        unsafe {
            if !transfer(fd, &mut thread, true) || !transfer(fd, &mut release, false) {
                let message = b"delayed signal handler entry/release failed or timed out\n";
                libc::write(libc::STDERR_FILENO, message.as_ptr().cast(), message.len());
                libc::_exit(101);
            }
            let handler: extern "C" fn(libc::c_int) =
                std::mem::transmute(HANDLER.load(Ordering::Acquire));
            handler(signal);
            if !transfer(fd, &mut release, true) {
                let message = b"delayed signal handler acknowledgement failed or timed out\n";
                libc::write(libc::STDERR_FILENO, message.as_ptr().cast(), message.len());
                libc::_exit(101);
            }
        }
    }

    pub(super) struct Guard {
        peer: UnixStream,
        _socket: UnixStream,
        signal: libc::c_int,
        previous: libc::sigaction,
    }
    impl Guard {
        pub(super) fn install(signal: libc::c_int) -> Self {
            let (peer, socket) = UnixStream::pair().unwrap();
            peer.set_read_timeout(Some(WAIT)).unwrap();
            peer.set_write_timeout(Some(WAIT)).unwrap();
            socket.set_read_timeout(Some(WAIT)).unwrap();
            socket.set_write_timeout(Some(WAIT)).unwrap();
            SOCKET.store(socket.as_raw_fd(), Ordering::Release);
            // SAFETY: both actions are initialized and the process is isolated.
            let previous = unsafe {
                let mut previous: libc::sigaction = std::mem::zeroed();
                assert_eq!(libc::sigaction(signal, std::ptr::null(), &mut previous), 0);
                HANDLER.store(previous.sa_sigaction, Ordering::Release);
                let mut action = previous;
                action.sa_sigaction = delayed as *const () as usize;
                assert_eq!(libc::sigaction(signal, &action, std::ptr::null_mut()), 0);
                previous
            };
            Self {
                peer,
                _socket: socket,
                signal,
                previous,
            }
        }

        pub(super) fn wait_until_entered(&mut self) {
            let mut thread = [0; std::mem::size_of::<libc::pid_t>()];
            self.peer
                .read_exact(&mut thread)
                .expect("delayed handler did not report entry before its deadline");
            // SAFETY: getpid only reads this process's ID.
            assert_eq!(
                i32::from_ne_bytes(thread),
                unsafe { libc::getpid() },
                "the delayed signal must run on the main thread"
            );
        }

        pub(super) fn release(&mut self) {
            self.peer
                .write_all(&[1])
                .expect("release delayed handler before its deadline");
            self.peer
                .read_exact(&mut [0])
                .expect("delayed handler did not acknowledge release before its deadline");
        }
    }
    impl Drop for Guard {
        fn drop(&mut self) {
            // Unblock the handler on assertion failure without waiting during unwinding.
            let _ = self.peer.write_all(&[1]);
            // SAFETY: the saved action came from sigaction for this same signal.
            unsafe {
                libc::sigaction(self.signal, &self.previous, std::ptr::null_mut());
            }
        }
    }
}

#[test]
#[ignore = "subprocess helper installs process-wide signal handlers"]
#[cfg(target_os = "linux")]
fn interrupted_handler_helper() {
    use std::sync::atomic::{AtomicBool, Ordering};
    static NESTED: AtomicBool = AtomicBool::new(false);
    extern "C" fn nested(_: libc::c_int) {
        NESTED.store(true, Ordering::Relaxed);
    }

    // SAFETY: this isolated fixture installs a handler that only sets an atomic.
    let main = unsafe {
        let mut action: libc::sigaction = std::mem::zeroed();
        action.sa_sigaction = nested as *const () as usize;
        libc::sigemptyset(&mut action.sa_mask);
        assert_eq!(
            libc::sigaction(libc::SIGUSR1, &action, std::ptr::null_mut()),
            0
        );
        libc::getpid()
    };
    let _signals = interruption::Guard::install().unwrap();
    let mut delayed = delayed_handler::Guard::install(libc::SIGTERM);
    // SAFETY: both signals target this fixture's main thread.
    assert_eq!(unsafe { libc::tgkill(main, main, libc::SIGTERM) }, 0);
    delayed.wait_until_entered();
    let wait_for_poll = || {
        let deadline = Instant::now() + WAIT;
        while !fs::read_to_string(format!("/proc/self/task/{main}/wchan"))
            .unwrap()
            .contains("poll")
        {
            assert!(
                Instant::now() < deadline,
                "delayed handler did not enter its bounded poll"
            );
            std::thread::yield_now();
        }
    };
    wait_for_poll();
    assert_eq!(unsafe { libc::tgkill(main, main, libc::SIGUSR1) }, 0);
    let deadline = Instant::now() + WAIT;
    while !NESTED.load(Ordering::Relaxed) {
        assert!(
            Instant::now() < deadline,
            "nested signal handler did not run"
        );
        std::thread::yield_now();
    }
    wait_for_poll();
    assert!(
        interruption::check().is_ok(),
        "EINTR released the handler barrier early"
    );
    delayed.release();
    assert!(interruption::check().is_err());
}

#[test]
#[cfg(target_os = "linux")]
fn an_interrupted_handler_wait_keeps_the_barrier_until_release() {
    let mut worker = Worker::spawn(
        std::process::Command::new(std::env::current_exe().unwrap()).args([
            "--ignored",
            "--exact",
            "commands::recipe::operations::tests::interrupted_handler_helper",
        ]),
    );
    let status = worker
        .wait(WAIT)
        .expect("nested signal fixture exceeded its exit deadline");
    assert!(
        status.success(),
        "nested signal fixture exited with {status}"
    );
}

#[test]
#[ignore = "subprocess helper installs process-wide signal handlers"]
#[cfg(target_os = "linux")]
fn interruption_helper() {
    let root = std::env::var_os("ORBIT_RECIPE_INTERRUPT_TEST").unwrap();
    let files = Files {
        root: root.into(),
        uid: rustix::process::getuid().as_raw(),
        gid: rustix::process::getgid().as_raw(),
    };
    // SAFETY: these calls only read the current thread and process IDs.
    assert_ne!(
        unsafe { libc::gettid() },
        unsafe { libc::getpid() },
        "the command must run on a non-main thread, like run_in_process"
    );
    let required = std::env::var("ORBIT_RECIPE_INTERRUPT_REQUIRED").unwrap() == "true";
    let _signals = interruption::Guard::install().unwrap();
    let delayed = (std::env::var("ORBIT_RECIPE_DELAY_HANDLER").unwrap() == "true").then(|| {
        delayed_handler::Guard::install(
            std::env::var("ORBIT_RECIPE_INTERRUPT_SIGNAL")
                .unwrap()
                .parse()
                .unwrap(),
        )
    });
    let _policy = Policy::install(&files).unwrap();
    let context = Context::new(Platform::Linux, Instant::now() + WAIT);
    let mut host = Native(&context);
    let binary = std::env::current_exe().unwrap();
    let call = Invocation::new(&[
        binary.to_str().unwrap(),
        "--ignored",
        "--exact",
        "commands::recipe::operations::tests::interruption_command_helper",
    ]);
    let result = super::super::run(&mut host, &call, required);
    if let Some(mut delayed) = delayed {
        delayed.wait_until_entered();
        // The result is settled before the real handler can set the flag.
        delayed.release();
    }
    let error = result.unwrap_err();
    assert_eq!(error.stage, "interrupted", "{error:?}");
}

#[test]
#[ignore = "subprocess helper installs process-wide signal handlers"]
fn interruption_before_execution_helper() {
    for signal in [libc::SIGHUP, libc::SIGINT, libc::SIGTERM] {
        let (_work, files, mut host) = fixture();
        let _signals = interruption::Guard::install().unwrap();
        let policy = Policy::install(&files).unwrap();
        // SAFETY: this isolated process has installed the signal guard.
        assert_eq!(unsafe { libc::raise(signal) }, 0);
        // The recorder shows that run started no command without checking interruption first.
        let error =
            super::super::run(&mut host, &Invocation::new(&["must-not-start"]), true).unwrap_err();
        assert_eq!(error.stage, "interrupted", "signal {signal}: {error:?}");
        assert!(host.calls.is_empty());
        drop(policy);
        assert!(!files.path("/usr/sbin/policy-rc.d").exists());
    }
}

#[test]
fn interruption_before_execution_starts_no_command_and_unwinds_the_restart_policy() {
    let mut worker = Worker::spawn(
        std::process::Command::new(std::env::current_exe().unwrap()).args([
            "--ignored",
            "--exact",
            "commands::recipe::operations::tests::interruption_before_execution_helper",
        ]),
    );
    let status = worker
        .wait(WAIT)
        .expect("interruption fixture exceeded its deadline");
    assert!(
        status.success(),
        "interruption fixture exited with {status}"
    );
}

#[test]
#[ignore = "subprocess helper installs process-wide signal handlers"]
fn interruption_after_execution_helper() {
    struct AfterCommand<'a> {
        native: Native<'a>,
        signal: i32,
    }
    impl Host for AfterCommand<'_> {
        fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
            let output = self.native.execute(call)?;
            assert_eq!(output.code, 0);
            assert_eq!(output.signal, None);
            // SAFETY: the isolated process has installed the signal guard. The
            // real command has already exited, so only the handler sees this signal.
            assert_eq!(unsafe { libc::raise(self.signal) }, 0);
            Ok(output)
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            panic!("no retries")
        }
    }
    for required in [true, false] {
        for signal in [libc::SIGHUP, libc::SIGINT, libc::SIGTERM] {
            let (_work, files, _) = fixture();
            let _signals = interruption::Guard::install().unwrap();
            let policy = Policy::install(&files).unwrap();
            let context = Context::new(Platform::Linux, Instant::now() + WAIT);
            let mut host = AfterCommand {
                native: Native(&context),
                signal,
            };
            let error =
                super::super::run(&mut host, &Invocation::new(&["/usr/bin/true"]), required)
                    .unwrap_err();
            assert_eq!(
                error.stage, "interrupted",
                "signal {signal}, required={required}: {error:?}"
            );
            drop(policy);
            assert!(!files.path("/usr/sbin/policy-rc.d").exists());
        }
    }
}

#[test]
fn interruption_after_execution_stops_the_recipe_and_unwinds_the_restart_policy() {
    let mut worker = Worker::spawn(
        std::process::Command::new(std::env::current_exe().unwrap()).args([
            "--ignored",
            "--exact",
            "commands::recipe::operations::tests::interruption_after_execution_helper",
        ]),
    );
    let status = worker
        .wait(WAIT)
        .expect("interruption fixture exceeded its deadline");
    assert!(
        status.success(),
        "interruption fixture exited with {status}"
    );
}

#[test]
#[cfg(target_os = "linux")]
fn service_termination_unwinds_the_temporary_package_restart_policy() {
    termination_unwinds(true, libc::SIGTERM, false);
}

#[test]
#[cfg(target_os = "linux")]
fn process_termination_before_handler_dispatch_interrupts_a_required_command() {
    for signal in [libc::SIGHUP, libc::SIGINT, libc::SIGTERM] {
        termination_unwinds(true, signal, true);
    }
}

#[test]
#[cfg(target_os = "linux")]
fn process_termination_before_handler_dispatch_interrupts_an_optional_command() {
    for signal in [libc::SIGHUP, libc::SIGINT, libc::SIGTERM] {
        termination_unwinds(false, signal, true);
    }
}

#[cfg(target_os = "linux")]
fn termination_unwinds(required: bool, signal: i32, delay_handler: bool) {
    use std::os::unix::net::UnixDatagram;

    let work = WorkDir::new().unwrap();
    fs::create_dir_all(work.0.join("usr/sbin")).unwrap();
    let path = work.0.join("ready.sock");
    let ready = UnixDatagram::bind(&path).unwrap();
    ready.set_read_timeout(Some(WAIT)).unwrap();
    let mut command = std::process::Command::new(std::env::current_exe().unwrap());
    command
        .args([
            "--ignored",
            "--exact",
            "commands::recipe::operations::tests::interruption_helper",
        ])
        .env("ORBIT_RECIPE_INTERRUPT_TEST", &work.0)
        .env("ORBIT_RECIPE_INTERRUPT_SOCKET", &path)
        .env("ORBIT_RECIPE_INTERRUPT_REQUIRED", required.to_string())
        .env("ORBIT_RECIPE_INTERRUPT_SIGNAL", signal.to_string())
        .env("ORBIT_RECIPE_DELAY_HANDLER", delay_handler.to_string());
    let mut worker = Worker::spawn(&mut command);
    let mut message = [0; std::mem::size_of::<i32>()];
    let received = ready
        .recv(&mut message)
        .expect("the supervised child did not report readiness after exec");
    assert_eq!(received, message.len(), "invalid readiness message");
    let group = i32::from_ne_bytes(message);
    assert!(group > 0);
    assert_ne!(group, worker.id());
    worker.command_group = Some(group);
    assert!(work.0.join("usr/sbin/policy-rc.d").exists());
    // The command owns a separate process group. Systemd kills both groups in the service cgroup.
    // SAFETY: readiness identifies the supervisor group owned by this test.
    unsafe {
        if delay_handler {
            // Process-wide kill may choose the recipe thread, which must remain
            // free to release the test barrier. Pin only the delayed test signal.
            assert_eq!(libc::tgkill(worker.id(), worker.id(), signal), 0);
        } else {
            assert_eq!(libc::kill(-worker.id(), signal), 0);
        }
        assert_eq!(libc::kill(-group, signal), 0);
    }
    let status = worker
        .wait(WAIT)
        .expect("interruption helper exceeded its exit deadline");
    assert!(status.success(), "interruption helper exited with {status} (required={required}, signal={signal}, delay_handler={delay_handler})");
    assert!(!work.0.join("usr/sbin/policy-rc.d").exists());
    worker.command_group = None;
}
