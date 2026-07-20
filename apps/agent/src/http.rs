use crate::{build_service_status_snapshot, AgentConfig, HttpAgentGateway, ServiceStatusSnapshot};
use axum::{
    body::Body,
    extract::State,
    http::{header, StatusCode},
    response::IntoResponse,
    routing::{get, post},
    Json, Router,
};
use bytes::Bytes;
use futures_core::Stream;
use serde::{Deserialize, Serialize};
use std::{
    collections::HashMap,
    convert::Infallible,
    io::{Read, Write},
    net::{IpAddr, Ipv4Addr, SocketAddr},
    path::{Path, PathBuf},
    pin::Pin,
    process::{Child, Command, ExitStatus, Stdio},
    sync::{
        atomic::{AtomicBool, Ordering},
        Arc, Mutex,
    },
    task::{Context, Poll},
    thread::{self, JoinHandle},
    time::{Duration, Instant},
};

const PROCESS_STREAM_V1_CONTENT_TYPE: &str = "application/vnd.orbit.process-stream.v1";
const FRAME_TYPE_STDOUT: u8 = 1;
const FRAME_TYPE_STDERR: u8 = 2;
const FRAME_TYPE_AGENT_ERROR: u8 = 3;
const FRAME_TYPE_EXIT: u8 = 4;
const CHILD_WAIT_POLL_INTERVAL: Duration = Duration::from_millis(5);
const CHILD_KILL_GRACE: Duration = Duration::from_secs(2);
const AGENT_HTTP_PORT: u16 = 9477;
const MAX_BINARY_EXECUTION_TIMEOUT_SECONDS: u64 = 86_415;

pub async fn run_agent_service() {
    let config = AgentConfig::load_default()
        .unwrap_or_else(|error| panic!("refusing to start Orbit Agent command listener: {error}"));
    let command_bind_addr = command_bind_addr(&config)
        .unwrap_or_else(|error| panic!("refusing to start Orbit Agent command listener: {error}"));
    let local_status_bind_addr = local_status_bind_addr();

    let command_listener = tokio::net::TcpListener::bind(command_bind_addr)
        .await
        .unwrap_or_else(|error| {
            panic!("failed to bind Orbit Agent command listener on {command_bind_addr}: {error}")
        });
    let local_status_listener = tokio::net::TcpListener::bind(local_status_bind_addr)
        .await
        .unwrap_or_else(|error| {
            panic!(
                "failed to bind Orbit Agent local status listener on {local_status_bind_addr}: {error}"
            )
        });

    eprintln!("Orbit Agent command listener on http://{command_bind_addr}");
    eprintln!("Orbit Agent local status listener on http://{local_status_bind_addr}");

    let command_service = axum::serve(
        command_listener,
        command_app_with_authorizer(Arc::new(GatewayCommandAuthorizer::new(config))),
    );
    let local_status_service = axum::serve(local_status_listener, local_status_app());

    tokio::try_join!(command_service, local_status_service)
        .expect("failed to run Orbit Agent HTTP listeners");
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

fn local_status_app() -> Router {
    Router::new()
        .route("/health", get(health))
        .route("/status", get(status))
}

fn command_app_with_authorizer(authorizer: Arc<dyn CommandAuthorizer>) -> Router {
    Router::new()
        .route("/v1/commands", post(command_push))
        .route("/v1/commands/stream", post(command_push_stream))
        .with_state(AgentHttpState { authorizer })
}

fn command_bind_addr(config: &AgentConfig) -> Result<SocketAddr, String> {
    let requested_bind = match std::env::var("ORBIT_AGENT_HTTP_BIND") {
        Ok(bind) => Some(bind),
        Err(std::env::VarError::NotPresent) => None,
        Err(std::env::VarError::NotUnicode(_)) => {
            return Err("ORBIT_AGENT_HTTP_BIND must be valid Unicode".to_string());
        }
    };

    command_bind_addr_for(config, requested_bind.as_deref())
}

fn command_bind_addr_for(
    config: &AgentConfig,
    requested_bind: Option<&str>,
) -> Result<SocketAddr, String> {
    if !config.managed {
        return Err("managed Agent intent is required".to_string());
    }

    let expected_bind = SocketAddr::new(config.wireguard_address, AGENT_HTTP_PORT);
    let Some(requested_bind) = requested_bind else {
        return Ok(expected_bind);
    };
    let requested_bind = requested_bind
        .trim()
        .parse::<SocketAddr>()
        .map_err(|error| format!("ORBIT_AGENT_HTTP_BIND must be a socket address: {error}"))?;

    if requested_bind.ip().is_unspecified() {
        return Err("ORBIT_AGENT_HTTP_BIND must not use a wildcard address".to_string());
    }

    if requested_bind != expected_bind {
        return Err(format!(
            "ORBIT_AGENT_HTTP_BIND must match configured WireGuard listener {expected_bind}"
        ));
    }

    Ok(expected_bind)
}

fn local_status_bind_addr() -> SocketAddr {
    SocketAddr::new(IpAddr::V4(Ipv4Addr::LOCALHOST), AGENT_HTTP_PORT)
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
        argv: &[String],
        cwd: Option<&str>,
        environment: Option<&HashMap<String, String>>,
        input: Option<&str>,
    ) -> Result<crate::OperationTokenVerification, String>;
}

