//! Declarative Node worker installation. Swamp owns execution and token lifecycle.
use super::recipe::{self, store::Account, Files, Host, Invocation};
use crate::{command::Context, command_errors, NodeWorkerConfig};
use std::{
    fs,
    os::unix::fs::{MetadataExt, PermissionsExt},
};

pub const VERSION: &str = "20261028.205235.0-sha.1602a617";
const DIGEST: &str = "38b81986c7203f1e93806fc7b6d38e560a86456ea755a4369a89b17b64d4fbe3";
// Size of the artifact pinned by DIGEST; update together when changing the pin.
const BINARY_BYTES: u64 = 403_378_168;
const MEMORY_HEADROOM: u64 = 256 * 1024 * 1024;
const URL: &str = "https://github.com/swamp-club/swamp/releases/download/v20261028.205235.0-sha.1602a617/swamp-linux-x86_64";
const BINARY: &str = recipe::swamp::BINARY;
const ROOT: &str = "/var/lib/orbit-node-worker";
const CREDENTIALS: &str = "/etc/orbit/swamp-worker";
const UNIT: &str = "orbit-node-worker.service";
const UNIT_PATH: &str = "/etc/systemd/system/orbit-node-worker.service";
const CA: &str = "/usr/local/share/ca-certificates/orbit-root.crt";

command_errors! { pub enum Error {
    Failed {} => "swamp.node_worker_failed", "The Node worker installation failed.";
    NotReady {} => "swamp.node_worker_not_ready", "The Node worker credentials are missing or unsafe.";
    Invalid {} => "swamp.node_worker_invalid", "The Node worker identity or custody is invalid.";
    MemoryLow {} => "swamp.node_worker_memory_low", "Available RAM must cover the pinned Swamp binary plus 256 MiB headroom.";
}}
pub(super) fn check_memory(files: &Files) -> Result<(), Error> {
    let available = fs::read_to_string(files.path("/proc/meminfo"))
        .ok()
        .and_then(|text| {
            let mut rows = text
                .lines()
                .filter_map(|line| line.strip_prefix("MemAvailable:"));
            let mut fields = rows.next()?.split_whitespace();
            let kib: u64 = fields.next()?.parse().ok()?;
            if fields.next()? != "kB" || fields.next().is_some() || rows.next().is_some() {
                return None;
            }
            kib.checked_mul(1024)
        });
    if available.is_some_and(|bytes| bytes >= BINARY_BYTES + MEMORY_HEADROOM) {
        Ok(())
    } else {
        Err(Error::MemoryLow {})
    }
}
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
    let cache = path == ROOT;
    let path = files.path(path);
    if cache
        && fs::symlink_metadata(&path).is_ok_and(|m| {
            m.is_dir() && m.uid() == files.uid && m.gid() == files.gid && m.mode() & 0o7777 == 0o755
        })
    {
        // Retire only the empty root-owned directory left by the earlier worker setup.
        fs::remove_dir(&path).map_err(|_| invalid())?;
    }
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
    format!("[Unit]\nDescription=Orbit Swamp worker for Node {}\nAfter=network-online.target\nWants=network-online.target\nStartLimitIntervalSec=300\nStartLimitBurst=3\n\n[Service]\nType=exec\nUser={}\nWorkingDirectory={ROOT}\nEnvironment=HOME={ROOT} SWAMP_HOME={ROOT}/home SWAMP_CONFIG_DIR={ROOT}/config\nLoadCredential=enrollment:{CREDENTIALS}/enrollment.token\nLoadCredential=server:{CREDENTIALS}/server.token\nExecStartPre=+/usr/local/bin/orbit-agent service node-worker-check\nExecStart={BINARY} worker connect wss://swamp.orbit --token-file=%d/enrollment --server-token-file=%d/server --ca-cert={CA} --cache-dir={ROOT}/cache --label node={}\nRestart=on-failure\nRestartSec=5\nKillMode=mixed\nSendSIGKILL=no\nTimeoutStopSec=infinity\nUMask=0077\nStandardOutput=journal\nStandardError=journal\n\n[Install]\nWantedBy=multi-user.target\n", input.node_id, input.user, input.node_id)
}
fn publish(
    files: &Files,
    host: &mut impl Host,
    input: &NodeWorkerConfig,
    account: &Account,
    binary_changed: bool,
    credentials: Option<&Credentials>,
) -> Result<(), Error> {
    if !valid(input) || account.name != input.user || account.uid == 0 || account.gid == 0 {
        return Err(invalid());
    }
    check_memory(files)?;
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
    private_dir(files, CREDENTIALS, files.uid, files.gid)?;
    let credentials_changed = if let Some(credentials) = credentials {
        credentials.validate(input)?;
        let mut changed = false;
        for (name, value) in [
            ("enrollment.token", &credentials.enrollment_token),
            ("server.token", &credentials.server_token),
        ] {
            let path = files.path(&format!("{CREDENTIALS}/{name}"));
            changed |= fs::read(&path).ok().as_deref() != Some(value.as_bytes());
            files
                .publish(&path, value.as_bytes(), 0o600, files.uid, files.gid, false)
                .map_err(|_| invalid())?;
        }
        changed
    } else {
        false
    };
    check_credentials(files).map_err(|_| Error::NotReady {})?;
    private_dir(files, ROOT, account.uid, account.gid)?;
    let text = unit(input);
    let path = files.path(UNIT_PATH);
    if fs::symlink_metadata(&path).is_ok() {
        owned_file(files, UNIT_PATH, 0o644)?;
    }
    let changed = binary_changed
        || credentials_changed
        || fs::read_to_string(&path).ok().as_deref() != Some(&text);
    files
        .publish_root(&path, text.as_bytes())
        .map_err(|_| invalid())?;
    call(host, &["systemctl", "daemon-reload"])?;
    call(host, &["systemctl", "enable", UNIT])?;
    call(host, &["systemctl", "reset-failed", UNIT])?;
    call(
        host,
        &[
            "systemctl",
            if changed { "restart" } else { "start" },
            "--no-block",
            UNIT,
        ],
    )
}
pub fn configure_native(
    context: &Context,
    input: Option<&NodeWorkerConfig>,
    credentials: Option<&Credentials>,
) -> Result<(), Error> {
    let files = Files::system();
    let mut host = recipe::Native(context);
    let Some(input) = input else {
        if fs::symlink_metadata(files.path(UNIT_PATH)).is_ok() {
            owned_file(&files, UNIT_PATH, 0o644)?;
            call(
                &mut host,
                &["systemctl", "disable", "--now", "--no-block", UNIT],
            )?;
            fs::remove_file(files.path(UNIT_PATH)).map_err(|_| failed())?;
            call(&mut host, &["systemctl", "daemon-reload"])?;
        }
        return Ok(());
    };
    if !valid(input) {
        return Err(invalid());
    }
    check_memory(&files)?;
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
    publish(
        &files,
        &mut host,
        input,
        &account,
        binary_changed,
        credentials,
    )
}

