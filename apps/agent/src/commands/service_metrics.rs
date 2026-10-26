//! Own the FPM exporter and scrape firewall rules, including recovery of interrupted updates.

use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::{
    collections::{BTreeMap, BTreeSet},
    fs,
    io::{self, Write},
    net::Ipv4Addr,
    os::unix::fs::{DirBuilderExt, MetadataExt, OpenOptionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

const MARKER: &str = "# Managed by Orbit: service-metrics\n";
const SERVICE: &str = "orbit-fpm-exporter";
const PREFIX: &str = "orbit:metrics-service-";

command_errors! {
    pub enum ServiceMetricsError {
        Invalid { reason: String } => "metrics.service_invalid", "Service metrics state is invalid.";
        Ownership { path: String } => "metrics.service_ownership", "Service metrics found unmanaged state.";
        Failed { step: String } => "metrics.service_failed", "A service metrics step failed.";
        Recovery {} => "metrics.service_recovery_failed", "Service metrics recovery failed; the journal is retained.";
    }
}

type Result<T> = std::result::Result<T, ServiceMetricsError>;
fn invalid(reason: &str) -> ServiceMetricsError {
    ServiceMetricsError::Invalid {
        reason: reason.into(),
    }
}
fn failed(step: &str) -> ServiceMetricsError {
    ServiceMetricsError::Failed { step: step.into() }
}
fn ownership(path: &Path) -> ServiceMetricsError {
    ServiceMetricsError::Ownership {
        path: path.display().to_string(),
    }
}

#[derive(schemars::JsonSchema, Clone, Debug, Deserialize, Serialize, PartialEq)]
#[serde(deny_unknown_fields)]
pub struct State {
    pub config: Option<String>,
    pub unit: Option<String>,
    pub rules: Vec<Vec<String>>,
    pub active: bool,
    pub enabled: bool,
    pub binary: bool,
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(tag = "operation", rename_all = "snake_case", deny_unknown_fields)]
pub enum Input {
    Snapshot {},
    Apply { state: State },
}

#[derive(schemars::JsonSchema, Serialize)]
#[serde(untagged)]
pub enum Output {
    Snapshot(State),
    Applied {},
}

/// Accept only the fixed rule grammar. UFW reorders fields and omits `from any` for a deny.
fn canonical_rule(args: &[String]) -> Result<Vec<String>> {
    if args.len() < 4 || args[1..4] != ["in", "on", "orbit"] {
        return Err(invalid("firewall scope"));
    }
    let action = args[0].as_str();
    let mut fields = BTreeMap::new();
    let (pairs, remainder) = args[4..].as_chunks::<2>();
    for pair in pairs {
        if fields.insert(pair[0].as_str(), pair[1].as_str()).is_some() {
            return Err(invalid("duplicate firewall field"));
        }
    }
    if !remainder.is_empty() {
        return Err(invalid("firewall fields"));
    }
    if action == "deny" {
        fields.entry("from").or_insert("any");
    }
    if fields.keys().copied().collect::<Vec<_>>() != ["comment", "from", "port", "proto", "to"] {
        return Err(invalid("firewall fields"));
    }
    let kind = match fields["comment"]
        .strip_prefix(PREFIX)
        .and_then(|s| s.strip_suffix(&format!("-{action}")))
    {
        Some("fpm") => "fpm",
        Some("caddy") => "caddy",
        _ => return Err(invalid("firewall marker")),
    };
    if fields["proto"] != "tcp"
        || fields["port"] != if kind == "fpm" { "9114" } else { "9103" }
        || fields["to"].parse::<Ipv4Addr>().is_err()
        || match action {
            "allow" => fields["from"].parse::<Ipv4Addr>().is_err(),
            "deny" => fields["from"] != "any",
            _ => true,
        }
    {
        return Err(invalid("firewall address, action, protocol, or port"));
    }
    Ok([
        action,
        "in",
        "on",
        "orbit",
        "proto",
        "tcp",
        "from",
        fields["from"],
        "to",
        fields["to"],
        "port",
        fields["port"],
        "comment",
        fields["comment"],
    ]
    .map(str::to_owned)
    .into())
}

fn canonical_rules(rules: &[Vec<String>]) -> Result<Vec<Vec<String>>> {
    let mut seen = BTreeSet::new();
    rules
        .iter()
        .map(|rule| {
            let rule = canonical_rule(rule)?;
            if !seen.insert(rule.last().unwrap().clone()) {
                return Err(invalid("duplicate firewall marker"));
            }
            Ok(rule)
        })
        .collect()
}

/// These rules contain only fixed words, IPv4 addresses, ports, and quoted single-word markers.
fn firewall_rules(text: &str) -> Result<Vec<Vec<String>>> {
    let mut rules = Vec::new();
    for line in text.lines().filter(|line| line.contains(PREFIX)) {
        let mut words = line
            .split_whitespace()
            .map(|word| {
                if word.starts_with(['\'', '"']) {
                    if word.len() < 2 || word.as_bytes().first() != word.as_bytes().last() {
                        return Err(invalid("firewall quoting"));
                    }
                    Ok(word[1..word.len() - 1].to_owned())
                } else {
                    Ok(word.to_owned())
                }
            })
            .collect::<Result<Vec<_>>>()?;
        if words.first().map(String::as_str) != Some("ufw") {
            return Err(invalid("firewall record"));
        }
        words.remove(0);
        if words.first().map(String::as_str) == Some("insert") && words.len() >= 2 {
            words.drain(..2);
        }
        rules.push(words);
    }
    canonical_rules(&rules)
}

fn listener(config: &str) -> Result<String> {
    let address = config
        .lines()
        .find_map(|line| line.strip_prefix("  listen_addr: "))
        .ok_or_else(|| invalid("exporter listener"))?;
    let address = address.trim_matches(['\'', '"']);
    let host = address
        .strip_suffix(":9114")
        .ok_or_else(|| invalid("exporter listener"))?;
    host.parse::<Ipv4Addr>()
        .map_err(|_| invalid("exporter listener"))?;
    Ok(address.into())
}

impl State {
    fn validate(&self) -> Result<()> {
        if self.config.is_some() != self.unit.is_some()
            || (self.config.is_some() && !self.binary)
            || (self.config.is_none() && (self.active || self.enabled))
        {
            return Err(invalid("incomplete exporter state"));
        }
        if let (Some(config), Some(unit)) = (&self.config, &self.unit) {
            if !config.starts_with(MARKER) || !unit.starts_with(MARKER) {
                return Err(invalid("candidate ownership marker"));
            }
            listener(config)?;
        }
        if canonical_rules(&self.rules)? != self.rules {
            return Err(invalid("noncanonical desired firewall rules"));
        }
        Ok(())
    }
}

/// The only external effects: fixed systemd/UFW commands and bounded HTTP requests.
trait Host {
    fn command(&mut self, program: &str, args: &[String]) -> Result<(bool, String)>;
    fn download(&mut self, machine: &str) -> Result<Vec<u8>>;
    fn ready(&mut self, address: &str) -> Result<()>;
}

struct NativeHost;
impl NativeHost {
    fn get(url: &str, limit: usize, proxy: bool) -> Result<Vec<u8>> {
        tokio::runtime::Builder::new_current_thread()
            .enable_all()
            .build()
            .map_err(|_| failed("HTTP runtime"))?
            .block_on(async {
                let mut builder = reqwest::Client::builder().timeout(Duration::from_secs(12));
                if !proxy {
                    builder = builder.no_proxy();
                }
                let mut response = builder
                    .build()
                    .map_err(|_| failed("HTTP client"))?
                    .get(url)
                    .send()
                    .await
                    .map_err(|_| failed("HTTP request"))?
                    .error_for_status()
                    .map_err(|_| failed("HTTP status"))?;
                let mut bytes = Vec::new();
                while let Some(chunk) = response.chunk().await.map_err(|_| failed("HTTP body"))? {
                    let remaining = limit.saturating_sub(bytes.len());
                    bytes.extend_from_slice(&chunk[..chunk.len().min(remaining)]);
                    if bytes.len() == limit {
                        break;
                    }
                }
                Ok(bytes)
            })
    }
}
impl Host for NativeHost {
    fn command(&mut self, program: &str, args: &[String]) -> Result<(bool, String)> {
        let output = Process::new(program)
            .args(args)
            .stdin(Stdio::null())
            .output()
            .map_err(|_| failed(program))?;
        Ok((
            output.status.success(),
            String::from_utf8_lossy(&output.stdout).into_owned(),
        ))
    }
    fn download(&mut self, machine: &str) -> Result<Vec<u8>> {
        Self::get(&format!("https://github.com/cboxdk/fpm-exporter/releases/download/v3.1.1/fpm-exporter-linux-{machine}"), 64 * 1024 * 1024, true)
    }
    fn ready(&mut self, address: &str) -> Result<()> {
        for attempt in 0..5 {
            if Self::get(&format!("http://{address}/metrics"), 64, false)
                .is_ok_and(|bytes| bytes.starts_with(b"# HELP"))
                && self
                    .command(
                        "systemctl",
                        &["is-active".into(), "--quiet".into(), SERVICE.into()],
                    )?
                    .0
            {
                return Ok(());
            }
            if attempt < 4 {
                std::thread::sleep(Duration::from_millis(200));
            }
        }
        Err(failed("exporter readiness"))
    }
}

struct Runtime<H> {
    filesystem_root: PathBuf,
    root: PathBuf,
    unit: PathBuf,
    binary: PathBuf,
    owner: u32,
    checksum: String,
    machine: &'static str,
    host: H,
}

fn metadata(path: &Path) -> Result<Option<fs::Metadata>> {
    match fs::symlink_metadata(path) {
        Ok(value) => Ok(Some(value)),
        Err(error) if error.kind() == io::ErrorKind::NotFound => Ok(None),
        Err(_) => Err(ownership(path)),
    }
}

fn write(path: &Path, bytes: &[u8], mode: u32) -> Result<()> {
    let candidate = path.with_file_name(format!(".orbit-{:016x}", rand::random::<u64>()));
    let result = (|| {
        let mut file = fs::OpenOptions::new()
            .create_new(true)
            .write(true)
            .mode(mode)
            .open(&candidate)?;
        file.write_all(bytes)?;
        file.sync_all()?;
        fs::rename(&candidate, path)
    })();
    if result.is_err() {
        let _ = fs::remove_file(candidate);
    }
    result.map_err(|_| failed("file publication"))
}

impl<H: Host> Runtime<H> {
    fn owned(&self, path: &Path, private: bool) -> Result<Option<Vec<u8>>> {
        let Some(meta) = metadata(path)? else {
            return Ok(None);
        };
        if !meta.is_file()
            || meta.uid() != self.owner
            || meta.mode() & if private { 0o077 } else { 0o022 } != 0
        {
            return Err(ownership(path));
        }
        fs::read(path).map(Some).map_err(|_| ownership(path))
    }
    fn marked(&self, path: &Path) -> Result<Option<String>> {
        self.owned(path, false)?
            .map(|bytes| {
                String::from_utf8(bytes)
                    .ok()
                    .filter(|s| s.starts_with(MARKER))
                    .ok_or_else(|| ownership(path))
            })
            .transpose()
    }
    fn check_directories(&self) -> Result<()> {
        for path in [&self.root, &self.unit, &self.binary] {
            for parent in path
                .ancestors()
                .skip(1)
                .take_while(|parent| parent.starts_with(&self.filesystem_root))
            {
                if let Some(meta) = metadata(parent)? {
                    if !meta.is_dir() || meta.uid() != self.owner || meta.mode() & 0o022 != 0 {
                        return Err(ownership(parent));
                    }
                }
            }
        }
        if let Some(meta) = metadata(&self.root)? {
            if !meta.is_dir()
                || meta.uid() != self.owner
                || meta.mode() & 0o077 != 0
                || self.marked(&self.root.join(".orbit-owner"))?.as_deref() != Some(MARKER)
            {
                return Err(ownership(&self.root));
            }
        }
        Ok(())
    }
    fn binary_present(&self) -> Result<bool> {
        self.owned(&self.binary, false)?
            .map(|bytes| {
                if format!("{:x}", Sha256::digest(bytes)) != self.checksum {
                    return Err(ownership(&self.binary));
                }
                Ok(true)
            })
            .unwrap_or(Ok(false))
    }
    fn checked(&mut self, program: &str, args: &[String]) -> Result<String> {
        let (ok, stdout) = self.host.command(program, args)?;
        if ok {
            Ok(stdout)
        } else {
            Err(failed(program))
        }
    }
    fn systemctl(&mut self, args: &[&str]) -> Result<()> {
        self.checked(
            "systemctl",
            &args.iter().map(|s| (*s).into()).collect::<Vec<_>>(),
        )
        .map(|_| ())
    }
    fn firewall(&mut self) -> Result<(Vec<Vec<String>>, String)> {
        if !self
            .checked("ufw", &["status".into()])?
            .starts_with("Status: active")
        {
            return Err(invalid("firewall is inactive"));
        }
        let text = self.checked("ufw", &["show".into(), "added".into()])?;
        Ok((firewall_rules(&text)?, text))
    }
    fn inspect(&mut self) -> Result<State> {
        let config = self.marked(&self.root.join("fpm.yml"))?;
        let unit = self.marked(&self.unit)?;
        let binary = self.binary_present()?;
        let rules = self.firewall()?.0;
        let active = self
            .host
            .command(
                "systemctl",
                &["is-active".into(), "--quiet".into(), SERVICE.into()],
            )?
            .0;
        let enabled = self
            .host
            .command(
                "systemctl",
                &["is-enabled".into(), "--quiet".into(), SERVICE.into()],
            )?
            .0;
        if unit.is_none() && (active || enabled) {
            return Err(ownership(&self.unit));
        }
        Ok(State {
            config,
            unit,
            rules,
            active,
            enabled,
            binary,
        })
    }
    fn set_rules(&mut self, desired: &[Vec<String>]) -> Result<()> {
        let (current, text) = self.firewall()?;
        let leading = text
            .lines()
            .filter(|line| line.starts_with("ufw "))
            .take(desired.len())
            .map(|line| {
                line.split_whitespace()
                    .last()
                    .unwrap_or("")
                    .trim_matches(['\'', '"'])
            })
            .collect::<Vec<_>>();
        if current == desired
            && leading
                == desired
                    .iter()
                    .map(|rule| rule.last().unwrap().as_str())
                    .collect::<Vec<_>>()
        {
            return Ok(());
        }
        for rule in current.iter().rev() {
            self.checked(
                "ufw",
                &[vec!["--force".into(), "delete".into()], rule.clone()].concat(),
            )?;
        }
        for rule in desired.iter().rev() {
            self.checked(
                "ufw",
                &[vec!["insert".into(), "1".into()], rule.clone()].concat(),
            )?;
        }
        if self.firewall()?.0 != desired {
            return Err(failed("firewall verification"));
        }
        Ok(())
    }
    fn apply(&mut self, desired: &State) -> Result<()> {
        let before = self.inspect()?;
        if desired.binary && !before.binary {
            let bytes = self.host.download(self.machine)?;
            if format!("{:x}", Sha256::digest(&bytes)) != self.checksum {
                return Err(failed("exporter checksum"));
            }
            write(&self.binary, &bytes, 0o755)?;
        }
        if let (Some(config), Some(unit)) = (&desired.config, &desired.unit) {
            if before.config.as_ref() != Some(config) || before.unit.as_ref() != Some(unit) {
                write(&self.root.join("fpm.yml"), config.as_bytes(), 0o600)?;
                write(&self.unit, unit.as_bytes(), 0o644)?;
                self.systemctl(&["daemon-reload"])?;
            }
            self.set_rules(&desired.rules)?;
            if before.enabled != desired.enabled {
                self.systemctl(&[if desired.enabled { "enable" } else { "disable" }, SERVICE])?;
            }
            if desired.active {
                if before.config != desired.config || before.unit != desired.unit || !before.active
                {
                    self.systemctl(&["restart", SERVICE])?;
                }
                self.host.ready(&listener(config)?)?;
            } else if before.active {
                self.systemctl(&["stop", SERVICE])?;
            }
        } else {
            if before.unit.is_some() {
                self.systemctl(&["disable", "--now", SERVICE])?;
                fs::remove_file(&self.unit).map_err(|_| failed("remove unit"))?;
                self.systemctl(&["daemon-reload"])?;
            }
            if before.config.is_some() {
                fs::remove_file(self.root.join("fpm.yml"))
                    .map_err(|_| failed("remove configuration"))?;
            }
            self.set_rules(&desired.rules)?;
        }
        if !desired.binary && before.binary {
            fs::remove_file(&self.binary).map_err(|_| failed("remove binary"))?;
        }
        if self.inspect()? != *desired {
            return Err(failed("exporter state verification"));
        }
        Ok(())
    }
    fn run(&mut self, input: Input) -> Result<Output> {
        if let Input::Apply { state } = &input {
            state.validate()?;
        }
        self.check_directories()?;
        let journal = self.root.join("transaction.json");
        if let Some(bytes) = self.owned(&journal, true)? {
            let previous: State =
                serde_json::from_slice(&bytes).map_err(|_| invalid("recovery journal"))?;
            previous.validate()?;
            self.apply(&previous)
                .map_err(|_| ServiceMetricsError::Recovery {})?;
            fs::remove_file(&journal).map_err(|_| failed("remove recovered journal"))?;
        }
        let before = self.inspect()?;
        let Input::Apply { state } = input else {
            return Ok(Output::Snapshot(before));
        };
        before.validate()?;
        if !self.root.exists() {
            fs::DirBuilder::new()
                .mode(0o700)
                .recursive(true)
                .create(&self.root)
                .map_err(|_| failed("create configuration directory"))?;
            write(&self.root.join(".orbit-owner"), MARKER.as_bytes(), 0o600)?;
        }
        write(
            &journal,
            &serde_json::to_vec(&before).map_err(|_| failed("encode journal"))?,
            0o600,
        )?;
        if let Err(error) = self.apply(&state) {
            self.apply(&before)
                .map_err(|_| ServiceMetricsError::Recovery {})?;
            fs::remove_file(&journal).map_err(|_| failed("remove journal"))?;
            return Err(error);
        }
        fs::remove_file(&journal).map_err(|_| failed("remove journal"))?;
        Ok(Output::Applied {})
    }
}

pub struct ServiceRuntime;
impl Command for ServiceRuntime {
    const NAME: &'static str = "metrics.service-runtime";
    const SUMMARY: &'static str =
        "Recover, snapshot, or apply the FPM exporter and service scrape firewall rules.";
    // A snapshot can recover the previous transaction before reading the state.
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(180);
    const RESOURCES: &'static [&'static str] = &["firewall"];
    type Input = Input;
    type Output = Output;
    type Error = ServiceMetricsError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("service-metrics".into())
    }
    fn run(input: Input, _: &Context) -> Result<Output> {
        let (machine, checksum) = match std::env::consts::ARCH {
            "x86_64" => (
                "amd64",
                "570d2b3d3284179c47acff8efd8f73ea650e42a2c6bd08c0a8e95cb4b0e3ca98",
            ),
            "aarch64" => (
                "arm64",
                "d9773dfef3f6efbbbea0fe8be40ea79652d71ea24b20bfe89690ab026e205442",
            ),
            _ => return Err(invalid("exporter architecture")),
        };
        Runtime {
            filesystem_root: "/".into(),
            root: "/etc/orbit/service-metrics".into(),
            unit: "/etc/systemd/system/orbit-fpm-exporter.service".into(),
            binary: "/usr/local/bin/orbit-fpm-exporter".into(),
            owner: 0,
            checksum: checksum.into(),
            machine,
            host: NativeHost,
        }
        .run(input)
    }
}

#[cfg(test)]
#[path = "service_metrics_tests.rs"]
mod tests;
