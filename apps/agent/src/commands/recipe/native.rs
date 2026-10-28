//! Direct child supervision retains the signal that ended a recipe command.
use super::{Failure, Invocation, Output};
use crate::command::Context;
use std::{
    os::unix::process::{CommandExt, ExitStatusExt},
    process::{Command, Stdio},
    sync::{
        atomic::{AtomicBool, Ordering},
        mpsc,
    },
    time::{Duration, Instant},
};

pub(super) fn execute(context: &Context, invocation: &Invocation) -> Result<Output, Failure> {
    let deadline = context.deadline().min(Instant::now() + invocation.timeout);
    if deadline <= Instant::now() {
        return Ok(Output {
            code: 124,
            ..Output::default()
        });
    }
    let mut command = Command::new("/usr/bin/env");
    command
        .args(
            invocation
                .environment
                .iter()
                .map(|(key, value)| format!("{key}={value}")),
        )
        .args(&invocation.argv)
        .process_group(0)
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped());
    let child = command.spawn().map_err(|error| Failure::new("", error))?;
    let group = child.id() as libc::pid_t;
    let expired = AtomicBool::new(false);
    let (finished, wait) = mpsc::channel();
    let captured = std::thread::scope(|scope| {
        let expired = &expired;
        scope.spawn(move || {
            if wait
                .recv_timeout(deadline.saturating_duration_since(Instant::now()))
                .is_ok()
            {
                return;
            }
            expired.store(true, Ordering::Relaxed);
            // SAFETY: the spawned child owns this process group, including its descendants.
            unsafe {
                libc::kill(-group, libc::SIGTERM);
            }
            if wait.recv_timeout(Duration::from_secs(1)).is_err() {
                // SAFETY: this is the same command group still awaiting completion.
                unsafe {
                    libc::kill(-group, libc::SIGKILL);
                }
            }
        });
        let result = super::super::incus::exchange(child, None);
        let _ = finished.send(());
        result
    });
    let (status, stdout, stderr) = captured.map_err(|error| Failure::new("", error))?;
    let expired = expired.load(Ordering::Relaxed);
    let output = super::super::incus::Output::captured(status, stdout, stderr);
    Ok(Output {
        code: if expired { 124 } else { output.code },
        signal: if expired { None } else { status.signal() },
        truncated: output.truncated,
        stdout: output.stdout,
        stderr: output.stderr,
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::{
        command::Platform,
        commands::recipe::{run, Native},
    };

    fn context() -> Context {
        Context::new(Platform::Linux, Instant::now() + Duration::from_secs(30))
    }

    #[test]
    #[ignore = "subprocess fixture exits with the requested status"]
    fn exit_helper() {
        if std::env::var("ORBIT_RECIPE_NATIVE_EXIT").unwrap() == "signal" {
            // SAFETY: only this isolated fixture process is killed.
            unsafe {
                libc::raise(libc::SIGKILL);
            }
        }
        std::process::exit(143);
    }

    #[test]
    fn numeric_exit_codes_and_other_signals_keep_required_and_optional_semantics() {
        let binary = std::env::current_exe().unwrap();
        for (exit, expected_code, expected_signal) in
            [("code", 143, None), ("signal", -1, Some(libc::SIGKILL))]
        {
            let call = Invocation::new(&[
                binary.to_str().unwrap(),
                "--ignored",
                "--exact",
                "commands::recipe::native::tests::exit_helper",
            ])
            .env("ORBIT_RECIPE_NATIVE_EXIT", exit);
            let context = context();
            let mut native = Native(&context);
            let output = run(&mut native, &call, false).unwrap();
            assert_eq!(
                (output.code, output.signal),
                (expected_code, expected_signal)
            );
            let error = run(&mut native, &call, true).unwrap_err();
            assert_eq!(error.stage, "");
            assert_eq!(error.diagnostic.unwrap().exit_code, expected_code);
        }
    }

    #[test]
    fn native_capture_keeps_environment_and_output_bounds() {
        let context = context();
        let output = execute(
            &context,
            &Invocation::new(&["/usr/bin/printenv", "ORBIT_RECIPE_NATIVE_ENV"])
                .env("ORBIT_RECIPE_NATIVE_ENV", "kept"),
        )
        .unwrap();
        assert_eq!(
            (output.code, output.signal, output.stdout.as_str()),
            (0, None, "kept\n")
        );
        let large = execute(
            &context,
            &Invocation::new(&["/usr/bin/head", "-c", "2097152", "/dev/zero"]),
        )
        .unwrap();
        assert!(large.truncated);
        assert_eq!(
            large.stdout.len(),
            super::super::super::incus::MAX_OUTPUT + 1
        );
        let invalid = execute(&context, &Invocation::new(&["/usr/bin/printf", "\\377"])).unwrap();
        assert!(invalid.truncated);
    }

    #[test]
    #[ignore = "subprocess fixture ignores TERM before spawning its command"]
    fn deadline_helper() {
        // SAFETY: only this isolated process and its subsequent child inherit SIG_IGN.
        unsafe {
            libc::signal(libc::SIGTERM, libc::SIG_IGN);
        }
        let context = context();
        let mut call = Invocation::new(&["/usr/bin/sleep", "60"]);
        call.timeout = Duration::from_millis(10);
        let output = execute(&context, &call).unwrap();
        assert_eq!((output.code, output.signal), (124, None));
    }

    #[test]
    fn deadline_kills_a_term_ignoring_command_without_reporting_interruption() {
        let mut worker = super::super::test_process::Worker::spawn(
            Command::new(std::env::current_exe().unwrap()).args([
                "--ignored",
                "--exact",
                "commands::recipe::native::tests::deadline_helper",
            ]),
        );
        let status = worker
            .wait(super::super::test_process::WAIT)
            .expect("deadline fixture exceeded its exit deadline");
        assert!(status.success(), "deadline fixture exited with {status}");
        // An already-expired budget starts no executable.
        let context = Context::new(Platform::Linux, Instant::now());
        let output = execute(&context, &Invocation::new(&["must-not-start"])).unwrap();
        assert_eq!((output.code, output.signal), (124, None));
    }
}
