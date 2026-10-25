//! Clear a never-enrolled image builder before publishing its disk.
use super::incus::{bounded, Output};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    fs, io,
    path::{Path, PathBuf},
    time::Duration,
};
const SERVICES: &[&str] = &[
    "caddy.service",
    "docker.service",
    "docker.socket",
    "containerd.service",
    "incus.socket",
    "incus-user.socket",
    "incus.service",
    "incus-user.service",
];
const IDS: &[&str] = &[
    "/var/lib/docker/engine-id",
    "/var/lib/containerd/io.containerd.grpc.v1.introspection/uuid",
    "/var/lib/caddy/.local/share/caddy/instance.uuid",
    "/var/lib/caddy/.local/share/caddy/last_clean.json",
    "/var/lib/caddy/.config/caddy/autosave.json",
    "/var/lib/systemd/credential.secret",
];
command_errors! {
    pub enum Error {
        Enrolled {} => "task_vm.image_identity_present", "An enrolled Node cannot be cleaned as an image.";
        Failed { stage: String, reason: String } => "task_vm.image_clean_failed", "The image builder identity could not be cleaned.";
    }
}
fn failed(stage: &str, reason: impl ToString) -> Error {
    Error::Failed {
        stage: stage.into(),
        reason: reason.to_string(),
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Cleaned {
    ready: bool,
}
trait Host {
    fn run(&mut self, program: &'static str, args: &[&str]) -> Output;
    fn cloud_init(&self) -> bool;
}
struct Native<'a>(&'a Context);
impl Host for Native<'_> {
    fn run(&mut self, program: &'static str, args: &[&str]) -> Output {
        bounded(self.0, program, args, None)
    }
    fn cloud_init(&self) -> bool {
        Path::new("/usr/bin/cloud-init").is_file()
    }
}
fn call(
    host: &mut impl Host,
    program: &'static str,
    args: &[&str],
    stage: &str,
) -> Result<String, Error> {
    let output = host.run(program, args);
    if output.code != 0 || output.truncated {
        return Err(failed(
            stage,
            if output.truncated {
                "Host output is incomplete.".into()
            } else {
                output.stderr.chars().take(2000).collect::<String>()
            },
        ));
    }
    Ok(output.stdout)
}
fn at(root: &Path, path: &str) -> PathBuf {
    root.join(path.trim_start_matches('/'))
}
fn entries(path: &Path) -> io::Result<Vec<PathBuf>> {
    match fs::read_dir(path) {
        Ok(entries) => entries
            .map(|entry| entry.map(|entry| entry.path()))
            .collect(),
        Err(error) if error.kind() == io::ErrorKind::NotFound => Ok(vec![]),
        Err(error) => Err(error),
    }
}
fn remove(path: &Path) -> io::Result<()> {
    match fs::remove_file(path) {
        Err(e) if e.kind() != io::ErrorKind::NotFound => Err(e),
        _ => Ok(()),
    }
}
fn remove_tree(path: &Path) -> io::Result<()> {
    if fs::symlink_metadata(path)?.is_dir() {
        fs::remove_dir_all(path)
    } else {
        fs::remove_file(path)
    }
}
fn exists(path: &Path) -> bool {
    fs::symlink_metadata(path).is_ok()
}
fn ssh_keys(root: &Path) -> io::Result<Vec<PathBuf>> {
    Ok(entries(&at(root, "/etc/ssh"))?
        .into_iter()
        .filter(|p| {
            p.file_name()
                .is_some_and(|n| n.to_string_lossy().starts_with("ssh_host_"))
        })
        .collect())
}
fn authorized_keys(root: &Path) -> io::Result<Vec<PathBuf>> {
    let mut homes = entries(&at(root, "/home"))?;
    homes.push(at(root, "/root"));
    Ok(homes
        .into_iter()
        .map(|p| p.join(".ssh/authorized_keys"))
        .filter(|p| exists(p))
        .collect())
}
fn logs(directory: &Path) -> io::Result<()> {
    for path in entries(directory)? {
        let metadata = fs::symlink_metadata(&path)?;
        if metadata.is_dir() {
            logs(&path)?;
        } else if metadata.is_file() {
            let name = path.file_name().unwrap().to_string_lossy();
            let rotated = name.ends_with(".gz")
                || name.as_bytes().last().is_some_and(u8::is_ascii_digit)
                    && name.as_bytes().get(name.len().saturating_sub(2)) == Some(&b'.');
            if rotated {
                fs::remove_file(path)?;
            } else {
                fs::OpenOptions::new()
                    .write(true)
                    .truncate(true)
                    .open(path)?;
            }
        }
    }
    Ok(())
}
fn clean(root: &Path, host: &mut impl Host) -> Result<Cleaned, Error> {
    if exists(&at(root, "/etc/orbit/agent/secret"))
        || exists(&at(root, "/etc/wireguard/orbit.conf"))
    {
        return Err(Error::Enrolled {});
    }
    let mut loaded = vec![];
    for unit in SERVICES {
        let state = call(
            host,
            "/usr/bin/systemctl",
            &["show", "--property=LoadState", "--value", unit],
            "inspect services",
        )?;
        match state.trim() {
            "loaded" => loaded.push(*unit),
            "not-found" | "masked" => {}
            _ => {
                return Err(failed(
                    "inspect services",
                    "A service load state is invalid.",
                ))
            }
        }
    }
    if !loaded.is_empty() {
        let mut args = vec!["stop"];
        args.extend(loaded);
        call(host, "/usr/bin/systemctl", &args, "stop services")?;
    }
    for path in entries(&at(root, "/var/lib/incus")).map_err(|e| failed("Incus identity", e))? {
        remove_tree(&path).map_err(|e| failed("Incus identity", e))?;
    }
    call(host, "/usr/bin/apt-get", &["clean"], "package cache")?;
    let filesystem = (|| -> io::Result<()> {
        for path in IDS
            .iter()
            .chain(["/var/lib/systemd/random-seed", "/root/.bash_history"].iter())
        {
            remove(&at(root, path))?;
        }
        for home in entries(&at(root, "/home"))? {
            remove(&home.join(".bash_history"))?;
        }
        for key in ssh_keys(root)? {
            remove(&key)?;
        }
        for key in authorized_keys(root)? {
            fs::OpenOptions::new()
                .write(true)
                .truncate(true)
                .open(key)?;
        }
        for directory in ["/tmp", "/var/tmp"] {
            for path in entries(&at(root, directory))? {
                if !path
                    .file_name()
                    .unwrap()
                    .to_string_lossy()
                    .starts_with("systemd-private-")
                {
                    remove_tree(&path)?;
                }
            }
        }
        let pi_cache = at(root, super::pi_artifact::CACHE);
        if exists(&pi_cache) {
            remove_tree(&pi_cache)?;
        }
        Ok(())
    })();
    filesystem.map_err(|e| failed("identity files", e))?;
    call(host, "/usr/bin/journalctl", &["--rotate"], "journal")?;
    call(
        host,
        "/usr/bin/journalctl",
        &["--vacuum-time=1s"],
        "journal",
    )?;
    logs(&at(root, "/var/log")).map_err(|e| failed("logs", e))?;
    if host.cloud_init() {
        call(
            host,
            "/usr/bin/cloud-init",
            &[
                "clean",
                "--logs",
                "--seed",
                "--machine-id",
                "--configs",
                "all",
            ],
            "cloud-init",
        )?;
    } else {
        fs::write(at(root, "/etc/machine-id"), b"uninitialized\n")
            .map_err(|e| failed("machine identity", e))?;
        remove(&at(root, "/var/lib/dbus/machine-id")).map_err(|e| failed("machine identity", e))?;
    }
    let verified = (|| -> io::Result<bool> {
        Ok(ssh_keys(root)?.is_empty()
            && fs::read_to_string(at(root, "/etc/machine-id"))?.trim() == "uninitialized"
            && !exists(&at(root, "/var/lib/cloud/instance"))
            && entries(&at(root, "/var/lib/incus"))?.is_empty()
            && IDS.iter().all(|p| !exists(&at(root, p)))
            && authorized_keys(root)?
                .iter()
                .all(|p| fs::metadata(p).is_ok_and(|m| m.len() == 0)))
    })()
    .map_err(|e| failed("verify", e))?;
    if !verified {
        return Err(failed("verify", "A builder identity remains."));
    }
    Ok(Cleaned { ready: true })
}
pub struct CleanImage;
impl Command for CleanImage {
    const NAME: &'static str = "image.clean";
    const SUMMARY: &'static str =
        "Remove a never-enrolled image builder's identities before publication.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(120);
    const RESOURCES: &'static [&'static str] = &["packages", "pi-runtime"];
    type Input = Input;
    type Output = Cleaned;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("image-identity".into())
    }
    fn run(_: Input, context: &Context) -> Result<Cleaned, Error> {
        clean(Path::new("/"), &mut Native(context))
    }
}
#[cfg(test)]
mod tests;

