use axum::{
    extract::State,
    http::StatusCode,
    response::IntoResponse,
    routing::{get, post},
    Json, Router,
};
use orbit_agent::{
    build_service_status_snapshot, default_http_bind_addr, run_polling_worker_loop, AgentConfig,
    HttpAgentGateway, ServiceStatusSnapshot,
};
use serde::{Deserialize, Serialize};
use std::{
    io::Write,
    path::Path,
    process::{Command, Stdio},
    sync::Arc,
    thread,
    time::{Duration, Instant},
};

#[tokio::main]
async fn main() {
    std::thread::spawn(|| run_polling_worker_loop(Duration::from_secs(15)));

    let app = app();

    let bind_addr = default_http_bind_addr();
    let listener = tokio::net::TcpListener::bind(&bind_addr)
        .await
        .unwrap_or_else(|error| {
            panic!("failed to bind Orbit Agent HTTP service on {bind_addr}: {error}")
        });

    eprintln!("Orbit Agent service listening on http://{bind_addr}");

    axum::serve(listener, app)
        .await
        .expect("failed to run Orbit Agent HTTP service");
}

async fn health() -> Json<HealthResponse> {
    Json(HealthResponse {
        status: "ok".to_string(),
    })
}

async fn status() -> Json<ServiceStatusSnapshot> {
    Json(build_service_status_snapshot())
}

fn app() -> Router {
    app_with_authorizer(Arc::new(GatewayCommandAuthorizer))
}

fn app_with_authorizer(authorizer: Arc<dyn CommandAuthorizer>) -> Router {
    Router::new()
        .route("/health", get(health))
        .route("/status", get(status))
        .route("/v1/commands", post(command_push))
        .with_state(AgentHttpState { authorizer })
}

#[derive(Clone)]
struct AgentHttpState {
    authorizer: Arc<dyn CommandAuthorizer>,
}

trait CommandAuthorizer: Send + Sync {
    fn authorize(&self, request: &CommandPushRequest) -> Result<(), String>;
}

struct GatewayCommandAuthorizer;

impl CommandAuthorizer for GatewayCommandAuthorizer {
    fn authorize(&self, request: &CommandPushRequest) -> Result<(), String> {
        let command = request
            .argv
            .first()
            .ok_or_else(|| "agent-push argv must include an Orbit command".to_string())?;
        let config = AgentConfig::load_default().map_err(|error| error.to_string())?;
        let gateway = HttpAgentGateway::new(config);
        let verification = gateway
            .verify_operation_token(&request.operation_token, command)
            .map_err(|error| format!("{error:?}"))?;

        if verification.allowed {
            return Ok(());
        }

        Err(verification
            .reason
            .unwrap_or_else(|| "operation token rejected".to_string()))
    }
}

async fn command_push(
    State(state): State<AgentHttpState>,
    Json(request): Json<CommandPushRequest>,
) -> impl IntoResponse {
    if let Err(reason) = state.authorizer.authorize(&request) {
        return command_error(
            StatusCode::UNAUTHORIZED,
            &format!("agent-push operation token was rejected: {reason}"),
        );
    }

    if request.binary != "orbit" {
        return command_error(StatusCode::BAD_REQUEST, "unsupported Orbit Agent binary");
    }

    let execution = execute_binary(&request);

    (
        StatusCode::OK,
        Json(CommandPushResponse {
            transport: "agent-push".to_string(),
            operation_id: request.operation_id,
            binary: request.binary,
            status: execution.status,
            frames: execution.frames,
            exit_code: execution.exit_code,
        }),
    )
        .into_response()
}

fn execute_binary(request: &CommandPushRequest) -> CommandExecution {
    let timeout_seconds = request.timeout_seconds.clamp(1, 300);
    let deadline = Instant::now() + Duration::from_secs(timeout_seconds);

    let execution = execute_binary_once(request, &request.argv, timeout_seconds, deadline);

    if should_retry_stale_fleet_update_without_legacy_flags(request, &execution) {
        let argv = request
            .argv
            .iter()
            .filter(|argument| {
                !argument.starts_with("--operation-token=") && argument.as_str() != "--json"
            })
            .cloned()
            .collect::<Vec<_>>();

        return execute_binary_once(request, &argv, timeout_seconds, deadline);
    }

    execution
}

