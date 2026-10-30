//! Node bootstrap uses the same worker start/stop path as task VMs.
use super::{
    recipe::{self, store::Account, Files, Host, Invocation},
    swamp_worker as worker,
};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    fs,
    os::unix::fs::{MetadataExt, PermissionsExt},
    time::Duration,
};
pub(crate) mod issuer;
pub(crate) mod lifecycle;
pub const VERSION: &str = "20261028.205235.0-sha.1602a617";
const DIGEST: &str = "38b81986c7203f1e93806fc7b6d38e560a86456ea755a4369a89b17b64d4fbe3";
const URL: &str = "https://github.com/swamp-club/swamp/releases/download/v20261028.205235.0-sha.1602a617/swamp-linux-x86_64";
const BINARY: &str = recipe::swamp::BINARY;
const ROOT: &str = "/var/lib/orbit-node-worker";
pub(super) const EXPIRY: &str = "/run/orbit-swamp-worker-expiry";
pub(super) const USER: &str = "orbit-swamp-worker";
const CA: &str = "/usr/local/share/ca-certificates/orbit-root.crt";

command_errors! { pub enum Error {
    Failed {} => "swamp.node_worker_failed", "The Node worker lifecycle step failed.";
    Invalid {} => "swamp.node_worker_invalid", "The Node worker identity or custody is invalid.";
}}
fn failed() -> Error {
    Error::Failed {}
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
pub(super) fn pinned(files: &Files) -> bool {
    owned_file(files, BINARY, 0o755).is_ok()
        && crate::command::run::file_sha256(&files.path(BINARY))
            .ok()
            .as_deref()
            == Some(DIGEST)
}
fn architecture(host: &mut impl Host) -> Result<(), Error> {
    if call(host, &["uname", "-m"])?.trim() != "x86_64" {
        return Err(invalid());
    }
    Ok(())
}
fn install_binary(files: &Files, host: &mut impl Host) -> Result<bool, Error> {
    architecture(host)?;
    if pinned(files) {
        return Ok(false);
    }
    let input = serde_json::from_value(serde_json::json!({
        "user": USER, "snapshot": "20261028T000000Z", "architecture": "amd64",
        "tools": {"swamp": {"source": "artifact", "version": VERSION,
            "artifacts": {"amd64": {"url": URL, "sha256": DIGEST}}}}
    }))
    .map_err(|_| invalid())?;
    recipe::swamp::install(host, files, &input).map_err(|_| failed())?;
    if !pinned(files) {
        return Err(invalid());
    }
    Ok(true)
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    node_id: u64,
}
pub fn configure_native(context: &Context, enabled: bool) -> Result<(), Error> {
    let files = Files::system();
    let mut host = recipe::Native(context);
    if !enabled {
        if fs::read_to_string(files.path(worker::UNIT_PATH))
            .is_ok_and(|s| s.contains("User=orbit-swamp-worker\n"))
        {
            worker::stop(&files, &mut host).map_err(|_| failed())?;
        }
        configure_local(&files, &mut host, false)?;
        return Ok(());
    }
    architecture(&mut host)?;
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
    if account.gid == 0
        || account.home != std::path::Path::new(ROOT).join("home")
        || call(&mut host, &["id", "-G", USER])?.trim() != account.gid.to_string()
    {
        return Err(invalid());
    }
    private_dir(&files, ROOT, files.uid, files.gid, 0o711)?;
    private_dir(
        &files,
        &format!("{ROOT}/home"),
        account.uid,
        account.gid,
        0o700,
    )?;
    owned_file(&files, crate::CA_PATH, 0o644)?;
    files
        .publish_root(
            &files.path(CA),
            &fs::read(files.path(crate::CA_PATH)).map_err(|_| failed())?,
        )
        .map_err(|_| invalid())?;
    install_binary(&files, &mut host)?;
    configure_local(&files, &mut host, true)?;
    Ok(())
}

fn configure_local(files: &Files, host: &mut impl Host, enabled: bool) -> Result<(), Error> {
    use crate::local_agent as local;
    let units = [
        (local::SOCKET_UNIT, local::SOCKET),
        (local::SERVICE_UNIT, local::SERVICE),
    ];
    if enabled {
        for (name, contents) in units {
            files
                .publish_root(
                    &files.path(&format!("/etc/systemd/system/{name}")),
                    contents.as_bytes(),
                )
                .map_err(|_| invalid())?;
        }
        call(host, &["systemctl", "daemon-reload"])?;
        call(host, &["systemctl", "enable", "--now", local::SOCKET_UNIT])?;
    } else if files
        .path(&format!("/etc/systemd/system/{}", local::SOCKET_UNIT))
        .exists()
    {
        for (name, contents) in units {
            let path = format!("/etc/systemd/system/{name}");
            owned_file(files, &path, 0o644)?;
            if fs::read_to_string(files.path(&path)).map_err(|_| invalid())? != contents {
                return Err(invalid());
            }
        }
        call(host, &["systemctl", "disable", "--now", local::SOCKET_UNIT])?;
        for (name, _) in units {
            fs::remove_file(files.path(&format!("/etc/systemd/system/{name}")))
                .map_err(|_| failed())?;
        }
        call(host, &["systemctl", "daemon-reload"])?;
    }
    Ok(())
}

#[cfg(test)]
mod local_tests {
    use super::*;
    use crate::commands::recipe::{Failure, Output};
    #[derive(Default)]
    struct Calls(Vec<Vec<String>>);
    impl Host for Calls {
        fn execute(&mut self, value: &Invocation) -> Result<Output, Failure> {
            self.0.push(value.argv.clone());
            Ok(Output::default())
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            unreachable!()
        }
    }
    #[test]
    fn converge_publishes_socket_units_and_removes_only_owned_units() {
        let root = std::env::temp_dir().join(format!(
            "orbit-local-converge-{:016x}",
            rand::random::<u64>()
        ));
        fs::create_dir_all(root.join("etc/systemd/system")).unwrap();
        let files = Files {
            root: root.clone(),
            uid: rustix::process::geteuid().as_raw(),
            gid: rustix::process::getegid().as_raw(),
        };
        let mut calls = Calls::default();
        configure_local(&files, &mut calls, true).unwrap();
        let socket = files.path("/etc/systemd/system/orbit-agent-local.socket");
        assert_eq!(
            fs::read_to_string(&socket).unwrap(),
            crate::local_agent::SOCKET
        );
        assert_eq!(fs::metadata(&socket).unwrap().mode() & 0o777, 0o644);
        assert_eq!(
            calls.0[1],
            ["systemctl", "enable", "--now", "orbit-agent-local.socket"]
        );
        fs::write(&socket, "foreign unit").unwrap();
        assert!(configure_local(&files, &mut calls, false).is_err());
        assert_eq!(calls.0.len(), 2);
        fs::write(&socket, crate::local_agent::SOCKET).unwrap();
        configure_local(&files, &mut calls, false).unwrap();
        assert!(!socket.exists());
        assert!(!files
            .path("/etc/systemd/system/orbit-agent-local@.service")
            .exists());
        assert_eq!(
            calls.0[2],
            ["systemctl", "disable", "--now", "orbit-agent-local.socket"]
        );
        fs::remove_dir_all(root).unwrap();
    }
}
