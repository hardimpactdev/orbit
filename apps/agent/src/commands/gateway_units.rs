//! Fixed Gateway release services, timer and host retention. Input contains intent, never unit text.
use super::recipe::{self, Files, Host, Invocation};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{fs, io, os::unix::fs::MetadataExt, path::Path, time::Duration};

const SERVICE: &str = "orbit-gateway-release.service";
const TIMER: &str = "orbit-gateway-release.timer";
const TEMPLATE: &str = "orbit-gateway-release-run@.service";
const PATH: &str = "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin";
command_errors! {
    pub enum Error {
        Invalid {} => "gateway.release_units_invalid", "The Gateway release unit identity or retention policy is invalid.";
        Install {stage: String} => "gateway.release_units_install_failed", "The Gateway release units could not be converged.";
        Unit {record: u64} => "gateway.release_unit_failed", "The requested Gateway release unit could not be started or observed.";
    }
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Retention {
    pub journal_max_mb: u64,
    pub journal_keep_free_mb: u64,
    pub journal_days: u64,
    pub journal_runtime_max_mb: u64,
    pub log_rotate_mb: u64,
    pub log_rotate_keep: u64,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub application: String,
    pub orbit_home: String,
    pub user: String,
    pub retention: Retention,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RunInput {
    pub record: u64,
}
#[derive(Debug, Serialize)]
pub struct Changed {
    changed: bool,
}
#[derive(Debug, Serialize)]
pub struct State {
    state: String,
}
fn failed(stage: &str) -> Error {
    Error::Install {
        stage: stage.into(),
    }
}
pub(super) fn path(value: &str) -> bool {
    value.starts_with('/')
        && value.len() > 1
        && !value.bytes().any(|b| b.is_ascii_control())
        && value
            .split('/')
            .skip(1)
            .all(|part| !part.is_empty() && part != "." && part != "..")
}
fn validate(input: &Input) -> Result<(), Error> {
    let r = &input.retention;
    if !path(&input.application)
        || !input.application.ends_with("/apps/gateway")
        || !path(&input.orbit_home)
        || input.user == "root"
        || input.user.is_empty()
        || !input
            .user
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || b == b'_' || b == b'-')
        || [
            r.journal_max_mb,
            r.journal_keep_free_mb,
            r.journal_days,
            r.journal_runtime_max_mb,
            r.log_rotate_mb,
            r.log_rotate_keep,
        ]
        .contains(&0)
    {
        return Err(Error::Invalid {});
    }
    Ok(())
}
pub(super) fn directive(value: &str) -> String {
    let mut result = String::new();
    for character in value.chars() {
        if character == '%' {
            result.push_str("%%");
        } else if character <= ' ' || matches!(character, '"' | '\'' | '$' | '\\' | '\x7f') {
            result.push_str(&format!("\\x{:02x}", character as u32));
        } else {
            result.push(character);
        }
    }
    result
}
pub(super) fn argument(value: &str) -> String {
    format!(
        "\"{}\"",
        value
            .replace('\\', "\\\\")
            .replace('"', "\\\"")
            .replace('$', "$$")
            .replace('%', "%%")
    )
}
fn service(input: &Input, home: &str, requested: bool) -> String {
    let (description, command, specifier) = if requested {
        ("Orbit Gateway release %i", "gateway:release:run", " %i")
    } else {
        (
            "Orbit Gateway automatic release",
            "gateway:release:auto",
            "",
        )
    };
    let artisan = argument(&format!("{}/artisan", input.application));
    format!("[Unit]\nDescription={description}\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=oneshot\nUser={}\nWorkingDirectory={}\nEnvironment=ORBIT_HOME={}\nEnvironment=HOME={}\nEnvironment=PATH={PATH}\nEnvironment=LANG=C.UTF-8\nExecStart=\"/usr/bin/php8.5\" {artisan} \"{command}\"{specifier}\nExecStopPost=\"/usr/bin/php8.5\" {artisan} \"gateway:release:settle\"{specifier}\nTimeoutStartSec=3600\n", input.user, directive(&input.application), directive(&input.orbit_home), directive(home))
}
fn render(input: &Input, home: &str) -> Vec<(String, String, &'static str)> {
    let r = &input.retention;
    let log = format!("{}/storage/logs/laravel.log", input.application)
        .replace('\\', "\\\\")
        .replace('"', "\\\"");
    vec![
        (format!("/etc/systemd/system/{SERVICE}"), service(input, home, false), "gateway-release-service"),
        (format!("/etc/systemd/system/{TEMPLATE}"), service(input, home, true), "gateway-release-run-service"),
        (format!("/etc/systemd/system/{TIMER}"), format!("[Unit]\nDescription=Orbit Gateway automatic release timer\n\n[Timer]\nOnCalendar=*-*-* *:*:00\nAccuracySec=1s\nRandomizedDelaySec=10s\nPersistent=false\nUnit={SERVICE}\n\n[Install]\nWantedBy=timers.target\n"), "gateway-release-timer"),
        ("/etc/systemd/journald.conf.d/orbit-retention.conf".into(), format!("# Managed by Orbit.\n[Journal]\nSystemMaxUse={}M\nSystemKeepFree={}M\nRuntimeMaxUse={}M\nMaxRetentionSec={}day\n", r.journal_max_mb, r.journal_keep_free_mb, r.journal_runtime_max_mb, r.journal_days), "gateway-retention-config"),
        ("/etc/logrotate.d/orbit-gateway".into(), format!("# Managed by Orbit. Daily Laravel logs rotate through Laravel itself.\n\"{log}\" {{\n    su {0} {0}\n    daily\n    maxsize {1}M\n    rotate {2}\n    missingok\n    notifempty\n    compress\n    copytruncate\n}}\n", input.user, r.log_rotate_mb, r.log_rotate_keep), "gateway-retention-config"),
        ("/etc/systemd/system/logrotate.timer.d/orbit-retention.conf".into(), "# Managed by Orbit.\n[Timer]\nOnCalendar=\nOnCalendar=hourly\nAccuracySec=1m\nRandomizedDelaySec=0\nPersistent=false\n".into(), "gateway-retention-config"),
    ]
}
pub(super) fn preflight(files: &Files, path: &Path, stage: &str) -> Result<(), Error> {
    for (index, ancestor) in path.ancestors().enumerate() {
        if !ancestor.starts_with(&files.root) {
            break;
        }
        match fs::symlink_metadata(ancestor) {
            Ok(m)
                if m.uid() == files.uid
                    && m.mode() & 0o022 == 0
                    && if index == 0 { m.is_file() } else { m.is_dir() } => {}
            Ok(_) => return Err(failed(stage)),
            Err(e) if e.kind() == io::ErrorKind::NotFound => {}
            Err(_) => return Err(failed(stage)),
        }
    }
    Ok(())
}
fn run(host: &mut impl Host, argv: &[&str], stage: &str) -> Result<(), Error> {
    let mut invocation = Invocation::new(argv);
    invocation.timeout = Duration::from_secs(30);
    recipe::run(host, &invocation, true).map_err(|_| failed(stage))?;
    Ok(())
}
pub(super) fn state(
    host: &mut impl Host,
    verb: &str,
    unit: &str,
    stage: &str,
) -> Result<String, Error> {
    let mut invocation = Invocation::new(&["/usr/bin/systemctl", verb, unit]);
    invocation.timeout = Duration::from_secs(10);
    let result = recipe::run(host, &invocation, false).map_err(|_| failed(stage))?;
    let value = if verb == "is-enabled" && result.code == 4 && result.stdout.trim().is_empty() {
        "not-found"
    } else {
        result.stdout.trim()
    };
    let valid = if verb == "is-enabled" {
        [
            "enabled",
            "enabled-runtime",
            "linked",
            "linked-runtime",
            "alias",
            "static",
            "indirect",
            "disabled",
            "generated",
            "transient",
            "masked",
            "masked-runtime",
            "not-found",
            "bad",
        ]
        .contains(&value)
    } else {
        [
            "active",
            "activating",
            "reloading",
            "deactivating",
            "inactive",
            "failed",
            "unknown",
            "maintenance",
        ]
        .contains(&value)
    };
    if !valid || result.code < 0 || result.code > 4 {
        return Err(failed(stage));
    }
    Ok(value.into())
}
fn masked(host: &mut impl Host, unit: &str) -> Result<bool, Error> {
    Ok(matches!(
        state(host, "is-enabled", unit, "gateway-retention-state")?.as_str(),
        "masked" | "masked-runtime"
    ))
}
fn logrotate(files: &Files, host: &mut impl Host) -> Result<bool, Error> {
    let path = files.path("/usr/sbin/logrotate");
    match fs::metadata(&path) {
        Ok(meta) if meta.is_file() && meta.mode() & 0o111 != 0 => return Ok(false),
        Ok(_) => return Err(failed("gateway-retention-packages")),
        Err(e) if e.kind() == io::ErrorKind::NotFound => {}
        Err(_) => return Err(failed("gateway-retention-packages")),
    }
    let mut masks = Vec::new();
    let mut result = (|| {
        for unit in ["logrotate.service", "logrotate.timer"] {
            if !masked(host, unit)? {
                run(
                    host,
                    &["/usr/bin/systemctl", "mask", "--runtime", unit],
                    "gateway-retention-mask",
                )?;
                masks.push(unit);
            }
        }
        for argv in [
            vec![
                "/usr/bin/apt-get",
                "-o",
                "DPkg::Lock::Timeout=300",
                "update",
            ],
            vec![
                "/usr/bin/apt-get",
                "-o",
                "DPkg::Lock::Timeout=300",
                "install",
                "--yes",
                "--no-install-recommends",
                "--no-remove",
                "--",
                "logrotate",
            ],
        ] {
            let invocation = Invocation::new(&argv)
                .env("DEBIAN_FRONTEND", "noninteractive")
                .env("NEEDRESTART_MODE", "l");
            recipe::run(host, &invocation, true)
                .map_err(|_| failed("gateway-retention-packages"))?;
        }
        Ok(true)
    })();
    for unit in masks {
        if let Err(error) = run(
            host,
            &["/usr/bin/systemctl", "unmask", "--runtime", unit],
            "gateway-retention-unmask",
        ) {
            if result.is_ok() {
                result = Err(error);
            }
        }
    }
    result
}
fn enable(host: &mut impl Host, unit: &str, stage: &str) -> Result<bool, Error> {
    let was_enabled = state(host, "is-enabled", unit, stage)? == "enabled";
    let was_active = state(host, "is-active", unit, stage)? == "active";
    run(
        host,
        &["/usr/bin/systemctl", "enable", "--now", unit],
        stage,
    )?;
    Ok(!was_enabled || !was_active)
}
fn converge(
    files: &Files,
    host: &mut impl Host,
    input: &Input,
    home: &str,
) -> Result<Changed, Error> {
    validate(input)?;
    let desired = render(input, home);
    for (name, _, stage) in &desired {
        preflight(files, &files.path(name), stage)?;
    }
    let mut changed = logrotate(files, host)?;
    for (name, contents, stage) in desired {
        let path = files.path(&name);
        let mut parents: Vec<_> = path
            .parent()
            .unwrap()
            .ancestors()
            .take_while(|p| p.starts_with(&files.root))
            .collect();
        parents.reverse();
        for parent in parents {
            files
                .directory(parent, files.uid, files.gid)
                .map_err(|_| failed(stage))?;
        }
        let same = fs::metadata(&path)
            .is_ok_and(|m| m.mode() & 0o7777 == 0o644 && m.gid() == files.gid)
            && fs::read(&path).ok().as_deref() == Some(contents.as_bytes());
        if !same {
            files
                .publish_root(&path, contents.as_bytes())
                .map_err(|_| failed(stage))?;
            changed = true;
        }
    }
    run(
        host,
        &["/usr/bin/systemctl", "daemon-reload"],
        "gateway-release-reload",
    )?;
    if !masked(host, "logrotate.service")? && !masked(host, "logrotate.timer")? {
        changed |= enable(host, "logrotate.timer", "gateway-retention-enable")?;
    }
    changed |= enable(host, TIMER, "gateway-release-enable")?;
    Ok(Changed { changed })
}
fn run_unit(record: u64) -> Result<String, Error> {
    if record == 0 || record > i64::MAX as u64 {
        return Err(Error::Invalid {});
    }
    Ok(format!("orbit-gateway-release-run@{record}.service"))
}
fn inspect(host: &mut impl Host, input: &RunInput) -> Result<State, Error> {
    let unit = run_unit(input.record)?;
    Ok(State {
        state: state(host, "is-active", &unit, "release-run").map_err(|_| Error::Unit {
            record: input.record,
        })?,
    })
}
fn start(host: &mut impl Host, input: &RunInput) -> Result<Changed, Error> {
    let unit = run_unit(input.record)?;
    let active = matches!(
        inspect(host, input)?.state.as_str(),
        "active" | "activating" | "reloading" | "deactivating"
    );
    if !active {
        run(
            host,
            &["/usr/bin/systemctl", "start", "--no-block", &unit],
            "release-run",
        )
        .map_err(|_| Error::Unit {
            record: input.record,
        })?;
    }
    Ok(Changed { changed: !active })
}
pub struct Converge;
impl Command for Converge {
    const NAME: &'static str = "gateway.converge-release-units";
    const SUMMARY: &'static str =
        "Converge fixed release units and host retention without restarting release services.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(1500);
    const RESOURCES: &'static [&'static str] = &["packages"];
    type Input = Input;
    type Output = Changed;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("gateway-release-units".into())
    }
    fn run(input: Input, context: &Context) -> Result<Changed, Error> {
        validate(&input)?;
        let account = recipe::store::Account::named(&input.user).map_err(|_| Error::Invalid {})?;
        converge(
            &Files::system(),
            &mut recipe::Native(context),
            &input,
            account.home.to_str().ok_or(Error::Invalid {})?,
        )
    }
}
pub struct Start;
impl Command for Start {
    const NAME: &'static str = "gateway.start-release-run";
    const SUMMARY: &'static str = "Request the fixed release unit for one queued record.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(45);
    type Input = RunInput;
    type Output = Changed;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(input: &RunInput) -> Option<String> {
        Some(format!("gateway-release-run:{}", input.record))
    }
    fn run(input: RunInput, context: &Context) -> Result<Changed, Error> {
        start(&mut recipe::Native(context), &input)
    }
}
pub struct Inspect;
impl Command for Inspect {
    const NAME: &'static str = "gateway.inspect-release-run";
    const SUMMARY: &'static str =
        "Read the systemd state of one release record without taking a lock.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(15);
    type Input = RunInput;
    type Output = State;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(input: RunInput, context: &Context) -> Result<State, Error> {
        inspect(&mut recipe::Native(context), &input)
    }
}
#[cfg(test)]
mod tests;
