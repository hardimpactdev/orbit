//! Node-local exporter transactions. Fleet selection and fleet rollback belong to the Gateway.
use crate::command::{Command, CommandError, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::{
    fs,
    io::{self, Write},
    net::Ipv4Addr,
    os::unix::fs::{OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

const CADVISOR_VERSION: &str = "v0.60.5";
const CADVISOR_HASH: &str = "6f258bed28113218c88729634c47ead8d928b81b1504b75fd911b98d29003a87";
const CADVISOR_BINARY: &str = "usr/local/bin/orbit-cadvisor";
const DISABLED_METRICS: &str = "sched,percpu,memory_numa,cpuLoad,diskIO,disk,network,tcp,advtcp,udp,app,process,hugetlb,referenced_memory,cpu_topology,resctrl,cpuset,oom_event,pressure";

#[derive(Clone, Copy, Debug, PartialEq)]
enum Kind {
    Exporter,
    Cadvisor,
}
impl Kind {
    fn service(self) -> &'static str {
        match self {
            Self::Exporter => "prometheus-node-exporter",
            Self::Cadvisor => "orbit-cadvisor",
        }
    }
    fn marker(self) -> &'static str {
        match self {
            Self::Exporter => "# Managed by Orbit: metrics\n",
            Self::Cadvisor => "# Managed by Orbit: metrics-cadvisor\n",
        }
    }
    fn path(self) -> &'static str {
        match self {
            Self::Exporter => "etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf",
            Self::Cadvisor => "etc/systemd/system/orbit-cadvisor.service",
        }
    }
    fn port(self) -> &'static str {
        match self {
            Self::Exporter => "9100",
            Self::Cadvisor => "9102",
        }
    }
    fn comment(self) -> &'static str {
        match self {
            Self::Exporter => "orbit:metrics-node-exporter",
            Self::Cadvisor => "orbit:metrics-cadvisor",
        }
    }
    fn error(self, step: Step) -> Error {
        Error {
            kind: self,
            step,
            recovery: None,
        }
    }
    fn configuration(self, address: Ipv4Addr) -> String {
        match self {
            Self::Exporter => format!("{}[Service]\nExecStart=\nExecStart=/usr/bin/prometheus-node-exporter --web.listen-address={address}:9100\nRestart=always\nRestartSec=2\n", self.marker()),
            Self::Cadvisor => format!("{}[Unit]\nDescription=Orbit cAdvisor exporter\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nExecStart=/{CADVISOR_BINARY} --listen_ip={address} --port=9102 --store_container_labels=false --docker_only=false --disable_metrics={DISABLED_METRICS}\nRestart=always\nRestartSec=2\n\n[Install]\nWantedBy=multi-user.target\n", self.marker()),
        }
    }
}

