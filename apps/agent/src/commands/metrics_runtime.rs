//! Metrics configuration and container lifecycle form one Node-local transaction.
use crate::command::{Command, CommandError, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use std::{
    collections::BTreeMap,
    fs,
    io::{self, Write},
    net::Ipv4Addr,
    os::unix::fs::{MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

const BASE: &str = "/etc/orbit/metrics";
const MARKER: &str = "/etc/orbit/metrics/.orbit-owner";
const PATHS: [&str; 8] = [
    "/etc/orbit/metrics/prometheus.yml",
    "/etc/orbit/metrics/grafana/grafana.ini",
    "/etc/orbit/metrics/grafana/provisioning/datasources/prometheus.yml",
    "/etc/orbit/metrics/grafana/provisioning/dashboards/provider.yml",
    "/etc/orbit/metrics/grafana/dashboards/orbit-node-resources.json",
    "/etc/orbit/metrics/grafana/dashboards/orbit-caddy.json",
    "/etc/orbit/metrics/grafana/dashboards/orbit-fpm.json",
    "/etc/orbit/metrics/grafana/admin-password",
];
const DIRS: [&str; 6] = [
    BASE,
    "/etc/orbit/metrics/grafana",
    "/etc/orbit/metrics/grafana/provisioning",
    "/etc/orbit/metrics/grafana/provisioning/datasources",
    "/etc/orbit/metrics/grafana/provisioning/dashboards",
    "/etc/orbit/metrics/grafana/dashboards",
];
const MANAGED: &str = "com.orbit.managed";
const HASH: &str = "com.orbit.metrics.spec-hash";
const PROMETHEUS_IMAGE: &str = "prom/prometheus:v3.5.0";
const GRAFANA_IMAGE: &str = "grafana/grafana:12.1.1";
type Configuration = BTreeMap<String, String>;
type Labels = BTreeMap<String, String>;
type Result<T> = std::result::Result<T, Error>;
macro_rules! errors {
    ($( $variant:ident => $code:literal, $status:literal, $message:literal; )+) => {
        #[derive(Debug, Clone, Copy, PartialEq)]
        pub enum Error { $( $variant ),+ }
        impl CommandError for Error {
            const CODES: &'static [&'static str] = &[$($code),+];
            fn code(&self) -> &'static str { match self { $( Self::$variant => $code ),+ } }
            fn message(&self) -> String { match self { $( Self::$variant => $message.into() ),+ } }
            fn details(&self) -> Value { json!({"status": match self { $( Self::$variant => $status ),+ }}) }
        }
    }
}
errors! {
    Invalid => "metrics.runtime_input_invalid", 422, "Metrics runtime input is invalid.";
    Address => "metrics.wireguard_ip_missing", 409, "Metrics requires a WireGuard IPv4 address.";
    ConfigurationOwnership => "metrics.configuration_ownership_drift", 409, "Metrics configuration ownership cannot be proved.";
    ConfigurationSnapshot => "metrics.configuration_snapshot_failed", 502, "Metrics configuration could not be read.";
    ConfigurationPublish => "metrics.configuration_publish_failed", 502, "Metrics configuration could not be published.";
    ConfigurationRemove => "metrics.configuration_remove_failed", 502, "Metrics configuration could not be removed.";
    RuntimeRollback => "metrics.runtime_rollback_failed", 502, "Metrics configuration recovery did not complete.";
    PrometheusInvalid => "metrics.prometheus_configuration_invalid", 502, "The generated Prometheus configuration is invalid.";
    PrometheusTimeout => "metrics.prometheus_configuration_check_timed_out", 502, "Prometheus configuration validation timed out.";
    ImagePull => "metrics.image_pull_failed", 502, "A pinned Metrics image could not be pulled.";
    ImageTimeout => "metrics.image_pull_timed_out", 502, "A Metrics image pull timed out.";
    DockerUnavailable => "metrics.docker_unavailable", 502, "Docker is not running. Start it, then converge Metrics again.";
    ContainerInspection => "metrics.container_inspection_failed", 502, "Metrics container state could not be inspected.";
    VolumeInspection => "metrics.volume_inspection_failed", 502, "Metrics volume state could not be inspected.";
    ContainerLabels => "metrics.container_inspection_invalid", 502, "Metrics container ownership labels are invalid.";
    VolumeLabels => "metrics.volume_inspection_invalid", 502, "Metrics volume ownership labels are invalid.";
    ContainerOwnership => "metrics.container_ownership_drift", 409, "Refusing to adopt a foreign Metrics container.";
    VolumeOwnership => "metrics.volume_ownership_drift", 409, "Refusing to adopt a foreign Metrics volume.";
    ContainerConvergence => "metrics.container_convergence_failed", 502, "Metrics container convergence did not complete.";
    ContainerRollback => "metrics.container_rollback_failed", 502, "Metrics container recovery did not complete.";
    ContainerCleanup => "metrics.container_cleanup_failed", 502, "Metrics containers were committed but recovery container cleanup did not complete.";
    ContainerRemove => "metrics.container_remove_failed", 502, "A proven Metrics container could not be removed.";
    VolumePurge => "metrics.volume_purge_failed", 502, "A proven Metrics volume could not be removed.";
    Timeout => "metrics.remote_command_timed_out", 502, "A Metrics runtime command timed out.";
    Credentials => "metrics.credentials_reset_failed", 502, "Grafana credential reset did not complete.";
}
#[derive(Clone, Copy, Debug, Deserialize, Serialize, PartialEq)]
#[serde(rename_all = "snake_case")]
pub enum Service {
    Prometheus,
    Grafana,
}
impl Service {
    fn name(self) -> &'static str {
        match self {
            Self::Prometheus => "orbit-metrics-prometheus",
            Self::Grafana => "orbit-metrics-grafana",
        }
    }
    fn value(self) -> &'static str {
        match self {
            Self::Prometheus => "prometheus",
            Self::Grafana => "grafana",
        }
    }
    fn image(self) -> &'static str {
        match self {
            Self::Prometheus => PROMETHEUS_IMAGE,
            Self::Grafana => GRAFANA_IMAGE,
        }
    }
    fn volume(self) -> String {
        format!("{}-data", self.name())
    }
    fn backup(self) -> String {
        format!("{}-orbit-rollback", self.name())
    }
    fn labels(self, volume: bool) -> Labels {
        BTreeMap::from([
            (MANAGED.into(), "metrics".into()),
            (
                format!(
                    "com.orbit.metrics.{}",
                    if volume { "volume" } else { "service" }
                ),
                self.value().into(),
            ),
        ])
    }
}
const SERVICES: [Service; 2] = [Service::Prometheus, Service::Grafana];
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    address: String,
    action: Operation,
}
#[derive(Deserialize)]
#[serde(tag = "operation", rename_all = "snake_case", deny_unknown_fields)]
enum Operation {
    Converge {
        assignment: u64,
        configuration: Configuration,
    },
    Remove {
        purge_data: bool,
    },
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct HealthInput {
    service: Service,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct CredentialInput {
    address: String,
    action: CredentialAction,
}
#[derive(Deserialize)]
#[serde(tag = "operation", rename_all = "snake_case", deny_unknown_fields)]
enum CredentialAction {
    Verify {
        password: String,
    },
    Apply {
        active_password: String,
        pending_password: String,
    },
}
#[derive(Debug, Serialize)]
#[serde(untagged)]
pub enum Output {
    Changed { changed: bool },
    Healthy { healthy: bool },
    Verified { verified: bool },
}

/// The host boundary replaces fixed Docker commands and HTTP, never filesystem policy.
trait Host {
    fn docker(
        &mut self,
        args: &[String],
        input: Option<&str>,
        seconds: u64,
    ) -> io::Result<(i32, String)>;
    fn docker_present(&self) -> bool;
    fn chown(&mut self, path: &Path, uid: u32, gid: u32) -> io::Result<()>;
    fn pause(&mut self);
    fn credential(&mut self, address: Ipv4Addr, password: &str, pending: Option<&str>) -> bool;
}
struct NativeHost;
impl Host for NativeHost {
    fn docker(
        &mut self,
        args: &[String],
        input: Option<&str>,
        seconds: u64,
    ) -> io::Result<(i32, String)> {
        let mut child = Process::new("/usr/bin/timeout")
            .args(["--kill-after=5s", &format!("{seconds}s"), "/usr/bin/docker"])
            .args(args)
            .stdin(if input.is_some() {
                Stdio::piped()
            } else {
                Stdio::null()
            })
            .stdout(Stdio::piped())
            .stderr(Stdio::null())
            .spawn()?;
        if let Some(input) = input {
            if let Err(error) = child.stdin.take().unwrap().write_all(input.as_bytes()) {
                let _ = child.kill();
                let _ = child.wait();
                return Err(error);
            }
        }
        let output = child.wait_with_output()?;
        Ok((
            output.status.code().unwrap_or(-1),
            String::from_utf8_lossy(&output.stdout).into_owned(),
        ))
    }
    fn docker_present(&self) -> bool {
        Path::new("/usr/bin/docker").exists()
    }
    fn chown(&mut self, path: &Path, uid: u32, gid: u32) -> io::Result<()> {
        std::os::unix::fs::chown(path, Some(uid), Some(gid))
    }
    fn pause(&mut self) {
        std::thread::sleep(Duration::from_secs(2));
    }
    fn credential(&mut self, address: Ipv4Addr, password: &str, pending: Option<&str>) -> bool {
        credential_request(&format!("{address}:3000"), password, pending)
    }
}
fn credential_request(address: &str, password: &str, pending: Option<&str>) -> bool {
    let Ok(runtime) = tokio::runtime::Builder::new_current_thread()
        .enable_all()
        .build()
    else {
        return false;
    };
    runtime.block_on(async {
        let Ok(client) = reqwest::Client::builder()
            .timeout(Duration::from_secs(30))
            .redirect(reqwest::redirect::Policy::none())
            .no_proxy()
            .build()
        else {
            return false;
        };
        let request = if let Some(pending) = pending {
            client
                .put(format!("http://{address}/api/admin/users/1/password"))
                .json(&json!({"password": pending}))
        } else {
            client.get(format!("http://{address}/api/user"))
        };
        request
            .basic_auth("admin", Some(password))
            .send()
            .await
            .is_ok_and(|response| response.status().is_success())
    })
}

#[derive(Clone)]
struct Spec {
    service: Service,
    args: Vec<String>,
    hash: String,
}
impl Spec {
    fn new(
        service: Service,
        assignment: u64,
        address: Ipv4Addr,
        configuration: &Configuration,
    ) -> Self {
        let mut args: Vec<String> = [
            "container",
            "run",
            "--detach",
            "--pull=never",
            "--name",
            service.name(),
            "--restart",
            "unless-stopped",
            "--network",
            "host",
            "--log-driver",
            "json-file",
            "--log-opt",
            "max-size=10m",
            "--log-opt",
            "max-file=3",
        ]
        .into_iter()
        .map(str::to_owned)
        .collect();
        for (key, value) in service.labels(false) {
            args.extend(["--label".into(), format!("{key}={value}")]);
        }
        args.extend([
            "--label".into(),
            format!("com.orbit.metrics.assignment={assignment}"),
            "--mount".into(),
            format!(
                "type=volume,source={},target={}",
                service.volume(),
                if service == Service::Prometheus {
                    "/prometheus"
                } else {
                    "/var/lib/grafana"
                }
            ),
        ]);
        let mounts: &[&str] = if service == Service::Prometheus {
            &["/etc/orbit/metrics/prometheus.yml:/etc/prometheus/prometheus.yml:ro"]
        } else {
            &[
                "/etc/orbit/metrics/grafana/grafana.ini:/etc/grafana/grafana.ini:ro",
                "/etc/orbit/metrics/grafana/provisioning:/etc/grafana/provisioning:ro",
                "/etc/orbit/metrics/grafana/dashboards:/var/lib/grafana/dashboards:ro",
                "/etc/orbit/metrics/grafana/admin-password:/run/orbit/grafana-admin-password:ro",
            ]
        };
        for mount in mounts {
            args.extend(["--volume".into(), (*mount).into()]);
        }
        if service == Service::Grafana {
            for env in [
                "GF_SECURITY_ADMIN_USER=admin".into(),
                "GF_SECURITY_ADMIN_PASSWORD__FILE=/run/orbit/grafana-admin-password".into(),
                format!("GF_SERVER_HTTP_ADDR={address}"),
                "GF_SERVER_HTTP_PORT=3000".into(),
            ] {
                args.extend(["--env".into(), env]);
            }
        }
        let health = if service == Service::Prometheus {
            "http://127.0.0.1:9090/-/ready".into()
        } else {
            format!("http://{address}:3000/api/health")
        };
        args.extend([
            "--health-cmd".into(),
            format!("wget --no-verbose --tries=1 --spider {health}"),
            "--health-start-period".into(),
            "30s".into(),
            "--health-interval".into(),
            "5s".into(),
            "--health-retries".into(),
            "12".into(),
        ]);
        let command: Vec<String> = if service == Service::Prometheus {
            [
                "--config.file=/etc/prometheus/prometheus.yml",
                "--storage.tsdb.path=/prometheus",
                "--storage.tsdb.retention.time=7d",
                "--web.listen-address=127.0.0.1:9090",
            ]
            .into_iter()
            .map(str::to_owned)
            .collect()
        } else {
            vec![]
        };
        // Dashboard files are polled by Grafana. The initial password file is not its active credential.
        let files: Vec<_> = if service == Service::Prometheus {
            &PATHS[..1]
        } else {
            &PATHS[1..4]
        }
        .iter()
        .map(|path| (*path, configuration.get(*path)))
        .collect();
        let hash = format!(
            "{:x}",
            Sha256::digest(
                serde_json::to_vec(&json!([args, service.image(), command, files])).unwrap()
            )
        );
        args.extend([
            "--label".into(),
            format!("{HASH}={hash}"),
            "--".into(),
            service.image().into(),
        ]);
        args.extend(command);
        Self {
            service,
            args,
            hash,
        }
    }
}
#[derive(Clone)]
struct FileState {
    bytes: Vec<u8>,
    mode: u32,
    uid: u32,
    gid: u32,
}
struct Snapshot {
    existed: bool,
    files: Vec<Option<FileState>>,
}
#[derive(Default)]
struct Progress {
    previous: bool,
    stopped: bool,
    renamed: bool,
    started: bool,
}
struct Runtime<H> {
    root: PathBuf,
    host: H,
}
impl<H: Host> Runtime<H> {
    fn path(&self, path: &str) -> PathBuf {
        self.root.join(path.trim_start_matches('/'))
    }
    fn raw(&mut self, args: &[&str]) -> Result<(i32, String)> {
        self.call(
            args.iter().map(|arg| (*arg).into()).collect(),
            None,
            45,
            Error::Timeout,
        )
    }
    fn call(
        &mut self,
        args: Vec<String>,
        input: Option<&str>,
        seconds: u64,
        timeout: Error,
    ) -> Result<(i32, String)> {
        let result = self
            .host
            .docker(&args, input, seconds)
            .map_err(|_| Error::DockerUnavailable)?;
        if matches!(result.0, 124 | 137) {
            return Err(timeout);
        }
        Ok(result)
    }
    fn execute(&mut self, args: &[&str], error: Error) -> Result<()> {
        if self.raw(args)?.0 == 0 {
            Ok(())
        } else {
            Err(error)
        }
    }
    fn inspect(&mut self, service: Service, volume: bool, backup: bool) -> Result<Option<Labels>> {
        if !self.host.docker_present() {
            return Ok(None);
        }
        let name = if volume {
            service.volume()
        } else if backup {
            service.backup()
        } else {
            service.name().into()
        };
        let kind = if volume { "volume" } else { "container" };
        let format = if volume {
            "--format={{json .Labels}}"
        } else {
            "--format={{json .Config.Labels}}"
        };
        let result = self.raw(&[kind, "inspect", format, "--", &name])?;
        if result.0 == 0 {
            // Docker emits null for an unlabeled resource. It is foreign, not absent.
            let labels: Value = serde_json::from_str(&result.1).map_err(|_| {
                if volume {
                    Error::VolumeLabels
                } else {
                    Error::ContainerLabels
                }
            })?;
            let labels = if labels.is_null() {
                Labels::new()
            } else {
                serde_json::from_value(labels).map_err(|_| {
                    if volume {
                        Error::VolumeLabels
                    } else {
                        Error::ContainerLabels
                    }
                })?
            };
            if !service
                .labels(volume)
                .iter()
                .all(|(key, value)| labels.get(key) == Some(value))
            {
                return Err(if volume {
                    Error::VolumeOwnership
                } else {
                    Error::ContainerOwnership
                });
            }
            return Ok(Some(labels));
        }
        let filter = format!("name=^{}{name}$", if volume { "" } else { "/" });
        let args = if volume {
            vec![kind, "ls", "--filter", &filter, "--format={{.Name}}"]
        } else {
            vec![
                kind,
                "ls",
                "--all",
                "--filter",
                &filter,
                "--format={{.Names}}",
            ]
        };
        let absence = self.raw(&args)?;
        if absence.0 == 0 && absence.1.trim().is_empty() {
            Ok(None)
        } else {
            Err(if volume {
                Error::VolumeInspection
            } else {
                Error::ContainerInspection
            })
        }
    }
    fn healthy(&mut self, service: Service, wait: bool) -> Result<bool> {
        for attempt in 0..if wait { 30 } else { 1 } {
            let result = self.raw(&[
                "container",
                "inspect",
                "--format={{.State.Status}} {{.State.Health.Status}}",
                "--",
                service.name(),
            ])?;
            if result.0 != 0 {
                return Ok(false);
            }
            match result.1.trim() {
                "running healthy" => return Ok(true),
                "running starting" if wait && attempt < 29 => self.host.pause(),
                _ => return Ok(false),
            }
        }
        Ok(false)
    }
    fn check_paths(&self) -> Result<bool> {
        // Inspect ancestors too: a marker must never authorize writing through a symlink.
        for path in DIRS.into_iter().chain(PATHS).chain([MARKER]) {
            let mut current = self.root.clone();
            for part in Path::new(path)
                .components()
                .filter(|p| matches!(p, std::path::Component::Normal(_)))
            {
                current.push(part);
                match fs::symlink_metadata(&current) {
                    Ok(meta) if meta.file_type().is_symlink() => {
                        return Err(Error::ConfigurationOwnership)
                    }
                    Ok(meta) if current == self.path(path) => {
                        let directory = DIRS.contains(&path);
                        if (directory && !meta.is_dir()) || (!directory && !meta.is_file()) {
                            return Err(Error::ConfigurationOwnership);
                        }
                    }
                    Ok(meta) if !meta.is_dir() => return Err(Error::ConfigurationOwnership),
                    Err(e) if e.kind() == io::ErrorKind::NotFound => break,
                    Err(_) => return Err(Error::ConfigurationSnapshot),
                    _ => {}
                }
            }
        }
        let existed = self.path(BASE).exists();
        if existed && fs::read(self.path(MARKER)).ok().as_deref() != Some(b"metrics\n") {
            return Err(Error::ConfigurationOwnership);
        }
        Ok(existed)
    }
    fn snapshot(&self) -> Result<Snapshot> {
        let existed = self.check_paths()?;
        let mut files = vec![];
        for path in PATHS {
            let path = self.path(path);
            let state = match fs::metadata(&path) {
                Ok(meta) => Some(FileState {
                    bytes: fs::read(&path).map_err(|_| Error::ConfigurationSnapshot)?,
                    mode: meta.mode() & 0o7777,
                    uid: meta.uid(),
                    gid: meta.gid(),
                }),
                Err(e) if e.kind() == io::ErrorKind::NotFound => None,
                Err(_) => return Err(Error::ConfigurationSnapshot),
            };
            files.push(state);
        }
        Ok(Snapshot { existed, files })
    }
    fn publish_file(&mut self, path: &str, file: &FileState) -> io::Result<()> {
        let path = self.path(path);
        let candidate = path.with_extension(format!("orbit-{:016x}", rand::random::<u64>()));
        let result = (|| {
            let mut output = fs::OpenOptions::new()
                .write(true)
                .create_new(true)
                .mode(0o600)
                .open(&candidate)?;
            output.write_all(&file.bytes)?;
            self.host.chown(&candidate, file.uid, file.gid)?;
            output.set_permissions(fs::Permissions::from_mode(file.mode))?;
            output.sync_all()?;
            fs::rename(&candidate, &path)
        })();
        let _ = fs::remove_file(candidate);
        result
    }
    fn publish(&mut self, configuration: &Configuration) -> Result<()> {
        for directory in DIRS {
            let path = self.path(directory);
            fs::create_dir_all(&path)
                .and_then(|()| fs::set_permissions(&path, fs::Permissions::from_mode(0o755)))
                .and_then(|()| self.host.chown(&path, 0, 0))
                .map_err(|_| Error::ConfigurationPublish)?;
        }
        self.publish_file(
            MARKER,
            &FileState {
                bytes: b"metrics\n".to_vec(),
                mode: 0o640,
                uid: 0,
                gid: 0,
            },
        )
        .map_err(|_| Error::ConfigurationPublish)?;
        for path in PATHS {
            let secret = path == PATHS[7];
            self.publish_file(
                path,
                &FileState {
                    bytes: configuration[path].as_bytes().to_vec(),
                    mode: if secret { 0o400 } else { 0o644 },
                    uid: if secret { 472 } else { 0 },
                    gid: if secret { 472 } else { 0 },
                },
            )
            .map_err(|_| Error::ConfigurationPublish)?;
        }
        Ok(())
    }
    fn restore(&mut self, snapshot: &Snapshot) -> Result<()> {
        for (path, state) in PATHS.iter().zip(&snapshot.files) {
            match state {
                Some(state) => self.publish_file(path, state),
                None => remove_file(&self.path(path)),
            }
            .map_err(|_| Error::RuntimeRollback)?;
        }
        if !snapshot.existed {
            self.remove_configuration()
                .map_err(|_| Error::RuntimeRollback)?;
        }
        Ok(())
    }
    fn remove_configuration(&self) -> Result<()> {
        for path in PATHS.into_iter().chain([MARKER]) {
            remove_file(&self.path(path)).map_err(|_| Error::ConfigurationRemove)?;
        }
        for directory in DIRS.into_iter().rev() {
            match fs::remove_dir(self.path(directory)) {
                Err(e)
                    if !matches!(
                        e.kind(),
                        io::ErrorKind::NotFound | io::ErrorKind::DirectoryNotEmpty
                    ) =>
                {
                    return Err(Error::ConfigurationRemove)
                }
                _ => {}
            }
        }
        Ok(())
    }
    fn ensure_images(&mut self) -> Result<()> {
        for image in [PROMETHEUS_IMAGE, GRAFANA_IMAGE] {
            if self
                .raw(&["image", "inspect", "--format={{.Id}}", "--", image])?
                .0
                == 0
            {
                continue;
            }
            self.execute(
                &["info", "--format={{.ServerVersion}}"],
                Error::DockerUnavailable,
            )?;
            if self
                .call(
                    ["image", "pull", "--", image].map(str::to_owned).into(),
                    None,
                    300,
                    Error::ImageTimeout,
                )?
                .0
                != 0
            {
                return Err(Error::ImagePull);
            }
        }
        Ok(())
    }
    fn remove_container(&mut self, name: &str) -> Result<()> {
        self.execute(
            &["container", "rm", "--force", "--", name],
            Error::ContainerRemove,
        )
    }
    fn rename(&mut self, from: &str, to: &str) -> Result<()> {
        self.execute(
            &["container", "rename", from, to],
            Error::ContainerConvergence,
        )
    }
    fn start(&mut self, name: &str) -> Result<()> {
        self.execute(
            &["container", "start", "--", name],
            Error::ContainerConvergence,
        )
    }
    fn replace(&mut self, spec: &Spec, progress: &mut Progress) -> Result<()> {
        let service = spec.service;
        if progress.previous {
            if let Err(error) = self.execute(
                &["container", "stop", "--time", "30", "--", service.name()],
                Error::ContainerConvergence,
            ) {
                self.inspect(service, false, false)?;
                if self.raw(&[
                    "container",
                    "inspect",
                    "--format={{.State.Running}}",
                    "--",
                    service.name(),
                ])? != (0, "false\n".into())
                {
                    return Err(error);
                }
            }
            progress.stopped = true;
            if let Err(error) = self.rename(service.name(), &service.backup()) {
                if self.inspect(service, false, false)?.is_some()
                    || self.inspect(service, false, true)?.is_none()
                {
                    return Err(error);
                }
            }
            progress.renamed = true;
        }
        let result = self.call(spec.args.clone(), None, 45, Error::Timeout);
        if !matches!(result, Ok((0, _)))
            && !self
                .inspect(service, false, false)?
                .is_some_and(|labels| labels.get(HASH) == Some(&spec.hash))
        {
            return Err(Error::ContainerConvergence);
        }
        progress.started = true;
        if !self.healthy(service, true)? {
            return Err(Error::ContainerConvergence);
        }
        Ok(())
    }
    fn rollback(
        &mut self,
        specs: &[Spec],
        progress: &[Progress],
        volumes: &[Service],
    ) -> Result<()> {
        for (spec, progress) in specs.iter().zip(progress).rev() {
            let service = spec.service;
            if progress.started && self.inspect(service, false, false)?.is_some() {
                self.remove_container(service.name())?;
            }
            if progress.renamed {
                self.rename(&service.backup(), service.name())?;
            }
            if progress.stopped {
                self.start(service.name())?;
            }
        }
        for service in volumes.iter().rev() {
            self.execute(
                &["volume", "rm", "--", &service.volume()],
                Error::ContainerRollback,
            )?;
        }
        Ok(())
    }
    fn converge(
        &mut self,
        address: Ipv4Addr,
        assignment: u64,
        configuration: &Configuration,
    ) -> Result<()> {
        if assignment == 0
            || configuration.len() != PATHS.len()
            || PATHS.iter().any(|path| !configuration.contains_key(*path))
            || configuration[PATHS[7]].is_empty()
        {
            return Err(Error::Invalid);
        }
        let snapshot = self.snapshot()?;
        let specs: Vec<_> = SERVICES
            .into_iter()
            .map(|service| Spec::new(service, assignment, address, configuration))
            .collect();
        let mut states = vec![];
        for service in SERVICES {
            states.push((
                self.inspect(service, false, false)?,
                self.inspect(service, false, true)?,
                self.inspect(service, true, false)?,
            ));
        }
        self.ensure_images()?;
        if self
            .call(
                [
                    "container",
                    "run",
                    "--rm",
                    "--interactive",
                    "--entrypoint",
                    "/bin/promtool",
                    "--",
                    PROMETHEUS_IMAGE,
                    "check",
                    "config",
                    "/dev/stdin",
                ]
                .map(str::to_owned)
                .into(),
                Some(&configuration[PATHS[0]]),
                60,
                Error::PrometheusTimeout,
            )?
            .0
            != 0
        {
            return Err(Error::PrometheusInvalid);
        }
        let mut progress: Vec<_> = SERVICES.iter().map(|_| Progress::default()).collect();
        let mut volumes = vec![];
        let change = (|| {
            self.publish(configuration)?;
            for (i, spec) in specs.iter().enumerate() {
                let service = spec.service;
                let (current, backup, volume) = &mut states[i];
                if current.is_none() && backup.is_some() {
                    self.rename(&service.backup(), service.name())?;
                    self.start(service.name())?;
                    *current = backup.take();
                }
                if current.is_some() && backup.is_some() {
                    self.remove_container(&service.backup())?;
                }
                if volume.is_none() {
                    let mut args = vec!["volume".into(), "create".into()];
                    for (key, value) in service.labels(true) {
                        args.extend(["--label".into(), format!("{key}={value}")]);
                    }
                    args.extend(["--".into(), service.volume()]);
                    let created = self.call(args, None, 45, Error::Timeout)?;
                    if created.0 != 0 {
                        return Err(Error::ContainerConvergence);
                    }
                    volumes.push(service);
                }
                if current
                    .as_ref()
                    .is_some_and(|labels| labels.get(HASH) == Some(&spec.hash))
                    && self.healthy(service, false)?
                {
                    continue;
                }
                progress[i].previous = current.is_some();
                self.replace(spec, &mut progress[i])?;
            }
            Ok(())
        })();
        if let Err(error) = change {
            let rollback = self.rollback(&specs, &progress, &volumes);
            self.restore(&snapshot)?;
            if rollback.is_err() {
                return Err(Error::ContainerRollback);
            }
            return Err(error);
        }
        // Commit point: both replacements are healthy. Never restore old files after this point.
        for (spec, progress) in specs.iter().zip(&progress) {
            if progress.renamed && self.remove_container(&spec.service.backup()).is_err() {
                match self.inspect(spec.service, false, true) {
                    Ok(None) => {}
                    _ => return Err(Error::ContainerCleanup),
                }
            }
        }
        Ok(())
    }
    fn remove(&mut self, purge: bool) -> Result<()> {
        self.check_paths()?;
        let mut states = vec![];
        for service in SERVICES {
            states.push((
                service,
                self.inspect(service, false, false)?.is_some(),
                self.inspect(service, false, true)?.is_some(),
                if purge {
                    self.inspect(service, true, false)?.is_some()
                } else {
                    false
                },
            ));
        }
        for (service, current, backup, _) in &states {
            if *current {
                self.remove_container(service.name())?;
            }
            if *backup {
                self.remove_container(&service.backup())?;
            }
        }
        self.remove_configuration()?;
        for (service, _, _, volume) in states {
            if volume {
                self.execute(
                    &["volume", "rm", "--", &service.volume()],
                    Error::VolumePurge,
                )?;
            }
        }
        Ok(())
    }
}
fn remove_file(path: &Path) -> io::Result<()> {
    match fs::remove_file(path) {
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(()),
        result => result,
    }
}
fn native() -> Runtime<NativeHost> {
    Runtime {
        root: "/".into(),
        host: NativeHost,
    }
}
pub struct MetricsRuntime;
impl Command for MetricsRuntime {
    const NAME: &'static str = "metrics.runtime";
    const SUMMARY: &'static str =
        "Converge or remove the owned Metrics configuration and containers.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(1200);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("metrics-runtime".into())
    }
    fn run(input: Input, _: &Context) -> Result<Output> {
        let address = input.address.parse().map_err(|_| Error::Address)?;
        let mut runtime = native();
        match input.action {
            Operation::Converge {
                assignment,
                configuration,
            } => runtime.converge(address, assignment, &configuration)?,
            Operation::Remove { purge_data } => runtime.remove(purge_data)?,
        }
        Ok(Output::Changed { changed: true })
    }
}
pub struct MetricsHealth;
impl Command for MetricsHealth {
    const NAME: &'static str = "metrics.runtime-health";
    const SUMMARY: &'static str = "Observe the health of an owned Metrics container.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = HealthInput;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(input: HealthInput, _: &Context) -> Result<Output> {
        let mut runtime = native();
        let healthy = runtime
            .inspect(input.service, false, false)
            .is_ok_and(|state| state.is_some())
            && runtime.healthy(input.service, false).unwrap_or(false);
        Ok(Output::Healthy { healthy })
    }
}
pub struct MetricsCredentials;
impl Command for MetricsCredentials {
    const NAME: &'static str = "metrics.credentials";
    const SUMMARY: &'static str = "Verify or reset Grafana's administrator credential.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = CredentialInput;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &CredentialInput) -> Option<String> {
        Some("metrics-runtime".into())
    }
    fn run(input: CredentialInput, _: &Context) -> Result<Output> {
        credentials(&mut NativeHost, input)
    }
}
fn credentials(host: &mut impl Host, input: CredentialInput) -> Result<Output> {
    let address = input.address.parse().map_err(|_| Error::Address)?;
    match input.action {
        CredentialAction::Verify { password } => {
            if password.is_empty() {
                return Err(Error::Invalid);
            }
            Ok(Output::Verified {
                verified: host.credential(address, &password, None),
            })
        }
        CredentialAction::Apply {
            active_password,
            pending_password,
        } => {
            if active_password.is_empty() || pending_password.is_empty() {
                return Err(Error::Invalid);
            }
            if !host.credential(address, &active_password, Some(&pending_password)) {
                return Err(Error::Credentials);
            }
            Ok(Output::Changed { changed: true })
        }
    }
}
#[cfg(test)]
#[path = "metrics_runtime_tests.rs"]
mod tests;
