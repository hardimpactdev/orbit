use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::path::{Path, PathBuf};
use std::process::Command;
use std::time::Duration;
use url::Url;

mod http;

pub use http::{run_agent_service, run_agent_service_blocking};

const GATEWAY_TIMEOUT: Duration = Duration::from_secs(5);

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct AgentConfig {
    pub gateway_url: String,
    pub node_id: String,
    pub node_name: String,
    pub gateway_name: String,
    pub bearer_token: Option<String>,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum ConfigError {
    MissingConfig(PathBuf),
    InvalidConfig(String),
}

impl std::fmt::Display for ConfigError {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            ConfigError::MissingConfig(path) => {
                write!(
                    formatter,
                    "Orbit Agent config is missing at {}",
                    path.display()
                )
            }
            ConfigError::InvalidConfig(message) => {
                write!(formatter, "Orbit Agent config is invalid: {message}")
            }
        }
    }
}

impl std::error::Error for ConfigError {}

impl AgentConfig {
    pub fn load_from_path(path: impl AsRef<Path>) -> Result<Self, ConfigError> {
        let path = path.as_ref();

        if !path.exists() {
            return Err(ConfigError::MissingConfig(path.to_path_buf()));
        }

        let contents = std::fs::read_to_string(path).map_err(|error| {
            ConfigError::InvalidConfig(format!("could not read {}: {error}", path.display()))
        })?;

        let parsed: AgentConfigFile = toml::from_str(&contents)
            .map_err(|error| ConfigError::InvalidConfig(error.to_string()))?;

        parsed.try_into()
    }

    pub fn load_default() -> Result<Self, ConfigError> {
        Self::load_from_path(default_config_path())
    }
}

#[derive(Debug, Deserialize)]
struct AgentConfigFile {
    gateway_url: String,
    node_id: String,
    node_name: String,
    gateway_name: String,
    bearer_token: Option<String>,
}

impl TryFrom<AgentConfigFile> for AgentConfig {
    type Error = ConfigError;

    fn try_from(value: AgentConfigFile) -> Result<Self, Self::Error> {
        require_present("gateway_url", &value.gateway_url)?;
        require_present("node_id", &value.node_id)?;
        require_present("node_name", &value.node_name)?;
        require_present("gateway_name", &value.gateway_name)?;

        Url::parse(&value.gateway_url).map_err(|error| {
            ConfigError::InvalidConfig(format!("gateway_url must be an absolute URL: {error}"))
        })?;

        let bearer_token = value.bearer_token.and_then(|token| {
            let token = token.trim();

            if token.is_empty() {
                return None;
            }

            Some(token.to_string())
        });

        Ok(Self {
            gateway_url: value.gateway_url.trim_end_matches('/').to_string(),
            node_id: value.node_id,
            node_name: value.node_name,
            gateway_name: value.gateway_name,
            bearer_token,
        })
    }
}

fn require_present(field: &str, value: &str) -> Result<(), ConfigError> {
    if value.trim().is_empty() {
        return Err(ConfigError::InvalidConfig(format!("{field} is required")));
    }

    Ok(())
}

