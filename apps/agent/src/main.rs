use axum::{
    extract::State,
    http::{header, HeaderMap, StatusCode},
    response::IntoResponse,
    routing::{get, post},
    Json, Router,
};
use orbit_agent::{
    build_service_status_snapshot, default_http_bind_addr, run_polling_worker_loop,
    ServiceStatusSnapshot,
};
use serde::{Deserialize, Serialize};
use std::{
    io::Write,
    process::{Command, Stdio},
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
    app_with_push_token(std::env::var("ORBIT_AGENT_PUSH_TOKEN").ok())
}

fn app_with_push_token(push_token: Option<String>) -> Router {
    Router::new()
        .route("/health", get(health))
        .route("/status", get(status))
        .route("/v1/commands", post(command_push))
        .with_state(AgentHttpState { push_token })
}

#[derive(Clone)]
struct AgentHttpState {
    push_token: Option<String>,
}

async fn command_push(
    State(state): State<AgentHttpState>,
    headers: HeaderMap,
    Json(request): Json<CommandPushRequest>,
) -> impl IntoResponse {
    let Some(expected_token) = state
        .push_token
        .as_deref()
        .filter(|token| !token.is_empty())
    else {
        return command_error(
            StatusCode::UNAUTHORIZED,
            "agent-push bearer token is not configured",
        );
    };

    let bearer = headers
        .get(header::AUTHORIZATION)
        .and_then(|value| value.to_str().ok())
        .and_then(|value| value.strip_prefix("Bearer "));

    if bearer != Some(expected_token) || request.operation_token != expected_token {
        return command_error(
            StatusCode::UNAUTHORIZED,
            "agent-push bearer token was rejected",
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

    let mut command = Command::new(&request.binary);
    command
        .args(&request.argv)
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

    #[tokio::test]
    async fn command_push_endpoint_accepts_allowlisted_binary_argv_envelope() {
        std::env::set_var("ORBIT_AGENT_PUSH_TOKEN", "op_test_123");

        let response = app_with_push_token(Some("op_test_123".to_string()))
            .oneshot(
                Request::builder()
                    .method(Method::POST)
                    .uri("/v1/commands")
                    .header(header::AUTHORIZATION, "Bearer op_test_123")
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
        std::env::set_var("ORBIT_AGENT_PUSH_TOKEN", "op_test_123");

        let response = app_with_push_token(Some("op_test_123".to_string()))
            .oneshot(
                Request::builder()
                    .method(Method::POST)
                    .uri("/v1/commands")
                    .header(header::AUTHORIZATION, "Bearer op_test_123")
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
    async fn command_push_endpoint_rejects_mismatched_operation_tokens() {
        std::env::set_var("ORBIT_AGENT_PUSH_TOKEN", "op_test_123");

        let response = app_with_push_token(Some("op_test_123".to_string()))
            .oneshot(
                Request::builder()
                    .method(Method::POST)
                    .uri("/v1/commands")
                    .header(header::AUTHORIZATION, "Bearer op_test_123")
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

    #[tokio::test]
    async fn command_push_endpoint_rejects_mismatched_bearer_tokens() {
        std::env::set_var("ORBIT_AGENT_PUSH_TOKEN", "op_test_123");

        let response = app_with_push_token(Some("op_test_123".to_string()))
            .oneshot(
                Request::builder()
                    .method(Method::POST)
                    .uri("/v1/commands")
                    .header(header::AUTHORIZATION, "Bearer op_wrong_123")
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

        assert_eq!(response.status(), StatusCode::UNAUTHORIZED);
    }
}
