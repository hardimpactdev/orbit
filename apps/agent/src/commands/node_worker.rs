//! Persistent Node worker custody. Package execution belongs to a separate UID.
use super::recipe::{self, store::Account, Files, Host, Invocation};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    fs,
    os::unix::fs::{MetadataExt, PermissionsExt},
    path::Path,
    time::Duration,
};

pub(crate) mod issuer;
pub(crate) mod renewal;
#[cfg(test)]
mod tests;

pub const VERSION: &str = "20261028.205235.0-sha.1602a617";
const DIGEST: &str = "38b81986c7203f1e93806fc7b6d38e560a86456ea755a4369a89b17b64d4fbe3";
const URL: &str = "https://github.com/swamp-club/swamp/releases/download/v20261028.205235.0-sha.1602a617/swamp-linux-x86_64";
const BINARY: &str = "/opt/orbit/swamp-worker/swamp";
const ROOT: &str = "/var/lib/orbit-node-worker";
const USER: &str = "orbit-swamp-worker";
const UNIT: &str = "orbit-node-worker.service";
const CA: &str = "/usr/local/share/ca-certificates/orbit-root.crt";

command_errors! { pub enum Error {
    Failed {} => "swamp.node_worker_failed", "The Node worker lifecycle step failed.";
    Invalid {} => "swamp.node_worker_invalid", "The Node worker identity or custody is invalid.";
}}
fn failed() -> Error {
    Error::Failed {}
}
fn lifecycle_lock(context: &Context) -> Result<crate::command::lock::Held, Error> {
    let directory = crate::command::lock::lock_directory().map_err(|_| failed())?;
    crate::command::lock::acquire(
        &directory.join("swamp-node-worker.lock"),
        context.deadline(),
    )
    .map_err(|_| failed())
}
fn invalid() -> Error {
    Error::Invalid {}
}
fn call(host: &mut impl Host, args: &[&str]) -> Result<String, Error> {
    let output = recipe::run(host, &Invocation::new(args), true).map_err(|_| failed())?;
    Ok(output.stdout)
}
fn owned_file(files: &Files, path: &str, mode: u32) -> Result<(), Error> {
    let m = fs::symlink_metadata(files.path(path)).map_err(|_| invalid())?;
    if !m.is_file() || m.uid() != files.uid || m.mode() & 0o777 != mode {
        return Err(invalid());
    }
    Ok(())
}
fn private_dir(files: &Files, path: &str, uid: u32, gid: u32, mode: u32) -> Result<(), Error> {
    let path = files.path(path);
    files.directory(&path, uid, gid).map_err(|_| invalid())?;
    fs::set_permissions(path, fs::Permissions::from_mode(mode)).map_err(|_| failed())
}
fn pinned(files: &Files) -> bool {
    owned_file(files, BINARY, 0o755).is_ok()
        && crate::command::run::file_sha256(&files.path(BINARY))
            .ok()
            .as_deref()
            == Some(DIGEST)
}
fn install_binary(files: &Files, host: &mut impl Host) -> Result<bool, Error> {
    if pinned(files) {
        return Ok(false);
    }
    private_dir(files, "/opt/orbit", files.uid, files.gid, 0o755)?;
    private_dir(
        files,
        "/opt/orbit/swamp-worker",
        files.uid,
        files.gid,
        0o755,
    )?;
    let candidate = files.path("/opt/orbit/swamp-worker/download");
    // This private candidate can be left by a killed download. Publishing
    // refuses links/foreign owners before replacing its managed content.
    files
        .publish(&candidate, &[], 0o600, files.uid, files.gid, false)
        .map_err(|_| invalid())?;
    let result = (|| {
        call(
            host,
            &[
                "curl",
                "--fail",
                "--location",
                "--proto",
                "=https",
                "--tlsv1.2",
                "--max-time",
                "120",
                "--output",
                candidate.to_str().ok_or_else(invalid)?,
                URL,
            ],
        )?;
        if crate::command::run::file_sha256(&candidate).ok().as_deref() != Some(DIGEST) {
            return Err(invalid());
        }
        let bytes = fs::read(&candidate).map_err(|_| failed())?;
        files
            .publish(
                &files.path(BINARY),
                &bytes,
                0o755,
                files.uid,
                files.gid,
                false,
            )
            .map_err(|_| failed())?;
        Ok(true)
    })();
    let _ = fs::remove_file(candidate);
    result
}

