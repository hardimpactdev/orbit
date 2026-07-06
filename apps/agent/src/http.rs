use crate::{
    build_service_status_snapshot, default_http_bind_addr, AgentConfig, HttpAgentGateway,
    ServiceStatusSnapshot,
};
use axum::{
    extract::State,
    http::StatusCode,
    response::IntoResponse,
    routing::{get, post},
    Json, Router,
};
use serde::{Deserialize, Serialize};
use std::{
    collections::HashMap,
    io::{Read, Write},
    path::{Path, PathBuf},
    process::{Command, ExitStatus, Stdio},
    sync::{Arc, Mutex},
    thread,
    time::{Duration, Instant},
};

pub async fn run_agent_service() {
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

pub fn run_agent_service_blocking() {
    tokio::runtime::Builder::new_multi_thread()
        .enable_all()
        .build()
        .expect("failed to build Orbit Agent runtime")
        .block_on(run_agent_service());
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
    app_with_authorizer(Arc::new(GatewayCommandAuthorizer::new()))
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

trait TokenVerifier: Send + Sync {
    fn verify_operation_token(
        &self,
        operation_token: &str,
        command: &str,
    ) -> Result<crate::OperationTokenVerification, String>;
}

impl TokenVerifier for HttpAgentGateway {
    fn verify_operation_token(
        &self,
        operation_token: &str,
        command: &str,
    ) -> Result<crate::OperationTokenVerification, String> {
        HttpAgentGateway::verify_operation_token(self, operation_token, command)
            .map_err(|error| format!("{error:?}"))
    }
}

struct GatewayCommandAuthorizer {
    verifier: Mutex<Option<Arc<dyn TokenVerifier>>>,
    factory: Arc<dyn Fn() -> Result<Arc<dyn TokenVerifier>, String> + Send + Sync>,
}

impl GatewayCommandAuthorizer {
    fn new() -> Self {
        Self::with_factory(Arc::new(|| {
            AgentConfig::load_default()
                .and_then(|config| {
                    let g = HttpAgentGateway::new(config)
                        .map_err(|error| crate::ConfigError::InvalidConfig(format!("{error:?}")))?;

                    Ok(Arc::new(g) as Arc<dyn TokenVerifier>)
                })
                .map_err(|error| error.to_string())
        }))
    }

    fn with_factory(
        factory: Arc<dyn Fn() -> Result<Arc<dyn TokenVerifier>, String> + Send + Sync>,
    ) -> Self {
        Self {
            verifier: Mutex::new(None),
            factory,
        }
    }

    fn verify_operation_token(
        &self,
        operation_token: &str,
        command: &str,
    ) -> Result<crate::OperationTokenVerification, String> {
        let mut verifier = self
            .verifier
            .lock()
            .map_err(|_| "gateway client lock poisoned".to_string())?;

        if verifier.is_none() {
            *verifier = Some((self.factory)()?);
        }

        verifier
            .as_ref()
            .expect("gateway client initialized")
            .verify_operation_token(operation_token, command)
    }
}

impl CommandAuthorizer for GatewayCommandAuthorizer {
    fn authorize(&self, request: &CommandPushRequest) -> Result<(), String> {
        let command = request
            .argv
            .first()
            .ok_or_else(|| "agent-push argv must include an Orbit command".to_string())?;
        let verification = self.verify_operation_token(&request.operation_token, command)?;

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
    match tokio::task::spawn_blocking(move || command_push_blocking(state, request)).await {
        Ok(Ok(response)) => (StatusCode::OK, Json(response)).into_response(),
        Ok(Err((status, message))) => command_error(status, &message),
        Err(error) => command_error(
            StatusCode::INTERNAL_SERVER_ERROR,
            &format!("agent-push worker failed: {error}"),
        ),
    }
}

fn command_push_blocking(
    state: AgentHttpState,
    request: CommandPushRequest,
) -> Result<CommandPushResponse, (StatusCode, String)> {
    let authorization_start = Instant::now();

    if let Err(reason) = state.authorizer.authorize(&request) {
        return Err((
            StatusCode::UNAUTHORIZED,
            format!("agent-push operation token was rejected: {reason}"),
        ));
    }

    let authorization_ms = elapsed_millis(authorization_start);

    if request.binary != "orbit" {
        return Err((
            StatusCode::BAD_REQUEST,
            "unsupported Orbit Agent binary".to_string(),
        ));
    }

    let execution = execute_binary(&request);

    Ok(CommandPushResponse {
        transport: "agent-push".to_string(),
        operation_id: request.operation_id,
        binary: request.binary,
        status: execution.status,
        frames: execution.frames,
        exit_code: execution.exit_code,
        timings: CommandPushTimings {
            authorization_ms,
            process_spawn_ms: execution.timings.process_spawn_ms,
            process_wait_ms: execution.timings.process_wait_ms,
            result_serialization_ms: execution.timings.result_serialization_ms,
        },
    })
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
    let spawn_start = Instant::now();
    let mut command = Command::new(command_binary(request));
    command
        .args(argv)
        .stdout(Stdio::piped())
        .stderr(Stdio::piped());

    if let Some(cwd) = &request.cwd {
        command.current_dir(cwd);
    }

    if let Some(environment) = &request.environment {
        command.envs(environment);
    }

    if request.input.is_some() {
        command.stdin(Stdio::piped());
    } else {
        command.stdin(Stdio::null());
    }

    let mut child = match command.spawn() {
        Ok(child) => child,
        Err(error) => {
            let serialization_start = Instant::now();
            let frames = vec![CommandPushFrame {
                frame_type: "stderr".to_string(),
                message: format!("failed to execute allowlisted binary: {error}"),
            }];

            return CommandExecution {
                status: "failed".to_string(),
                exit_code: None,
                frames,
                timings: CommandExecutionTimings {
                    process_spawn_ms: elapsed_millis(spawn_start),
                    process_wait_ms: 0,
                    result_serialization_ms: elapsed_millis(serialization_start),
                },
            };
        }
    };

    let process_spawn_ms = elapsed_millis(spawn_start);
    let stdout = child.stdout.take().map(spawn_output_drain);
    let stderr = child.stderr.take().map(spawn_output_drain);

    if let Some(input) = &request.input {
        if let Some(mut stdin) = child.stdin.take() {
            if let Err(error) = stdin.write_all(input.as_bytes()) {
                let _ = child.kill();
                let _ = child.wait();
                let output = collect_drained_output(stdout, stderr);

                return CommandExecution {
                    status: "failed".to_string(),
                    exit_code: None,
                    frames: output
                        .with_error_frame(format!("failed to write binary stdin: {error}")),
                    timings: CommandExecutionTimings {
                        process_spawn_ms,
                        process_wait_ms: 0,
                        result_serialization_ms: 0,
                    },
                };
            }
        }
    }

    let wait_start = Instant::now();
    let child_id = child.id();
    let (wait_sender, wait_receiver) = std::sync::mpsc::channel();

    thread::spawn(move || {
        let _ = wait_sender.send(child.wait());
    });

    match wait_receiver.recv_timeout(deadline.saturating_duration_since(Instant::now())) {
        Ok(Ok(status)) => {
            let process_wait_ms = elapsed_millis(wait_start);
            let output = collect_drained_output(stdout, stderr);

            command_output_to_execution(
                status,
                output.stdout,
                output.stderr,
                CommandExecutionTimings {
                    process_spawn_ms,
                    process_wait_ms,
                    result_serialization_ms: 0,
                },
            )
        }
        Ok(Err(error)) => {
            let process_wait_ms = elapsed_millis(wait_start);
            let output = collect_drained_output(stdout, stderr);
            let serialization_start = Instant::now();
            let frames =
                output.with_error_frame(format!("failed to wait for allowlisted binary: {error}"));

            CommandExecution {
                status: "failed".to_string(),
                exit_code: None,
                frames,
                timings: CommandExecutionTimings {
                    process_spawn_ms,
                    process_wait_ms,
                    result_serialization_ms: elapsed_millis(serialization_start),
                },
            }
        }
        Err(std::sync::mpsc::RecvTimeoutError::Timeout) => {
            terminate_process(child_id);
            if wait_receiver.recv_timeout(Duration::from_secs(2)).is_err() {
                force_terminate_process(child_id);
                let _ = wait_receiver.recv_timeout(Duration::from_secs(2));
            }
            let process_wait_ms = elapsed_millis(wait_start);
            let output = collect_drained_output(stdout, stderr);
            let serialization_start = Instant::now();
            let frames = output.with_error_frame(format!(
                "binary execution timed out after {timeout_seconds} seconds"
            ));

            CommandExecution {
                status: "failed".to_string(),
                exit_code: None,
                frames,
                timings: CommandExecutionTimings {
                    process_spawn_ms,
                    process_wait_ms,
                    result_serialization_ms: elapsed_millis(serialization_start),
                },
            }
        }
        Err(std::sync::mpsc::RecvTimeoutError::Disconnected) => {
            let process_wait_ms = elapsed_millis(wait_start);
            let output = collect_drained_output(stdout, stderr);
            let serialization_start = Instant::now();
            let frames = output.with_error_frame(
                "failed to wait for allowlisted binary: wait worker disconnected".to_string(),
            );

            CommandExecution {
                status: "failed".to_string(),
                exit_code: None,
                frames,
                timings: CommandExecutionTimings {
                    process_spawn_ms,
                    process_wait_ms,
                    result_serialization_ms: elapsed_millis(serialization_start),
                },
            }
        }
    }
}

fn terminate_process(pid: u32) {
    let _ = Command::new("kill")
        .arg("-TERM")
        .arg(pid.to_string())
        .status();
}

fn force_terminate_process(pid: u32) {
    let _ = Command::new("kill")
        .arg("-KILL")
        .arg(pid.to_string())
        .status();
}

fn spawn_output_drain<R>(mut reader: R) -> thread::JoinHandle<Result<Vec<u8>, String>>
where
    R: Read + Send + 'static,
{
    thread::spawn(move || {
        let mut bytes = Vec::new();
        reader
            .read_to_end(&mut bytes)
            .map_err(|error| error.to_string())?;

        Ok(bytes)
    })
}

fn collect_drained_output(
    stdout: Option<thread::JoinHandle<Result<Vec<u8>, String>>>,
    stderr: Option<thread::JoinHandle<Result<Vec<u8>, String>>>,
) -> DrainedCommandOutput {
    DrainedCommandOutput {
        stdout: collect_drained_pipe(stdout, "stdout"),
        stderr: collect_drained_pipe(stderr, "stderr"),
    }
}

fn collect_drained_pipe(
    handle: Option<thread::JoinHandle<Result<Vec<u8>, String>>>,
    stream: &str,
) -> Vec<u8> {
    match handle {
        Some(handle) => match handle.join() {
            Ok(Ok(bytes)) => bytes,
            Ok(Err(error)) => format!("failed to read command {stream}: {error}").into_bytes(),
            Err(_) => format!("failed to join command {stream} reader").into_bytes(),
        },
        None => Vec::new(),
    }
}

struct DrainedCommandOutput {
    stdout: Vec<u8>,
    stderr: Vec<u8>,
}

impl DrainedCommandOutput {
    fn with_error_frame(self, message: String) -> Vec<CommandPushFrame> {
        let mut frames = output_bytes_to_frames(self.stdout, self.stderr);

        frames.push(CommandPushFrame {
            frame_type: "stderr".to_string(),
            message,
        });

        frames
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

    let mut candidates = home_relative_orbit_binary().into_iter().collect::<Vec<_>>();
    candidates.push("/usr/local/bin/orbit".to_string());
    candidates.push("/opt/homebrew/bin/orbit".to_string());
    candidates.push("../cli/orbit".to_string());

    candidates.into_iter().find(|path| Path::new(path).exists())
}

fn existing_env_path(key: &str) -> Option<String> {
    let path = std::env::var(key).ok()?.trim().to_string();

    if path.is_empty() || !Path::new(&path).exists() {
        return None;
    }

    Some(path)
}

fn home_relative_orbit_binary() -> Option<String> {
    std::env::var("HOME")
        .ok()
        .filter(|home| !home.trim().is_empty())
        .map(|home| PathBuf::from(home).join(".local/bin/orbit"))
        .map(|path| path.to_string_lossy().to_string())
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

fn command_output_to_execution(
    status: ExitStatus,
    stdout: Vec<u8>,
    stderr: Vec<u8>,
    mut timings: CommandExecutionTimings,
) -> CommandExecution {
    let serialization_start = Instant::now();
    let mut frames = output_bytes_to_frames(stdout, stderr);
    let exit_code = status.code();

    frames.push(CommandPushFrame {
        frame_type: "exit".to_string(),
        message: exit_code
            .map(|code| code.to_string())
            .unwrap_or_else(|| "terminated".to_string()),
    });

    CommandExecution {
        status: if status.success() {
            "succeeded".to_string()
        } else {
            "failed".to_string()
        },
        exit_code,
        frames,
        timings: {
            timings.result_serialization_ms = elapsed_millis(serialization_start);
            timings
        },
    }
}

fn elapsed_millis(start: Instant) -> u64 {
    u64::try_from(start.elapsed().as_millis()).unwrap_or(u64::MAX)
}

fn output_bytes_to_frames(stdout: Vec<u8>, stderr: Vec<u8>) -> Vec<CommandPushFrame> {
    let mut frames = Vec::new();
    let stdout = String::from_utf8_lossy(&stdout).trim().to_string();
    let stderr = String::from_utf8_lossy(&stderr).trim().to_string();

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

    frames
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
    cwd: Option<String>,
    environment: Option<HashMap<String, String>>,
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
    timings: CommandPushTimings,
}

#[derive(Debug, Clone)]
struct CommandExecution {
    status: String,
    frames: Vec<CommandPushFrame>,
    exit_code: Option<i32>,
    timings: CommandExecutionTimings,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
struct CommandPushTimings {
    authorization_ms: u64,
    process_spawn_ms: u64,
    process_wait_ms: u64,
    result_serialization_ms: u64,
}

#[derive(Debug, Clone, PartialEq, Eq)]
struct CommandExecutionTimings {
    process_spawn_ms: u64,
    process_wait_ms: u64,
    result_serialization_ms: u64,
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
    use serde_json::Value;
    use std::sync::atomic::{AtomicUsize, Ordering};
    use std::sync::Arc;
    use tower::ServiceExt;

    struct StaticCommandAuthorizer {
        allowed: bool,
    }

    struct CountingTokenVerifier {
        count: Arc<AtomicUsize>,
    }

    impl TokenVerifier for CountingTokenVerifier {
        fn verify_operation_token(
            &self,
            _operation_token: &str,
            _command: &str,
        ) -> Result<crate::OperationTokenVerification, String> {
            self.count.fetch_add(1, Ordering::SeqCst);
            Ok(crate::OperationTokenVerification {
                allowed: true,
                reason: None,
                operation_id: None,
            })
        }
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
    fn default_bind_addr_targets_reachable_agent_port() {
        assert_eq!(default_http_bind_addr(), "0.0.0.0:9477");
    }

    #[test]
    fn execute_binary_writes_request_input_to_stdin() {
        let execution = execute_binary(&CommandPushRequest {
            operation_id: "op_agent_test_123".to_string(),
            binary: "/bin/cat".to_string(),
            argv: vec![],
            input: Some("agent stdin\n".to_string()),
            cwd: None,
            environment: None,
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
        let _ = execution.timings.process_spawn_ms;
        let _ = execution.timings.process_wait_ms;
        let _ = execution.timings.result_serialization_ms;
    }

    #[test]
    fn execute_binary_applies_request_cwd_and_environment() {
        let cwd = std::env::temp_dir()
            .canonicalize()
            .expect("temp dir should canonicalize");
        let execution = execute_binary(&CommandPushRequest {
            operation_id: "op_agent_test_123".to_string(),
            binary: "/bin/sh".to_string(),
            argv: vec![
                "-c".to_string(),
                "printf '%s:%s' \"$PWD\" \"$ORBIT_AGENT_TEST_VALUE\"".to_string(),
            ],
            input: None,
            cwd: Some(cwd.to_string_lossy().to_string()),
            environment: Some(HashMap::from([(
                "ORBIT_AGENT_TEST_VALUE".to_string(),
                "from-env".to_string(),
            )])),
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
                message: format!("{}:from-env", cwd.to_string_lossy()),
            })
        );
    }

    #[test]
    fn execute_binary_does_not_quantize_fast_completion_to_scheduler_delay() {
        let execution = execute_binary(&CommandPushRequest {
            operation_id: "op_agent_test_123".to_string(),
            binary: "/usr/bin/true".to_string(),
            argv: vec![],
            input: None,
            cwd: None,
            environment: None,
            operation_token: "op_test_123".to_string(),
            timeout_seconds: 30,
            stream: true,
        });

        assert_eq!(execution.status, "succeeded");
        assert!(execution.timings.process_wait_ms < 25);
    }

    #[test]
    fn execute_binary_drains_large_stdout_while_child_runs() {
        let execution = execute_binary(&CommandPushRequest {
            operation_id: "op_agent_test_123".to_string(),
            binary: "/bin/sh".to_string(),
            argv: vec![
                "-c".to_string(),
                "i=0; while [ $i -lt 8192 ]; do printf 0123456789abcdef; i=$((i + 1)); done"
                    .to_string(),
            ],
            input: None,
            cwd: None,
            environment: None,
            operation_token: "op_test_123".to_string(),
            timeout_seconds: 5,
            stream: true,
        });

        assert_eq!(execution.status, "succeeded");
        assert_eq!(execution.exit_code, Some(0));
        assert_eq!(
            execution.frames.first(),
            Some(&CommandPushFrame {
                frame_type: "stdout".to_string(),
                message: "0123456789abcdef".repeat(8192),
            })
        );
    }

    #[test]
    fn execute_binary_times_out_with_bounded_wait_and_timeout_frame() {
        let start = Instant::now();
        let execution = execute_binary(&CommandPushRequest {
            operation_id: "op_timeout_test".to_string(),
            binary: "/bin/sleep".to_string(),
            argv: vec!["5".to_string()],
            input: None,
            cwd: None,
            environment: None,
            operation_token: "op_test".to_string(),
            timeout_seconds: 1,
            stream: true,
        });
        let elapsed = start.elapsed();

        assert_eq!(execution.status, "failed");
        assert_eq!(execution.exit_code, None);
        let has_timeout_frame = execution
            .frames
            .iter()
            .any(|f| f.frame_type == "stderr" && f.message.contains("timed out after 1 seconds"));
        assert!(
            has_timeout_frame,
            "expected timeout stderr frame, got frames: {:?}",
            execution.frames
        );
        assert!(
            elapsed.as_millis() < 3000,
            "timeout path should be bounded, took {} ms",
            elapsed.as_millis()
        );
        assert!(execution.timings.process_wait_ms < 3000);
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
            cwd: None,
            environment: None,
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
            timings: zero_execution_timings(),
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
            cwd: None,
            environment: None,
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
            timings: zero_execution_timings(),
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
            cwd: None,
            environment: None,
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
            timings: zero_execution_timings(),
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
            cwd: None,
            environment: None,
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
            timings: zero_execution_timings(),
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
            cwd: None,
            environment: None,
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
        assert!(payload["timings"]["authorization_ms"].is_u64());
        assert!(payload["timings"]["process_spawn_ms"].is_u64());
        assert!(payload["timings"]["process_wait_ms"].is_u64());
        assert!(payload["timings"]["result_serialization_ms"].is_u64());
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

    #[tokio::test]
    async fn gateway_command_authorizer_reuses_client_factory() {
        let factory_invocations = Arc::new(AtomicUsize::new(0));
        let verify_invocations = Arc::new(AtomicUsize::new(0));

        let factory = {
            let factory_invocations = factory_invocations.clone();
            let verify_invocations = verify_invocations.clone();
            Arc::new(move || {
                factory_invocations.fetch_add(1, Ordering::SeqCst);
                let verifier: Arc<dyn TokenVerifier> = Arc::new(CountingTokenVerifier {
                    count: verify_invocations.clone(),
                });
                Ok(verifier)
            }) as Arc<dyn Fn() -> Result<Arc<dyn TokenVerifier>, String> + Send + Sync>
        };

        let authorizer = Arc::new(GatewayCommandAuthorizer::with_factory(factory));
        let app = app_with_authorizer(authorizer);

        for i in 0..2 {
            let response = app
                .clone()
                .oneshot(
                    Request::builder()
                        .method(Method::POST)
                        .uri("/v1/commands")
                        .header(header::CONTENT_TYPE, "application/json")
                        .body(axum::body::Body::from(
                            serde_json::json!({
                                "operation_id": format!("op_reuse_{}", i),
                                "binary": "orbit",
                                "argv": ["version", "--json"],
                                "operation_token": format!("op_token_{}", i),
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
        }

        assert_eq!(
            factory_invocations.load(Ordering::SeqCst),
            1,
            "gateway/client factory must be invoked exactly once"
        );
        assert_eq!(
            verify_invocations.load(Ordering::SeqCst),
            2,
            "token verification must be invoked twice"
        );
    }

    fn zero_execution_timings() -> CommandExecutionTimings {
        CommandExecutionTimings {
            process_spawn_ms: 0,
            process_wait_ms: 0,
            result_serialization_ms: 0,
        }
    }
}