impl TokenVerifier for HttpAgentGateway {
    fn verify_operation_token(
        &self,
        operation_token: &str,
        command: &str,
        argv: &[String],
        cwd: Option<&str>,
        environment: Option<&HashMap<String, String>>,
        input: Option<&str>,
    ) -> Result<crate::OperationTokenVerification, String> {
        HttpAgentGateway::verify_operation_token(
            self,
            operation_token,
            command,
            argv,
            cwd,
            environment,
            input,
        )
        .map_err(|error| format!("{error:?}"))
    }
}

struct GatewayCommandAuthorizer {
    verifier: Mutex<Option<Arc<dyn TokenVerifier>>>,
    factory: Arc<dyn Fn() -> Result<Arc<dyn TokenVerifier>, String> + Send + Sync>,
}

impl GatewayCommandAuthorizer {
    fn new(config: AgentConfig) -> Self {
        Self::with_factory(Arc::new(move || {
            let gateway =
                HttpAgentGateway::new(config.clone()).map_err(|error| format!("{error:?}"))?;

            Ok(Arc::new(gateway) as Arc<dyn TokenVerifier>)
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
        argv: &[String],
        cwd: Option<&str>,
        environment: Option<&HashMap<String, String>>,
        input: Option<&str>,
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
            .verify_operation_token(operation_token, command, argv, cwd, environment, input)
    }
}

impl CommandAuthorizer for GatewayCommandAuthorizer {
    fn authorize(&self, request: &CommandPushRequest) -> Result<(), String> {
        if request.binary != "orbit" {
            return Err("unsupported Orbit Agent binary".to_string());
        }

        let command = request
            .argv
            .first()
            .ok_or_else(|| "agent-push argv must include an Orbit command".to_string())?;
        let verification = self.verify_operation_token(
            &request.operation_token,
            command,
            &request.argv,
            request.cwd.as_deref(),
            request.environment.as_ref(),
            request.input.as_deref(),
        )?;

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

async fn command_push_stream(
    State(state): State<AgentHttpState>,
    Json(request): Json<CommandPushRequest>,
) -> axum::response::Response {
    let authorization = tokio::task::spawn_blocking({
        let state = state.clone();
        let request = request.clone();

        move || {
            state
                .authorizer
                .authorize(&request)
                .map(|()| request)
                .map_err(|reason| {
                    (
                        StatusCode::UNAUTHORIZED,
                        format!("agent-push operation token was rejected: {reason}"),
                    )
                })
        }
    })
    .await;

    let request = match authorization {
        Ok(Ok(request)) => request,
        Ok(Err((status, message))) => return command_error(status, &message),
        Err(error) => {
            return command_error(
                StatusCode::INTERNAL_SERVER_ERROR,
                &format!("agent-push stream worker failed: {error}"),
            );
        }
    };

    match execute_binary_stream(request) {
        Ok(stream) => (
            StatusCode::OK,
            [(header::CONTENT_TYPE, PROCESS_STREAM_V1_CONTENT_TYPE)],
            Body::from_stream(stream),
        )
            .into_response(),
        Err((status, message)) => command_error(status, &message),
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
    let timeout_seconds = bounded_execution_timeout_seconds(request.timeout_seconds);
    let deadline = Instant::now() + Duration::from_secs(timeout_seconds);

    execute_binary_once(request, &request.argv, timeout_seconds, deadline)
}

fn execute_binary_stream(
    request: CommandPushRequest,
) -> Result<CommandOutputStream, (StatusCode, String)> {
    let binary = command_binary(&request).map_err(|error| {
        (
            StatusCode::INTERNAL_SERVER_ERROR,
            format!("failed to resolve allowlisted binary: {error}"),
        )
    })?;
    let mut command = Command::new(binary);
    command
        .args(&request.argv)
        .stdout(Stdio::piped())
        .stderr(Stdio::piped());

    if let Some(cwd) = &request.cwd {
        command.current_dir(cwd);
    }

    if let Some(environment) = &request.environment {
        command.envs(environment);
    }

    apply_agent_push_authorization_environment(&mut command, &request);

    if request.input.is_some() {
        command.stdin(Stdio::piped());
    } else {
        command.stdin(Stdio::null());
    }

    let mut child = command.spawn().map_err(|error| {
        (
            StatusCode::INTERNAL_SERVER_ERROR,
            format!("failed to execute allowlisted binary: {error}"),
        )
    })?;
    if let Some(input) = &request.input {
        if let Some(mut stdin) = child.stdin.take() {
            if let Err(error) = stdin.write_all(input.as_bytes()) {
                let _ = child.kill();
                let _ = child.wait();

                return Err((
                    StatusCode::INTERNAL_SERVER_ERROR,
                    format!("failed to write binary stdin: {error}"),
                ));
            }
        }
    }

    let (sender, receiver) = tokio::sync::mpsc::unbounded_channel();
    let completed = Arc::new(AtomicBool::new(false));
    let cancelled = Arc::new(AtomicBool::new(false));

    let stdout_drain = child.stdout.take().map(|stdout| {
        spawn_stream_drain(stdout, sender.clone(), cancelled.clone(), FRAME_TYPE_STDOUT)
    });
    let stderr_drain = child.stderr.take().map(|stderr| {
        spawn_stream_drain(stderr, sender.clone(), cancelled.clone(), FRAME_TYPE_STDERR)
    });

    spawn_stream_waiter(
        child,
        request.timeout_seconds,
        sender,
        completed.clone(),
        cancelled.clone(),
        StreamDrainHandles {
            stdout: stdout_drain,
            stderr: stderr_drain,
        },
        Instant::now(),
    );

    Ok(CommandOutputStream {
        receiver,
        completed,
        cancelled,
    })
}

fn encode_process_stream_frame(frame_type: u8, payload: &[u8]) -> Bytes {
    let payload_len = u32::try_from(payload.len()).unwrap_or(u32::MAX);
    let mut frame = Vec::with_capacity(8 + payload.len());
    frame.push(frame_type);
    frame.push(0);
    frame.extend_from_slice(&[0_u8, 0_u8]);
    frame.extend_from_slice(&payload_len.to_be_bytes());
    frame.extend_from_slice(payload);

    Bytes::from(frame)
}

fn encode_agent_error_frame(code: &str, message: impl Into<String>, retryable: bool) -> Bytes {
    let payload = serde_json::json!({
        "code": code,
        "message": message.into(),
        "retryable": retryable,
    });

    encode_process_stream_frame(FRAME_TYPE_AGENT_ERROR, payload.to_string().as_bytes())
}

fn encode_exit_frame(exit_code: Option<i32>, signal: Option<i32>, duration_ms: u64) -> Bytes {
    let payload = serde_json::json!({
        "exit_code": exit_code,
        "signal": signal,
        "duration_ms": duration_ms,
    });

    encode_process_stream_frame(FRAME_TYPE_EXIT, payload.to_string().as_bytes())
}

fn spawn_stream_drain<R>(
    mut reader: R,
    sender: tokio::sync::mpsc::UnboundedSender<Result<Bytes, Infallible>>,
    cancelled: Arc<AtomicBool>,
    frame_type: u8,
) -> JoinHandle<()>
where
    R: Read + Send + 'static,
{
    thread::spawn(move || {
        let mut buffer = [0_u8; 8192];

        loop {
            match reader.read(&mut buffer) {
                Ok(0) => break,
                Ok(read) => {
                    let frame = encode_process_stream_frame(frame_type, &buffer[..read]);

                    if sender.send(Ok(frame)).is_err() {
                        cancelled.store(true, Ordering::SeqCst);

                        break;
                    }
                }
                Err(error) => {
                    let _ = sender.send(Ok(encode_agent_error_frame(
                        "process_read_failed",
                        format!("failed to read command stream: {error}"),
                        false,
                    )));

                    break;
                }
            }
        }
    })
}

fn spawn_stream_waiter(
    mut child: Child,
    timeout_seconds: u64,
    sender: tokio::sync::mpsc::UnboundedSender<Result<Bytes, Infallible>>,
    completed: Arc<AtomicBool>,
    cancelled: Arc<AtomicBool>,
    drains: StreamDrainHandles,
    started_at: Instant,
) {
    thread::spawn(move || {
        let timeout_seconds = bounded_execution_timeout_seconds(timeout_seconds);
        let deadline =
            (timeout_seconds > 0).then(|| Instant::now() + Duration::from_secs(timeout_seconds));
        let wait_result = wait_for_child(&mut child, deadline, Some(&cancelled));

        let duration_ms = elapsed_millis(started_at);
        let mut exit_code = None;
        let mut signal = None;
        let mut agent_error_sent = false;
        let mut child_exited = false;

        match wait_result {
            Ok(ChildWaitOutcome::Exited(status)) => {
                child_exited = true;
                let status_parts = exit_status_parts(status);
                exit_code = status_parts.0;
                signal = status_parts.1;
            }
            Ok(ChildWaitOutcome::TimedOut) => {
                child_exited = terminate_child(&mut child, CHILD_KILL_GRACE)
                    .map(|status| status.is_some())
                    .unwrap_or(false);
                let _ = sender.send(Ok(encode_agent_error_frame(
                    "process_timeout",
                    format!("binary execution timed out after {timeout_seconds} seconds"),
                    false,
                )));
                agent_error_sent = true;
            }
            Ok(ChildWaitOutcome::Cancelled) => {
                child_exited = terminate_child(&mut child, CHILD_KILL_GRACE)
                    .map(|status| status.is_some())
                    .unwrap_or(false);
                agent_error_sent = true;
            }
            Err(error) => {
                let _ = sender.send(Ok(encode_agent_error_frame(
                    "process_wait_failed",
                    format!("failed to wait for allowlisted binary: {error}"),
                    false,
                )));
                agent_error_sent = true;
            }
        }

        if child_exited {
            if let Some(handle) = drains.stdout {
                let _ = handle.join();
            }

            if let Some(handle) = drains.stderr {
                let _ = handle.join();
            }
        }

        let _ = sender.send(Ok(encode_exit_frame(
            if agent_error_sent { None } else { exit_code },
            if agent_error_sent { None } else { signal },
            duration_ms,
        )));

        completed.store(true, Ordering::SeqCst);
    });
}

fn bounded_execution_timeout_seconds(timeout_seconds: u64) -> u64 {
    timeout_seconds.clamp(1, MAX_BINARY_EXECUTION_TIMEOUT_SECONDS)
}

struct StreamDrainHandles {
    stdout: Option<JoinHandle<()>>,
    stderr: Option<JoinHandle<()>>,
}

struct CommandOutputStream {
    receiver: tokio::sync::mpsc::UnboundedReceiver<Result<Bytes, Infallible>>,
    completed: Arc<AtomicBool>,
    cancelled: Arc<AtomicBool>,
}

impl Stream for CommandOutputStream {
    type Item = Result<Bytes, Infallible>;

    fn poll_next(self: Pin<&mut Self>, cx: &mut Context<'_>) -> Poll<Option<Self::Item>> {
        let stream = self.get_mut();

        Pin::new(&mut stream.receiver).poll_recv(cx)
    }
}

impl Drop for CommandOutputStream {
    fn drop(&mut self) {
        if !self.completed.load(Ordering::SeqCst) {
            self.cancelled.store(true, Ordering::SeqCst);
        }
    }
}

fn apply_agent_push_authorization_environment(command: &mut Command, request: &CommandPushRequest) {
    if let Some(command_name) = request.argv.first() {
        command.env(
            "ORBIT_AGENT_PUSH_AUTHORIZED_OPERATION_ID",
            &request.operation_id,
        );
        command.env("ORBIT_AGENT_PUSH_AUTHORIZED_COMMAND", command_name);
        command.env(
            "ORBIT_AGENT_PUSH_AUTHORIZED_OPERATION_TOKEN",
            &request.operation_token,
        );
    }
}

fn execute_binary_once(
    request: &CommandPushRequest,
    argv: &[String],
    timeout_seconds: u64,
    deadline: Instant,
) -> CommandExecution {
    let spawn_start = Instant::now();
    let binary = match command_binary(request) {
        Ok(binary) => binary,
        Err(error) => {
            return failed_execution(
                spawn_start,
                format!("failed to resolve allowlisted binary: {error}"),
            );
        }
    };
    let mut command = Command::new(binary);
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

    apply_agent_push_authorization_environment(&mut command, request);

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
    match wait_for_child(&mut child, Some(deadline), None) {
        Ok(ChildWaitOutcome::Exited(status)) => {
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
        Ok(ChildWaitOutcome::TimedOut) => {
            let terminated = terminate_child(&mut child, CHILD_KILL_GRACE)
                .ok()
                .flatten()
                .is_some();
            let process_wait_ms = elapsed_millis(wait_start);
            let output = if terminated {
                collect_drained_output(stdout, stderr)
            } else {
                DrainedCommandOutput::empty()
            };
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
        Ok(ChildWaitOutcome::Cancelled) => {
            let process_wait_ms = elapsed_millis(wait_start);
            let output = collect_drained_output(stdout, stderr);
            let serialization_start = Instant::now();
            let frames = output.with_error_frame("binary execution was cancelled".to_string());

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
        Err(error) => {
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
    }
}

enum ChildWaitOutcome {
    Exited(ExitStatus),
    TimedOut,
    Cancelled,
}

fn wait_for_child(
    child: &mut Child,
    deadline: Option<Instant>,
    cancelled: Option<&AtomicBool>,
) -> Result<ChildWaitOutcome, String> {
    loop {
        if let Some(status) = child.try_wait().map_err(|error| error.to_string())? {
            return Ok(ChildWaitOutcome::Exited(status));
        }

        if cancelled.is_some_and(|cancelled| cancelled.load(Ordering::SeqCst)) {
            return Ok(ChildWaitOutcome::Cancelled);
        }

        if deadline.is_some_and(|deadline| Instant::now() >= deadline) {
            return Ok(ChildWaitOutcome::TimedOut);
        }

        thread::sleep(child_wait_poll_duration(deadline));
    }
}

fn child_wait_poll_duration(deadline: Option<Instant>) -> Duration {
    deadline.map_or(CHILD_WAIT_POLL_INTERVAL, |deadline| {
        deadline
            .saturating_duration_since(Instant::now())
            .min(CHILD_WAIT_POLL_INTERVAL)
    })
}

fn terminate_child(child: &mut Child, grace: Duration) -> Result<Option<ExitStatus>, String> {
    if let Some(status) = child.try_wait().map_err(|error| error.to_string())? {
        return Ok(Some(status));
    }

    child.kill().map_err(|error| error.to_string())?;
    let deadline = Instant::now() + grace;

    match wait_for_child(child, Some(deadline), None)? {
        ChildWaitOutcome::Exited(status) => Ok(Some(status)),
        ChildWaitOutcome::TimedOut => Ok(None),
        ChildWaitOutcome::Cancelled => Ok(None),
    }
}

fn exit_status_parts(status: ExitStatus) -> (Option<i32>, Option<i32>) {
    #[cfg(unix)]
    {
        use std::os::unix::process::ExitStatusExt;

        (status.code(), status.signal())
    }

    #[cfg(not(unix))]
    {
        (status.code(), None)
    }
}

fn failed_execution(spawn_start: Instant, message: String) -> CommandExecution {
    let serialization_start = Instant::now();
    let frames = vec![CommandPushFrame {
        frame_type: "stderr".to_string(),
        message,
    }];

    CommandExecution {
        status: "failed".to_string(),
        exit_code: None,
        frames,
        timings: CommandExecutionTimings {
            process_spawn_ms: elapsed_millis(spawn_start),
            process_wait_ms: 0,
            result_serialization_ms: elapsed_millis(serialization_start),
        },
    }
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
    fn empty() -> Self {
        Self {
            stdout: Vec::new(),
            stderr: Vec::new(),
        }
    }

    fn with_error_frame(self, message: String) -> Vec<CommandPushFrame> {
        let mut frames = output_bytes_to_frames(self.stdout, self.stderr);

        frames.push(CommandPushFrame {
            frame_type: "stderr".to_string(),
            message,
        });

        frames
    }
}

fn command_binary(request: &CommandPushRequest) -> Result<String, String> {
    // Binary gate is now enforced once in CommandAuthorizer::authorize.
    // Only "orbit" binaries reach execution paths.
    if let Some(path) = request_env_path(request, "ORBIT_BIN_PATH")? {
        return Ok(path);
    }

    resolve_orbit_binary()
}

fn resolve_orbit_binary() -> Result<String, String> {
    if let Some(path) = existing_env_path("ORBIT_AGENT_ORBIT_BINARY")? {
        return Ok(path);
    }

    orbit_binary_candidates()
        .into_iter()
        .find(|path| Path::new(path).exists())
        .ok_or_else(|| {
            "no absolute Orbit binary found; set ORBIT_AGENT_ORBIT_BINARY or ORBIT_BIN_PATH to an absolute existing orbit binary".to_string()
        })
}

fn orbit_binary_candidates() -> Vec<String> {
    orbit_binary_candidates_for(std::env::var("HOME").ok(), cfg!(target_os = "macos"))
}

fn orbit_binary_candidates_for(home: Option<String>, macos: bool) -> Vec<String> {
    let home_binary = home.filter(|home| !home.trim().is_empty()).map(|home| {
        PathBuf::from(home)
            .join(".local/bin/orbit")
            .to_string_lossy()
            .to_string()
    });
    let mut candidates = Vec::new();

    if macos {
        candidates.extend(home_binary.clone());
        candidates.push("/opt/homebrew/bin/orbit".to_string());
        candidates.push("/usr/local/bin/orbit".to_string());
    } else {
        candidates.push("/usr/local/bin/orbit".to_string());
        candidates.extend(home_binary);
        candidates.push("/opt/homebrew/bin/orbit".to_string());
    }

    candidates
}

fn existing_env_path(key: &str) -> Result<Option<String>, String> {
    let Some(path) = std::env::var(key).ok() else {
        return Ok(None);
    };

    existing_absolute_path(key, &path).map(Some)
}

fn request_env_path(request: &CommandPushRequest, key: &str) -> Result<Option<String>, String> {
    let Some(path) = request
        .environment
        .as_ref()
        .and_then(|environment| environment.get(key))
    else {
        return Ok(None);
    };

    existing_absolute_path(key, path).map(Some)
}

fn existing_absolute_path(key: &str, path: &str) -> Result<String, String> {
    let path = path.trim();

    if path.is_empty() {
        return Err(format!("{key} must not be empty"));
    }

    let candidate = Path::new(path);

    if !candidate.is_absolute() {
        return Err(format!("{key} must be an absolute path, got {path}"));
    }

    if !candidate.exists() {
        return Err(format!("{key} path does not exist: {path}"));
    }

    Ok(path.to_string())
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

    #[derive(Debug, Clone, PartialEq, Eq)]
    struct CapturedVerificationRequest {
        operation_token: String,
        command: String,
        argv: Vec<String>,
        cwd: Option<String>,
        environment: Option<HashMap<String, String>>,
        input: Option<String>,
    }

    struct CapturingTokenVerifier {
        captured: Arc<Mutex<Option<CapturedVerificationRequest>>>,
    }

    impl TokenVerifier for CountingTokenVerifier {
        fn verify_operation_token(
            &self,
            _operation_token: &str,
            _command: &str,
            _argv: &[String],
            _cwd: Option<&str>,
            _environment: Option<&HashMap<String, String>>,
            _input: Option<&str>,
        ) -> Result<crate::OperationTokenVerification, String> {
            self.count.fetch_add(1, Ordering::SeqCst);
            Ok(crate::OperationTokenVerification {
                allowed: true,
                reason: None,
                operation_id: None,
            })
        }
    }

    impl TokenVerifier for CapturingTokenVerifier {
        fn verify_operation_token(
            &self,
            operation_token: &str,
            command: &str,
            argv: &[String],
            cwd: Option<&str>,
            environment: Option<&HashMap<String, String>>,
            input: Option<&str>,
        ) -> Result<crate::OperationTokenVerification, String> {
            *self.captured.lock().expect("captured verifier lock") =
                Some(CapturedVerificationRequest {
                    operation_token: operation_token.to_string(),
                    command: command.to_string(),
                    argv: argv.to_vec(),
                    cwd: cwd.map(str::to_string),
                    environment: environment.cloned(),
                    input: input.map(str::to_string),
                });

            Ok(crate::OperationTokenVerification {
                allowed: true,
                reason: None,
                operation_id: None,
            })
        }
    }

    impl CommandAuthorizer for StaticCommandAuthorizer {
        fn authorize(&self, request: &CommandPushRequest) -> Result<(), String> {
            if request.binary != "orbit" {
                return Err("unsupported Orbit Agent binary".to_string());
            }
            if self.allowed {
                return Ok(());
            }

            Err("invalid_token".to_string())
        }
    }

    fn app_with_static_authorizer(allowed: bool) -> Router {
        command_app_with_authorizer(Arc::new(StaticCommandAuthorizer { allowed }))
    }

    fn listener_security_config() -> AgentConfig {
        AgentConfig {
            gateway_url: "https://gateway.test".to_string(),
            node_id: "node_123".to_string(),
            node_name: "NMBP".to_string(),
            gateway_name: "dev-gateway".to_string(),
            ca_pem_path: None,
            platform: "macos_26-5-1".to_string(),
            managed: true,
            wireguard_address: "10.6.0.3".parse().expect("wireguard IP"),
        }
    }

    #[test]
    fn health_response_is_ok() {
        let response = HealthResponse {
            status: "ok".to_string(),
        };

        assert_eq!(response.status, "ok");
    }

    #[tokio::test]
    async fn listener_security_command_router_does_not_expose_local_status_endpoints() {
        let response = app_with_static_authorizer(true)
            .oneshot(
                Request::builder()
                    .method(Method::GET)
                    .uri("/health")
                    .body(axum::body::Body::empty())
                    .expect("request"),
            )
            .await
            .expect("response");

        assert_eq!(response.status(), StatusCode::NOT_FOUND);
    }

    #[tokio::test]
    async fn listener_security_local_router_does_not_expose_command_endpoints() {
        let response = local_status_app()
            .oneshot(
                Request::builder()
                    .method(Method::POST)
                    .uri("/v1/commands")
                    .body(axum::body::Body::empty())
                    .expect("request"),
            )
            .await
            .expect("response");

        assert_eq!(response.status(), StatusCode::NOT_FOUND);
    }

    #[test]
    fn listener_security_derives_command_bind_from_configured_wireguard_address() {
        let bind = command_bind_addr_for(&listener_security_config(), None)
            .expect("configured WireGuard bind");

        assert_eq!(bind, "10.6.0.3:9477".parse().expect("socket address"));
    }

    #[test]
    fn listener_security_accepts_only_an_exact_wireguard_bind_override() {
        let config = listener_security_config();

        assert_eq!(
            command_bind_addr_for(&config, Some("10.6.0.3:9477")).expect("exact WireGuard bind"),
            "10.6.0.3:9477".parse().expect("socket address")
        );
        assert_eq!(
            command_bind_addr_for(&config, Some("0.0.0.0:9477"))
                .expect_err("wildcard bind must fail closed"),
            "ORBIT_AGENT_HTTP_BIND must not use a wildcard address"
        );
        assert!(command_bind_addr_for(&config, Some("192.168.1.9:9477"))
            .expect_err("non-WireGuard bind must fail closed")
            .contains("must match configured WireGuard listener"));
        assert!(command_bind_addr_for(&config, Some("10.6.0.3:9000"))
            .expect_err("non-Agent port must fail closed")
            .contains("must match configured WireGuard listener"));
    }

    #[test]
    fn listener_security_status_listener_is_loopback_only() {
        let bind = local_status_bind_addr();

        assert!(bind.ip().is_loopback());
        assert_eq!(bind.port(), 9477);
    }

    #[test]
    fn execute_binary_writes_request_input_to_stdin() {
        let execution = execute_binary(&CommandPushRequest {
            operation_id: "op_agent_test_123".to_string(),
            binary: "orbit".to_string(),
            argv: vec![],
            input: Some("agent stdin\n".to_string()),
            cwd: None,
            environment: Some(HashMap::from([(
                "ORBIT_BIN_PATH".to_string(),
                "/bin/cat".to_string(),
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
            binary: "orbit".to_string(),
            argv: vec![
                "-c".to_string(),
                "printf '%s:%s' \"$PWD\" \"$ORBIT_AGENT_TEST_VALUE\"".to_string(),
            ],
            input: None,
            cwd: Some(cwd.to_string_lossy().to_string()),
            environment: Some(HashMap::from([
                ("ORBIT_BIN_PATH".to_string(), "/bin/sh".to_string()),
                ("ORBIT_AGENT_TEST_VALUE".to_string(), "from-env".to_string()),
            ])),
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
            binary: "orbit".to_string(),
            argv: vec![],
            input: None,
            cwd: None,
            environment: Some(HashMap::from([(
                "ORBIT_BIN_PATH".to_string(),
                "/usr/bin/true".to_string(),
            )])),
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
            binary: "orbit".to_string(),
            argv: vec![
                "-c".to_string(),
                "i=0; while [ $i -lt 8192 ]; do printf 0123456789abcdef; i=$((i + 1)); done"
                    .to_string(),
            ],
            input: None,
            cwd: None,
            environment: Some(HashMap::from([(
                "ORBIT_BIN_PATH".to_string(),
                "/bin/sh".to_string(),
            )])),
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
            binary: "orbit".to_string(),
            argv: vec!["5".to_string()],
            input: None,
            cwd: None,
            environment: Some(HashMap::from([(
                "ORBIT_BIN_PATH".to_string(),
                "/bin/sleep".to_string(),
            )])),
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
    fn agent_push_timeout_supports_the_schedule_transport_bound() {
        assert_eq!(bounded_execution_timeout_seconds(0), 1);
        assert_eq!(bounded_execution_timeout_seconds(7_215), 7_215);
        assert_eq!(bounded_execution_timeout_seconds(86_415), 86_415);
        assert_eq!(bounded_execution_timeout_seconds(u64::MAX), 86_415);
    }

    #[test]
    fn command_binary_prefers_request_orbit_bin_path() {
        let binary = std::env::temp_dir().join(format!(
            "orbit-agent-request-bin-path-{}",
            std::process::id()
        ));
        std::fs::write(&binary, "#!/usr/bin/env sh\n").expect("write temp orbit binary");
        let binary = binary.to_string_lossy().to_string();

        let request = CommandPushRequest {
            operation_id: "op_agent_test_123".to_string(),
            binary: "orbit".to_string(),
            argv: vec![],
            input: None,
            cwd: None,
            environment: Some(HashMap::from([(
                "ORBIT_BIN_PATH".to_string(),
                binary.clone(),
            )])),
            operation_token: "op_test_123".to_string(),
            timeout_seconds: 30,
            stream: true,
        };

        assert_eq!(command_binary(&request).expect("command binary"), binary);
    }

    #[test]
    fn command_binary_rejects_relative_request_orbit_bin_path() {
        let request = CommandPushRequest {
            operation_id: "op_agent_test_123".to_string(),
            binary: "orbit".to_string(),
            argv: vec![],
            input: None,
            cwd: None,
            environment: Some(HashMap::from([(
                "ORBIT_BIN_PATH".to_string(),
                "../cli/orbit".to_string(),
            )])),
            operation_token: "op_test_123".to_string(),
            timeout_seconds: 30,
            stream: true,
        };

        assert!(command_binary(&request).is_err());
    }

    #[test]
    fn orbit_binary_candidates_prefer_system_install_on_linux() {
        let candidates = orbit_binary_candidates_for(Some("/home/orbit".to_string()), false);

        assert_eq!(
            candidates.first(),
            Some(&"/usr/local/bin/orbit".to_string())
        );
        assert_eq!(
            candidates.get(1),
            Some(&"/home/orbit/.local/bin/orbit".to_string())
        );
        assert!(candidates.iter().all(|path| Path::new(path).is_absolute()));
        assert!(!candidates
            .iter()
            .any(|path| path == "../cli/orbit" || path == "orbit"));
    }

    #[test]
    fn orbit_binary_candidates_keep_home_install_first_on_macos() {
        let candidates = orbit_binary_candidates_for(Some("/Users/orbit".to_string()), true);

        assert_eq!(
            candidates.first(),
            Some(&"/Users/orbit/.local/bin/orbit".to_string())
        );
        assert_eq!(
            candidates.get(1),
            Some(&"/opt/homebrew/bin/orbit".to_string())
        );
        assert!(candidates.iter().all(|path| Path::new(path).is_absolute()));
        assert!(!candidates
            .iter()
            .any(|path| path == "../cli/orbit" || path == "orbit"));
    }

    #[test]
    fn process_timeout_path_does_not_shell_out_to_kill() {
        let source = include_str!("http.rs");
        let forbidden = ["Command::new(", "\"kill\"", ")"].join("");

        assert!(
            !source.contains(&forbidden),
            "agent process timeout path must kill through the owned Child handle"
        );
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
                            "argv": [],
                            "environment": {
                                "ORBIT_BIN_PATH": "/usr/bin/true"
                            },
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

    const PROCESS_STREAM_V1_CONTENT_TYPE: &str = "application/vnd.orbit.process-stream.v1";

    fn decode_process_stream_frames(body: &[u8]) -> Vec<(u8, Vec<u8>)> {
        let mut frames = Vec::new();
        let mut offset = 0;

        while offset + 8 <= body.len() {
            let frame_type = body[offset];
            let payload_len = u32::from_be_bytes([
                body[offset + 4],
                body[offset + 5],
                body[offset + 6],
                body[offset + 7],
            ]) as usize;
            offset += 8;

            if offset + payload_len > body.len() {
                break;
            }

            frames.push((frame_type, body[offset..offset + payload_len].to_vec()));
            offset += payload_len;
        }

        frames
    }

    #[tokio::test]
    async fn command_push_stream_endpoint_returns_process_stream_v1_content_type() {
        let response = app_with_static_authorizer(true)
            .oneshot(
                Request::builder()
                    .method(Method::POST)
                    .uri("/v1/commands/stream")
                    .header(header::CONTENT_TYPE, "application/json")
                    .body(axum::body::Body::from(
                        serde_json::json!({
                            "operation_id": "op_agent_stream_ct",
                            "binary": "orbit",
                            "argv": [],
                            "environment": {
                                "ORBIT_BIN_PATH": "/usr/bin/true"
                            },
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
        assert_eq!(
            response
                .headers()
                .get(header::CONTENT_TYPE)
                .and_then(|value| value.to_str().ok()),
            Some(PROCESS_STREAM_V1_CONTENT_TYPE)
        );
    }

    #[tokio::test]
    async fn command_push_stream_endpoint_emits_exit_frame_with_child_exit_code() {
        let response = app_with_static_authorizer(true)
            .oneshot(
                Request::builder()
                    .method(Method::POST)
                    .uri("/v1/commands/stream")
                    .header(header::CONTENT_TYPE, "application/json")
                    .body(axum::body::Body::from(
                        serde_json::json!({
                            "operation_id": "op_agent_stream_exit",
                            "binary": "orbit",
                            "argv": ["-c", "exit 17"],
                            "environment": {
                                "ORBIT_BIN_PATH": "/bin/sh"
                            },
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
        let frames = decode_process_stream_frames(&body);
        let exit_frame = frames
            .iter()
            .find(|(frame_type, _)| *frame_type == 4)
            .expect("expected exit frame");

        let payload: Value = serde_json::from_slice(&exit_frame.1).expect("exit json");
        assert_eq!(payload["exit_code"], 17);
    }

    #[tokio::test]
    async fn command_push_stream_endpoint_emits_agent_error_frame_on_timeout() {
        let response = app_with_static_authorizer(true)
            .oneshot(
                Request::builder()
                    .method(Method::POST)
                    .uri("/v1/commands/stream")
                    .header(header::CONTENT_TYPE, "application/json")
                    .body(axum::body::Body::from(
                        serde_json::json!({
                            "operation_id": "op_agent_stream_timeout",
                            "binary": "orbit",
                            "argv": ["5"],
                            "environment": {
                                "ORBIT_BIN_PATH": "/bin/sleep"
                            },
                            "operation_token": "op_test_123",
                            "timeout_seconds": 1,
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
        let frames = decode_process_stream_frames(&body);
        let agent_error_frame = frames
            .iter()
            .find(|(frame_type, _)| *frame_type == 3)
            .expect("expected agent_error frame");

        let payload: Value =
            serde_json::from_slice(&agent_error_frame.1).expect("agent_error json");
        assert!(payload["message"]
            .as_str()
            .is_some_and(|message| message.contains("timed out after 1 seconds")));
    }

    #[tokio::test]
    async fn command_push_stream_endpoint_streams_allowlisted_binary_output() {
        let response = app_with_static_authorizer(true)
            .oneshot(
                Request::builder()
                    .method(Method::POST)
                    .uri("/v1/commands/stream")
                    .header(header::CONTENT_TYPE, "application/json")
                    .body(axum::body::Body::from(
                        serde_json::json!({
                            "operation_id": "op_agent_stream_test_123",
                            "binary": "orbit",
                            "argv": ["orbit version"],
                            "environment": {
                                "ORBIT_BIN_PATH": "/bin/echo"
                            },
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
        let frames = decode_process_stream_frames(&body);
        let stdout = frames
            .iter()
            .filter(|(frame_type, _)| *frame_type == 1)
            .flat_map(|(_, payload)| payload.iter().copied())
            .collect::<Vec<_>>();
        let output = String::from_utf8(stdout).expect("utf8 output");
        let normalized = output.to_ascii_lowercase();

        assert!(
            normalized.contains("version") || normalized.contains("orbit"),
            "expected stdout version output, got: {output:?}"
        );
    }

    #[tokio::test]
    async fn command_push_stream_endpoint_rejects_non_allowlisted_binaries() {
        let response = app_with_static_authorizer(true)
            .oneshot(
                Request::builder()
                    .method(Method::POST)
                    .uri("/v1/commands/stream")
                    .header(header::CONTENT_TYPE, "application/json")
                    .body(axum::body::Body::from(
                        serde_json::json!({
                            "operation_id": "op_agent_stream_test_123",
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

        assert_eq!(response.status(), StatusCode::UNAUTHORIZED);
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

        assert_eq!(response.status(), StatusCode::UNAUTHORIZED);
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
        let app = command_app_with_authorizer(authorizer);

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

    #[test]
    fn gateway_command_authorizer_forwards_execution_context_to_gateway_verifier() {
        let captured = Arc::new(Mutex::new(None));
        let verifier: Arc<dyn TokenVerifier> = Arc::new(CapturingTokenVerifier {
            captured: captured.clone(),
        });
        let factory = Arc::new(move || Ok(verifier.clone()))
            as Arc<dyn Fn() -> Result<Arc<dyn TokenVerifier>, String> + Send + Sync>;
        let authorizer = GatewayCommandAuthorizer::with_factory(factory);
        let request = CommandPushRequest {
            operation_id: "op_context_test".to_string(),
            binary: "orbit".to_string(),
            argv: vec![
                "internal:executor:verify".to_string(),
                "--operation-token=op_token".to_string(),
                "--json".to_string(),
            ],
            input: Some("stdin-payload".to_string()),
            cwd: Some("/srv/orbit".to_string()),
            environment: Some(HashMap::from([
                ("ALPHA".to_string(), "1".to_string()),
                ("BETA".to_string(), "2".to_string()),
            ])),
            operation_token: "op_token".to_string(),
            timeout_seconds: 30,
            stream: false,
        };

        authorizer.authorize(&request).expect("authorized");

        let captured = captured
            .lock()
            .expect("captured verifier lock")
            .clone()
            .expect("verification request captured");

        assert_eq!(captured.operation_token, "op_token");
        assert_eq!(captured.command, "internal:executor:verify");
        assert_eq!(captured.argv, request.argv);
        assert_eq!(captured.cwd.as_deref(), Some("/srv/orbit"));
        assert_eq!(
            captured
                .environment
                .as_ref()
                .and_then(|environment| environment.get("ALPHA")),
            Some(&"1".to_string())
        );
        assert_eq!(captured.input.as_deref(), Some("stdin-payload"));
    }

    #[tokio::test]
    async fn command_authorizer_rejects_non_orbit_binary() {
        // The binary gate is now inside authorize; non-"orbit" must be rejected at the boundary.
        let authorizer: Arc<dyn CommandAuthorizer> =
            Arc::new(GatewayCommandAuthorizer::new(listener_security_config()));
        let request = CommandPushRequest {
            operation_id: "op_bin_test".to_string(),
            binary: "not-orbit".to_string(),
            argv: vec!["version".to_string()],
            input: None,
            cwd: None,
            environment: None,
            operation_token: "op_token".to_string(),
            timeout_seconds: 30,
            stream: false,
        };

        let result = authorizer.authorize(&request);
        assert!(result.is_err());
        let err = result.unwrap_err();
        assert!(
            err.contains("unsupported Orbit Agent binary"),
            "expected binary rejection message, got: {err}"
        );
    }
}