fn worker_unit() -> String {
    format!("[Unit]\nDescription=Orbit Node Swamp worker\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=exec\nUser={USER}\nGroup={USER}\nWorkingDirectory={ROOT}/work\nEnvironment=HOME={ROOT}/home SWAMP_HOME={ROOT}/home SWAMP_CONFIG_DIR={ROOT}/home/config\nLoadCredential=enrollment:{ROOT}/credentials/enrollment\nLoadCredential=server:{ROOT}/credentials/server\nExecStart={BINARY} worker connect wss://swamp.orbit --token-file=%d/enrollment --server-token-file=%d/server --ca-cert={CA} --cache-dir={ROOT}/cache --concurrency=1 --no-telemetry\nRestart=on-failure\nRestartSec=10\nKillMode=mixed\nSendSIGKILL=no\nTimeoutStopSec=infinity\nUMask=0077\nNoNewPrivileges=yes\nProtectSystem=strict\nProtectHome=yes\nPrivateTmp=yes\nReadWritePaths={ROOT}/cache {ROOT}/work {ROOT}/home\nStandardOutput=null\nStandardError=null\n")
}
fn renew_unit() -> &'static str {
    "[Unit]\nDescription=Renew Orbit Node worker credentials\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=oneshot\nUser=root\nExecStart=/usr/local/bin/orbit-agent service swamp-node-worker-renew\nUMask=0077\nStandardOutput=null\nStandardError=null\n"
}
const TIMER: &str = "[Unit]\nDescription=Check Orbit Node worker credential lifetime\n\n[Timer]\nOnBootSec=30\nOnUnitInactiveSec=60\nUnit=orbit-node-worker-renew.service\n\n[Install]\nWantedBy=timers.target\n";

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    node_id: u64,
}
#[derive(schemars::JsonSchema, Serialize, Debug)]
pub struct Output {
    installed: bool,
    changed: bool,
    version: String,
}
fn configure(
    files: &Files,
    host: &mut impl Host,
    account: &Account,
    node: u64,
    binary_changed: bool,
) -> Result<Output, Error> {
    if node == 0
        || account.uid == 0
        || account.gid == 0
        || account.name != USER
        || account.home != Path::new(ROOT).join("home")
    {
        return Err(invalid());
    }
    owned_file(files, crate::CA_PATH, 0o644)?;
    private_dir(
        files,
        "/usr/local/share/ca-certificates",
        files.uid,
        files.gid,
        0o755,
    )?;
    files
        .publish_root(
            &files.path(CA),
            &fs::read(files.path(crate::CA_PATH)).map_err(|_| failed())?,
        )
        .map_err(|_| invalid())?;
    private_dir(files, ROOT, files.uid, files.gid, 0o711)?;
    private_dir(
        files,
        &format!("{ROOT}/credentials"),
        files.uid,
        files.gid,
        0o700,
    )?;
    for dir in ["home", "work", "cache"] {
        private_dir(
            files,
            &format!("{ROOT}/{dir}"),
            account.uid,
            account.gid,
            0o700,
        )?;
    }
    let mut changed = binary_changed;
    for (path, value, mode) in [
        (format!("{ROOT}/node-id"), format!("{node}\n"), 0o600),
        (format!("/etc/systemd/system/{UNIT}"), worker_unit(), 0o644),
        (
            "/etc/systemd/system/orbit-node-worker-renew.service".into(),
            renew_unit().into(),
            0o644,
        ),
        (
            "/etc/systemd/system/orbit-node-worker-renew.timer".into(),
            TIMER.into(),
            0o644,
        ),
    ] {
        if fs::read(files.path(&path)).ok().as_deref() != Some(value.as_bytes()) {
            changed = true;
        }
        files
            .publish(
                &files.path(&path),
                value.as_bytes(),
                mode,
                files.uid,
                files.gid,
                false,
            )
            .map_err(|_| invalid())?;
    }
    call(host, &["systemctl", "daemon-reload"])?;
    if changed {
        call(host, &["systemctl", "stop", "--no-block", UNIT])?;
    }
    call(
        host,
        &[
            "systemctl",
            "enable",
            "--now",
            "orbit-node-worker-renew.timer",
        ],
    )?;
    Ok(Output {
        installed: true,
        changed,
        version: VERSION.into(),
    })
}
pub fn configure_native(node_id: u64, context: &Context) -> Result<Output, Error> {
    if node_id == 0 {
        return Err(invalid());
    }
    let _lock = lifecycle_lock(context)?;
    let files = Files::system();
    let mut host = recipe::Native(context);
    // Reject a pre-existing account with an unrelated home or privilege-bearing groups.
    if call(&mut host, &["getent", "passwd", USER]).is_err() {
        call(
            &mut host,
            &[
                "useradd",
                "--system",
                "--user-group",
                "--home-dir",
                &format!("{ROOT}/home"),
                "--no-create-home",
                "--shell",
                "/usr/sbin/nologin",
                USER,
            ],
        )?;
    }
    let account = Account::named(USER).map_err(|_| invalid())?;
    if account.gid == 0 {
        return Err(invalid());
    }
    let passwd = call(&mut host, &["getent", "passwd", USER])?;
    let fields: Vec<_> = passwd.trim().split(':').collect();
    if fields.len() != 7
        || fields[0] != USER
        || fields[5] != format!("{ROOT}/home")
        || fields[6] != "/usr/sbin/nologin"
    {
        return Err(invalid());
    }
    if call(&mut host, &["id", "-G", USER])?
        .split_whitespace()
        .collect::<Vec<_>>()
        != [account.gid.to_string()]
    {
        return Err(invalid());
    }
    let changed = install_binary(&files, &mut host)?;
    configure(&files, &mut host, &account, node_id, changed)
}

