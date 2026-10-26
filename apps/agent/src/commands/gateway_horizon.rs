//! Stop consumers before publishing a new release. systemd owns the stop, so a lost caller leaves it held.
use super::{
    gateway_scheduler::{self, DrainInput, Input},
    recipe::{self, Files, Invocation},
};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::Serialize;
use std::{
    fs, io,
    os::unix::fs::{MetadataExt, OpenOptionsExt},
    path::Path,
    time::{Duration, Instant},
};

command_errors! {
    pub enum Error {
        NotDrained { stage: String } => "gateway.release_horizon_not_drained", "Horizon has not drained. The serving release stays unchanged; inspect its Process journal before resuming.";
    }
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Done {
    drained: bool,
}

// Keep this policy while a stop is pending, including after the command's deadline or a lost caller.
const POLICY: &str = "# Orbit Horizon release drain\n[Service]\nKillMode=mixed\nKillSignal=SIGTERM\nSendSIGHUP=no\nSendSIGKILL=no\nTimeoutStopSec=infinity\n";
trait Host: recipe::Host {
    fn now(&self) -> Instant;
}
impl Host for recipe::Native<'_> {
    fn now(&self) -> Instant {
        Instant::now()
    }
}
fn failed(stage: &str) -> Error {
    Error::NotDrained {
        stage: stage.into(),
    }
}
fn ctl(host: &mut impl Host, args: &[&str]) -> Result<String, Error> {
    let mut call = Invocation::new(&[&["/usr/bin/systemctl"][..], args].concat());
    call.timeout = Duration::from_secs(10);
    Ok(recipe::run(host, &call, true)
        .map_err(|_| failed("systemctl"))?
        .stdout
        .trim()
        .into())
}
fn property(host: &mut impl Host, unit: &str, name: &str) -> Result<String, Error> {
    ctl(host, &["show", "--property", name, "--value", unit])
}
fn directory(files: &Files, path: &Path) -> Result<(), Error> {
    match fs::create_dir(path) {
        Ok(()) => (),
        Err(e) if e.kind() == io::ErrorKind::AlreadyExists => (),
        Err(_) => return Err(failed("policy_directory")),
    }
    let meta = fs::symlink_metadata(path).map_err(|_| failed("policy_directory"))?;
    if !meta.is_dir() || meta.uid() != files.uid || meta.mode() & 0o022 != 0 {
        return Err(failed("policy_directory"));
    }
    Ok(())
}
fn drain(files: &Files, host: &mut impl Host, input: &DrainInput) -> Result<Done, Error> {
    if input.drain_seconds == 0 || input.drain_seconds > 3600 {
        return Err(failed("deadline"));
    }
    gateway_scheduler::checked(
        files,
        &Input {
            process_id: input.process_id,
            name: input.name.clone(),
        },
    )
    .map_err(|_| failed("unit"))?;
    let root = files.path("/run/systemd");
    directory(files, &root)?;
    let root = root.join("system");
    directory(files, &root)?;
    let dir = root.join(format!("{}.d", input.name));
    directory(files, &dir)?;
    let policy = dir.join("zz-orbit-horizon-drain.conf");
    match fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o644)
        .open(&policy)
    {
        Ok(mut file) => {
            use std::io::Write;
            file.write_all(POLICY.as_bytes())
                .map_err(|_| failed("policy"))?;
            file.sync_all().map_err(|_| failed("policy"))?;
        }
        Err(e) if e.kind() == io::ErrorKind::AlreadyExists => {
            let meta = fs::symlink_metadata(&policy).map_err(|_| failed("policy"))?;
            if !meta.is_file()
                || meta.uid() != files.uid
                || meta.mode() & 0o022 != 0
                || fs::read_to_string(&policy).map_err(|_| failed("policy"))? != POLICY
            {
                return Err(failed("policy"));
            }
        }
        Err(_) => return Err(failed("policy")),
    }
    ctl(host, &["daemon-reload"])?;
    for (name, expected) in [
        ("KillMode", "mixed"),
        ("KillSignal", "15"),
        ("SendSIGHUP", "no"),
        ("SendSIGKILL", "no"),
        ("TimeoutStopUSec", "infinity"),
    ] {
        if property(host, &input.name, name)? != expected {
            return Err(failed("stop_policy"));
        }
    }
    let group = property(host, &input.name, "ControlGroup")?;
    if !group.is_empty() && group != format!("/system.slice/{}", input.name) {
        return Err(failed("cgroup"));
    }
    // A missing unit cgroup proves emptiness only on an observable cgroup v2 hierarchy.
    fs::read_to_string(files.path("/sys/fs/cgroup/cgroup.controllers"))
        .map_err(|_| failed("cgroup"))?;
    // An explicit stop suppresses Restart=always, including if the caller disappears before switching.
    ctl(host, &["stop", "--no-block", &input.name])?;
    let deadline = host.now() + Duration::from_secs(input.drain_seconds);
    loop {
        let state = property(host, &input.name, "ActiveState")?;
        // Read the original unit's complete cgroup, including descendants, even after MainPID becomes zero.
        let path = files.path(&format!(
            "/sys/fs/cgroup/system.slice/{}/cgroup.events",
            input.name
        ));
        let empty = match fs::read_to_string(path) {
            Ok(events) if events.lines().any(|line| line == "populated 0") => true,
            Ok(events) if events.lines().any(|line| line == "populated 1") => false,
            Ok(_) => return Err(failed("cgroup")),
            Err(e) if e.kind() == io::ErrorKind::NotFound => true,
            Err(_) => return Err(failed("cgroup")),
        };
        if state == "inactive" && empty {
            break;
        }
        if !["active", "activating", "deactivating", "inactive", "failed"].contains(&state.as_str())
        {
            return Err(failed("state"));
        }
        if host.now() >= deadline {
            return Err(failed("deadline"));
        }
        host.wait(Duration::from_millis(100))
            .map_err(|_| failed("wait"))?;
    }
    fs::remove_file(policy).map_err(|_| failed("policy_cleanup"))?;
    ctl(host, &["daemon-reload"])?;
    Ok(Done { drained: true })
}
pub struct Drain;
impl Command for Drain {
    const NAME: &'static str = "gateway.drain-horizon";
    const SUMMARY: &'static str =
        "Gracefully stop the source Horizon Process before changing the serving release.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(3720);
    type Input = DrainInput;
    type Output = Done;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(input: &DrainInput) -> Option<String> {
        Some(format!("process:{}", input.process_id))
    }
    fn run(input: DrainInput, context: &Context) -> Result<Done, Error> {
        drain(&Files::system(), &mut recipe::Native(context), &input)
    }
}
#[cfg(test)]
mod tests;