fn execute_binary_once(
    request: &CommandPushRequest,
    argv: &[String],
    timeout_seconds: u64,
    deadline: Instant,
) -> CommandExecution {
    let mut command = Command::new(command_binary(request));
    command
        .args(argv)
        .stdout(Stdio::piped())
        .stderr(Stdio::piped());

    if request.input.is_some() {
        command.stdin(Stdio::piped());
    } else {
        command.stdin(Stdio::null());
    }

    let mut child = match command.spawn() {
        Ok(child) => child,
        Err(error) => {
            return CommandExecution {
                status: "failed".to_string(),
                exit_code: None,
                frames: vec![CommandPushFrame {
                    frame_type: "stderr".to_string(),
                    message: format!("failed to execute allowlisted binary: {error}"),
                }],
            };
        }
    };

    if let Some(input) = &request.input {
        if let Some(mut stdin) = child.stdin.take() {
            if let Err(error) = stdin.write_all(input.as_bytes()) {
                let _ = child.kill();

                return CommandExecution {
                    status: "failed".to_string(),
                    exit_code: None,
                    frames: vec![CommandPushFrame {
                        frame_type: "stderr".to_string(),
                        message: format!("failed to write binary stdin: {error}"),
                    }],
                };
            }
        }
    }

    loop {
        match child.try_wait() {
            Ok(Some(_)) => {
                return command_output_to_execution(
                    child
                        .wait_with_output()
                        .expect("completed child output should be readable"),
                );
            }
            Ok(None) if Instant::now() >= deadline => {
                let _ = child.kill();

                return CommandExecution {
                    status: "failed".to_string(),
                    exit_code: None,
                    frames: vec![CommandPushFrame {
                        frame_type: "stderr".to_string(),
                        message: format!(
                            "binary execution timed out after {timeout_seconds} seconds"
                        ),
                    }],
                };
            }
            Ok(None) => thread::sleep(Duration::from_millis(25)),
            Err(error) => {
                return CommandExecution {
                    status: "failed".to_string(),
                    exit_code: None,
                    frames: vec![CommandPushFrame {
                        frame_type: "stderr".to_string(),
                        message: format!("failed to wait for allowlisted binary: {error}"),
                    }],
                };
            }
        }
    }
}

fn command_binary(request: &CommandPushRequest) -> String {
    if request.binary != "orbit" {
        return request.binary.clone();
    }

    resolve_orbit_binary().unwrap_or_else(|| request.binary.clone())
}

fn resolve_orbit_binary() -> Option<String> {
    if let Some(path) = existing_env_path("ORBIT_AGENT_ORBIT_BINARY") {
        return Some(path);
    }

    [
        "/usr/local/bin/orbit",
        "/Users/nckrtl/.local/bin/orbit",
        "/home/nckrtl/.local/bin/orbit",
        "/opt/homebrew/bin/orbit",
        "../cli/orbit",
    ]
    .into_iter()
    .find(|path| Path::new(path).exists())
    .map(str::to_string)
}

fn existing_env_path(key: &str) -> Option<String> {
    let path = std::env::var(key).ok()?.trim().to_string();

    if path.is_empty() || !Path::new(&path).exists() {
        return None;
    }

    Some(path)
}

fn should_retry_stale_fleet_update_without_legacy_flags(
    request: &CommandPushRequest,
    execution: &CommandExecution,
) -> bool {
    if request.binary != "orbit" || execution.exit_code == Some(0) {
        return false;
    }

    if request.argv.first().map(String::as_str) != Some("internal:fleet-update:install-cli") {
        return false;
    }

    if !request
        .argv
        .iter()
        .any(|argument| argument.starts_with("--operation-token=") || argument == "--json")
    {
        return false;
    }

    execution.frames.iter().any(|frame| {
        frame
            .message
            .contains("The \"--operation-token\" option does not exist.")
            || frame
                .message
                .contains("The \"--json\" option does not exist.")
    })
}

fn command_output_to_execution(output: std::process::Output) -> CommandExecution {
    let mut frames = Vec::new();
    let stdout = String::from_utf8_lossy(&output.stdout).trim().to_string();
    let stderr = String::from_utf8_lossy(&output.stderr).trim().to_string();
    let exit_code = output.status.code();

    if !stdout.is_empty() {
        frames.push(CommandPushFrame {
            frame_type: "stdout".to_string(),
            message: stdout,
        });
    }

    if !stderr.is_empty() {
        frames.push(CommandPushFrame {
            frame_type: "stderr".to_string(),
            message: stderr,
        });
    }

    frames.push(CommandPushFrame {
        frame_type: "exit".to_string(),
        message: exit_code
            .map(|code| code.to_string())
            .unwrap_or_else(|| "terminated".to_string()),
    });

    CommandExecution {
        status: if output.status.success() {
            "succeeded".to_string()
        } else {
            "failed".to_string()
        },
        exit_code,
        frames,
    }
}

fn command_error(status: StatusCode, message: &str) -> axum::response::Response {
    (
        status,
        Json(CommandPushError {
            error: message.to_string(),
        }),
    )
        .into_response()
}

#[derive(Debug, Clone, PartialEq, Eq, serde::Serialize)]
struct HealthResponse {
    status: String,
}