fn remove(files: &Files, host: &mut impl Host) -> Result<(), Error> {
    if !files.path(ROOT).exists() {
        return Ok(());
    }
    let meta = fs::symlink_metadata(files.path(ROOT)).map_err(|_| invalid())?;
    if !meta.is_dir() || meta.uid() != files.uid {
        return Err(invalid());
    }
    for unit in [
        "orbit-node-worker-renew.timer",
        "orbit-node-worker-renew.service",
        UNIT,
    ] {
        let path = format!("/etc/systemd/system/{unit}");
        if files.path(&path).exists() {
            owned_file(files, &path, 0o644)?;
            if unit.ends_with(".timer") {
                call(host, &["systemctl", "disable", "--now", unit])?;
            } else {
                call(host, &["systemctl", "stop", unit])?;
            }
            fs::remove_file(files.path(&path)).map_err(|_| failed())?;
        }
    }
    // A blocking native drain must finish before credentials or agent trust go away.
    for name in [
        "credentials/pair.json",
        "credentials/enrollment",
        "credentials/server",
        "node-id",
    ] {
        let path = format!("{ROOT}/{name}");
        if files.path(&path).exists() {
            owned_file(files, &path, 0o600)?;
            fs::remove_file(files.path(&path)).map_err(|_| failed())?;
        }
    }
    call(host, &["systemctl", "daemon-reload"])?;
    Ok(())
}

pub fn remove_native(context: &Context) -> Result<(), Error> {
    let _lock = lifecycle_lock(context)?;
    remove(&Files::system(), &mut recipe::Native(context))
}
