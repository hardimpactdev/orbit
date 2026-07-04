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
use serde_json::Value;
use std::time::Duration;

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

    if request.command_id != "orbit.agent.noop" {
        return command_error(
            StatusCode::BAD_REQUEST,
            "unsupported Orbit Agent command envelope",
        );
    }

    if !request
        .payload
        .as_object()
        .is_some_and(|payload| payload.is_empty())
    {
        return command_error(
            StatusCode::BAD_REQUEST,
            "orbit.agent.noop does not accept command payload",
        );
    }

    (
        StatusCode::OK,
        Json(CommandPushResponse {
            transport: "agent-push".to_string(),
            command_id: request.command_id,
            status: "succeeded".to_string(),
            frames: vec![CommandPushFrame {
                frame_type: "status".to_string(),
                message: "noop accepted".to_string(),
            }],
        }),
    )
        .into_response()
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
    command_id: String,
    operation_token: String,
    payload: Value,
}

#[derive(Debug, Clone, Serialize)]
struct CommandPushResponse {
    transport: String,
    command_id: String,
    status: String,
    frames: Vec<CommandPushFrame>,
}

#[derive(Debug, Clone, Serialize)]
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
    use axum::http::{header, Method, Request, StatusCode};
    use orbit_agent::default_http_bind_addr;
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

    #[tokio::test]
    async fn command_push_endpoint_accepts_allowlisted_noop_envelope() {
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
                            "command_id": "orbit.agent.noop",
                            "operation_token": "op_test_123",
                            "payload": {},
                        })
                        .to_string(),
                    ))
                    .expect("request"),
            )
            .await
            .expect("response");

        assert_eq!(response.status(), StatusCode::OK);
    }

    #[tokio::test]
    async fn command_push_endpoint_rejects_unsupported_command_ids() {
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
                            "command_id": "shell.exec",
                            "operation_token": "op_test_123",
                            "payload": {"argv": ["whoami"]},
                        })
                        .to_string(),
                    ))
                    .expect("request"),
            )
            .await
            .expect("response");

        assert_eq!(response.status(), StatusCode::BAD_REQUEST);
    }
}