pub fn default_config_path() -> PathBuf {
    if let Some(path) = std::env::var_os("ORBIT_AGENT_CONFIG") {
        return PathBuf::from(path);
    }

    let home = std::env::var_os("HOME").map_or_else(|| PathBuf::from("."), PathBuf::from);

    home.join(".config").join("orbit").join("agent.toml")
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RequestSpec {
    pub method: String,
    pub path: String,
    pub bearer_token: Option<String>,
    pub body: Option<String>,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct GatewayClient {
    config: AgentConfig,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum GatewayError {
    InvalidResponse(String),
    PayloadRejected(PayloadError),
    Transport(String),
}

impl GatewayClient {
    pub fn new(config: AgentConfig) -> Self {
        Self { config }
    }

    pub fn config(&self) -> &AgentConfig {
        &self.config
    }

    pub fn build_ping_request(&self) -> RequestSpec {
        self.request("GET", "/api/status", None)
    }

    pub fn build_claim_request(&self) -> RequestSpec {
        self.request("POST", "/api/orbit-agent/jobs/claim", None)
    }

    pub fn build_report_event_request(&self, job_id: &str, event: JobEvent) -> RequestSpec {
        self.request(
            "POST",
            &format!("/api/orbit-agent/jobs/{job_id}/events"),
            Some(json!({
                "event": event.as_gateway_event(),
                "payload": {
                    "message": format!("orbit agent {}", event.as_gateway_event()),
                },
            })),
        )
    }

    pub fn build_verify_operation_token_request(
        &self,
        operation_token: &str,
        command: &str,
    ) -> RequestSpec {
        let mut request = self.request(
            "POST",
            "/api/internal-executor/token/verify",
            Some(json!({
                "operation_token": operation_token,
                "command": command,
            })),
        );

        request.bearer_token = self.config.bearer_token.clone();

        request
    }

    pub fn parse_claim_response(&self, body: &str) -> Result<Option<Job>, GatewayError> {
        if body.trim().is_empty() {
            return Ok(None);
        }

        let envelope: ClaimEnvelope = serde_json::from_str(body)
            .map_err(|error| GatewayError::InvalidResponse(error.to_string()))?;

        let Some(job) = envelope.job else {
            return Ok(None);
        };

        let payload = validate_payload_value(&job.job_type, &job.payload)
            .map_err(GatewayError::PayloadRejected)?;

        Ok(Some(Job {
            id: job.id,
            job_type: job.job_type,
            target_node_name: job.target_node.name,
            payload,
        }))
    }

    pub fn parse_report_response(&self, body: &str) -> Result<JobStatus, GatewayError> {
        let envelope: ReportEnvelope = serde_json::from_str(body)
            .map_err(|error| GatewayError::InvalidResponse(error.to_string()))?;

        Ok(envelope.job)
    }

    pub fn parse_verify_operation_token_response(
        &self,
        body: &str,
    ) -> Result<OperationTokenVerification, GatewayError> {
        let envelope: OperationTokenVerificationEnvelope = serde_json::from_str(body)
            .map_err(|error| GatewayError::InvalidResponse(error.to_string()))?;

        Ok(envelope.success.data)
    }

    pub fn absolute_url(&self, path: &str) -> Result<String, GatewayError> {
        let base = format!("{}/", self.config.gateway_url);
        let url = Url::parse(&base)
            .and_then(|base| base.join(path.trim_start_matches('/')))
            .map_err(|error| GatewayError::Transport(error.to_string()))?;

        Ok(url.to_string())
    }

    fn request(&self, method: &str, path: &str, body: Option<Value>) -> RequestSpec {
        RequestSpec {
            method: method.to_string(),
            path: path.to_string(),
            bearer_token: None,
            body: body.map(|value| value.to_string()),
        }
    }
}

#[derive(Debug, Deserialize)]
struct ClaimEnvelope {
    job: Option<ClaimJob>,
}

#[derive(Debug, Deserialize)]
struct ClaimJob {
    id: String,
    #[serde(rename = "type")]
    job_type: String,
    target_node: ClaimTargetNode,
    payload: Value,
}

#[derive(Debug, Deserialize)]
struct ClaimTargetNode {
    name: String,
}

#[derive(Debug, Deserialize)]
struct ReportEnvelope {
    job: JobStatus,
}

#[derive(Debug, Deserialize)]
struct OperationTokenVerificationEnvelope {
    success: OperationTokenVerificationSuccess,
}

#[derive(Debug, Deserialize)]
struct OperationTokenVerificationSuccess {
    data: OperationTokenVerification,
}

#[derive(Debug, Clone, Deserialize, PartialEq, Eq)]
pub struct OperationTokenVerification {
    pub allowed: bool,
    pub reason: Option<String>,
    pub operation_id: Option<String>,
}

#[derive(Debug, Clone, Deserialize, PartialEq, Eq)]
pub struct JobStatus {
    pub id: String,
    pub status: String,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Job {
    pub id: String,
    pub job_type: String,
    pub target_node_name: String,
    pub payload: JobPayload,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum JobPayload {
    Noop,
    AppDevConvergence(AppDevConvergencePayload),
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct AppDevConvergencePayload {
    pub operation: String,
    pub role: String,
    pub tld: String,
    pub tools: Vec<AppDevTool>,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum AppDevTool {
    Caddy,
    Composer,
    Docker,
    LaravelInstaller,
    PhpCli,
}

impl AppDevTool {
    fn from_slug(value: &str) -> Result<Self, PayloadError> {
        match value {
            "caddy" => Ok(Self::Caddy),
            "composer" => Ok(Self::Composer),
            "docker" => Ok(Self::Docker),
            "laravel-installer" => Ok(Self::LaravelInstaller),
            "php-cli" => Ok(Self::PhpCli),
            unknown => Err(PayloadError::Rejected(format!(
                "app-dev convergence tool `{unknown}` is not allowed"
            ))),
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum JobEvent {
    Accepted,
    Running,
    PrivilegeRequested,
    Succeeded,
    Failed,
}

impl JobEvent {
    pub fn as_gateway_event(self) -> &'static str {
        match self {
            JobEvent::Accepted => "accepted",
            JobEvent::Running => "running",
            JobEvent::PrivilegeRequested => "privilege_requested",
            JobEvent::Succeeded => "succeeded",
            JobEvent::Failed => "failed",
        }
    }
}

pub fn lifecycle_events_for_noop(job: &Job) -> Vec<JobEvent> {
    if job.job_type != "noop" {
        return vec![JobEvent::Accepted, JobEvent::Running, JobEvent::Failed];
    }

    vec![JobEvent::Accepted, JobEvent::Running, JobEvent::Succeeded]
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum PayloadError {
    Rejected(String),
}

pub fn validate_payload(job_type: &str, payload: &str) -> Result<JobPayload, PayloadError> {
    let value: Value = serde_json::from_str(payload)
        .map_err(|error| PayloadError::Rejected(format!("payload must be JSON: {error}")))?;

    validate_payload_value(job_type, &value)
}

fn validate_payload_value(job_type: &str, payload: &Value) -> Result<JobPayload, PayloadError> {
    match job_type {
        "noop" => {
            reject_unsafe_payload(payload, "noop")?;

            Ok(JobPayload::Noop)
        }
        "app-dev-convergence" => {
            reject_unsafe_payload(payload, "app-dev convergence")?;

            validate_app_dev_convergence_payload(payload).map(JobPayload::AppDevConvergence)
        }
        _ => Err(PayloadError::Rejected(format!(
            "unsupported Orbit Agent job type `{job_type}`"
        ))),
    }
}

#[derive(Debug, Deserialize)]
struct RawAppDevConvergencePayload {
    operation: String,
    role: String,
    tld: String,
    tools: Vec<String>,
}

const APP_DEV_TOOL_CATALOG: [AppDevTool; 5] = [
    AppDevTool::Docker,
    AppDevTool::PhpCli,
    AppDevTool::Composer,
    AppDevTool::LaravelInstaller,
    AppDevTool::Caddy,
];

fn validate_app_dev_convergence_payload(
    payload: &Value,
) -> Result<AppDevConvergencePayload, PayloadError> {
    let raw: RawAppDevConvergencePayload =
        serde_json::from_value(payload.clone()).map_err(|error| {
            PayloadError::Rejected(format!("app-dev convergence payload is invalid: {error}"))
        })?;

    if raw.operation != "app_dev_convergence" {
        return Err(PayloadError::Rejected(
            "app-dev convergence operation must be app_dev_convergence".to_string(),
        ));
    }

    if raw.role != "app-dev" {
        return Err(PayloadError::Rejected(
            "app-dev convergence role must be app-dev".to_string(),
        ));
    }

    if raw.tld.trim().is_empty() {
        return Err(PayloadError::Rejected(
            "app-dev convergence tld is required".to_string(),
        ));
    }

    let tools = raw
        .tools
        .iter()
        .map(|tool| AppDevTool::from_slug(tool))
        .collect::<Result<Vec<_>, _>>()?;

    if tools.as_slice() != APP_DEV_TOOL_CATALOG.as_slice() {
        return Err(PayloadError::Rejected(
            "app-dev convergence tools must match the fixed app-dev tool catalog".to_string(),
        ));
    }

    Ok(AppDevConvergencePayload {
        operation: raw.operation,
        role: raw.role,
        tld: raw.tld,
        tools,
    })
}

fn reject_unsafe_payload(value: &Value, label: &str) -> Result<(), PayloadError> {
    match value {
        Value::Object(entries) => {
            for (key, value) in entries {
                let normalized = key.to_ascii_lowercase();

                if matches!(normalized.as_str(), "shell" | "argv") {
                    return Err(PayloadError::Rejected(format!(
                        "{label} payload cannot contain shell or argv work"
                    )));
                }

                if matches!(
                    normalized.as_str(),
                    "command" | "operation_token" | "bearer" | "password" | "secret" | "api_key"
                ) {
                    return Err(PayloadError::Rejected(format!(
                        "{label} payload cannot contain unsafe key `{key}`"
                    )));
                }

                reject_unsafe_payload(value, label)?;
            }
        }
        Value::Array(values) => {
            for value in values {
                reject_unsafe_payload(value, label)?;
            }
        }
        Value::String(value) => {
            if value.contains("BEGIN PRIVATE KEY") {
                return Err(PayloadError::Rejected(format!(
                    "{label} payload cannot contain private key material"
                )));
            }
        }
        Value::Null | Value::Bool(_) | Value::Number(_) => {}
    }

    Ok(())
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum ConnectionStatus {
    Connected,
    Disconnected(String),
    MissingConfig(PathBuf),
}

impl ConnectionStatus {
    pub fn label(&self) -> String {
        match self {
            ConnectionStatus::Connected => "Connected".to_string(),
            ConnectionStatus::Disconnected(reason) => format!("Disconnected: {reason}"),
            ConnectionStatus::MissingConfig(path) => {
                format!("Disconnected: missing config at {}", path.display())
            }
        }
    }
}

pub fn connection_status_from_ping(status_code: u16) -> ConnectionStatus {
    if (200..500).contains(&status_code) {
        return ConnectionStatus::Connected;
    }

    ConnectionStatus::Disconnected(format!("gateway returned HTTP {status_code}"))
}

pub fn gateway_host_from_config(config: &AgentConfig) -> String {
    Url::parse(&config.gateway_url)
        .ok()
        .and_then(|url| url.host_str().map(ToString::to_string))
        .unwrap_or_else(|| config.gateway_url.clone())
}

pub fn ping_gateway_connection(config: &AgentConfig) -> ConnectionStatus {
    let client = GatewayClient::new(config.clone());
    let request = client.build_ping_request();

    let url = match client.absolute_url(&request.path) {
        Ok(url) => url,
        Err(error) => return ConnectionStatus::Disconnected(format!("{error:?}")),
    };

    let mut ping = ureq::get(&url);
    ping = ping.timeout(GATEWAY_TIMEOUT);

    if let Some(token) = request.bearer_token {
        ping = ping.set("Authorization", &format!("Bearer {token}"));
    }

    match ping.call() {
        Ok(response) => connection_status_from_ping(response.status()),
        Err(ureq::Error::Status(status, _)) => connection_status_from_ping(status),
        Err(error) => ConnectionStatus::Disconnected(error.to_string()),
    }
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct ServiceStatusSnapshot {
    pub connection: String,
    pub gateway_ip: String,
    pub node_ip: Option<String>,
    pub node_name: Option<String>,
    pub config_loaded: bool,
}

pub fn build_service_status_snapshot() -> ServiceStatusSnapshot {
    match AgentConfig::load_default() {
        Ok(config) => {
            let gateway_ip = gateway_host_from_config(&config);
            let status = ping_gateway_connection(&config);

            ServiceStatusSnapshot {
                connection: status.label(),
                gateway_ip,
                node_ip: None,
                node_name: Some(config.node_name.clone()),
                config_loaded: true,
            }
        }
        Err(ConfigError::MissingConfig(path)) => ServiceStatusSnapshot {
            connection: ConnectionStatus::MissingConfig(path.clone()).label(),
            gateway_ip: "unknown".to_string(),
            node_ip: None,
            node_name: None,
            config_loaded: false,
        },
        Err(error) => ServiceStatusSnapshot {
            connection: ConnectionStatus::Disconnected(error.to_string()).label(),
            gateway_ip: "unknown".to_string(),
            node_ip: None,
            node_name: None,
            config_loaded: false,
        },
    }
}

pub fn poll_once_from_default_config() {
    if let Ok(config) = AgentConfig::load_default() {
        let gateway = HttpAgentGateway::new(config);
        let mut worker = PollingWorker::new(gateway);

        if let Err(error) = worker.poll_once() {
            eprintln!("Orbit Agent poll failed: {error:?}");
        }
    }
}

pub fn run_polling_worker_loop(poll_interval: Duration) {
    loop {
        poll_once_from_default_config();
        std::thread::sleep(poll_interval);
    }
}

pub fn default_http_bind_addr() -> String {
    if let Ok(addr) = std::env::var("ORBIT_AGENT_HTTP_BIND") {
        if !addr.trim().is_empty() {
            return addr;
        }
    }

    "127.0.0.1:9477".to_string()
}

pub trait AgentGateway {
    fn claim_next_job(&mut self) -> Result<Option<Job>, GatewayError>;

    fn report_event(&mut self, job_id: &str, event: JobEvent) -> Result<(), GatewayError>;
}

pub struct HttpAgentGateway {
    client: GatewayClient,
    agent: ureq::Agent,
}

impl HttpAgentGateway {
    pub fn new(config: AgentConfig) -> Self {
        Self {
            client: GatewayClient::new(config),
            agent: ureq::AgentBuilder::new()
                .timeout_connect(GATEWAY_TIMEOUT)
                .timeout_read(GATEWAY_TIMEOUT)
                .timeout_write(GATEWAY_TIMEOUT)
                .build(),
        }
    }

    fn send(&self, request: RequestSpec) -> Result<String, GatewayError> {
        self.send_optional_not_found(request)?
            .ok_or_else(|| GatewayError::Transport("gateway returned HTTP 404".to_string()))
    }

    fn send_optional_not_found(
        &self,
        request: RequestSpec,
    ) -> Result<Option<String>, GatewayError> {
        let url = self.client.absolute_url(&request.path)?;
        let mut http_request = match request.method.as_str() {
            "GET" => self.agent.get(&url),
            "POST" => self.agent.post(&url),
            method => {
                return Err(GatewayError::Transport(format!(
                    "unsupported HTTP method `{method}`"
                )))
            }
        };

        if let Some(token) = request.bearer_token {
            http_request = http_request.set("Authorization", &format!("Bearer {token}"));
        }

        let response = if let Some(body) = request.body {
            http_request
                .set("Content-Type", "application/json")
                .send_string(&body)
        } else {
            http_request.call()
        };

        match response {
            Ok(response) => response
                .into_string()
                .map(Some)
                .map_err(|error| GatewayError::Transport(error.to_string())),
            Err(ureq::Error::Status(404, _)) => Ok(None),
            Err(ureq::Error::Status(status, response)) => {
                let body = response.into_string().unwrap_or_default();

                Err(GatewayError::Transport(format!(
                    "gateway returned HTTP {status}: {body}"
                )))
            }
            Err(error) => Err(GatewayError::Transport(error.to_string())),
        }
    }

    pub fn verify_operation_token(
        &self,
        operation_token: &str,
        command: &str,
    ) -> Result<OperationTokenVerification, GatewayError> {
        let body = self.send(
            self.client
                .build_verify_operation_token_request(operation_token, command),
        )?;

        self.client.parse_verify_operation_token_response(&body)
    }
}

impl AgentGateway for HttpAgentGateway {
    fn claim_next_job(&mut self) -> Result<Option<Job>, GatewayError> {
        let Some(body) = self.send_optional_not_found(self.client.build_claim_request())? else {
            return Ok(None);
        };

        self.client.parse_claim_response(&body)
    }

    fn report_event(&mut self, job_id: &str, event: JobEvent) -> Result<(), GatewayError> {
        let body = self.send(self.client.build_report_event_request(job_id, event))?;

        if body.trim().is_empty() {
            return Ok(());
        }

        self.client.parse_report_response(&body)?;

        Ok(())
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct JobExecutionSummary {
    pub job_id: String,
    pub events: Vec<JobEvent>,
    pub executed_operations: Vec<ExecutedOperation>,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum ExecutedOperation {
    AppDevConvergence { tools: Vec<AppDevTool> },
}

pub trait JobExecutor {
    fn execute(&mut self, job: &Job) -> Result<Vec<ExecutedOperation>, GatewayError>;
}

#[derive(Debug, Default)]
pub struct LocalJobExecutor;

impl JobExecutor for LocalJobExecutor {
    fn execute(&mut self, job: &Job) -> Result<Vec<ExecutedOperation>, GatewayError> {
        match &job.payload {
            JobPayload::Noop => Ok(Vec::new()),
            JobPayload::AppDevConvergence(payload) => {
                self.converge_app_dev(payload)?;

                Ok(vec![ExecutedOperation::AppDevConvergence {
                    tools: payload.tools.clone(),
                }])
            }
        }
    }
}

impl LocalJobExecutor {
    fn launchd_safe_path() -> String {
        let home = std::env::var("HOME").unwrap_or_else(|_| "~".to_string());

        [
            format!("{home}/.orbstack/bin"),
            format!("{home}/.docker/bin"),
            format!("{home}/.local/bin"),
            format!("{home}/.composer/vendor/bin"),
            "/Applications/OrbStack.app/Contents/MacOS/xbin".to_string(),
            "/Applications/Docker.app/Contents/Resources/bin".to_string(),
            "/opt/homebrew/bin".to_string(),
            "/opt/homebrew/sbin".to_string(),
            "/usr/local/bin".to_string(),
            "/usr/bin".to_string(),
            "/bin".to_string(),
            "/usr/sbin".to_string(),
            "/sbin".to_string(),
        ]
        .join(":")
    }

    fn script_with_launchd_safe_path(script: &str) -> String {
        format!(
            "export PATH=\"{}:${{PATH:-}}\"\n{}",
            Self::launchd_safe_path(),
            script
        )
    }

    fn script_working_directory() -> PathBuf {
        Self::script_working_directory_for(
            std::env::var_os("HOME").map(PathBuf::from),
            PathBuf::from("/tmp"),
        )
    }

    fn script_working_directory_for(home: Option<PathBuf>, fallback: PathBuf) -> PathBuf {
        match home {
            Some(home) if home.is_dir() => home,
            _ => fallback,
        }
    }

    fn converge_app_dev(&self, payload: &AppDevConvergencePayload) -> Result<(), GatewayError> {
        for tool in &payload.tools {
            self.converge_tool(*tool)?;
        }

        Ok(())
    }

    fn converge_tool(&self, tool: AppDevTool) -> Result<(), GatewayError> {
        let (label, script) = match tool {
            AppDevTool::Docker => ("docker", DOCKER_SCRIPT),
            AppDevTool::Caddy => ("caddy", CADDY_SCRIPT),
            AppDevTool::PhpCli => ("php-cli", PHP_CLI_SCRIPT),
            AppDevTool::Composer => ("composer", COMPOSER_SCRIPT),
            AppDevTool::LaravelInstaller => ("laravel-installer", LARAVEL_INSTALLER_SCRIPT),
        };

        self.run_script(label, script)
    }

    fn run_script(&self, label: &str, script: &str) -> Result<(), GatewayError> {
        let script = Self::script_with_launchd_safe_path(script);
        let path = Self::launchd_safe_path();
        let working_directory = Self::script_working_directory();
        let output = Command::new("/bin/bash")
            .arg("-lc")
            .arg(script)
            .env("PATH", path)
            .current_dir(working_directory)
            .output()
            .map_err(|error| {
                GatewayError::Transport(format!("{label} failed to start: {error}"))
            })?;

        if output.status.success() {
            return Ok(());
        }

        let stdout = String::from_utf8_lossy(&output.stdout);
        let stderr = String::from_utf8_lossy(&output.stderr);
        let separator = if stdout.trim().is_empty() || stderr.trim().is_empty() {
            ""
        } else {
            "\n"
        };

        Err(GatewayError::Transport(format!(
            "{label} failed with exit {}: {}{}{}",
            output.status,
            stdout.trim(),
            separator,
            stderr.trim(),
        )))
    }
}

pub struct PollingWorker<G, E = LocalJobExecutor> {
    gateway: G,
    executor: E,
}

impl<G> PollingWorker<G, LocalJobExecutor>
where
    G: AgentGateway,
{
    pub fn new(gateway: G) -> Self {
        Self {
            gateway,
            executor: LocalJobExecutor,
        }
    }
}

impl<G, E> PollingWorker<G, E>
where
    G: AgentGateway,
    E: JobExecutor,
{
    pub fn with_executor(gateway: G, executor: E) -> Self {
        Self { gateway, executor }
    }

    pub fn poll_once(&mut self) -> Result<Option<JobExecutionSummary>, GatewayError> {
        let Some(job) = self.gateway.claim_next_job()? else {
            return Ok(None);
        };

        let mut events = Vec::new();

        self.gateway.report_event(&job.id, JobEvent::Running)?;
        events.push(JobEvent::Running);

        let executed_operations = match self.executor.execute(&job) {
            Ok(operations) => operations,
            Err(error) => {
                let _ = self.gateway.report_event(&job.id, JobEvent::Failed);

                return Err(error);
            }
        };

        self.gateway.report_event(&job.id, JobEvent::Succeeded)?;
        events.push(JobEvent::Succeeded);

        Ok(Some(JobExecutionSummary {
            job_id: job.id,
            events,
            executed_operations,
        }))
    }
}

const DOCKER_SCRIPT: &str = r#"
set -e
docker info >/dev/null
"#;

const PHP_CLI_SCRIPT: &str = r#"
set -e

case "$(uname -s)" in
    Linux) OS=linux ;;
    Darwin) OS=macos ;;
    *) echo "unsupported os" >&2; exit 1 ;;
esac

case "$(uname -m)" in
    x86_64|amd64) ARCH=x86_64 ;;
    aarch64|arm64) ARCH=aarch64 ;;
    *) echo "unsupported arch" >&2; exit 1 ;;
esac

install_php() {
    minor="$1"
    patch="$2"
    root="/opt/orbit/php/${minor}"

    if [ -x "${root}/bin/php" ]; then
        return
    fi

    sudo install -d -m 0755 /opt /opt/orbit /opt/orbit/php "${root}" "${root}/bin"
    curl -fsSL --retry 5 --retry-delay 2 --retry-all-errors "https://dl.static-php.dev/static-php-cli/bulk/php-${patch}-cli-${OS}-${ARCH}.tar.gz" -o "/tmp/orbit-php-${minor}.tar.gz"
    sudo tar -xzf "/tmp/orbit-php-${minor}.tar.gz" -C "${root}/bin"
    sudo chmod 0755 /opt /opt/orbit /opt/orbit/php "${root}" "${root}/bin"
    sudo chmod 0755 "${root}/bin/php"
    sudo ln -sf "${root}/bin/php" "/usr/local/bin/php${minor}"
    sudo chmod -h 0755 "/usr/local/bin/php${minor}" 2>/dev/null || true
    rm -f "/tmp/orbit-php-${minor}.tar.gz"
}

install_php 8.5 8.5.6
install_php 8.4 8.4.21
install_php 8.3 8.3.31

sudo ln -sf /opt/orbit/php/8.5/bin/php /usr/local/bin/php
sudo chmod -h 0755 /usr/local/bin/php 2>/dev/null || true
/opt/orbit/php/8.5/bin/php --version >/dev/null
"#;

const COMPOSER_SCRIPT: &str = r#"
set -e

if command -v composer >/dev/null 2>&1 || [ -x /usr/local/bin/composer ]; then
    exit 0
fi

PHP_BIN="/opt/orbit/php/8.5/bin/php"
if [ ! -x "${PHP_BIN}" ]; then
    if command -v php >/dev/null 2>&1; then
        PHP_BIN="$(command -v php)"
    else
        echo "php is required before installing composer." >&2
        exit 1
    fi
fi

cd /tmp
if ! EXPECTED_SIG="$(curl -fsSL https://composer.github.io/installer.sig)"; then
    echo "Failed to fetch Composer installer signature." >&2
    exit 1
fi

if ! curl -fsSL https://getcomposer.org/installer -o composer-setup.php; then
    echo "Failed to download Composer installer." >&2
    exit 1
fi

if command -v sha384sum >/dev/null 2>&1; then
    ACTUAL_SIG="$(sha384sum composer-setup.php | awk '{print $1}')"
elif command -v shasum >/dev/null 2>&1; then
    ACTUAL_SIG="$(shasum -a 384 composer-setup.php | awk '{print $1}')"
else
    echo "No SHA-384 checksum utility found." >&2
    rm -f composer-setup.php
    exit 1
fi

if [ "${EXPECTED_SIG}" != "${ACTUAL_SIG}" ]; then
    echo "Composer installer signature verification failed." >&2
    rm -f composer-setup.php
    exit 1
fi

if ! sudo "${PHP_BIN}" composer-setup.php --install-dir=/usr/local/bin --filename=composer; then
    echo "Composer installer command failed." >&2
    rm -f composer-setup.php
    exit 1
fi

rm -f composer-setup.php
if [ ! -x /usr/local/bin/composer ]; then
    echo "Composer installation did not create /usr/local/bin/composer." >&2
    exit 1
fi
"#;

const LARAVEL_INSTALLER_SCRIPT: &str = r#"
set -e

if ! command -v composer >/dev/null 2>&1; then
    echo "composer is required before installing laravel/installer" >&2
    exit 1
fi

export COMPOSER_HOME="${COMPOSER_HOME:-${HOME}/.config/composer}"
mkdir -p "${COMPOSER_HOME}"
composer global require laravel/installer --no-interaction --no-progress

if [ -x "${COMPOSER_HOME}/vendor/bin/laravel" ]; then
    sudo ln -sf "${COMPOSER_HOME}/vendor/bin/laravel" /usr/local/bin/laravel
    sudo chmod -h 0755 /usr/local/bin/laravel 2>/dev/null || true
fi

if command -v laravel >/dev/null 2>&1; then
    if laravel --version 2>&1 | grep -q "Laravel Installer"; then
        exit 0
    fi
fi

"${COMPOSER_HOME}/vendor/bin/laravel" --version | grep -q "Laravel Installer"
"#;

const CADDY_SCRIPT: &str = r#"
set -e

docker info >/dev/null

case "$(uname -s)" in
    Darwin)
        CADDY_CONFIG_ROOT="${HOME}/.config/orbit/agent/caddy"
        CADDY_STATE_ROOT="${HOME}/.config/orbit/agent/caddy-state"
        ORBIT_CONFIG_MOUNT="${HOME}/.config/orbit"
        ;;
    *)
        CADDY_CONFIG_ROOT="/etc/caddy"
        CADDY_STATE_ROOT="/var/lib/orbit/caddy"
        ORBIT_CONFIG_MOUNT="/etc/orbit"
        ;;
esac

if ! docker image inspect caddy:2-alpine >/dev/null 2>&1; then
    docker pull caddy:2-alpine
fi

if ! docker network inspect orbit-network >/dev/null 2>&1; then
    docker network create \
        --label orbit.managed=true \
        --label orbit.network.kind=runtime \
        orbit-network
fi

if [ "$(uname -s)" = "Darwin" ]; then
    install -d -m 0755 \
        "${CADDY_CONFIG_ROOT}" \
        "${CADDY_CONFIG_ROOT}/orbit" \
        "${CADDY_CONFIG_ROOT}/sites" \
        "${ORBIT_CONFIG_MOUNT}" \
        "${CADDY_STATE_ROOT}/data" \
        "${CADDY_STATE_ROOT}/config"
else
    sudo install -d -m 0755 \
        "${CADDY_CONFIG_ROOT}" \
        "${CADDY_CONFIG_ROOT}/orbit" \
        "${CADDY_CONFIG_ROOT}/sites" \
        "${ORBIT_CONFIG_MOUNT}" \
        "${CADDY_STATE_ROOT}/data" \
        "${CADDY_STATE_ROOT}/config"
fi

if [ ! -f "${CADDY_CONFIG_ROOT}/Caddyfile" ]; then
    if [ "$(uname -s)" = "Darwin" ]; then
        cat > "${CADDY_CONFIG_ROOT}/Caddyfile" <<'CADDY'
{
    local_certs
    admin localhost:2019
}

(security_headers) {
    header {
        X-Content-Type-Options "nosniff"
        X-XSS-Protection "1; mode=block"
        Referrer-Policy "strict-origin-when-cross-origin"
        Permissions-Policy "camera=(), microphone=(), geolocation=()"
        -Server
    }
}

(profiling_headers) {
    request_header X-Caddy-Start "{time.now.unix_ms}"
    header {
        X-Caddy-End "{time.now.unix_ms}"
        defer
    }
}

(security_txt) {
}

(cache_headers) {
    @static {
        path /build/*
    }
    header @static Cache-Control "public, max-age=31536000, immutable"
}

(path_blocking_public_root) {
    @blocked path /.env /.env.* /.git/* /artisan
    respond @blocked 404
}

import /etc/caddy/orbit/*.caddy
import /etc/caddy/sites/*.caddy
CADDY
    else
        sudo tee "${CADDY_CONFIG_ROOT}/Caddyfile" >/dev/null <<'CADDY'
{
    local_certs
    admin localhost:2019
}

(security_headers) {
    header {
        X-Content-Type-Options "nosniff"
        X-XSS-Protection "1; mode=block"
        Referrer-Policy "strict-origin-when-cross-origin"
        Permissions-Policy "camera=(), microphone=(), geolocation=()"
        -Server
    }
}

(profiling_headers) {
    request_header X-Caddy-Start "{time.now.unix_ms}"
    header {
        X-Caddy-End "{time.now.unix_ms}"
        defer
    }
}

(security_txt) {
}

(cache_headers) {
    @static {
        path /build/*
    }
    header @static Cache-Control "public, max-age=31536000, immutable"
}

(path_blocking_public_root) {
    @blocked path /.env /.env.* /.git/* /artisan
    respond @blocked 404
}

import /etc/caddy/orbit/*.caddy
import /etc/caddy/sites/*.caddy
CADDY
    fi
fi

publish_args=()
wireguard_address="$(ifconfig 2>/dev/null | awk '/inet 10\.6\./ { print $2; exit }')"
if [ -n "${wireguard_address}" ]; then
    publish_args+=("--publish" "${wireguard_address}:80:80")
    publish_args+=("--publish" "${wireguard_address}:443:443")
    publish_args+=("--publish" "${wireguard_address}:443:443/udp")
    publish_args+=("--publish" "${wireguard_address}:8081:8081")
fi

host_mount_args=()
if [ -d /home ]; then
    host_mount_args+=("--mount" "type=bind,source=/home,target=/home,readonly")
fi
if [ -d /Users ]; then
    host_mount_args+=("--mount" "type=bind,source=/Users,target=/Users,readonly")
fi
if [ -d /run/php ]; then
    host_mount_args+=("--mount" "type=bind,source=/run/php,target=/run/php")
fi

expected_spec="orbit-agent-app-dev-v1:${wireguard_address}"
actual_spec="$(docker container inspect --format '{{ index .Config.Labels "orbit.agent.spec" }}' orbit-caddy 2>/dev/null || true)"

if [ "${actual_spec}" != "${expected_spec}" ] && docker container inspect orbit-caddy >/dev/null 2>&1; then
    docker rm -f orbit-caddy
fi

if ! docker container inspect orbit-caddy >/dev/null 2>&1; then
    docker run -d \
        --pull never \
        --name orbit-caddy \
        --restart unless-stopped \
        --network orbit-network \
        --network-alias orbit-caddy \
        --label orbit.managed=true \
        --label orbit.container.kind=caddy \
        --label "orbit.agent.spec=${expected_spec}" \
        --add-host host.docker.internal:host-gateway \
        "${publish_args[@]}" \
        --mount type=bind,source="${CADDY_STATE_ROOT}/data",target=/data/caddy \
        --mount type=bind,source="${CADDY_STATE_ROOT}/config",target=/config/caddy \
        --mount type=bind,source="${CADDY_CONFIG_ROOT}/Caddyfile",target=/etc/caddy/Caddyfile,readonly \
        --mount type=bind,source="${CADDY_CONFIG_ROOT}/orbit",target=/etc/caddy/orbit,readonly \
        --mount type=bind,source="${CADDY_CONFIG_ROOT}/sites",target=/etc/caddy/sites,readonly \
        --mount type=bind,source="${ORBIT_CONFIG_MOUNT}",target=/etc/orbit,readonly \
        "${host_mount_args[@]}" \
        caddy:2-alpine
fi

running="$(docker container inspect --format '{{ .State.Running }}' orbit-caddy 2>/dev/null || true)"
if [ "${running}" != "true" ]; then
    docker start orbit-caddy >/dev/null
fi

docker exec orbit-caddy caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null
"#;

#[cfg(test)]
mod tests {
    use super::*;
    use std::fs;
    use std::time::{SystemTime, UNIX_EPOCH};

    fn config_fixture() -> AgentConfig {
        AgentConfig {
            gateway_url: "https://gateway.test".to_string(),
            node_id: "node_123".to_string(),
            node_name: "NMBP".to_string(),
            gateway_name: "dev-gateway".to_string(),
            bearer_token: Some("dev-token-placeholder".to_string()),
        }
    }

    fn temp_config_path() -> PathBuf {
        let suffix = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .expect("clock should be after unix epoch")
            .as_nanos();

        std::env::temp_dir().join(format!("orbit-agent-config-{suffix}.toml"))
    }

    #[test]
    fn loads_agent_config_from_explicit_path() {
        let path = temp_config_path();

        fs::write(
            &path,
            r#"
gateway_url = "https://gateway.test"
node_id = "node_123"
node_name = "NMBP"
gateway_name = "dev-gateway"
bearer_token = "dev-token-placeholder"
"#,
        )
        .expect("fixture config should be writable");

        let config = AgentConfig::load_from_path(&path).expect("config should load");

        assert_eq!(config, config_fixture());

        let _ = fs::remove_file(path);
    }

    #[test]
    fn missing_config_returns_operator_facing_error() {
        let path = temp_config_path();

        let error = AgentConfig::load_from_path(&path).expect_err("config should be missing");

        assert_eq!(error, ConfigError::MissingConfig(path.clone()));
        assert!(error.to_string().contains("Orbit Agent config is missing"));
        assert!(error.to_string().contains(&path.display().to_string()));
    }

    #[test]
    fn builds_gateway_status_request_for_menu_ping() {
        let client = GatewayClient::new(config_fixture());

        let request = client.build_ping_request();

        assert_eq!(request.method, "GET");
        assert_eq!(request.path, "/api/status");
        assert_eq!(request.bearer_token, None);
        assert_eq!(request.body, None);
        assert_eq!(
            client
                .absolute_url(&request.path)
                .expect("url should build"),
            "https://gateway.test/api/status"
        );
    }

    #[test]
    fn builds_slice_2_claim_request() {
        let client = GatewayClient::new(config_fixture());

        let request = client.build_claim_request();

        assert_eq!(request.method, "POST");
        assert_eq!(request.path, "/api/orbit-agent/jobs/claim");
        assert_eq!(request.bearer_token, None);
        assert_eq!(request.body, None);
    }

    #[test]
    fn builds_slice_2_report_event_request() {
        let client = GatewayClient::new(config_fixture());

        let request = client.build_report_event_request("job_123", JobEvent::Running);
        let body: Value = serde_json::from_str(request.body.as_deref().expect("body should exist"))
            .expect("request body should be json");

        assert_eq!(request.method, "POST");
        assert_eq!(request.path, "/api/orbit-agent/jobs/job_123/events");
        assert_eq!(body["event"], "running");
        assert_eq!(body["payload"]["message"], "orbit agent running");
    }

    #[test]
    fn builds_operation_token_verification_request() {
        let client = GatewayClient::new(config_fixture());

        let request = client.build_verify_operation_token_request(
            "signed-operation-token",
            "internal:fleet-update:install-cli",
        );
        let body: Value = serde_json::from_str(request.body.as_deref().expect("body should exist"))
            .expect("request body should be json");

        assert_eq!(request.method, "POST");
        assert_eq!(request.path, "/api/internal-executor/token/verify");
        assert_eq!(
            request.bearer_token,
            Some("dev-token-placeholder".to_string())
        );
        assert_eq!(body["operation_token"], "signed-operation-token");
        assert_eq!(body["command"], "internal:fleet-update:install-cli");
    }

    #[test]
    fn parses_operation_token_verification_response_envelope() {
        let client = GatewayClient::new(config_fixture());

        let verification = client
            .parse_verify_operation_token_response(
                r#"{
  "success": {
    "data": {
      "allowed": true,
      "reason": null,
      "operation_id": "operation-123"
    }
  }
}"#,
            )
            .expect("verification response should parse");

        assert_eq!(
            verification,
            OperationTokenVerification {
                allowed: true,
                reason: None,
                operation_id: Some("operation-123".to_string()),
            }
        );
    }

    #[test]
    fn parses_slice_2_claim_response_envelope() {
        let client = GatewayClient::new(config_fixture());

        let job = client
            .parse_claim_response(
                r#"{
  "job": {
    "id": "job_123",
    "type": "noop",
    "target_node": { "name": "NMBP" },
    "payload": {}
  }
}"#,
            )
            .expect("claim response should parse")
            .expect("claim response should include a job");

        assert_eq!(job.id, "job_123");
        assert_eq!(job.job_type, "noop");
        assert_eq!(job.target_node_name, "NMBP");
        assert_eq!(job.payload, JobPayload::Noop);
    }

    #[test]
    fn parses_app_dev_convergence_claim_response_envelope() {
        let client = GatewayClient::new(config_fixture());

        let job = client
            .parse_claim_response(
                r#"{
  "job": {
    "id": "job_456",
    "type": "app-dev-convergence",
    "target_node": { "name": "NMBP" },
    "payload": {
      "operation": "app_dev_convergence",
      "role": "app-dev",
      "tld": "test",
      "tools": ["docker", "php-cli", "composer", "laravel-installer", "caddy"]
    }
  }
}"#,
            )
            .expect("claim response should parse")
            .expect("claim response should include a job");

        assert_eq!(job.id, "job_456");
        assert_eq!(job.job_type, "app-dev-convergence");
        assert_eq!(job.target_node_name, "NMBP");
        assert_eq!(
            job.payload,
            JobPayload::AppDevConvergence(AppDevConvergencePayload {
                operation: "app_dev_convergence".to_string(),
                role: "app-dev".to_string(),
                tld: "test".to_string(),
                tools: vec![
                    AppDevTool::Docker,
                    AppDevTool::PhpCli,
                    AppDevTool::Composer,
                    AppDevTool::LaravelInstaller,
                    AppDevTool::Caddy,
                ],
            })
        );
    }

    #[test]
    fn maps_noop_job_lifecycle_events() {
        let job = Job {
            id: "job_123".to_string(),
            job_type: "noop".to_string(),
            target_node_name: "NMBP".to_string(),
            payload: JobPayload::Noop,
        };

        assert_eq!(
            lifecycle_events_for_noop(&job),
            vec![JobEvent::Accepted, JobEvent::Running, JobEvent::Succeeded]
        );
    }

    #[test]
    fn rejects_shell_shaped_noop_payloads() {
        let error = validate_payload("noop", r#"{ "shell": "rm -rf /", "argv": ["sh"] }"#)
            .expect_err("shell-shaped payload must be rejected before execution");

        assert_eq!(
            error,
            PayloadError::Rejected("noop payload cannot contain shell or argv work".to_string())
        );
    }

    #[test]
    fn rejects_unsupported_job_types() {
        let error = validate_payload("shell", r#"{ "reason": "nope" }"#)
            .expect_err("unsupported job type should be rejected");

        assert_eq!(
            error,
            PayloadError::Rejected("unsupported Orbit Agent job type `shell`".to_string())
        );
    }

    #[test]
    fn rejects_app_dev_convergence_with_non_catalog_tools() {
        let error = validate_payload(
            "app-dev-convergence",
            r#"{
  "operation": "app_dev_convergence",
  "role": "app-dev",
  "tld": "test",
  "tools": ["docker", "shell"]
}"#,
        )
        .expect_err("non-catalog tools must be rejected");

        assert_eq!(
            error,
            PayloadError::Rejected("app-dev convergence tool `shell` is not allowed".to_string())
        );
    }

    #[test]
    fn caddy_script_keeps_macos_bind_mounts_under_user_config() {
        assert!(CADDY_SCRIPT.contains("Darwin)"));
        assert!(CADDY_SCRIPT.contains(r#"CADDY_CONFIG_ROOT="${HOME}/.config/orbit/agent/caddy""#));
        assert!(
            CADDY_SCRIPT.contains(r#"CADDY_STATE_ROOT="${HOME}/.config/orbit/agent/caddy-state""#)
        );
        assert!(CADDY_SCRIPT.contains(r#"CADDY_STATE_ROOT="/var/lib/orbit/caddy""#));
    }

    #[test]
    fn php_cli_script_keeps_root_owned_install_tree_user_executable() {
        assert!(PHP_CLI_SCRIPT.contains(
            r#"sudo install -d -m 0755 /opt /opt/orbit /opt/orbit/php "${root}" "${root}/bin""#
        ));
        assert!(PHP_CLI_SCRIPT.contains(r#"sudo chmod 0755 "${root}/bin/php""#));
        assert!(PHP_CLI_SCRIPT.contains(r#"sudo chmod -h 0755 /usr/local/bin/php"#));
    }

    #[test]
    fn composer_script_treats_existing_binary_as_installed() {
        assert!(COMPOSER_SCRIPT.contains("command -v composer >/dev/null 2>&1"));
        assert!(COMPOSER_SCRIPT.contains("[ -x /usr/local/bin/composer ]"));
        assert!(COMPOSER_SCRIPT.contains("php is required before installing composer."));
        assert!(COMPOSER_SCRIPT.contains("Failed to fetch Composer installer signature."));
        assert!(COMPOSER_SCRIPT.contains("Failed to download Composer installer."));
        assert!(COMPOSER_SCRIPT.contains("Composer installer command failed."));
        assert!(COMPOSER_SCRIPT
            .contains("Composer installation did not create /usr/local/bin/composer."));
    }

    #[test]
    fn laravel_installer_script_repairs_symlink_permissions_and_verifies_output() {
        assert!(LARAVEL_INSTALLER_SCRIPT.contains(r#"sudo chmod -h 0755 /usr/local/bin/laravel"#));
        assert!(LARAVEL_INSTALLER_SCRIPT.contains(r#"grep -q "Laravel Installer""#));
    }

    #[test]
    fn local_executor_uses_launchd_safe_path_for_tool_scripts() {
        let path = LocalJobExecutor::launchd_safe_path();

        assert!(path.contains("/.orbstack/bin"));
        assert!(path.contains("/.docker/bin"));
        assert!(path.contains("/opt/homebrew/bin"));
        assert!(path.contains("/usr/local/bin"));
    }

    #[test]
    fn launchd_safe_path_export_keeps_login_shells_from_hiding_homebrew() {
        let script = LocalJobExecutor::script_with_launchd_safe_path("docker --version");

        assert!(script.starts_with("export PATH=\""));
        assert!(script.contains("/.orbstack/bin"));
        assert!(script.contains("/opt/homebrew/bin"));
        assert!(script.ends_with("\ndocker --version"));
    }

    #[test]
    fn local_executor_runs_tool_scripts_from_safe_working_directory() {
        let temp_dir = std::env::temp_dir();
        let fallback_dir = PathBuf::from("/tmp/orbit-agent-fallback");

        assert_eq!(
            LocalJobExecutor::script_working_directory_for(
                Some(temp_dir.clone()),
                fallback_dir.clone()
            ),
            temp_dir
        );

        assert_eq!(
            LocalJobExecutor::script_working_directory_for(
                Some(PathBuf::from("/orbit-agent/missing-home")),
                fallback_dir.clone()
            ),
            fallback_dir
        );
    }

    #[test]
    fn poll_once_claims_noop_and_reports_lifecycle() {
        let mut worker = PollingWorker::new(FakeGateway {
            job: Some(Job {
                id: "job_123".to_string(),
                job_type: "noop".to_string(),
                target_node_name: "NMBP".to_string(),
                payload: JobPayload::Noop,
            }),
            reported: Vec::new(),
        });

        let summary = worker
            .poll_once()
            .expect("poll should run")
            .expect("job should be claimed");

        assert_eq!(summary.job_id, "job_123");
        assert_eq!(summary.events, vec![JobEvent::Running, JobEvent::Succeeded]);
    }

    #[test]
    fn poll_once_executes_typed_app_dev_convergence_before_success() {
        let executor = RecordingExecutor;
        let mut worker = PollingWorker::with_executor(
            FakeGateway {
                job: Some(Job {
                    id: "job_456".to_string(),
                    job_type: "app-dev-convergence".to_string(),
                    target_node_name: "NMBP".to_string(),
                    payload: JobPayload::AppDevConvergence(AppDevConvergencePayload {
                        operation: "app_dev_convergence".to_string(),
                        role: "app-dev".to_string(),
                        tld: "test".to_string(),
                        tools: vec![
                            AppDevTool::Docker,
                            AppDevTool::PhpCli,
                            AppDevTool::Composer,
                            AppDevTool::LaravelInstaller,
                            AppDevTool::Caddy,
                        ],
                    }),
                }),
                reported: Vec::new(),
            },
            executor,
        );

        let summary = worker
            .poll_once()
            .expect("poll should run")
            .expect("job should be claimed");

        assert_eq!(summary.job_id, "job_456");
        assert_eq!(summary.events, vec![JobEvent::Running, JobEvent::Succeeded]);
        assert_eq!(
            summary.executed_operations,
            vec![ExecutedOperation::AppDevConvergence {
                tools: vec![
                    AppDevTool::Docker,
                    AppDevTool::PhpCli,
                    AppDevTool::Composer,
                    AppDevTool::LaravelInstaller,
                    AppDevTool::Caddy,
                ],
            }]
        );
    }

    struct FakeGateway {
        job: Option<Job>,
        reported: Vec<(String, JobEvent)>,
    }

    impl AgentGateway for FakeGateway {
        fn claim_next_job(&mut self) -> Result<Option<Job>, GatewayError> {
            Ok(self.job.take())
        }

        fn report_event(&mut self, job_id: &str, event: JobEvent) -> Result<(), GatewayError> {
            self.reported.push((job_id.to_string(), event));

            Ok(())
        }
    }

    #[derive(Default)]
    struct RecordingExecutor;

    impl JobExecutor for RecordingExecutor {
        fn execute(&mut self, job: &Job) -> Result<Vec<ExecutedOperation>, GatewayError> {
            match &job.payload {
                JobPayload::AppDevConvergence(payload) => {
                    Ok(vec![ExecutedOperation::AppDevConvergence {
                        tools: payload.tools.clone(),
                    }])
                }
                JobPayload::Noop => Ok(Vec::new()),
            }
        }
    }
}