#[derive(Debug, Clone, Deserialize)]
struct CommandPushRequest {
    operation_id: String,
    binary: String,
    argv: Vec<String>,
    input: Option<String>,
    operation_token: String,
    timeout_seconds: u64,
    #[allow(dead_code)]
    stream: bool,
}

#[derive(Debug, Clone, Serialize)]
struct CommandPushResponse {
    transport: String,
    operation_id: String,
    binary: String,
    status: String,
    frames: Vec<CommandPushFrame>,
    exit_code: Option<i32>,
}

#[derive(Debug, Clone)]
struct CommandExecution {
    status: String,
    frames: Vec<CommandPushFrame>,
    exit_code: Option<i32>,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
struct CommandPushFrame {
    #[serde(rename = "type")]
    frame_type: String,
    message: String,
}

#[derive(Debug, Clone, Serialize)]
struct CommandPushError {
    error: String,
}

#[cfg(test)]
mod tests {
    use super::*;
    use axum::body::to_bytes;
    use axum::http::{header, Method, Request, StatusCode};
    use orbit_agent::default_http_bind_addr;
    use serde_json::Value;
    use tower::ServiceExt;

    struct StaticCommandAuthorizer {
        allowed: bool,
    }

    impl CommandAuthorizer for StaticCommandAuthorizer {
        fn authorize(&self, _request: &CommandPushRequest) -> Result<(), String> {
            if self.allowed {
                return Ok(());
            }

            Err("invalid_token".to_string())
        }
    }

    fn app_with_static_authorizer(allowed: bool) -> Router {
        app_with_authorizer(Arc::new(StaticCommandAuthorizer { allowed }))
    }

    #[test]
    fn health_response_is_ok() {
        let response = HealthResponse {
            status: "ok".to_string(),
        };

        assert_eq!(response.status, "ok");
    }

    #[test]
    fn default_bind_addr_targets_loopback_agent_port() {
        assert_eq!(default_http_bind_addr(), "127.0.0.1:9477");
    }

    #[test]
    fn execute_binary_writes_request_input_to_stdin() {
        let execution = execute_binary(&CommandPushRequest {
            operation_id: "op_agent_test_123".to_string(),
            binary: "/bin/cat".to_string(),
            argv: vec![],
            input: Some("agent stdin\n".to_string()),
            operation_token: "op_test_123".to_string(),
            timeout_seconds: 30,
            stream: true,
        });

        assert_eq!(execution.status, "succeeded");
        assert_eq!(execution.exit_code, Some(0));
        assert_eq!(
            execution.frames.first(),
            Some(&CommandPushFrame {
                frame_type: "stdout".to_string(),
                message: "agent stdin".to_string(),
            })
        );
    }

    #[test]
    fn retry_without_legacy_flags_is_used_for_stale_fleet_update_cli() {
        let request = CommandPushRequest {
            operation_id: "op_agent_test_123".to_string(),
            binary: "orbit".to_string(),
            argv: vec![
                "internal:fleet-update:install-cli".to_string(),
                "--operation-token=op_test_123".to_string(),
                "--json".to_string(),
            ],
            input: None,
            operation_token: "op_test_123".to_string(),
            timeout_seconds: 30,
            stream: true,
        };
        let execution = CommandExecution {
            status: "failed".to_string(),
            exit_code: Some(1),
            frames: vec![CommandPushFrame {
                frame_type: "stderr".to_string(),
                message: "The \"--operation-token\" option does not exist.".to_string(),
            }],
        };

        assert!(should_retry_stale_fleet_update_without_legacy_flags(
            &request, &execution
        ));
    }

    #[test]
    fn retry_without_legacy_flags_is_not_used_for_successful_execution() {
        let request = CommandPushRequest {
            operation_id: "op_agent_test_123".to_string(),
            binary: "orbit".to_string(),
            argv: vec![
                "internal:fleet-update:install-cli".to_string(),
                "--operation-token=op_test_123".to_string(),
                "--json".to_string(),
            ],
            input: None,
            operation_token: "op_test_123".to_string(),
            timeout_seconds: 30,
            stream: true,
        };
        let execution = CommandExecution {
            status: "succeeded".to_string(),
            exit_code: Some(0),
            frames: vec![CommandPushFrame {
                frame_type: "stdout".to_string(),
                message: "updated".to_string(),
            }],
        };

        assert!(!should_retry_stale_fleet_update_without_legacy_flags(
            &request, &execution
        ));
    }

