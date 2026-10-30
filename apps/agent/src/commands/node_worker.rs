//! Declarative Node worker installation. Swamp owns execution and token lifecycle.
use super::recipe::{self, store::Account, Files, Host, Invocation};
use crate::{command::Context, command_errors, NodeWorkerConfig};
use std::{
    fs,
    os::unix::fs::{MetadataExt, PermissionsExt},
};

pub const VERSION: &str = "20261028.205235.0-sha.1602a617";
const DIGEST: &str = "38b81986c7203f1e93806fc7b6d38e560a86456ea755a4369a89b17b64d4fbe3";
const URL: &str = "https://github.com/swamp-club/swamp/releases/download/v20261028.205235.0-sha.1602a617/swamp-linux-x86_64";
const BINARY: &str = recipe::swamp::BINARY;
const ROOT: &str = "/var/lib/orbit-node-worker";
const CREDENTIALS: &str = "/etc/orbit/swamp-worker";
const UNIT: &str = "orbit-node-worker.service";
const UNIT_PATH: &str = "/etc/systemd/system/orbit-node-worker.service";
const CA: &str = "/usr/local/share/ca-certificates/orbit-root.crt";

command_errors! { pub enum Error {
    Failed {} => "swamp.node_worker_failed", "The Node worker installation failed.";
    Invalid {} => "swamp.node_worker_invalid", "The Node worker identity or custody is invalid.";
}}
fn failed() -> Error {
    Error::Failed {}
}
fn invalid() -> Error {
    Error::Invalid {}
}
fn call(host: &mut impl Host, args: &[&str]) -> Result<(), Error> {
    recipe::run(host, &Invocation::new(args), true).map_err(|_| failed())?;
    Ok(())
}
fn owned_file(files: &Files, path: &str, mode: u32) -> Result<(), Error> {
    let m = fs::symlink_metadata(files.path(path)).map_err(|_| invalid())?;
    if !m.is_file() || m.uid() != files.uid || m.mode() & 0o777 != mode {
        return Err(invalid());
    }
    Ok(())
}
fn private_dir(files: &Files, path: &str, uid: u32, gid: u32) -> Result<(), Error> {
    let path = files.path(path);
    files.directory(&path, uid, gid).map_err(|_| invalid())?;
    fs::set_permissions(path, fs::Permissions::from_mode(0o700)).map_err(|_| failed())
}
pub(super) fn valid(input: &NodeWorkerConfig) -> bool {
    input.node_id > 0
        && input.user.len() <= 32
        && input
            .user
            .bytes()
            .next()
            .is_some_and(|c| c.is_ascii_lowercase() || c == b'_')
        && input
            .user
            .bytes()
            .all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || b"_-".contains(&c))
        && input.user != "root"
        && input.user != "orbit-swamp-package"
}
fn unit(input: &NodeWorkerConfig) -> String {
    format!("[Unit]\nDescription=Orbit Swamp worker for Node {}\nAfter=network-online.target\nWants=network-online.target\nConditionPathExists={CREDENTIALS}/enrollment.token\nConditionPathExists={CREDENTIALS}/server.token\n\n[Service]\nType=exec\nUser={}\nWorkingDirectory={ROOT}\nEnvironment=HOME={ROOT} SWAMP_HOME={ROOT}/home SWAMP_CONFIG_DIR={ROOT}/config\nLoadCredential=enrollment:{CREDENTIALS}/enrollment.token\nLoadCredential=server:{CREDENTIALS}/server.token\nExecStart={BINARY} worker connect wss://swamp.orbit --token-file=%d/enrollment --server-token-file=%d/server --ca-cert={CA} --cache-dir={ROOT}/cache --label node={}\nRestart=on-failure\nRestartSec=5\nKillMode=mixed\nSendSIGKILL=no\nTimeoutStopSec=infinity\nUMask=0077\nStandardOutput=null\nStandardError=null\n\n[Install]\nWantedBy=multi-user.target\n", input.node_id, input.user, input.node_id)
}
fn publish(
    files: &Files,
    host: &mut impl Host,
    input: &NodeWorkerConfig,
    account: &Account,
    binary_changed: bool,
) -> Result<(), Error> {
    if !valid(input) || account.name != input.user || account.uid == 0 || account.gid == 0 {
        return Err(invalid());
    }
    // Verify existing authority; never add a sudo rule or silently use another account.
    call(
        host,
        &[
            "/usr/sbin/runuser",
            "-u",
            &account.name,
            "--",
            "/usr/bin/sudo",
            "-n",
            "/usr/bin/true",
        ],
    )?;
    for name in ["enrollment.token", "server.token"] {
        let path = format!("{CREDENTIALS}/{name}");
        if fs::symlink_metadata(files.path(&path)).is_ok() {
            owned_file(files, &path, 0o600)?;
        }
    }
    private_dir(files, CREDENTIALS, files.uid, files.gid)?;
    private_dir(files, ROOT, account.uid, account.gid)?;
    let text = unit(input);
    let path = files.path(UNIT_PATH);
    if fs::symlink_metadata(&path).is_ok() {
        owned_file(files, UNIT_PATH, 0o644)?;
    }
    let changed = binary_changed || fs::read_to_string(&path).ok().as_deref() != Some(&text);
    files
        .publish_root(&path, text.as_bytes())
        .map_err(|_| invalid())?;
    call(host, &["systemctl", "daemon-reload"])?;
    call(host, &["systemctl", "enable", UNIT])?;
    call(
        host,
        &["systemctl", if changed { "restart" } else { "start" }, UNIT],
    )
}
pub fn configure_native(context: &Context, input: Option<&NodeWorkerConfig>) -> Result<(), Error> {
    let files = Files::system();
    let mut host = recipe::Native(context);
    let Some(input) = input else {
        if fs::symlink_metadata(files.path(UNIT_PATH)).is_ok() {
            owned_file(&files, UNIT_PATH, 0o644)?;
            call(&mut host, &["systemctl", "disable", "--now", UNIT])?;
            fs::remove_file(files.path(UNIT_PATH)).map_err(|_| failed())?;
            call(&mut host, &["systemctl", "daemon-reload"])?;
        }
        return Ok(());
    };
    if !valid(input) {
        return Err(invalid());
    }
    let account = Account::named(&input.user).map_err(|_| invalid())?;
    let architecture =
        recipe::run(&mut host, &Invocation::new(&["uname", "-m"]), true).map_err(|_| failed())?;
    if architecture.stdout.trim() != "x86_64" {
        return Err(invalid());
    }
    owned_file(&files, crate::CA_PATH, 0o644)?;
    files
        .publish_root(
            &files.path(CA),
            &fs::read(files.path(crate::CA_PATH)).map_err(|_| failed())?,
        )
        .map_err(|_| invalid())?;
    let binary_changed = owned_file(&files, BINARY, 0o755).is_err()
        || crate::command::run::file_sha256(&files.path(BINARY))
            .ok()
            .as_deref()
            != Some(DIGEST);
    if binary_changed {
        let recipe = serde_json::from_value(serde_json::json!({
            "user": input.user, "snapshot": "20261028T000000Z", "architecture": "amd64",
            "tools": {"swamp": {"source": "artifact", "version": VERSION,
                "artifacts": {"amd64": {"url": URL, "sha256": DIGEST}}}}
        }))
        .map_err(|_| invalid())?;
        recipe::swamp::install(&mut host, &files, &recipe).map_err(|_| failed())?;
    }
    owned_file(&files, BINARY, 0o755)?;
    if crate::command::run::file_sha256(&files.path(BINARY))
        .ok()
        .as_deref()
        != Some(DIGEST)
    {
        return Err(invalid());
    }
    publish(&files, &mut host, input, &account, binary_changed)
}

#[cfg(test)]
mod tests;