// One step vocabulary keeps the existing public error codes for both exporters.
macro_rules! steps {
    ($( $variant:ident => $suffix:literal, $status:literal, $message:literal; )+) => {
        #[derive(Clone, Copy, Debug, PartialEq)]
        enum Step { $( $variant ),+ }
        impl CommandError for Error {
            const CODES: &'static [&'static str] = &[$(concat!("metrics.exporter_", $suffix), concat!("metrics.cadvisor_", $suffix)),+];
            fn code(&self) -> &'static str { match (self.kind, self.step) { $(
                (Kind::Exporter, Step::$variant) => concat!("metrics.exporter_", $suffix),
                (Kind::Cadvisor, Step::$variant) => concat!("metrics.cadvisor_", $suffix),
            )+ } }
            fn message(&self) -> String { match self.step { $(Step::$variant => $message.into()),+ } }
            fn details(&self) -> serde_json::Value {
                let status = match self.step { $(Step::$variant => $status),+ };
                serde_json::json!({"status": status, "recovery": self.recovery})
            }
        }
    }
}
steps! {
    Invalid => "input_invalid", 422, "Exporter input is invalid.";
    Address => "address_invalid", 409, "The exporter requires IPv4 Node and Metrics addresses.";
    Platform => "platform_unsupported", 422, "The pinned cAdvisor binary requires Linux x86_64.";
    ConfigurationInspection => "configuration_inspection_failed", 502, "The exporter configuration could not be inspected.";
    ConfigurationOwnership => "configuration_ownership_drift", 409, "Exporter configuration ownership cannot be proved.";
    Configuration => "configuration_failed", 502, "The exporter configuration could not be published.";
    ConfigurationVerify => "configuration_verify_failed", 502, "The exporter configuration could not be verified.";
    ConfigurationRemove => "configuration_remove_failed", 502, "The exporter configuration could not be removed.";
    ConfigurationRemoveVerify => "configuration_remove_verify_failed", 502, "The exporter configuration remained after removal.";
    ServiceInspection => "service_inspection_failed", 502, "The exporter service state could not be inspected.";
    Service => "service_failed", 502, "The exporter service could not be activated.";
    ServiceVerify => "service_verify_failed", 502, "The exporter service is not active.";
    ServiceRemove => "service_remove_failed", 502, "The exporter service could not be disabled.";
    ServiceRemoveVerify => "service_remove_verify_failed", 502, "The exporter service remained active after removal.";
    FirewallInspection => "firewall_inspection_failed", 502, "The exporter firewall state could not be inspected.";
    FirewallInactive => "firewall_inactive", 409, "UFW must be active for exporter convergence.";
    FirewallOwnership => "firewall_ownership_drift", 409, "Exporter firewall ownership cannot be proved.";
    Firewall => "firewall_failed", 502, "The exporter firewall rule could not be applied.";
    FirewallRemove => "firewall_remove_failed", 502, "The exporter firewall rule could not be removed.";
    FirewallVerify => "firewall_verify_failed", 502, "The exporter firewall rule could not be verified.";
    FirewallRemoveVerify => "firewall_remove_verify_failed", 502, "The exporter firewall rule remained after removal.";
    Install => "install_failed", 502, "The exporter package could not be installed.";
    Download => "binary_download_failed", 502, "The cAdvisor binary could not be downloaded.";
    BinaryInspection => "binary_inspection_failed", 502, "The cAdvisor binary could not be inspected.";
    Checksum => "checksum_mismatch", 502, "The downloaded cAdvisor binary failed checksum verification.";
    BinaryInstall => "binary_install_failed", 502, "The cAdvisor binary could not be installed.";
    BinaryRemove => "binary_remove_failed", 502, "The cAdvisor binary could not be removed.";
    Rollback => "rollback_failed", 502, "Exporter state could not be restored.";
    ConfigurationRollbackVerify => "configuration_rollback_verify_failed", 502, "Exporter configuration recovery could not be verified.";
    ServiceRollbackVerify => "service_rollback_verify_failed", 502, "Exporter service recovery could not be verified.";
    FirewallRollbackVerify => "firewall_rollback_verify_failed", 502, "Exporter firewall recovery could not be verified.";
}
#[derive(Debug, PartialEq)]
pub struct Error {
    kind: Kind,
    step: Step,
    recovery: Option<[String; 2]>,
}
type Result<T> = std::result::Result<T, Error>;

