//! Read-only Node observation. No shell, inventory mutation, or secret bytes in the result.
use crate::{
    command::{identity, run::file_sha256, Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    collections::HashSet,
    fs,
    net::Ipv4Addr,
    os::unix::fs::MetadataExt,
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};
command_errors! {
    pub enum Error {
        Invalid {} => "doctor.node_input_invalid", "The Node observation input is invalid.";
        Unavailable {} => "doctor.node_inspection_failed", "The Node state could not be observed.";
    }
}
type Result<T> = std::result::Result<T, Error>;
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    user: String,
    address: Ipv4Addr,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct Disk {
    location: &'static str,
    available_kib: u64,
    size_kib: u64,
    free_inodes: Option<u64>,
    total_inodes: Option<u64>,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct Output {
    platform: String,
    architecture: String,
    wireguard: bool,
    agent_binary: Option<bool>,
    agent_unit: Option<bool>,
    agent_active: Option<bool>,
    agent_checksum: Option<String>,
    secret_checksum: Option<String>,
    disks: Vec<Disk>,
}
struct Filesystem {
    device: u64,
    block_size: u64,
    blocks: u64,
    available: u64,
    files: u64,
    free_files: u64,
}
trait Host {
    fn platform(&self) -> Result<(String, String)>;
    fn addresses(&self) -> Result<Vec<Ipv4Addr>>;
    fn filesystem(&self, path: &Path) -> Result<Filesystem>;
    fn active(&self) -> Result<bool>;
}
struct NativeHost;
impl Host for NativeHost {
    fn platform(&self) -> Result<(String, String)> {
        super::node_identity::platform().map_err(|_| Error::Unavailable {})
    }
    fn addresses(&self) -> Result<Vec<Ipv4Addr>> {
        super::node_identity::addresses().map_err(|_| Error::Unavailable {})
    }
    fn filesystem(&self, path: &Path) -> Result<Filesystem> {
        let stat = rustix::fs::statvfs(path).map_err(|_| Error::Unavailable {})?;
        let meta = fs::metadata(path).map_err(|_| Error::Unavailable {})?;
        Ok(Filesystem {
            device: meta.dev(),
            block_size: stat.f_frsize,
            blocks: stat.f_blocks,
            available: stat.f_bavail,
            files: stat.f_files,
            free_files: stat.f_ffree,
        })
    }
    fn active(&self) -> Result<bool> {
        let status = Process::new("/usr/bin/timeout")
            .args([
                "--kill-after=1s",
                "5s",
                "/usr/bin/systemctl",
                "is-active",
                "--quiet",
                "orbit-agent.service",
            ])
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status()
            .map_err(|_| Error::Unavailable {})?;
        match status.code() {
            Some(0) => Ok(true),
            Some(3 | 4) => Ok(false),
            _ => Err(Error::Unavailable {}),
        }
    }
}
fn disk(location: &'static str, state: Filesystem, linux: bool) -> Result<Disk> {
    if state.block_size == 0 || state.blocks == 0 {
        return Err(Error::Unavailable {});
    }
    // statvfs exposes a negative available count as unsigned on Linux. df clamps it for reporting.
    let available = if state.available > i64::MAX as u64 {
        0
    } else {
        state.available
    };
    if available > state.blocks {
        return Err(Error::Unavailable {});
    }
    let kib = |blocks: u64| -> Result<u64> {
        blocks
            .checked_mul(state.block_size)
            .map(|bytes| bytes / 1024)
            .ok_or(Error::Unavailable {})
    };
    let (free_inodes, total_inodes) = if !linux
        || state.files == 0
        || (state.files == u64::MAX && state.free_files == u64::MAX)
    {
        if linux && state.files == 0 && state.free_files != 0 {
            return Err(Error::Unavailable {});
        }
        (None, None)
    } else {
        if state.free_files > state.files {
            return Err(Error::Unavailable {});
        }
        (Some(state.free_files), Some(state.files))
    };
    let size_kib = kib(state.blocks)?;
    if size_kib == 0 {
        return Err(Error::Unavailable {});
    }
    Ok(Disk {
        location,
        available_kib: kib(available)?,
        size_kib,
        free_inodes,
        total_inodes,
    })
}
fn optional_metadata(path: &Path) -> Result<Option<fs::Metadata>> {
    match fs::metadata(path) {
        Ok(meta) => Ok(Some(meta)),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(None),
        Err(_) => Err(Error::Unavailable {}),
    }
}
fn observe(host: &impl Host, root: &Path, home: &Path, address: Ipv4Addr) -> Result<Output> {
    let (platform, architecture) = host.platform()?;
    if !["linux", "darwin", "freebsd"].contains(&platform.as_str()) {
        return Err(Error::Unavailable {});
    }
    let architecture = match architecture.to_lowercase().as_str() {
        "amd64" | "x86_64" => "x86_64",
        "arm64" | "aarch64" => "aarch64",
        _ => return Err(Error::Unavailable {}),
    }
    .into();
    let wireguard = host.addresses()?.contains(&address);
    let linux = platform == "linux";
    let mut output = Output {
        platform,
        architecture,
        wireguard,
        agent_binary: None,
        agent_unit: None,
        agent_active: None,
        agent_checksum: None,
        secret_checksum: None,
        disks: vec![],
    };
    if linux {
        let binary = root.join("usr/local/bin/orbit-agent");
        let exists = optional_metadata(&binary)?
            .is_some_and(|meta| meta.is_file() && meta.mode() & 0o111 != 0);
        output.agent_binary = Some(exists);
        if exists {
            output.agent_checksum = Some(file_sha256(&binary).map_err(|_| Error::Unavailable {})?);
        }
        output.agent_unit = Some(
            optional_metadata(&root.join("etc/systemd/system/orbit-agent.service"))?
                .is_some_and(|meta| meta.is_file()),
        );
        output.agent_active = Some(host.active()?);
        let secret = root.join("etc/orbit/agent/secret");
        if optional_metadata(&secret)?.is_some_and(|meta| meta.is_file()) {
            output.secret_checksum = Some(file_sha256(&secret).map_err(|_| Error::Unavailable {})?);
        }
    }
    let locations = if linux {
        vec![("root", root), ("home", home)]
    } else {
        vec![("home", home)]
    };
    let mut seen = HashSet::new();
    for (location, path) in locations {
        let state = host.filesystem(path)?;
        if seen.insert(state.device) {
            output.disks.push(disk(location, state, linux)?);
        }
    }
    Ok(output)
}
pub struct InspectNode;
impl Command for InspectNode {
    const NAME: &'static str = "doctor.inspect-node";
    const SUMMARY: &'static str =
        "Observe Node identity, addresses, observer state, and filesystem capacity.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        Some(if platform == Platform::Linux {
            Privilege::Root
        } else {
            Privilege::Account
        })
    }
    fn run(input: Input, context: &Context) -> Result<Output> {
        let account = identity::passwd(&input.user).ok_or(Error::Invalid {})?;
        let home = PathBuf::from(account.home);
        if !home.is_absolute()
            || (context.platform == Platform::Macos && account.uid != identity::effective_uid())
        {
            return Err(Error::Invalid {});
        }
        observe(&NativeHost, Path::new("/"), &home, input.address)
    }
}
#[cfg(test)]
mod tests {
    use super::*;
    use std::{cell::Cell, os::unix::fs::PermissionsExt};
    struct Fake {
        platform: &'static str,
        architecture: &'static str,
        device: Cell<u64>,
        active_calls: Cell<u8>,
        separate: bool,
    }
    impl Host for Fake {
        fn platform(&self) -> Result<(String, String)> {
            Ok((self.platform.into(), self.architecture.into()))
        }
        fn addresses(&self) -> Result<Vec<Ipv4Addr>> {
            Ok(vec!["10.44.0.2".parse().unwrap()])
        }
        fn filesystem(&self, _: &Path) -> Result<Filesystem> {
            if self.separate {
                self.device.set(self.device.get() + 1);
            }
            Ok(Filesystem {
                device: self.device.get(),
                block_size: 1024,
                blocks: 26214400,
                available: 16384,
                files: 100000,
                free_files: 90000,
            })
        }
        fn active(&self) -> Result<bool> {
            self.active_calls.set(self.active_calls.get() + 1);
            Ok(true)
        }
    }
    fn fake() -> Fake {
        Fake {
            platform: "linux",
            architecture: "amd64",
            device: Cell::new(1),
            active_calls: Cell::new(0),
            separate: false,
        }
    }
    #[test]
    fn native_queries_read_identity_interfaces_and_filesystem_without_commands() {
        let host = NativeHost;
        let (platform, architecture) = host.platform().unwrap();
        assert!(!platform.is_empty());
        assert!(!architecture.is_empty());
        assert!(host.addresses().unwrap().contains(&Ipv4Addr::LOCALHOST));
        let state = host.filesystem(Path::new("/")).unwrap();
        assert!(
            disk("root", state, cfg!(target_os = "linux"))
                .unwrap()
                .size_kib
                > 0
        );
    }
    #[test]
    fn observes_real_agent_files_repeats_and_deduplicates_filesystems() {
        let root =
            std::env::temp_dir().join(format!("orbit-doctor-node-{:016x}", rand::random::<u64>()));
        for directory in ["usr/local/bin", "etc/systemd/system", "etc/orbit/agent"] {
            fs::create_dir_all(root.join(directory)).unwrap();
        }
        let host = fake();
        let address = "10.44.0.2".parse().unwrap();
        let absent = observe(&host, &root, &root, address).unwrap();
        assert_eq!(absent.agent_binary, Some(false));
        assert_eq!(absent.agent_unit, Some(false));
        assert_eq!(absent.secret_checksum, None);
        fs::write(root.join("usr/local/bin/orbit-agent"), b"binary").unwrap();
        fs::set_permissions(
            root.join("usr/local/bin/orbit-agent"),
            fs::Permissions::from_mode(0o755),
        )
        .unwrap();
        fs::write(
            root.join("etc/orbit/agent/secret"),
            b"never-return-this-secret",
        )
        .unwrap();
        fs::write(root.join("etc/systemd/system/orbit-agent.service"), b"unit").unwrap();
        let first = observe(&host, &root, &root, address).unwrap();
        let second = observe(&host, &root, &root, address).unwrap();
        assert_eq!(first, second);
        assert_eq!(first.disks.len(), 1);
        assert_eq!(first.agent_binary, Some(true));
        assert_eq!(first.agent_unit, Some(true));
        assert!(first.wireguard);
        assert_eq!(first.architecture, "x86_64");
        assert_eq!(
            first.agent_checksum,
            Some(file_sha256(&root.join("usr/local/bin/orbit-agent")).unwrap())
        );
        assert!(!serde_json::to_string(&first)
            .unwrap()
            .contains("never-return-this-secret"));
        let mut host = fake();
        host.separate = true;
        assert_eq!(
            observe(&host, &root, &root, address).unwrap().disks.len(),
            2
        );
        fs::remove_dir_all(root).unwrap();
    }
    #[test]
    fn mac_observation_omits_linux_state_and_reads_only_home() {
        let host = Fake {
            platform: "darwin",
            architecture: "arm64",
            ..fake()
        };
        let result = observe(
            &host,
            Path::new("/unused"),
            Path::new("/home"),
            Ipv4Addr::LOCALHOST,
        )
        .unwrap();
        assert_eq!(result.architecture, "aarch64");
        assert!(!result.wireguard);
        assert_eq!(result.agent_binary, None);
        assert_eq!(result.agent_active, None);
        assert_eq!(host.active_calls.get(), 0);
        assert_eq!(result.disks.len(), 1);
        assert_eq!(result.disks[0].location, "home");
        assert_eq!(result.disks[0].total_inodes, None);
    }
    #[test]
    fn disk_counters_cover_unavailable_inodes_negative_space_and_invalid_values() {
        for (available, files, free, expected) in [
            (16384, 0, 0, Some(16384)),
            (u64::MAX - 16383, 100000, 90000, Some(0)),
            (16384, u64::MAX, u64::MAX, Some(16384)),
            (30000000, 0, 0, None),
            (16384, 0, 1, None),
            (16384, 10, 11, None),
        ] {
            let result = disk(
                "root",
                Filesystem {
                    device: 1,
                    block_size: 1024,
                    blocks: 26214400,
                    available,
                    files,
                    free_files: free,
                },
                true,
            );
            if let Some(expected) = expected {
                let result = result.unwrap();
                assert_eq!(result.available_kib, expected);
                assert_eq!(
                    result.total_inodes,
                    if files == 0 || files == u64::MAX {
                        None
                    } else {
                        Some(files)
                    }
                );
            } else {
                assert!(result.is_err());
            }
        }
    }
    #[test]
    fn rejects_unknown_input_accounts_architecture_and_unreadable_filesystem() {
        assert!(serde_json::from_value::<Input>(
            serde_json::json!({"user":"orbit","address":"10.44.0.1","script":"x"})
        )
        .is_err());
        let input = Input {
            user: "orbit-no-such-user".into(),
            address: Ipv4Addr::LOCALHOST,
        };
        assert!(InspectNode::run(
            input,
            &Context::new(
                Platform::Linux,
                std::time::Instant::now() + Duration::from_secs(30)
            )
        )
        .is_err());
        let host = Fake {
            architecture: "unknown",
            ..fake()
        };
        assert!(observe(&host, Path::new("/"), Path::new("/"), Ipv4Addr::LOCALHOST).is_err());
        assert!(NativeHost
            .filesystem(Path::new("/orbit-no-such-home"))
            .is_err());
    }
}