#[derive(schemars::JsonSchema, serde::Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Credentials {
    enrollment_token: String,
    server_token: String,
}
impl Credentials {
    fn validate(&self, input: &NodeWorkerConfig) -> Result<(), Error> {
        for (value, name) in [
            (
                &self.enrollment_token,
                format!("orbit-node-{}", input.node_id),
            ),
            (
                &self.server_token,
                format!("orbit-node-{}-access", input.node_id),
            ),
        ] {
            if value.len() > 4096
                || value.split_once('.').is_none_or(|(prefix, secret)| {
                    prefix != name
                        || secret.len() < 16
                        || !secret
                            .bytes()
                            .all(|b| b.is_ascii_alphanumeric() || b"_-".contains(&b))
                })
            {
                return Err(invalid());
            }
        }
        Ok(())
    }
}
fn check_credentials(files: &Files) -> Result<(), Error> {
    let directory = fs::symlink_metadata(files.path(CREDENTIALS)).map_err(|_| invalid())?;
    if !directory.is_dir() || directory.uid() != files.uid || directory.mode() & 0o777 != 0o700 {
        return Err(invalid());
    }
    for name in ["enrollment.token", "server.token"] {
        owned_file(files, &format!("{CREDENTIALS}/{name}"), 0o600)?;
    }
    Ok(())
}
/// Fixed systemd pre-start boundary; root source modes are checked on every restart.
pub struct Check;
impl orbit_agent_contract::Service for Check {
    const NAME: &'static str = "node-worker-check";
    fn supported(platform: crate::command::Platform) -> bool {
        platform == crate::command::Platform::Linux
    }
    fn run(arguments: &[String]) -> i32 {
        if !arguments.is_empty()
            || rustix::process::geteuid().as_raw() != 0
            || check_credentials(&Files::system()).is_err()
            || check_memory(&Files::system()).is_err()
        {
            eprintln!("Node worker is not ready: credential custody or available RAM check failed");
            return 1;
        }
        0
    }
}

#[cfg(test)]
mod tests;