#[derive(Clone, Debug, Deserialize, Serialize, PartialEq)]
#[serde(deny_unknown_fields)]
pub struct State {
    pub configuration: Option<String>,
    pub service_active: bool,
    pub firewall_source: Option<Ipv4Addr>,
}
#[derive(Debug, Deserialize)]
#[serde(tag = "operation", rename_all = "snake_case", deny_unknown_fields)]
pub enum Operation {
    Snapshot {},
    Converge {},
    Remove {},
    Restore { state: State },
    Actual {},
}
#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    address: String,
    metrics_address: String,
    action: Operation,
}
#[derive(Debug, Serialize)]
#[serde(untagged)]
pub enum Output {
    State(State),
    Actual { actual: &'static str },
    Changed { changed: bool },
}

/// Parse only the owned rule. A source change is repairable only for one IPv4 host rule;
/// duplicate comments, malformed rows, or any other shape change refuse before mutation.
#[derive(Clone, Debug, PartialEq)]
struct Rule {
    number: String,
    source: Ipv4Addr,
}
fn rule(status: &str, kind: Kind, destination: Ipv4Addr) -> Result<Option<Rule>> {
    if !status
        .lines()
        .any(|line| line.trim().eq_ignore_ascii_case("Status: active"))
    {
        return Err(kind.error(Step::FirewallInactive));
    }
    let mut found = None;
    for line in status.lines() {
        let Some((body, comment)) = line.rsplit_once('#') else {
            continue;
        };
        if comment.trim() != kind.comment() {
            continue;
        }
        let invalid = || kind.error(Step::FirewallOwnership);
        if found.is_some() {
            return Err(invalid());
        }
        let body = body.trim().strip_prefix('[').ok_or_else(invalid)?;
        let (number, body) = body.split_once(']').ok_or_else(invalid)?;
        let number = number.trim();
        if number.parse::<u32>().is_err() || number == "0" {
            return Err(invalid());
        }
        let words: Vec<_> = body.split_whitespace().collect();
        // UFW can attach `on orbit` to either endpoint. Both mean the incoming interface.
        let (target, source) = match words.as_slice() {
            [dest, port, "on", "orbit", "ALLOW", "IN", source] => ([*dest, *port], *source),
            [dest, port, "ALLOW", "IN", source, "on", "orbit"] => ([*dest, *port], *source),
            _ => return Err(invalid()),
        };
        let host = |s: &str| s.strip_suffix("/32").unwrap_or(s).parse::<Ipv4Addr>();
        if host(target[0]).ok() != Some(destination) || target[1] != format!("{}/tcp", kind.port())
        {
            return Err(invalid());
        }
        let source = host(source).map_err(|_| invalid())?;
        found = Some(Rule {
            number: number.into(),
            source,
        });
    }
    Ok(found)
}

/// Fixed executables are the only injected effects. Filesystem logic stays real in unit tests.
trait Host {
    fn command(&mut self, program: &str, args: &[&str]) -> io::Result<(i32, String)>;
    fn download(&mut self) -> io::Result<Vec<u8>>;
}
struct NativeHost;
impl Host for NativeHost {
    fn command(&mut self, program: &str, args: &[&str]) -> io::Result<(i32, String)> {
        let output = Process::new("timeout")
            .args([
                "--kill-after=5s",
                if program == "apt-get" { "300s" } else { "120s" },
                program,
            ])
            .args(args)
            .env("LC_ALL", "C")
            .env("DEBIAN_FRONTEND", "noninteractive")
            .stdin(Stdio::null())
            .output()?;
        Ok((
            output.status.code().unwrap_or(1),
            String::from_utf8_lossy(&output.stdout).into_owned(),
        ))
    }
    fn download(&mut self) -> io::Result<Vec<u8>> {
        tokio::runtime::Builder::new_current_thread().enable_all().build()?.block_on(async {
            let mut response = reqwest::Client::builder().connect_timeout(Duration::from_secs(10)).timeout(Duration::from_secs(120)).build().map_err(io::Error::other)?
                .get(format!("https://github.com/google/cadvisor/releases/download/{CADVISOR_VERSION}/cadvisor-{CADVISOR_VERSION}-linux-amd64"))
                .send().await.map_err(io::Error::other)?.error_for_status().map_err(io::Error::other)?;
            let mut bytes = Vec::new();
            while let Some(chunk) = response.chunk().await.map_err(io::Error::other)? {
                if bytes.len() + chunk.len() > 128 * 1024 * 1024 { return Err(io::Error::other("binary too large")); }
                bytes.extend_from_slice(&chunk);
            }
            Ok(bytes)
        })
    }
}

struct Runtime<H> {
    root: PathBuf,
    kind: Kind,
    address: Ipv4Addr,
    metrics_address: Ipv4Addr,
    checksum: String,
    host: H,
}
impl<H: Host> Runtime<H> {
    fn path(&self) -> PathBuf {
        self.root.join(self.kind.path())
    }
    fn error(&self, step: Step) -> Error {
        self.kind.error(step)
    }
    fn command(&mut self, program: &str, args: &[&str], step: Step) -> Result<(i32, String)> {
        self.host
            .command(program, args)
            .map_err(|_| self.error(step))
    }
    fn checked(&mut self, program: &str, args: &[&str], step: Step) -> Result<()> {
        if self.command(program, args, step)?.0 == 0 {
            Ok(())
        } else {
            Err(self.error(step))
        }
    }
    fn configuration(&self) -> Result<Option<String>> {
        let path = self.path();
        match fs::symlink_metadata(&path) {
            Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(None),
            Err(_) => Err(self.error(Step::ConfigurationInspection)),
            Ok(m) if !m.is_file() => Err(self.error(Step::ConfigurationOwnership)),
            Ok(_) => {
                let text = fs::read_to_string(path)
                    .map_err(|_| self.error(Step::ConfigurationInspection))?;
                if !text.starts_with(self.kind.marker()) {
                    return Err(self.error(Step::ConfigurationOwnership));
                }
                Ok(Some(text))
            }
        }
    }
    fn service_active(&mut self) -> Result<bool> {
        let (code, stdout) = self.command(
            "systemctl",
            &["is-active", self.kind.service()],
            Step::ServiceInspection,
        )?;
        match (code, stdout.trim()) {
            (0, "active") => Ok(true),
            (3 | 4, _) => Ok(false),
            _ => Err(self.error(Step::ServiceInspection)),
        }
    }
    fn firewall(&mut self) -> Result<Option<Rule>> {
        let (code, text) =
            self.command("ufw", &["status", "numbered"], Step::FirewallInspection)?;
        if code != 0 {
            return Err(self.error(Step::FirewallInspection));
        }
        rule(&text, self.kind, self.address)
    }
    fn snapshot(&mut self) -> Result<State> {
        let configuration = self.configuration()?;
        let firewall_source = self.firewall()?.map(|r| r.source);
        Ok(State {
            configuration,
            service_active: self.service_active()?,
            firewall_source,
        })
    }
    fn actual(&mut self) -> &'static str {
        match self.snapshot() {
            Ok(state) if state.configuration.is_none() && state.firewall_source.is_none() => {
                "inactive"
            }
            Ok(state)
                if state.configuration.is_none()
                    || state.firewall_source != Some(self.metrics_address) =>
            {
                "drift"
            }
            Ok(state) => {
                if state.service_active {
                    "active"
                } else {
                    "inactive"
                }
            }
            Err(error)
                if matches!(
                    error.step,
                    Step::ConfigurationOwnership | Step::FirewallOwnership | Step::FirewallInactive
                ) =>
            {
                "drift"
            }
            Err(_) => "unknown",
        }
    }
    fn publish(&mut self, configuration: Option<&str>, step: Step) -> Result<()> {
        let path = self.path();
        match configuration {
            Some(text) => {
                atomic_write(&path, text.as_bytes(), 0o644).map_err(|_| self.error(step))?
            }
            None => remove_file(&path).map_err(|_| self.error(step))?,
        }
        self.checked("systemctl", &["daemon-reload"], step)?;
        if configuration.is_none() && self.kind == Kind::Cadvisor {
            // An unloaded or nonfailed unit has no failure record; systemd reports that with a nonzero exit.
            let _ = self.command("systemctl", &["reset-failed", self.kind.service()], step);
        }
        Ok(())
    }
    fn set_active(&mut self, active: bool, step: Step) -> Result<()> {
        self.checked(
            "systemctl",
            &[
                if active { "enable" } else { "disable" },
                "--now",
                self.kind.service(),
            ],
            step,
        )?;
        if active {
            self.checked("systemctl", &["restart", self.kind.service()], step)?;
        }
        Ok(())
    }
    fn set_firewall(&mut self, current: Option<Rule>, source: Option<Ipv4Addr>) -> Result<()> {
        if current.as_ref().map(|r| r.source) == source {
            return Ok(());
        }
        if let Some(rule) = current {
            self.checked(
                "ufw",
                &["--force", "delete", &rule.number],
                Step::FirewallRemove,
            )?;
        }
        if let Some(source) = source {
            self.checked(
                "ufw",
                &[
                    "allow",
                    "in",
                    "on",
                    "orbit",
                    "proto",
                    "tcp",
                    "from",
                    &source.to_string(),
                    "to",
                    &self.address.to_string(),
                    "port",
                    self.kind.port(),
                    "comment",
                    self.kind.comment(),
                ],
                Step::Firewall,
            )?;
        }
        Ok(())
    }
    fn install(&mut self) -> Result<()> {
        if self.kind == Kind::Exporter {
            let installed = self.command(
                "dpkg-query",
                &[
                    "--show",
                    "--showformat=${Status}",
                    "--",
                    self.kind.service(),
                ],
                Step::Install,
            )?;
            if installed.0 == 0 && installed.1.trim() == "install ok installed" {
                return Ok(());
            }
            return self.checked(
                "apt-get",
                &[
                    "-o",
                    "DPkg::Lock::Timeout=60",
                    "install",
                    "--yes",
                    "--no-install-recommends",
                    "--",
                    self.kind.service(),
                ],
                Step::Install,
            );
        }
        let path = self.root.join(CADVISOR_BINARY);
        match fs::read(&path) {
            Ok(bytes) if format!("{:x}", Sha256::digest(&bytes)) == self.checksum => return Ok(()),
            Err(e) if e.kind() != io::ErrorKind::NotFound => {
                return Err(self.error(Step::BinaryInspection))
            }
            _ => {}
        }
        let bytes = self
            .host
            .download()
            .map_err(|_| self.error(Step::Download))?;
        if format!("{:x}", Sha256::digest(&bytes)) != self.checksum {
            return Err(self.error(Step::Checksum));
        }
        atomic_write(&path, &bytes, 0o755).map_err(|_| self.error(Step::BinaryInstall))
    }
    fn validate_state(&self, state: &State) -> Result<()> {
        if state
            .configuration
            .as_ref()
            .is_some_and(|s| !s.starts_with(self.kind.marker()) || s.contains('\0'))
        {
            return Err(self.error(Step::Invalid));
        }
        Ok(())
    }
    fn restore(&mut self, state: &State) -> Result<()> {
        // Prove ownership of all current state before touching any of it.
        let configuration = self.configuration()?;
        let rule = self.firewall()?;
        let active = self.service_active()?;
        if self.kind == Kind::Cadvisor
            && state.configuration.is_some()
            && !self.root.join(CADVISOR_BINARY).exists()
        {
            self.install()?;
        }
        // Stop before removing a unit, so systemd can still find it.
        if !state.service_active && active {
            self.set_active(false, Step::ServiceRemove)?;
        }
        if configuration != state.configuration {
            self.publish(state.configuration.as_deref(), Step::Configuration)?;
        }
        if state.service_active {
            self.set_active(true, Step::Service)?;
        }
        self.set_firewall(rule, state.firewall_source)?;
        if self.configuration()? != state.configuration {
            return Err(self.error(Step::ConfigurationRollbackVerify));
        }
        if self.service_active()? != state.service_active {
            return Err(self.error(Step::ServiceRollbackVerify));
        }
        if self.firewall()?.map(|r| r.source) != state.firewall_source {
            return Err(self.error(Step::FirewallRollbackVerify));
        }
        Ok(())
    }
    fn change(&mut self, operation: &Operation, before: &State) -> Result<()> {
        match operation {
            Operation::Converge {} => {
                self.install()?;
                let expected = self.kind.configuration(self.address);
                self.publish(Some(&expected), Step::Configuration)?;
                self.set_active(true, Step::Service)?;
                let rule = self.firewall()?;
                self.set_firewall(rule, Some(self.metrics_address))?;
                if self.configuration()?.as_deref() != Some(&expected) {
                    return Err(self.error(Step::ConfigurationVerify));
                }
                if !self.service_active()? {
                    return Err(self.error(Step::ServiceVerify));
                }
                if self.firewall()?.map(|r| r.source) != Some(self.metrics_address) {
                    return Err(self.error(Step::FirewallVerify));
                }
            }
            Operation::Remove {} => {
                if before.configuration.is_some() {
                    self.set_active(false, Step::ServiceRemove)?;
                    self.publish(None, Step::ConfigurationRemove)?;
                }
                let rule = self.firewall()?;
                self.set_firewall(rule, None)?;
                if self.configuration()?.is_some() {
                    return Err(self.error(Step::ConfigurationRemoveVerify));
                }
                if before.configuration.is_some() && self.service_active()? {
                    return Err(self.error(Step::ServiceRemoveVerify));
                }
                if self.firewall()?.is_some() {
                    return Err(self.error(Step::FirewallRemoveVerify));
                }
                if self.kind == Kind::Cadvisor {
                    remove_file(&self.root.join(CADVISOR_BINARY))
                        .map_err(|_| self.error(Step::BinaryRemove))?;
                }
            }
            Operation::Restore { state } => self.restore(state)?,
            _ => unreachable!(),
        }
        Ok(())
    }
    fn execute(&mut self, operation: Operation) -> Result<Output> {
        if let Operation::Restore { state } = &operation {
            self.validate_state(state)?;
        }
        if matches!(operation, Operation::Actual {}) {
            return Ok(Output::Actual {
                actual: self.actual(),
            });
        }
        let before = self.snapshot()?;
        if matches!(operation, Operation::Snapshot {}) {
            return Ok(Output::State(before));
        }
        if let Err(error) = self.change(&operation, &before) {
            if let Err(rollback) = self.restore(&before) {
                return Err(Error {
                    recovery: Some([error.code().into(), rollback.code().into()]),
                    ..self.error(Step::Rollback)
                });
            }
            return Err(error);
        }
        Ok(Output::Changed { changed: true })
    }
}
fn remove_file(path: &Path) -> io::Result<()> {
    match fs::remove_file(path) {
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(()),
        result => result,
    }
}
fn atomic_write(path: &Path, bytes: &[u8], mode: u32) -> io::Result<()> {
    fs::create_dir_all(path.parent().unwrap())?;
    let candidate = path.with_extension(format!("orbit-{:016x}", rand::random::<u64>()));
    let result = (|| {
        let mut file = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(mode)
            .open(&candidate)?;
        file.write_all(bytes)?;
        file.set_permissions(fs::Permissions::from_mode(mode))?;
        file.sync_all()?;
        fs::rename(&candidate, path)
    })();
    let _ = remove_file(&candidate);
    result
}
fn run(kind: Kind, input: Input) -> Result<Output> {
    let address = input
        .address
        .parse::<Ipv4Addr>()
        .map_err(|_| kind.error(Step::Address))?;
    let metrics_address = input
        .metrics_address
        .parse::<Ipv4Addr>()
        .map_err(|_| kind.error(Step::Address))?;
    if kind == Kind::Cadvisor
        && std::env::consts::ARCH != "x86_64"
        && matches!(
            input.action,
            Operation::Converge {} | Operation::Restore { .. }
        )
    {
        return Err(kind.error(Step::Platform));
    }
    Runtime {
        root: "/".into(),
        kind,
        address,
        metrics_address,
        checksum: CADVISOR_HASH.into(),
        host: NativeHost,
    }
    .execute(input.action)
}
macro_rules! exporter_command {
    ($command:ident, $name:literal, $kind:expr) => {
        pub struct $command;
        impl Command for $command {
            const NAME: &'static str = $name;
            const SUMMARY: &'static str =
                "Snapshot, converge, remove, or restore one Metrics exporter.";
            // Snapshots share the same lock so fleet rollback never records half of a local transaction.
            const MUTATES: bool = true;
            const RESOURCES: &'static [&'static str] = &["apt", "firewall"];
            const TIMEOUT: Duration = Duration::from_secs(420);
            type Input = Input;
            type Output = Output;
            type Error = Error;
            fn privilege(platform: Platform) -> Option<Privilege> {
                super::linux_only(platform, Privilege::Root)
            }
            fn target(_: &Input) -> Option<String> {
                Some(Self::NAME.into())
            }
            fn run(input: Input, _: &Context) -> Result<Output> {
                run($kind, input)
            }
        }
    };
}
exporter_command!(NodeExporter, "metrics.node-exporter", Kind::Exporter);
exporter_command!(Cadvisor, "metrics.cadvisor", Kind::Cadvisor);

#[cfg(test)]
#[path = "metrics_exporter_tests.rs"]
mod tests;