    #[test]
    fn retry_without_legacy_flags_requires_fleet_update_command() {
        let request = CommandPushRequest {
            operation_id: "op_agent_test_123".to_string(),
            binary: "orbit".to_string(),
            argv: vec![
                "version".to_string(),
                "--operation-token=op_test_123".to_string(),
                "--json".to_string(),
            ],
            input: None,
            operation_token: "op_test_123".to_string(),
            timeout_seconds: 30,
            stream: true,
        };
        let execution = CommandExecution {
            status: "failed".to_string(),
            exit_code: Some(1),
            frames: vec![CommandPushFrame {
                frame_type: "stderr".to_string(),
                message: "The \"--operation-token\" option does not exist.".to_string(),
            }],
        };

        assert!(!should_retry_stale_fleet_update_without_legacy_flags(
            &request, &execution
        ));
    }

    #[test]
    fn retry_without_legacy_flags_handles_json_option_failures() {
        let request = CommandPushRequest {
            operation_id: "op_agent_test_123".to_string(),
            binary: "orbit".to_string(),
            argv: vec![
                "internal:fleet-update:install-cli".to_string(),
                "--operation-token=op_test_123".to_string(),
                "--json".to_string(),
            ],
            input: None,
            operation_token: "op_test_123".to_string(),
            timeout_seconds: 30,
            stream: true,
        };
        let execution = CommandExecution {
            status: "failed".to_string(),
            exit_code: Some(1),
            frames: vec![CommandPushFrame {
                frame_type: "stderr".to_string(),
                message: "The \"--json\" option does not exist.".to_string(),
            }],
        };

        assert!(should_retry_stale_fleet_update_without_legacy_flags(
            &request, &execution
        ));
    }

    #[test]
    fn command_binary_preserves_non_orbit_binaries() {
        let request = CommandPushRequest {
            operation_id: "op_agent_test_123".to_string(),
            binary: "/bin/cat".to_string(),
            argv: vec![],
            input: None,
            operation_token: "op_test_123".to_string(),
            timeout_seconds: 30,
            stream: true,
        };

        assert_eq!(command_binary(&request), "/bin/cat");
    }

    #[tokio::test]
    async fn command_push_endpoint_accepts_allowlisted_binary_argv_envelope() {
        let response = app_with_static_authorizer(true)
            .oneshot(
                Request::builder()
                    .method(Method::POST)
                    .uri("/v1/commands")
                    .header(header::CONTENT_TYPE, "application/json")
                    .body(axum::body::Body::from(
                        serde_json::json!({
                            "operation_id": "op_agent_test_123",
                            "binary": "orbit",
                            "argv": ["version", "--json"],
                            "operation_token": "op_test_123",
                            "timeout_seconds": 30,
                            "stream": true,
                        })
                        .to_string(),
                    ))
                    .expect("request"),
            )
            .await
            .expect("response");

        assert_eq!(response.status(), StatusCode::OK);

        let body = to_bytes(response.into_body(), 1024 * 1024)
            .await
            .expect("body bytes");
        let payload: Value = serde_json::from_slice(&body).expect("json response");

        assert_eq!(payload["transport"], "agent-push");
        assert_eq!(payload["operation_id"], "op_agent_test_123");
        assert_eq!(payload["binary"], "orbit");
        assert_eq!(payload["status"], "succeeded");
        assert!(payload["frames"]
            .as_array()
            .is_some_and(|frames| !frames.is_empty()));
        assert!(payload["exit_code"].is_i64());
    }

    #[tokio::test]
    async fn command_push_endpoint_rejects_non_allowlisted_binaries() {
        let response = app_with_static_authorizer(true)
            .oneshot(
                Request::builder()
                    .method(Method::POST)
                    .uri("/v1/commands")
                    .header(header::CONTENT_TYPE, "application/json")
                    .body(axum::body::Body::from(
                        serde_json::json!({
                            "operation_id": "op_agent_test_123",
                            "binary": "rm",
                            "argv": ["-rf", "/tmp/orbit-agent-push-test"],
                            "operation_token": "op_test_123",
                            "timeout_seconds": 30,
                            "stream": true,
                        })
                        .to_string(),
                    ))
                    .expect("request"),
            )
            .await
            .expect("response");

        assert_eq!(response.status(), StatusCode::BAD_REQUEST);
    }

    #[tokio::test]
    async fn command_push_endpoint_rejects_gateway_denied_operation_tokens() {
        let response = app_with_static_authorizer(false)
            .oneshot(
                Request::builder()
                    .method(Method::POST)
                    .uri("/v1/commands")
                    .header(header::CONTENT_TYPE, "application/json")
                    .body(axum::body::Body::from(
                        serde_json::json!({
                            "operation_id": "op_agent_test_123",
                            "binary": "orbit",
                            "argv": ["version", "--json"],
                            "operation_token": "op_wrong_123",
                            "timeout_seconds": 30,
                            "stream": true,
                        })
                        .to_string(),
                    ))
                    .expect("request"),
            )
            .await
            .expect("response");

        assert_eq!(response.status(), StatusCode::UNAUTHORIZED);
    }
}
