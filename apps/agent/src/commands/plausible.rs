//! Plausible's container startup, under the image's own account and environment.
use orbit_agent_contract::{Platform, Service};
use std::{
    os::unix::process::{CommandExt, ExitStatusExt},
    path::Path,
    process::Command,
    time::Duration,
};
use tokio::{
    process::Child,
    signal::unix::{signal, SignalKind},
};

const ENTRYPOINT: &str = "/entrypoint.sh";
pub struct Plausible;
impl Service for Plausible {
    const NAME: &'static str = "plausible";
    fn supported(platform: Platform) -> bool {
        platform == Platform::Linux
    }
    fn run(arguments: &[String]) -> i32 {
        if !arguments.is_empty() || crate::command::identity::effective_uid() == 0 {
            eprintln!("Plausible startup requires the image's non-root account and no arguments.");
            return 64;
        }
        let Ok(runtime) = tokio::runtime::Builder::new_current_thread()
            .enable_all()
            .build()
        else {
            eprintln!("Plausible startup could not create its runtime.");
            return 1;
        };
        let code = initialize(|args| runtime.block_on(step(Path::new(ENTRYPOINT), args)));
        drop(runtime);
        if code != 0 {
            return code;
        }
        let _error = Command::new(ENTRYPOINT).arg("run").exec();
        eprintln!("Plausible could not start its server.");
        127
    }
}

fn initialize(mut run: impl FnMut(&[&str]) -> i32) -> i32 {
    for args in [["db", "createdb"], ["db", "migrate"]] {
        let code = run(&args);
        if code != 0 {
            return code;
        }
    }
    0
}

async fn step(program: &Path, args: &[&str]) -> i32 {
    let (Ok(mut terminate), Ok(mut interrupt)) = (
        signal(SignalKind::terminate()),
        signal(SignalKind::interrupt()),
    ) else {
        eprintln!("Plausible startup could not receive stop signals.");
        return 1;
    };
    let Ok(mut child) = tokio::process::Command::new(program)
        .args(args)
        .process_group(0)
        .kill_on_drop(true)
        .spawn()
    else {
        eprintln!("Plausible could not start its database initialization.");
        return 127;
    };
    tokio::select! {
        biased;
        _ = terminate.recv() => stop(&mut child, libc::SIGTERM).await,
        _ = interrupt.recv() => stop(&mut child, libc::SIGINT).await,
        status = child.wait() => status.ok().and_then(|status| status.code().or_else(|| status.signal().map(|signal| 128 + signal))).unwrap_or(1),
    }
}

async fn stop(child: &mut Child, signal: i32) -> i32 {
    if let Some(pid) = child.id() {
        // SAFETY: this child started a new process group; initialization descendants receive the same stop.
        unsafe {
            libc::kill(-(pid as libc::pid_t), signal);
        }
        if tokio::time::timeout(Duration::from_secs(5), child.wait())
            .await
            .is_err()
        {
            // SAFETY: the still-unreaped child owns this process group.
            unsafe {
                libc::kill(-(pid as libc::pid_t), libc::SIGKILL);
            }
            let _ = child.wait().await;
        }
    }
    128 + signal
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn initializes_in_order_on_every_start_and_stops_at_each_failed_step() {
        for failure in [None, Some(0), Some(1)] {
            for _ in 0..2 {
                let mut calls = Vec::new();
                let code = initialize(|args| {
                    let index = calls.len();
                    calls.push(args.join(" "));
                    if failure == Some(index) {
                        7
                    } else {
                        0
                    }
                });
                assert_eq!(code, if failure.is_some() { 7 } else { 0 });
                assert_eq!(
                    calls,
                    if failure == Some(0) {
                        vec!["db createdb"]
                    } else {
                        vec!["db createdb", "db migrate"]
                    }
                );
            }
        }
    }
    #[test]
    fn refuses_arguments_before_starting_an_image_program() {
        assert_eq!(Plausible::run(&["untrusted".into()]), 64);
    }

    #[tokio::test]
    async fn stopping_initialization_signals_and_reaps_its_child() {
        let mut child = tokio::process::Command::new("/bin/sleep")
            .arg("30")
            .process_group(0)
            .kill_on_drop(true)
            .spawn()
            .unwrap();
        assert_eq!(stop(&mut child, libc::SIGTERM).await, 143);
        assert!(child.try_wait().unwrap().is_some());
    }
}