const INCUS_DROP_IN: &str = "/etc/systemd/system/cloud-init-main.service.d/orbit-incus-agent.conf";
const INCUS_ORDER: &[u8] = b"[Unit]\nWants=incus-agent.service\nAfter=incus-agent.service\n";
fn prepare_incus(root: &Path) -> Result<Cleaned, Error> {
    write_fixed(&at(root, INCUS_DROP_IN), INCUS_ORDER)
        .map_err(|e| failed("cloud-init drop-in", e))?;
    Ok(Cleaned { ready: true })
}
pub(super) fn write_fixed(path: &Path, bytes: &[u8]) -> io::Result<()> {
    use std::{
        io::Write,
        os::unix::fs::{OpenOptionsExt, PermissionsExt},
    };
    let directory = path.parent().unwrap();
    let candidate = directory.join(format!(".orbit-{:016x}", rand::random::<u64>()));
    let result = (|| -> io::Result<()> {
        fs::create_dir_all(directory)?;
        let mut file = fs::OpenOptions::new()
            .create_new(true)
            .write(true)
            .mode(0o644)
            .open(&candidate)?;
        file.write_all(bytes)?;
        file.sync_all()?;
        fs::set_permissions(&candidate, fs::Permissions::from_mode(0o644))?;
        fs::rename(&candidate, path)
    })();
    if result.is_err() {
        let _ = fs::remove_file(&candidate);
    }
    result
}
pub struct PrepareIncus;
impl Command for PrepareIncus {
    const NAME: &'static str = "image.prepare-incus";
    const SUMMARY: &'static str =
        "Order the image's cloud-init after the Incus agent before publication.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = Input;
    type Output = Cleaned;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("image-identity".into())
    }
    fn run(_: Input, _: &Context) -> Result<Cleaned, Error> {
        prepare_incus(Path::new("/"))
    }
}
