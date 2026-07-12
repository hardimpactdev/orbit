use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::collections::HashMap;
use std::fs;
use std::io::BufReader;
use std::net::IpAddr;
use std::path::{Path, PathBuf};
use std::sync::Arc;
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
    pub ca_pem_path: Option<PathBuf>,
    pub platform: String,
    pub managed: bool,
    pub wireguard_address: IpAddr,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum ConfigError {
    MissingConfig(PathBuf),
    InvalidConfig(String),
}

pub fn launchd_safe_path() -> String {
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

pub fn install_launchd_safe_path() {
    let existing_path = std::env::var("PATH").unwrap_or_default();

    std::env::set_var("PATH", launchd_safe_process_path(&existing_path));
}

fn launchd_safe_process_path(existing_path: &str) -> String {
    format!("{}:{existing_path}", launchd_safe_path())
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
#[serde(deny_unknown_fields)]
struct AgentConfigFile {
    gateway_url: String,
    node_id: String,
    node_name: String,
    gateway_name: String,
    ca_pem_path: Option<String>,
    platform: Option<String>,
    managed: Option<bool>,
    wireguard_address: Option<String>,
}

impl TryFrom<AgentConfigFile> for AgentConfig {
    type Error = ConfigError;

    fn try_from(value: AgentConfigFile) -> Result<Self, Self::Error> {
        require_present("gateway_url", &value.gateway_url)?;
        require_present("node_id", &value.node_id)?;
        require_present("node_name", &value.node_name)?;
        require_present("gateway_name", &value.gateway_name)?;

        let platform = value
            .platform
            .as_deref()
            .ok_or_else(|| ConfigError::InvalidConfig("platform is required".to_string()))?
            .trim();

        if !is_supported_agent_platform(platform) {
            return Err(ConfigError::InvalidConfig(
                "platform must be macOS or Ubuntu".to_string(),
            ));
        }

        if value.managed != Some(true) {
            return Err(ConfigError::InvalidConfig(
                "managed must be true for the Orbit Agent command listener".to_string(),
            ));
        }

        let wireguard_address = value
            .wireguard_address
            .as_deref()
            .ok_or_else(|| ConfigError::InvalidConfig("wireguard_address is required".to_string()))?
            .trim()
            .parse::<IpAddr>()
            .map_err(|error| {
                ConfigError::InvalidConfig(format!(
                    "wireguard_address must be a valid IP address: {error}"
                ))
            })?;

        if !is_unicast_wireguard_address(wireguard_address) {
            return Err(ConfigError::InvalidConfig(
                "wireguard_address must be a unicast IP address".to_string(),
            ));
        }

        Url::parse(&value.gateway_url).map_err(|error| {
            ConfigError::InvalidConfig(format!("gateway_url must be an absolute URL: {error}"))
        })?;

        Ok(Self {
            gateway_url: value.gateway_url.trim_end_matches('/').to_string(),
            node_id: value.node_id,
            node_name: value.node_name,
            gateway_name: value.gateway_name,
            ca_pem_path: value.ca_pem_path.and_then(|path| {
                let path = path.trim();

                if path.is_empty() {
                    return None;
                }

                Some(PathBuf::from(path))
            }),
            platform: platform.to_string(),
            managed: true,
            wireguard_address,
        })
    }
}

fn is_supported_agent_platform(platform: &str) -> bool {
    matches!(
        platform.to_ascii_lowercase().split('_').next(),
        Some("macos" | "darwin" | "ubuntu")
    )
}

fn is_unicast_wireguard_address(address: IpAddr) -> bool {
    !address.is_unspecified()
        && !address.is_loopback()
        && !address.is_multicast()
        && !matches!(address, IpAddr::V4(address) if address.is_broadcast())
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
    pub body: Option<String>,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct GatewayClient {
    config: AgentConfig,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum GatewayError {
    InvalidResponse(String),
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

    pub fn build_verify_operation_token_request(
        &self,
        operation_token: &str,
        command: &str,
        argv: &[String],
        cwd: Option<&str>,
        environment: Option<&HashMap<String, String>>,
        input: Option<&str>,
    ) -> RequestSpec {
        let mut body = serde_json::Map::from_iter([
            (
                "operation_token".to_string(),
                Value::String(operation_token.to_string()),
            ),
            ("command".to_string(), Value::String(command.to_string())),
            ("argv".to_string(), json!(argv)),
            ("consume".to_string(), Value::Bool(true)),
        ]);

        if let Some(cwd) = cwd {
            body.insert("cwd".to_string(), Value::String(cwd.to_string()));
        }

        if let Some(environment) = environment {
            body.insert("environment".to_string(), json!(environment));
        }

        if let Some(input) = input {
            body.insert("input".to_string(), Value::String(input.to_string()));
        }

        self.request(
            "POST",
            "/api/internal-executor/token/verify",
            Some(Value::Object(body)),
        )
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
            body: body.map(|value| value.to_string()),
        }
    }
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

    let agent = match ureq_agent(config) {
        Ok(agent) => agent,
        Err(error) => return ConnectionStatus::Disconnected(format!("{error:?}")),
    };

    let mut ping = agent.get(&url);
    ping = ping.timeout(GATEWAY_TIMEOUT);

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
                node_ip: Some(config.wireguard_address.to_string()),
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

pub struct HttpAgentGateway {
    client: GatewayClient,
    agent: ureq::Agent,
}

impl HttpAgentGateway {
    pub fn new(config: AgentConfig) -> Result<Self, GatewayError> {
        Ok(Self {
            agent: ureq_agent(&config)?,
            client: GatewayClient::new(config),
        })
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
        let http_request = match request.method.as_str() {
            "GET" => self.agent.get(&url),
            "POST" => self.agent.post(&url),
            method => {
                return Err(GatewayError::Transport(format!(
                    "unsupported HTTP method `{method}`"
                )))
            }
        };

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
        argv: &[String],
        cwd: Option<&str>,
        environment: Option<&HashMap<String, String>>,
        input: Option<&str>,
    ) -> Result<OperationTokenVerification, GatewayError> {
        let body = self.send(self.client.build_verify_operation_token_request(
            operation_token,
            command,
            argv,
            cwd,
            environment,
            input,
        ))?;

        self.client.parse_verify_operation_token_response(&body)
    }
}

fn ureq_agent(config: &AgentConfig) -> Result<ureq::Agent, GatewayError> {
    let mut builder = ureq::AgentBuilder::new()
        .timeout_connect(GATEWAY_TIMEOUT)
        .timeout_read(GATEWAY_TIMEOUT)
        .timeout_write(GATEWAY_TIMEOUT);

    if let Some(ca_pem_path) = &config.ca_pem_path {
        let pem = fs::read(ca_pem_path).map_err(|error| {
            GatewayError::Transport(format!(
                "could not read CA PEM at {}: {error}",
                ca_pem_path.display(),
            ))
        })?;
        let certificates = rustls_pemfile::certs(&mut BufReader::new(pem.as_slice()))
            .collect::<Result<Vec<_>, _>>()
            .map_err(|error| {
                GatewayError::Transport(format!(
                    "could not parse CA PEM at {}: {error}",
                    ca_pem_path.display(),
                ))
            })?;

        if certificates.is_empty() {
            return Err(GatewayError::Transport(format!(
                "CA PEM at {} did not contain any certificates",
                ca_pem_path.display(),
            )));
        }

        let mut root_store = rustls::RootCertStore::empty();
        let (valid, invalid) = root_store.add_parsable_certificates(certificates);

        if valid == 0 {
            return Err(GatewayError::Transport(format!(
                "CA PEM at {} did not contain a valid certificate; invalid={invalid}",
                ca_pem_path.display(),
            )));
        }

        let config = rustls::ClientConfig::builder()
            .with_root_certificates(root_store)
            .with_no_client_auth();

        builder = builder.tls_connector(Arc::new(Arc::new(config)));
    }

    Ok(builder.build())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::fs;
    use std::sync::atomic::{AtomicU64, Ordering};
    use std::time::{SystemTime, UNIX_EPOCH};

    static NEXT_TEMP_CONFIG_ID: AtomicU64 = AtomicU64::new(0);

    fn config_fixture() -> AgentConfig {
        AgentConfig {
            gateway_url: "https://gateway.test".to_string(),
            node_id: "node_123".to_string(),
            node_name: "NMBP".to_string(),
            gateway_name: "dev-gateway".to_string(),
            ca_pem_path: Some(PathBuf::from("/home/orbit/.config/orbit/ca/root.crt")),
            platform: "macos_26-5-1".to_string(),
            managed: true,
            wireguard_address: "10.6.0.3".parse().expect("wireguard IP"),
        }
    }

    fn temp_config_path() -> PathBuf {
        let suffix = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .expect("clock should be after unix epoch")
            .as_nanos();

        let sequence = NEXT_TEMP_CONFIG_ID.fetch_add(1, Ordering::Relaxed);

        std::env::temp_dir().join(format!(
            "orbit-agent-config-{}-{suffix}-{sequence}.toml",
            std::process::id()
        ))
    }

    fn write_config_fixture(contents: &str) -> PathBuf {
        let path = temp_config_path();

        fs::write(&path, contents).expect("fixture config should be writable");

        path
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
ca_pem_path = "/home/orbit/.config/orbit/ca/root.crt"
platform = "macos_26-5-1"
managed = true
wireguard_address = "10.6.0.3"
"#,
        )
        .expect("fixture config should be writable");

        let config = AgentConfig::load_from_path(&path).expect("config should load");

        assert_eq!(config, config_fixture());

        let _ = fs::remove_file(path);
    }

    #[test]
    fn rejects_unknown_agent_config_fields() {
        let path = write_config_fixture(
            r#"
gateway_url = "https://gateway.test"
node_id = "node_123"
node_name = "NMBP"
gateway_name = "dev-gateway"
platform = "macos_26-5-1"
managed = true
wireguard_address = "10.6.0.3"
retired_setting = "unsupported"
"#,
        );

        let error = AgentConfig::load_from_path(&path).expect_err("config should fail closed");

        assert!(error
            .to_string()
            .contains("unknown field `retired_setting`"));

        let _ = fs::remove_file(path);
    }

    #[test]
    fn listener_security_rejects_config_without_managed_agent_intent() {
        let path = write_config_fixture(
            r#"
gateway_url = "https://gateway.test"
node_id = "node_123"
node_name = "NMBP"
gateway_name = "dev-gateway"
platform = "macos_26-5-1"
managed = false
wireguard_address = "10.6.0.3"
"#,
        );

        let error = AgentConfig::load_from_path(&path).expect_err("config should fail closed");

        assert!(error.to_string().contains("managed must be true"));

        let _ = fs::remove_file(path);
    }

    #[test]
    fn listener_security_rejects_unsupported_platform() {
        let path = write_config_fixture(
            r#"
gateway_url = "https://gateway.test"
node_id = "node_123"
node_name = "NMBP"
gateway_name = "dev-gateway"
platform = "windows_11"
managed = true
wireguard_address = "10.6.0.3"
"#,
        );

        let error = AgentConfig::load_from_path(&path).expect_err("config should fail closed");

        assert!(error
            .to_string()
            .contains("platform must be macOS or Ubuntu"));

        let _ = fs::remove_file(path);
    }

    #[test]
    fn listener_security_rejects_missing_wireguard_address() {
        let path = write_config_fixture(
            r#"
gateway_url = "https://gateway.test"
node_id = "node_123"
node_name = "NMBP"
gateway_name = "dev-gateway"
platform = "macos_26-5-1"
managed = true
"#,
        );

        let error = AgentConfig::load_from_path(&path).expect_err("config should fail closed");

        assert!(error.to_string().contains("wireguard_address is required"));

        let _ = fs::remove_file(path);
    }

    #[test]
    fn listener_security_rejects_non_unicast_wireguard_address() {
        for wireguard_address in ["0.0.0.0", "127.0.0.1", "224.0.0.1", "255.255.255.255"] {
            let path = write_config_fixture(&format!(
                r#"
gateway_url = "https://gateway.test"
node_id = "node_123"
node_name = "NMBP"
gateway_name = "dev-gateway"
platform = "ubuntu_24-04"
managed = true
wireguard_address = "{wireguard_address}"
"#,
            ));

            let error = AgentConfig::load_from_path(&path).expect_err("config should fail closed");

            assert!(error
                .to_string()
                .contains("wireguard_address must be a unicast IP address"));

            let _ = fs::remove_file(path);
        }
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
        assert_eq!(request.body, None);
        assert_eq!(
            client
                .absolute_url(&request.path)
                .expect("url should build"),
            "https://gateway.test/api/status"
        );
    }

    #[test]
    fn builds_operation_token_verification_request() {
        let client = GatewayClient::new(config_fixture());
        let argv = vec![
            "internal:fleet-update:install-cli".to_string(),
            "--operation-token=signed-operation-token".to_string(),
            "--json".to_string(),
        ];
        let environment = std::collections::HashMap::from([
            ("ALPHA".to_string(), "1".to_string()),
            ("BETA".to_string(), "2".to_string()),
        ]);

        let request = client.build_verify_operation_token_request(
            "signed-operation-token",
            "internal:fleet-update:install-cli",
            &argv,
            Some("/srv/orbit"),
            Some(&environment),
            Some("stdin-payload"),
        );
        let body: Value = serde_json::from_str(request.body.as_deref().expect("body should exist"))
            .expect("request body should be json");

        assert_eq!(request.method, "POST");
        assert_eq!(request.path, "/api/internal-executor/token/verify");
        assert_eq!(body["operation_token"], "signed-operation-token");
        assert_eq!(body["command"], "internal:fleet-update:install-cli");
        assert_eq!(body["argv"][0], "internal:fleet-update:install-cli");
        assert_eq!(body["argv"][1], "--operation-token=signed-operation-token");
        assert_eq!(body["cwd"], "/srv/orbit");
        assert_eq!(body["environment"]["ALPHA"], "1");
        assert_eq!(body["environment"]["BETA"], "2");
        assert_eq!(body["input"], "stdin-payload");
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
    fn launchd_safe_path_includes_macos_container_provider_paths() {
        let path = launchd_safe_path();

        assert!(path.contains("/.orbstack/bin"));
        assert!(path.contains("/.docker/bin"));
        assert!(path.contains("/Applications/OrbStack.app/Contents/MacOS/xbin"));
        assert!(path.contains("/Applications/Docker.app/Contents/Resources/bin"));
        assert!(path.contains("/opt/homebrew/bin"));
        assert!(path.contains("/usr/local/bin"));
    }

    #[test]
    fn launchd_safe_process_path_hardens_minimal_launchd_path() {
        let path = launchd_safe_process_path("/usr/bin:/bin");

        assert!(path.starts_with(&launchd_safe_path()));
        assert!(path.ends_with(":/usr/bin:/bin"));
    }

    #[test]
    fn agent_source_has_no_job_claim_retrieval_surface() {
        let source = include_str!("lib.rs");

        assert!(!source.contains(&["/api/orbit-agent/jobs", "/claim"].concat()));
        assert!(!source.contains(&["Polling", "Worker"].concat()));
        assert!(!source.contains(&["claim_next", "_job"].concat()));
    }
}
