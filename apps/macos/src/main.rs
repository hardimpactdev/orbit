use orbit_agent::{
    gateway_host_from_config, ping_gateway_connection, AgentConfig, ConfigError, ConnectionStatus,
    GatewayClient, GatewayConnection, ServiceStatusSnapshot,
};
use orbit_macos::colima::{ensure_ready, stop_owned_profile};
use orbit_macos::installer::{apply_bound_update, commit_install_attempt, ApplyFailure};
use orbit_macos::legacy::{
    conflict_remediation, inspect_legacy_agent, parse_launchctl_pid, parse_lsof_listener_pid,
    parse_plist_program, LegacyDecision, LegacyLaunchdState, ListenerOwner, LEGACY_LAUNCHD_LABEL,
};
use orbit_macos::lifecycle::{
    agent_restart_endpoint, dashboard_close_action, provider_health_action, provider_label,
    provider_retry_enabled, quit_disposition, try_begin_provider_attempt, ProviderHealthAction,
    ProviderRuntimeState, QuitDisposition,
};
use orbit_macos::paths::{
    desktop_launch_agent_plist, legacy_launch_agent_plist, pending_update_path,
};
use orbit_macos::pending_update::{
    read_pending_update, ExpectedUpdateIdentity, PendingDesktopUpdate,
};
use orbit_macos::supervisor::{
    agent_launch_plan, agent_status_label, crash_action, resolve_agent_binary,
    spawn_supervised_agent, stop_supervised_agent, AgentRunState, CrashAction,
    DEFAULT_CHILD_CRASH_WINDOW, DEFAULT_MAX_CHILD_CRASH_RESTARTS,
};
use orbit_macos::tray_labels::{
    launch_at_login_checked, launch_at_login_label, quit_orbit_label, restart_orbit_label,
    LaunchAtLoginState,
};
use orbit_macos::update_machine::{
    ready_state_for_handoff, transition, InstallAttemptResult, UpdateEvent, UpdateState,
};
use orbit_macos::version::orbit_version;
use serde::de::DeserializeOwned;
use serde::{Deserialize, Serialize};
use std::collections::{HashMap, HashSet};
use std::path::{Path, PathBuf};
use std::process::Child;
use std::sync::{Arc, Mutex};
use std::thread;
use std::time::{Duration, Instant};
use tauri::image::Image;
use tauri::menu::{
    CheckMenuItem, CheckMenuItemBuilder, Menu, MenuBuilder, MenuItem, MenuItemBuilder,
};
use tauri::tray::{MouseButton, TrayIconBuilder, TrayIconEvent};
use tauri::{AppHandle, Manager, PhysicalPosition, RunEvent, WebviewWindow, Wry};
use tauri_plugin_autostart::MacosLauncher;

const TRAY_ID: &str = "orbit-macos-tray";
const AGENT_STATUS_MENU_ID: &str = "agent_status";
const STATUS_MENU_ID: &str = "connection_status";
const NODE_IP_MENU_ID: &str = "node_peer_ip";
const GATEWAY_MENU_ID: &str = "gateway_ip";
const LAUNCH_AT_LOGIN_MENU_ID: &str = "launch_at_login";
const UPDATE_MENU_ID: &str = "update_status";
const REFRESH_MENU_ID: &str = "refresh_connection";
const RESTART_MENU_ID: &str = "restart_app";
const QUIT_MENU_ID: &str = "quit_app";
const PROVIDER_MENU_ID: &str = "provider_status";
const RETRY_PROVIDER_MENU_ID: &str = "retry_provider";
const IP_ROW_MIN_GAP_WIDTH_UNITS: usize = 1_200;
const IP_ROW_PAD_WIDE: char = '\u{2002}';
const IP_ROW_PAD_WIDE_WIDTH_UNITS: usize = 642;
const IP_ROW_PAD_MEDIUM: char = '\u{2009}';
const IP_ROW_PAD_MEDIUM_WIDTH_UNITS: usize = 175;
const IP_ROW_PAD_NARROW: char = '\u{200A}';
const IP_ROW_PAD_NARROW_WIDTH_UNITS: usize = 84;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum RefreshSource {
    TrayClick,
    MenuCommand,
}

struct DesktopRuntime {
    child: Mutex<Option<Child>>,
    agent_state: Mutex<AgentRunState>,
    crash_times: Mutex<Vec<Instant>>,
    update_state: Mutex<UpdateState>,
    launch_at_login: Mutex<LaunchAtLoginState>,
    conflict_reason: Mutex<Option<String>>,
    quitting: Mutex<bool>,
    docker_host: Mutex<Option<String>>,
    provider_state: Mutex<ProviderRuntimeState>,
    provider_attempt: Mutex<bool>,
}

impl DesktopRuntime {
    fn new() -> Self {
        Self {
            child: Mutex::new(None),
            agent_state: Mutex::new(AgentRunState::Stopped),
            crash_times: Mutex::new(Vec::new()),
            update_state: Mutex::new(UpdateState::Idle),
            launch_at_login: Mutex::new(LaunchAtLoginState::Disabled),
            conflict_reason: Mutex::new(None),
            quitting: Mutex::new(false),
            docker_host: Mutex::new(None),
            provider_state: Mutex::new(ProviderRuntimeState::Starting),
            provider_attempt: Mutex::new(false),
        }
    }
}

fn main() {
    bootstrap_process_environment();

    tauri::Builder::default()
        .plugin(tauri_plugin_autostart::init(
            MacosLauncher::LaunchAgent,
            None,
        ))
        .plugin(tauri_plugin_updater::Builder::new().build())
        .manage(DesktopRuntime::new())
        .invoke_handler(tauri::generate_handler![dashboard_config])
        .setup(|app| {
            let runtime = app.state::<DesktopRuntime>();
            request_provider_start(app.handle());
            enable_launch_at_login_after_setup(app.handle(), &runtime);
            consume_pending_update_handoff(&runtime);
            let auto_install = runtime
                .update_state
                .lock()
                .ok()
                .is_some_and(|state| matches!(*state, UpdateState::Installing { .. }));
            if auto_install {
                install_ready_update(app.handle());
            }

            let tray_menu = build_tray_menu(app.handle(), &load_menu_state(), &runtime)?;
            let menu_items = Arc::new(Mutex::new(tray_menu.items));
            let menu_command_items = Arc::clone(&menu_items);
            let tray_click_items = Arc::clone(&menu_items);

            TrayIconBuilder::with_id(TRAY_ID)
                .tooltip("Orbit Desktop")
                .icon(tray_icon_for_update(&runtime))
                .icon_as_template(true)
                .menu(&tray_menu.menu)
                .show_menu_on_left_click(true)
                .on_menu_event(move |app, event| match event.id().as_ref() {
                    REFRESH_MENU_ID => {
                        refresh_menu(app, &menu_command_items, RefreshSource::MenuCommand)
                    }
                    LAUNCH_AT_LOGIN_MENU_ID => toggle_launch_at_login(app),
                    UPDATE_MENU_ID => restart_to_update_if_ready(app),
                    RESTART_MENU_ID => restart_orbit(app),
                    QUIT_MENU_ID => quit_orbit(app),
                    RETRY_PROVIDER_MENU_ID => retry_local_runtime(app),
                    _ => {}
                })
                .on_tray_icon_event(move |tray, event| {
                    if should_refresh_for_tray_event(&event) {
                        refresh_menu(
                            tray.app_handle(),
                            &tray_click_items,
                            RefreshSource::TrayClick,
                        );
                    }
                })
                .build(app)?;

            show_dashboard_window(app.handle());

            Ok(())
        })
        .on_window_event(|window, event| {
            if let tauri::WindowEvent::CloseRequested { api, .. } = event {
                let _ = dashboard_close_action();
                api.prevent_close();
                let _ = window.hide();
            }
        })
        .build(tauri::generate_context!())
        .expect("failed to build Orbit Desktop")
        .run(|app, event| {
            if let RunEvent::ExitRequested { api, .. } = event {
                let runtime = app.state::<DesktopRuntime>();
                let quitting = runtime.quitting.lock().map(|flag| *flag).unwrap_or(false);

                if !quitting {
                    api.prevent_exit();
                }
            }
        });
}

#[tauri::command]
fn dashboard_config() -> DashboardConfig {
    match AgentConfig::load_default() {
        Ok(config) => {
            let gateway_host = gateway_host_from_config(&config);
            let connection = ping_gateway_connection(&config).label();

            DashboardConfig {
                base_url: Some(format!("{}/api", config.gateway_url.trim_end_matches('/'))),
                config_loaded: true,
                connection,
                gateway_host,
                gateway_name: Some(config.gateway_name),
                node_name: Some(config.node_name),
            }
        }
        Err(ConfigError::MissingConfig(path)) => DashboardConfig {
            base_url: None,
            config_loaded: false,
            connection: ConnectionStatus::MissingConfig(path).label(),
            gateway_host: "unknown".to_string(),
            gateway_name: None,
            node_name: None,
        },
        Err(error) => DashboardConfig {
            base_url: None,
            config_loaded: false,
            connection: ConnectionStatus::Disconnected(error.to_string()).label(),
            gateway_host: "unknown".to_string(),
            gateway_name: None,
            node_name: None,
        },
    }
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
struct DashboardConfig {
    base_url: Option<String>,
    config_loaded: bool,
    connection: String,
    gateway_host: String,
    gateway_name: Option<String>,
    node_name: Option<String>,
}

fn bootstrap_process_environment() {
    orbit_agent::install_launchd_safe_path();
}

fn start_owned_agent(app: &AppHandle, runtime: &DesktopRuntime) -> bool {
    let home = PathBuf::from(std::env::var("HOME").unwrap_or_else(|_| ".".to_string()));
    let endpoint = runtime
        .docker_host
        .lock()
        .ok()
        .and_then(|value| value.clone());
    if let Some(endpoint) = endpoint {
        let started = spawn_or_mark_missing(app, runtime, &home, endpoint);
        if !started {
            if let Ok(mut endpoint) = runtime.docker_host.lock() {
                *endpoint = None;
            }
            if let Ok(mut provider) = runtime.provider_state.lock() {
                *provider = ProviderRuntimeState::Degraded {
                    detail: "Agent failed to start".into(),
                };
            }
        }
        started
    } else {
        if let Ok(mut state) = runtime.agent_state.lock() {
            *state = AgentRunState::Stopped;
        }
        if let Ok(mut provider) = runtime.provider_state.lock() {
            if matches!(*provider, ProviderRuntimeState::Ready { .. }) {
                *provider = ProviderRuntimeState::Degraded {
                    detail: "endpoint unavailable".into(),
                };
            }
        }
        false
    }
}

fn request_provider_start(app: &AppHandle) {
    let runtime = app.state::<DesktopRuntime>();
    let Ok(mut attempt) = runtime.provider_attempt.lock() else {
        return;
    };
    let Ok(mut provider) = runtime.provider_state.lock() else {
        return;
    };
    if !try_begin_provider_attempt(&mut attempt, &mut provider) {
        return;
    }
    start_provider_in_background(app, &runtime);
}

fn start_provider_in_background(app: &AppHandle, _runtime: &DesktopRuntime) {
    let handle = app.clone();
    std::thread::spawn(move || {
        let runtime = handle.state::<DesktopRuntime>();
        let home = PathBuf::from(std::env::var("HOME").unwrap_or_else(|_| ".".into()));
        let colima =
            PathBuf::from(std::env::var_os("ORBIT_COLIMA_BIN").unwrap_or_else(|| "colima".into()));
        let docker =
            PathBuf::from(std::env::var_os("ORBIT_DOCKER_BIN").unwrap_or_else(|| "docker".into()));
        match inspect_legacy_agent(
            &home,
            observed_legacy_launchd(&home),
            observed_listener_owner(),
        ) {
            LegacyDecision::Migrate(state) => migrate_legacy_launchd(&state),
            LegacyDecision::Conflict { reason } => {
                *runtime.agent_state.lock().unwrap() = AgentRunState::Conflict;
                *runtime.conflict_reason.lock().unwrap() = Some(conflict_remediation(&reason));
                *runtime.provider_state.lock().unwrap() =
                    ProviderRuntimeState::OwnershipConflict { detail: reason };
                *runtime.provider_attempt.lock().unwrap() = false;
                return;
            }
            LegacyDecision::Absent => {}
        }
        match ensure_ready(&home, &colima, &docker) {
            Ok(ready) => {
                if let Ok(mut endpoint) = runtime.docker_host.lock() {
                    *endpoint = Some(ready.socket.clone());
                }
                if let Ok(mut provider) = runtime.provider_state.lock() {
                    *provider = ProviderRuntimeState::Ready {
                        endpoint: ready.socket.clone(),
                    };
                }
                if start_owned_agent(&handle, &runtime) {
                    start_provider_health_monitor(handle.clone(), ready.socket.clone());
                }
            }
            Err(error) => {
                let detail = error.to_string();
                let state = match error {
                    orbit_macos::colima::ColimaError::MissingExecutable(_) => {
                        ProviderRuntimeState::MissingPrerequisite { detail }
                    }
                    orbit_macos::colima::ColimaError::OwnershipConflict => {
                        ProviderRuntimeState::OwnershipConflict { detail }
                    }
                    orbit_macos::colima::ColimaError::UnsupportedVersion(_) => {
                        ProviderRuntimeState::Incompatible { detail }
                    }
                    _ => ProviderRuntimeState::Degraded { detail },
                };
                if let Ok(mut provider) = runtime.provider_state.lock() {
                    *provider = state;
                }
            }
        }
        if let Ok(mut attempt) = runtime.provider_attempt.lock() {
            *attempt = false;
        };
    });
}

fn retry_local_runtime(app: &AppHandle) {
    request_provider_start(app);
}

fn start_provider_health_monitor(app: AppHandle, endpoint: String) {
    thread::spawn(move || loop {
        thread::sleep(Duration::from_secs(2));
        let runtime = app.state::<DesktopRuntime>();
        let docker =
            PathBuf::from(std::env::var_os("ORBIT_DOCKER_BIN").unwrap_or_else(|| "docker".into()));
        if orbit_macos::colima::docker_info_command(docker, &endpoint)
            .output()
            .is_ok_and(|output| output.status.success())
        {
            continue;
        }
        let same = runtime.provider_state.lock().ok().is_some_and(|state| matches!(&*state, ProviderRuntimeState::Ready { endpoint: current } if current == &endpoint));
        if same {
            if provider_health_action(&runtime.provider_state.lock().unwrap(), &endpoint, false)
                != ProviderHealthAction::DegradeAndStop
            {
                return;
            }
            if let Ok(mut state) = runtime.provider_state.lock() {
                *state = ProviderRuntimeState::Degraded {
                    detail: "Docker endpoint unavailable".into(),
                };
            }
            if let Ok(mut stored) = runtime.docker_host.lock() {
                *stored = None;
            }
            stop_owned_child(&runtime);
        }
        return;
    });
}

fn spawn_or_mark_missing(
    app: &AppHandle,
    runtime: &DesktopRuntime,
    home: &Path,
    docker_host: String,
) -> bool {
    let override_bin = std::env::var_os("ORBIT_AGENT_BIN").map(PathBuf::from);
    let Some(binary) = resolve_agent_binary(home, override_bin.as_deref()) else {
        if let Ok(mut agent_state) = runtime.agent_state.lock() {
            *agent_state = AgentRunState::Stopped;
        }
        return false;
    };

    if let Ok(mut agent_state) = runtime.agent_state.lock() {
        *agent_state = AgentRunState::Starting;
    }

    let plan = agent_launch_plan(binary, docker_host);
    match spawn_supervised_agent(&plan) {
        Ok(child) => {
            if let Ok(mut slot) = runtime.child.lock() {
                *slot = Some(child);
            }
            if let Ok(mut agent_state) = runtime.agent_state.lock() {
                *agent_state = AgentRunState::Running;
            }
            watch_child_crashes(app.clone());
            true
        }
        Err(_) => {
            if let Ok(mut agent_state) = runtime.agent_state.lock() {
                *agent_state = AgentRunState::Stopped;
            }
            false
        }
    }
}

fn watch_child_crashes(app: AppHandle) {
    thread::spawn(move || loop {
        thread::sleep(Duration::from_millis(250));
        let runtime = app.state::<DesktopRuntime>();
        if runtime.quitting.lock().map(|flag| *flag).unwrap_or(false) {
            return;
        }

        let exited = runtime.child.lock().ok().and_then(|mut child| {
            child
                .as_mut()
                .and_then(|process| process.try_wait().ok().flatten())
        });

        if exited.is_none() {
            continue;
        }

        if let Ok(mut slot) = runtime.child.lock() {
            *slot = None;
        }

        let now = Instant::now();
        let action = {
            let mut history = runtime.crash_times.lock().expect("crash times");
            history.push(now);
            crash_action(
                &history,
                now,
                DEFAULT_MAX_CHILD_CRASH_RESTARTS,
                DEFAULT_CHILD_CRASH_WINDOW,
            )
        };

        match action {
            CrashAction::Restart => {
                let home = PathBuf::from(std::env::var("HOME").unwrap_or_else(|_| ".".to_string()));
                let endpoint = runtime
                    .docker_host
                    .lock()
                    .ok()
                    .and_then(|value| value.clone());
                let state = runtime
                    .provider_state
                    .lock()
                    .ok()
                    .map(|state| state.clone())
                    .unwrap_or(ProviderRuntimeState::Starting);
                if let Some(endpoint) = agent_restart_endpoint(endpoint.as_deref(), &state) {
                    spawn_or_mark_missing(&app, &runtime, &home, endpoint);
                } else if let Ok(mut state) = runtime.agent_state.lock() {
                    *state = AgentRunState::Stopped;
                }
            }
            CrashAction::StayStopped => {
                if let Ok(mut agent_state) = runtime.agent_state.lock() {
                    *agent_state = AgentRunState::Stopped;
                }
                return;
            }
        }
    });
}

fn observed_legacy_launchd(home: &Path) -> Option<LegacyLaunchdState> {
    let plist_path = legacy_launch_agent_plist(home);
    let program =
        fs_read_to_string(&plist_path).and_then(|contents| parse_plist_program(&contents));
    let uid = users_uid();
    let printed = launchctl_output(&["print", &format!("gui/{uid}/{LEGACY_LAUNCHD_LABEL}")]);
    let loaded = printed.is_some();
    let pid = printed.as_deref().and_then(parse_launchctl_pid);

    if !plist_path.exists() && !loaded {
        return None;
    }

    Some(LegacyLaunchdState {
        label: LEGACY_LAUNCHD_LABEL.to_string(),
        plist_path,
        program,
        loaded,
        pid,
    })
}

fn observed_listener_owner() -> Option<ListenerOwner> {
    let output = lsof_output(&["-nP", "-iTCP:9477", "-sTCP:LISTEN"])?;
    let pid = parse_lsof_listener_pid(&output)?;
    let executable = process_command(pid)?;

    Some(ListenerOwner { pid, executable })
}

fn migrate_legacy_launchd(state: &LegacyLaunchdState) {
    let uid = users_uid();
    let _ = std::process::Command::new("launchctl")
        .args(["bootout", &format!("gui/{uid}/{}", state.label)])
        .status();

    if state.plist_path.exists()
        && !orbit_macos::paths::is_protected_system_launcher(&state.plist_path.to_string_lossy())
    {
        let _ = std::fs::remove_file(&state.plist_path);
    }
}

fn enable_launch_at_login_after_setup(app: &AppHandle, runtime: &DesktopRuntime) {
    use tauri_plugin_autostart::ManagerExt;

    let manager = app.autolaunch();
    let enabled = manager.enable().is_ok()
        && std::env::current_exe()
            .ok()
            .and_then(|path| path.canonicalize().ok())
            .is_some_and(|executable| {
                let home = PathBuf::from(std::env::var("HOME").unwrap_or_else(|_| ".".to_string()));
                let plist = fs_read_to_string(&desktop_launch_agent_plist(&home));

                launch_at_login_registration_matches(plist.as_deref(), &executable)
            });

    if let Ok(mut state) = runtime.launch_at_login.lock() {
        *state = if enabled {
            LaunchAtLoginState::Enabled
        } else {
            LaunchAtLoginState::Error
        };
    };
}

fn launch_at_login_registration_matches(plist: Option<&str>, current_exe: &Path) -> bool {
    plist
        .and_then(parse_plist_program)
        .is_some_and(|registered| registered == current_exe)
}

fn toggle_launch_at_login(app: &AppHandle) {
    use tauri_plugin_autostart::ManagerExt;

    let runtime = app.state::<DesktopRuntime>();
    let manager = app.autolaunch();
    let currently_enabled = runtime
        .launch_at_login
        .lock()
        .map(|state| matches!(*state, LaunchAtLoginState::Enabled))
        .unwrap_or(false);

    let result = if currently_enabled {
        manager.disable().map(|_| LaunchAtLoginState::Disabled)
    } else {
        manager.enable().map(|_| LaunchAtLoginState::Enabled)
    };

    if let Ok(mut state) = runtime.launch_at_login.lock() {
        *state = result.unwrap_or(LaunchAtLoginState::Error);
    };
}

fn consume_pending_update_handoff(runtime: &DesktopRuntime) {
    let home = PathBuf::from(std::env::var("HOME").unwrap_or_else(|_| ".".to_string()));
    let path = pending_update_path(&home);
    let Ok(update) =
        read_pending_update(&path.to_string_lossy(), &ExpectedUpdateIdentity::default())
    else {
        return;
    };

    if let Ok(mut state) = runtime.update_state.lock() {
        *state = ready_state_for_handoff(&update);
    }
}

fn restart_to_update_if_ready(app: &AppHandle) {
    let runtime = app.state::<DesktopRuntime>();
    let ready = runtime.update_state.lock().ok().is_some_and(|state| {
        matches!(
            *state,
            UpdateState::RestartReady { .. } | UpdateState::Installing { .. }
        )
    });

    if ready {
        install_ready_update(app);
    }
}

fn install_ready_update(app: &AppHandle) {
    let runtime = app.state::<DesktopRuntime>();
    let version = runtime
        .update_state
        .lock()
        .ok()
        .and_then(|state| match &*state {
            UpdateState::RestartReady { version }
            | UpdateState::Installing { version }
            | UpdateState::Verified { version } => Some(version.clone()),
            _ => None,
        })
        .unwrap_or_default();

    if let Ok(mut state) = runtime.update_state.lock() {
        *state = transition(state.clone(), UpdateEvent::BeginInstall, &version);
    }

    stop_owned_child(&runtime);

    let home = PathBuf::from(std::env::var("HOME").unwrap_or_else(|_| ".".to_string()));
    let path = pending_update_path(&home);
    let attempt =
        match read_pending_update(&path.to_string_lossy(), &ExpectedUpdateIdentity::default()) {
            Ok(update) => match apply_verified_update(&update) {
                Ok(()) => InstallAttemptResult::Succeeded,
                Err(failure) => failure.attempt,
            },
            Err(_) => InstallAttemptResult::FailedBeforeReplacement,
        };

    let current = runtime
        .update_state
        .lock()
        .map(|state| state.clone())
        .unwrap_or(UpdateState::Idle);
    let disposition = commit_install_attempt(&path, current, attempt, &version);

    if let Ok(mut state) = runtime.update_state.lock() {
        *state = disposition.next_state;
    }

    if disposition.restart_app {
        app.restart();
    } else {
        let endpoint = runtime
            .docker_host
            .lock()
            .ok()
            .and_then(|value| value.clone());
        if let Some(endpoint) = endpoint {
            spawn_or_mark_missing(app, &runtime, &home, endpoint);
        }
    }
}

fn apply_verified_update(update: &PendingDesktopUpdate) -> Result<(), ApplyFailure> {
    use orbit_macos::paths::{owner_agent_bin, owner_cli_bin, owner_updates_dir};

    let home = PathBuf::from(std::env::var("HOME").unwrap_or_else(|_| ".".to_string()));
    let updates = owner_updates_dir(&home);
    let staged_agent = updates.join(format!("agent-{}", &update.agent.sha256[..12]));
    let staged_cli = updates.join(format!("cli-{}", &update.cli.sha256[..12]));

    apply_bound_update(
        update,
        &staged_agent,
        &staged_cli,
        &owner_agent_bin(&home),
        &owner_cli_bin(&home),
        desktop_app_destination().as_deref(),
        None,
    )
}

fn desktop_app_destination() -> Option<PathBuf> {
    if let Some(path) = std::env::var_os("ORBIT_DESKTOP_APP_DIR") {
        return Some(PathBuf::from(path));
    }

    let executable = std::env::current_exe().ok()?;
    let bundle = executable.parent()?.parent()?.parent()?.to_path_buf();

    if bundle.extension().and_then(|ext| ext.to_str()) == Some("app") {
        Some(bundle)
    } else {
        None
    }
}

fn restart_orbit(app: &AppHandle) {
    let runtime = app.state::<DesktopRuntime>();
    stop_owned_child(&runtime);
    app.restart();
}

fn quit_orbit(app: &AppHandle) {
    let runtime = app.state::<DesktopRuntime>();
    if let Ok(mut quitting) = runtime.quitting.lock() {
        *quitting = true;
    };
    stop_owned_child(&runtime);
    if let Ok(mut state) = runtime.provider_state.lock() {
        *state = ProviderRuntimeState::Stopping;
    }
    let home = PathBuf::from(std::env::var("HOME").unwrap_or_else(|_| ".".to_string()));
    let colima =
        PathBuf::from(std::env::var_os("ORBIT_COLIMA_BIN").unwrap_or_else(|| "colima".into()));
    match quit_disposition(stop_owned_profile(&home, &colima).map_err(|error| error.to_string())) {
        QuitDisposition::Exit => app.exit(0),
        QuitDisposition::RemainOpen(detail) => {
            if let Ok(mut quitting) = runtime.quitting.lock() {
                *quitting = false;
            }
            if let Ok(mut state) = runtime.provider_state.lock() {
                *state = ProviderRuntimeState::StopFailed { detail };
            }
        }
    }
}

fn stop_owned_child(runtime: &DesktopRuntime) {
    if let Ok(mut slot) = runtime.child.lock() {
        if let Some(child) = slot.as_mut() {
            let _ = stop_supervised_agent(child, Duration::from_secs(5));
        }
        *slot = None;
    }
    if let Ok(mut agent_state) = runtime.agent_state.lock() {
        if *agent_state != AgentRunState::Conflict {
            *agent_state = AgentRunState::Stopped;
        }
    }
}

fn tray_icon_for_update(runtime: &DesktopRuntime) -> Image<'static> {
    let ready = runtime
        .update_state
        .lock()
        .ok()
        .is_some_and(|state| state.shows_tray_dot());

    if ready {
        update_ready_tray_icon()
    } else {
        tray_icon()
    }
}

fn update_ready_tray_icon() -> Image<'static> {
    const SIZE: u32 = 18;
    let mut rgba = Vec::with_capacity((SIZE * SIZE * 4) as usize);
    let center = (SIZE as f32 - 1.0) / 2.0;

    for y in 0..SIZE {
        for x in 0..SIZE {
            let dx = x as f32 - center;
            let dy = y as f32 - center;
            let distance = (dx * dx + dy * dy).sqrt();
            let in_ring = distance <= 5.0 || (6.5..=8.0).contains(&distance);
            let in_dot = (x as i32 - 13).pow(2) + (y as i32 - 4).pow(2) <= 6;

            if in_dot || in_ring {
                rgba.extend_from_slice(&[0, 0, 0, 255]);
            } else {
                rgba.extend_from_slice(&[0, 0, 0, 0]);
            }
        }
    }

    Image::new_owned(rgba, SIZE, SIZE)
}

fn fs_read_to_string(path: &Path) -> Option<String> {
    std::fs::read_to_string(path).ok()
}

fn users_uid() -> u32 {
    std::process::Command::new("id")
        .arg("-u")
        .output()
        .ok()
        .and_then(|output| String::from_utf8(output.stdout).ok())
        .and_then(|text| text.trim().parse().ok())
        .unwrap_or(501)
}

fn launchctl_output(args: &[&str]) -> Option<String> {
    let output = std::process::Command::new("launchctl")
        .args(args)
        .output()
        .ok()?;
    if output.status.success() {
        Some(String::from_utf8_lossy(&output.stdout).into_owned())
    } else {
        None
    }
}

fn lsof_output(args: &[&str]) -> Option<String> {
    let output = std::process::Command::new("lsof")
        .args(args)
        .output()
        .ok()?;
    Some(String::from_utf8_lossy(&output.stdout).into_owned())
}

fn process_command(pid: u32) -> Option<PathBuf> {
    let output = std::process::Command::new("ps")
        .args(["-p", &pid.to_string(), "-o", "command="])
        .output()
        .ok()?;
    let command = String::from_utf8_lossy(&output.stdout).trim().to_string();
    command.split_whitespace().next().map(PathBuf::from)
}

fn desktop_menu_snapshot(
    runtime: &DesktopRuntime,
) -> (AgentRunState, LaunchAtLoginState, UpdateState) {
    let agent = runtime
        .agent_state
        .lock()
        .map(|state| *state)
        .unwrap_or(AgentRunState::Stopped);
    let launch_at_login = runtime
        .launch_at_login
        .lock()
        .map(|state| *state)
        .unwrap_or(LaunchAtLoginState::Disabled);
    let update = runtime
        .update_state
        .lock()
        .map(|state| state.clone())
        .unwrap_or(UpdateState::Idle);

    (agent, launch_at_login, update)
}

struct TrayMenu {
    menu: Menu<Wry>,
    items: TrayMenuItems,
}

struct TrayMenuItems {
    agent_status: MenuItem<Wry>,
    status: MenuItem<Wry>,
    node_ip: MenuItem<Wry>,
    gateway: MenuItem<Wry>,
    granted_nodes: Vec<MenuItem<Wry>>,
    launch_at_login: CheckMenuItem<Wry>,
    update: MenuItem<Wry>,
    provider: MenuItem<Wry>,
    retry_provider: MenuItem<Wry>,
}

impl TrayMenuItems {
    fn new(app: &AppHandle, state: &MenuState, runtime: &DesktopRuntime) -> tauri::Result<Self> {
        let mut granted_nodes = Vec::with_capacity(state.granted_nodes.len());
        let ip_row_layout = state.ip_row_layout();
        let (agent, launch_at_login, update) = desktop_menu_snapshot(runtime);

        for (index, node) in state.granted_nodes.iter().enumerate() {
            granted_nodes.push(disabled_menu_item(
                app,
                format!("granted_node_{index}"),
                granted_node_label(node, ip_row_layout),
            )?);
        }

        Ok(Self {
            agent_status: disabled_menu_item(app, AGENT_STATUS_MENU_ID, agent_status_label(agent))?,
            status: disabled_menu_item(app, STATUS_MENU_ID, state.status.label())?,
            node_ip: disabled_menu_item(
                app,
                NODE_IP_MENU_ID,
                aligned_ip_label("IP", state.node_ip(), ip_row_layout),
            )?,
            gateway: disabled_menu_item(
                app,
                GATEWAY_MENU_ID,
                aligned_ip_label("Gateway", &state.gateway_ip, ip_row_layout),
            )?,
            granted_nodes,
            launch_at_login: CheckMenuItemBuilder::with_id(
                LAUNCH_AT_LOGIN_MENU_ID,
                launch_at_login_label(launch_at_login),
            )
            .checked(launch_at_login_checked(launch_at_login))
            .enabled(!matches!(launch_at_login, LaunchAtLoginState::Error))
            .build(app)?,
            update: disabled_menu_item(
                app,
                UPDATE_MENU_ID,
                if update.label().is_empty() {
                    format!("Orbit {}", orbit_version())
                } else {
                    update.label()
                },
            )?,
            provider: disabled_menu_item(
                app,
                PROVIDER_MENU_ID,
                provider_label(
                    &runtime
                        .provider_state
                        .lock()
                        .map(|state| state.clone())
                        .unwrap_or(ProviderRuntimeState::Starting),
                ),
            )?,
            retry_provider: MenuItemBuilder::with_id(RETRY_PROVIDER_MENU_ID, "Retry Local Runtime")
                .enabled(!matches!(
                    runtime
                        .provider_state
                        .lock()
                        .map(|state| state.clone())
                        .unwrap_or(ProviderRuntimeState::Starting),
                    ProviderRuntimeState::Ready { .. }
                        | ProviderRuntimeState::Starting
                        | ProviderRuntimeState::Stopping
                ))
                .build(app)?,
        })
    }

    fn update(&self, state: &MenuState, runtime: &DesktopRuntime) {
        let ip_row_layout = state.ip_row_layout();
        let (agent, launch_at_login, update) = desktop_menu_snapshot(runtime);

        let _ = self.agent_status.set_text(agent_status_label(agent));
        let _ = self.status.set_text(state.status.label());
        let _ = self
            .node_ip
            .set_text(aligned_ip_label("IP", state.node_ip(), ip_row_layout));
        let _ = self.gateway.set_text(aligned_ip_label(
            "Gateway",
            &state.gateway_ip,
            ip_row_layout,
        ));
        let _ = self
            .launch_at_login
            .set_text(launch_at_login_label(launch_at_login));
        let _ = self
            .launch_at_login
            .set_checked(launch_at_login_checked(launch_at_login));
        let _ = self.update.set_text(if update.label().is_empty() {
            format!("Orbit {}", orbit_version())
        } else {
            update.label()
        });
        let provider = runtime
            .provider_state
            .lock()
            .ok()
            .map(|state| state.clone())
            .unwrap_or(ProviderRuntimeState::Starting);
        let _ = self.provider.set_text(provider_label(&provider));
        let retry_enabled = provider_retry_enabled(&provider);
        let _ = self.retry_provider.set_enabled(retry_enabled);

        for (item, node) in self.granted_nodes.iter().zip(state.granted_nodes.iter()) {
            let _ = item.set_text(granted_node_label(node, ip_row_layout));
        }
    }

    fn grant_row_count(&self) -> usize {
        self.granted_nodes.len()
    }
}

fn refresh_menu(
    app: &AppHandle,
    menu_items: &Arc<Mutex<TrayMenuItems>>,
    refresh_source: RefreshSource,
) {
    let menu_state = load_menu_state();
    let runtime = app.state::<DesktopRuntime>();
    let current_grant_rows = menu_items
        .lock()
        .map(|items| items.grant_row_count())
        .unwrap_or_default();

    if should_replace_menu_for_refresh(
        refresh_source,
        current_grant_rows,
        menu_state.granted_nodes.len(),
    ) {
        if let Some(tray) = app.tray_by_id(TRAY_ID) {
            if let Ok(tray_menu) = build_tray_menu(app, &menu_state, &runtime) {
                if tray.set_menu(Some(tray_menu.menu)).is_ok() {
                    if let Ok(mut items) = menu_items.lock() {
                        *items = tray_menu.items;
                    }
                }
            }
        }
    } else if let Ok(items) = menu_items.lock() {
        items.update(&menu_state, &runtime);
    }

    if let Some(tray) = app.tray_by_id(TRAY_ID) {
        let _ = tray.set_icon(Some(tray_icon_for_update(&runtime)));
    }
}

fn show_dashboard_window(app: &AppHandle) {
    if let Some(window) = app.get_webview_window("main") {
        let _ = window.set_position(dashboard_window_position(&window));
        let _ = window.show();
        let _ = window.set_focus();
    }
}

fn dashboard_window_position(window: &WebviewWindow<Wry>) -> PhysicalPosition<i32> {
    window
        .available_monitors()
        .ok()
        .and_then(|monitors| {
            monitors
                .into_iter()
                .map(|monitor| {
                    let work_area = *monitor.work_area();

                    (monitor_work_area_score(work_area), work_area)
                })
                .min_by_key(|(score, _)| *score)
                .map(|(_, work_area)| {
                    PhysicalPosition::new(
                        work_area.position.x.saturating_add(96),
                        work_area.position.y.saturating_add(96),
                    )
                })
        })
        .unwrap_or_else(|| PhysicalPosition::new(96, 96))
}

fn monitor_work_area_score(work_area: tauri::PhysicalRect<i32, u32>) -> (u8, u8, i64) {
    let width = i32::try_from(work_area.size.width).unwrap_or(i32::MAX);
    let height = i32::try_from(work_area.size.height).unwrap_or(i32::MAX);
    let contains_origin = work_area.position.x <= 0
        && work_area.position.y <= 0
        && work_area.position.x.saturating_add(width) > 0
        && work_area.position.y.saturating_add(height) > 0;
    let starts_on_positive_desktop = work_area.position.x >= 0 && work_area.position.y >= 0;
    let distance_from_origin =
        i64::from(work_area.position.x).abs() + i64::from(work_area.position.y).abs();

    (
        if contains_origin { 0 } else { 1 },
        if starts_on_positive_desktop { 0 } else { 1 },
        distance_from_origin,
    )
}

fn should_replace_menu_for_refresh(
    refresh_source: RefreshSource,
    current_grant_rows: usize,
    next_grant_rows: usize,
) -> bool {
    matches!(refresh_source, RefreshSource::MenuCommand) && current_grant_rows != next_grant_rows
}

fn build_tray_menu(
    app: &AppHandle,
    state: &MenuState,
    runtime: &DesktopRuntime,
) -> tauri::Result<TrayMenu> {
    let items = TrayMenuItems::new(app, state, runtime)?;
    let update_enabled = runtime
        .update_state
        .lock()
        .ok()
        .is_some_and(|state| matches!(*state, UpdateState::RestartReady { .. }));

    let mut menu = MenuBuilder::new(app)
        .item(&items.agent_status)
        .item(&items.provider)
        .item(&items.status)
        .item(&items.node_ip)
        .item(&items.gateway)
        .separator();

    for item in &items.granted_nodes {
        menu = menu.item(item);
    }

    let menu = menu
        .separator()
        .item(&items.launch_at_login)
        .item(&items.update)
        .separator()
        .text(REFRESH_MENU_ID, "Refresh")
        .item(&items.retry_provider)
        .text(RESTART_MENU_ID, restart_orbit_label())
        .text(QUIT_MENU_ID, quit_orbit_label())
        .build()?;

    let _ = items.update.set_enabled(update_enabled);

    Ok(TrayMenu { menu, items })
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
struct IpRowLayout {
    target_title_width_units: usize,
}

fn granted_node_label(node: &GrantedNodeMenuRow, layout: IpRowLayout) -> String {
    aligned_ip_label(&node.name, node.ip(), layout)
}

fn aligned_ip_label(label: &str, ip: &str, layout: IpRowLayout) -> String {
    let content_width_units = menu_title_width_units(label) + menu_title_width_units(ip);
    let padding_width_units = layout
        .target_title_width_units
        .saturating_sub(content_width_units)
        .max(IP_ROW_MIN_GAP_WIDTH_UNITS);

    format!("{label}{}{ip}", ip_row_padding(padding_width_units))
}

fn ip_row_padding(width_units: usize) -> String {
    let base_wide_count = width_units / IP_ROW_PAD_WIDE_WIDTH_UNITS;
    let mut best = (usize::MAX, usize::MAX, 0, 0, 0);
    let first_wide_count = base_wide_count.saturating_sub(1);
    let last_wide_count = base_wide_count.saturating_add(1);

    for wide_count in first_wide_count..=last_wide_count {
        for medium_count in 0..=10 {
            for narrow_count in 0..=10 {
                let candidate_width = wide_count * IP_ROW_PAD_WIDE_WIDTH_UNITS
                    + medium_count * IP_ROW_PAD_MEDIUM_WIDTH_UNITS
                    + narrow_count * IP_ROW_PAD_NARROW_WIDTH_UNITS;
                let error = candidate_width.abs_diff(width_units);
                let glyph_count = wide_count + medium_count + narrow_count;

                if (error, glyph_count) < (best.0, best.1) {
                    best = (error, glyph_count, wide_count, medium_count, narrow_count);
                }
            }
        }
    }

    let (_, _, wide_count, medium_count, narrow_count) = best;
    let mut padding = String::with_capacity(wide_count + medium_count + narrow_count);

    padding.extend(std::iter::repeat_n(IP_ROW_PAD_WIDE, wide_count));
    padding.extend(std::iter::repeat_n(IP_ROW_PAD_MEDIUM, medium_count));
    padding.extend(std::iter::repeat_n(IP_ROW_PAD_NARROW, narrow_count));

    padding
}

fn menu_title_width_units(text: &str) -> usize {
    text.chars().map(menu_title_char_width_units).sum()
}

fn menu_title_char_width_units(character: char) -> usize {
    match character {
        IP_ROW_PAD_WIDE => IP_ROW_PAD_WIDE_WIDTH_UNITS,
        IP_ROW_PAD_MEDIUM => IP_ROW_PAD_MEDIUM_WIDTH_UNITS,
        IP_ROW_PAD_NARROW => IP_ROW_PAD_NARROW_WIDTH_UNITS,
        'a' => 710,
        'b' => 791,
        'c' => 720,
        'd' => 791,
        'e' => 735,
        'f' => 463,
        'g' => 785,
        'h' => 757,
        'i' => 314,
        'j' => 313,
        'k' => 698,
        'l' => 321,
        'm' => 1_124,
        'n' => 751,
        'o' => 760,
        'p' => 786,
        'q' => 785,
        'r' => 488,
        's' => 673,
        't' => 465,
        'u' => 751,
        'v' => 697,
        'w' => 999,
        'x' => 674,
        'y' => 698,
        'z' => 693,
        'A' => 868,
        'B' => 847,
        'C' => 923,
        'D' => 937,
        'E' => 767,
        'F' => 736,
        'G' => 963,
        'H' => 957,
        'I' => 340,
        'J' => 692,
        'K' => 849,
        'L' => 731,
        'M' => 1_129,
        'N' => 957,
        'O' => 995,
        'P' => 818,
        'Q' => 995,
        'R' => 842,
        'S' => 821,
        'T' => 816,
        'U' => 951,
        'V' => 868,
        'W' => 1_251,
        'X' => 875,
        'Y' => 844,
        'Z' => 853,
        '0' => 811,
        '1' => 595,
        '2' => 777,
        '3' => 807,
        '4' => 829,
        '5' => 796,
        '6' => 820,
        '7' => 733,
        '8' => 823,
        '9' => 820,
        '.' => 378,
        '-' => 606,
        '_' => 751,
        ' ' => 358,
        ':' => 378,
        _ => 760,
    }
}

fn disabled_menu_item(
    app: &AppHandle,
    id: impl Into<tauri::menu::MenuId>,
    text: impl AsRef<str>,
) -> tauri::Result<MenuItem<Wry>> {
    MenuItemBuilder::with_id(id, text).enabled(false).build(app)
}

fn agent_service_base_url() -> String {
    std::env::var("ORBIT_AGENT_SERVICE_URL")
        .unwrap_or_else(|_| "http://127.0.0.1:9477".to_string())
        .trim_end_matches('/')
        .to_string()
}

fn load_menu_state() -> MenuState {
    if let Some(state) = load_menu_state_from_agent_service() {
        return state;
    }

    load_menu_state_from_gateway()
}

fn load_menu_state_from_agent_service() -> Option<MenuState> {
    let url = format!("{}/status", agent_service_base_url());
    let response = ureq::get(&url)
        .timeout(Duration::from_secs(2))
        .call()
        .ok()?;
    let body = response.into_string().ok()?;
    let snapshot: ServiceStatusSnapshot = serde_json::from_str(&body).ok()?;
    let mut state = menu_state_from_service_status_snapshot(snapshot);

    if !matches!(state.status, ConnectionStatus::Connected) {
        return Some(state);
    }

    let config = AgentConfig::load_default().ok()?;

    match fetch_topology_menu_data(&config) {
        Ok(topology) => {
            state.node_ip = topology.node_ip.or(state.node_ip);
            state.granted_nodes = topology.granted_nodes;
            Some(state)
        }
        Err(error) => Some(MenuState {
            gateway_ip: state.gateway_ip,
            status: ConnectionStatus::Disconnected(format!("{error:?}")),
            ..MenuState::default()
        }),
    }
}

fn menu_state_from_service_status_snapshot(snapshot: ServiceStatusSnapshot) -> MenuState {
    match snapshot {
        ServiceStatusSnapshot::Loaded {
            gateway_ip,
            node_ip,
            node_name: _,
            connection,
        } => MenuState {
            status: match connection {
                GatewayConnection::Connected => ConnectionStatus::Connected,
                GatewayConnection::Disconnected { reason } => {
                    ConnectionStatus::Disconnected(reason)
                }
            },
            node_ip,
            gateway_ip,
            granted_nodes: Vec::new(),
        },
        ServiceStatusSnapshot::MissingConfig { path } => MenuState {
            status: ConnectionStatus::MissingConfig(path),
            ..MenuState::default()
        },
        ServiceStatusSnapshot::InvalidConfig { reason } => MenuState {
            status: ConnectionStatus::Disconnected(reason),
            ..MenuState::default()
        },
    }
}

fn load_menu_state_from_gateway() -> MenuState {
    match AgentConfig::load_default() {
        Ok(config) => {
            let gateway_ip = gateway_host_from_config(&config);
            let status = ping_gateway_connection(&config);

            if !matches!(status, ConnectionStatus::Connected) {
                return MenuState {
                    gateway_ip,
                    status,
                    ..MenuState::default()
                };
            }

            match fetch_topology_menu_data(&config) {
                Ok(topology) => MenuState {
                    node_ip: topology.node_ip,
                    gateway_ip,
                    granted_nodes: topology.granted_nodes,
                    status,
                },
                Err(error) => MenuState {
                    gateway_ip,
                    status: ConnectionStatus::Disconnected(format!("{error:?}")),
                    ..MenuState::default()
                },
            }
        }
        Err(ConfigError::MissingConfig(path)) => MenuState {
            status: ConnectionStatus::MissingConfig(path),
            ..MenuState::default()
        },
        Err(error) => MenuState {
            status: ConnectionStatus::Disconnected(error.to_string()),
            ..MenuState::default()
        },
    }
}

fn fetch_topology_menu_data(
    config: &AgentConfig,
) -> Result<TopologyMenuData, orbit_agent::GatewayError> {
    let client = GatewayClient::new(config.clone());
    let node: NodeShowEnvelope =
        get_gateway_json(&client, &format!("/api/nodes/{}", config.node_name))?;
    let nodes: NodeListEnvelope = get_gateway_json(&client, "/api/nodes")?;

    Ok(TopologyMenuData {
        node_ip: node.success.data.node.addresses.wireguard.clone(),
        granted_nodes: granted_node_rows(&node.success.data.node, &nodes.success.data.nodes),
    })
}

fn get_gateway_json<T: DeserializeOwned>(
    client: &GatewayClient,
    path: &str,
) -> Result<T, orbit_agent::GatewayError> {
    let url = client.absolute_url(path)?;
    let response = ureq::get(&url)
        .timeout(Duration::from_secs(5))
        .call()
        .map_err(|error| orbit_agent::GatewayError::Transport(error.to_string()))?;
    let body = response
        .into_string()
        .map_err(|error| orbit_agent::GatewayError::Transport(error.to_string()))?;

    serde_json::from_str(&body)
        .map_err(|error| orbit_agent::GatewayError::InvalidResponse(error.to_string()))
}

fn should_refresh_for_tray_event(event: &TrayIconEvent) -> bool {
    matches!(
        event,
        TrayIconEvent::Click {
            button: MouseButton::Left,
            ..
        }
    )
}

fn tray_icon() -> Image<'static> {
    Image::from_bytes(include_bytes!("../icons/tray-icon.png"))
        .unwrap_or_else(|_| fallback_tray_icon())
}

fn fallback_tray_icon() -> Image<'static> {
    const SIZE: u32 = 18;
    const INNER_RADIUS: f32 = 5.0;
    const OUTER_RADIUS: f32 = 8.0;

    let mut rgba = Vec::with_capacity((SIZE * SIZE * 4) as usize);
    let center = (SIZE as f32 - 1.0) / 2.0;

    for y in 0..SIZE {
        for x in 0..SIZE {
            let dx = x as f32 - center;
            let dy = y as f32 - center;
            let distance = (dx * dx + dy * dy).sqrt();
            let alpha = if distance <= INNER_RADIUS || (6.5..=OUTER_RADIUS).contains(&distance) {
                255
            } else {
                0
            };

            rgba.extend_from_slice(&[0, 0, 0, alpha]);
        }
    }

    Image::new_owned(rgba, SIZE, SIZE)
}

#[derive(Debug, Clone, PartialEq, Eq)]
struct MenuState {
    status: ConnectionStatus,
    node_ip: Option<String>,
    gateway_ip: String,
    granted_nodes: Vec<GrantedNodeMenuRow>,
}

impl MenuState {
    fn node_ip(&self) -> &str {
        self.node_ip.as_deref().unwrap_or("unknown")
    }

    fn ip_row_layout(&self) -> IpRowLayout {
        let widest_content_width_units = self
            .granted_nodes
            .iter()
            .map(|node| menu_title_width_units(&node.name) + menu_title_width_units(node.ip()))
            .chain([
                menu_title_width_units("IP") + menu_title_width_units(self.node_ip()),
                menu_title_width_units("Gateway") + menu_title_width_units(&self.gateway_ip),
            ])
            .max()
            .unwrap_or_default();

        IpRowLayout {
            target_title_width_units: widest_content_width_units + IP_ROW_MIN_GAP_WIDTH_UNITS,
        }
    }
}

impl Default for MenuState {
    fn default() -> Self {
        Self {
            status: ConnectionStatus::Disconnected("not configured".to_string()),
            node_ip: None,
            gateway_ip: "unknown".to_string(),
            granted_nodes: Vec::new(),
        }
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
struct TopologyMenuData {
    node_ip: Option<String>,
    granted_nodes: Vec<GrantedNodeMenuRow>,
}

#[derive(Debug, Clone, PartialEq, Eq)]
struct GrantedNodeMenuRow {
    name: String,
    ip: Option<String>,
}

impl GrantedNodeMenuRow {
    fn ip(&self) -> &str {
        self.ip.as_deref().unwrap_or("unknown")
    }
}

#[derive(Debug, Deserialize)]
struct NodeShowEnvelope {
    success: NodeShowSuccess,
}

#[derive(Debug, Deserialize)]
struct NodeShowSuccess {
    data: NodeShowData,
}

#[derive(Debug, Deserialize)]
struct NodeShowData {
    node: NodeMenuPayload,
}

#[derive(Debug, Deserialize)]
struct NodeMenuPayload {
    addresses: NodeAddresses,
    #[serde(default)]
    grants: NodeGrants,
}

#[derive(Debug, Default, Deserialize)]
struct NodeAddresses {
    wireguard: Option<String>,
}

#[derive(Debug, Default, Deserialize)]
struct NodeGrants {
    #[serde(default)]
    consuming_nodes: Vec<NodeGrantPayload>,
    #[serde(default)]
    serving_nodes: Vec<NodeGrantPayload>,
}

#[derive(Debug, Deserialize)]
struct NodeGrantPayload {
    name: String,
}

#[derive(Debug, Deserialize)]
struct NodeListEnvelope {
    success: NodeListSuccess,
}

#[derive(Debug, Deserialize)]
struct NodeListSuccess {
    data: NodeListData,
}

#[derive(Debug, Deserialize)]
struct NodeListData {
    nodes: Vec<NodeListPayload>,
}

#[derive(Debug, Deserialize)]
struct NodeListPayload {
    name: String,
    addresses: NodeAddresses,
}

fn granted_node_rows(node: &NodeMenuPayload, nodes: &[NodeListPayload]) -> Vec<GrantedNodeMenuRow> {
    let node_ips = nodes
        .iter()
        .map(|node| (node.name.as_str(), node.addresses.wireguard.as_deref()))
        .collect::<HashMap<_, _>>();
    let mut seen = HashSet::new();
    let mut granted_nodes = Vec::new();

    for grant in node
        .grants
        .consuming_nodes
        .iter()
        .chain(node.grants.serving_nodes.iter())
    {
        if !seen.insert(grant.name.to_lowercase()) {
            continue;
        }

        granted_nodes.push(GrantedNodeMenuRow {
            name: grant.name.clone(),
            ip: node_ips
                .get(grant.name.as_str())
                .copied()
                .flatten()
                .map(ToString::to_string),
        });
    }

    granted_nodes
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn loads_orbit_tray_icon_asset() {
        let icon = tray_icon();

        assert_eq!(icon.width(), 36);
        assert_eq!(icon.height(), 36);
    }

    #[test]
    fn granted_node_rows_only_include_nodes_with_grants() {
        let node = NodeMenuPayload {
            addresses: NodeAddresses {
                wireguard: Some("10.6.0.3".to_string()),
            },
            grants: NodeGrants {
                consuming_nodes: vec![
                    NodeGrantPayload {
                        name: "agent".to_string(),
                    },
                    NodeGrantPayload {
                        name: "NMBP".to_string(),
                    },
                ],
                serving_nodes: vec![
                    NodeGrantPayload {
                        name: "gateway".to_string(),
                    },
                    NodeGrantPayload {
                        name: "beast".to_string(),
                    },
                    NodeGrantPayload {
                        name: "NMBP".to_string(),
                    },
                ],
            },
        };
        let nodes = vec![
            node_list_payload("agent", "10.6.0.11"),
            node_list_payload("gateway", "10.6.0.2"),
            node_list_payload("beast", "10.6.0.7"),
            node_list_payload("mini", "10.6.0.8"),
            node_list_payload("NMBP", "10.6.0.3"),
        ];

        let rows = granted_node_rows(&node, &nodes);

        assert_eq!(
            rows,
            vec![
                granted_node_row("agent", "10.6.0.11"),
                granted_node_row("NMBP", "10.6.0.3"),
                granted_node_row("gateway", "10.6.0.2"),
                granted_node_row("beast", "10.6.0.7"),
            ]
        );
    }

    #[test]
    fn tray_click_refresh_does_not_replace_the_native_menu() {
        assert!(!should_replace_menu_for_refresh(
            RefreshSource::TrayClick,
            0,
            5
        ));
        assert!(!should_replace_menu_for_refresh(
            RefreshSource::MenuCommand,
            5,
            5
        ));
        assert!(should_replace_menu_for_refresh(
            RefreshSource::MenuCommand,
            4,
            5
        ));
    }

    #[test]
    fn agent_service_base_url_defaults_to_local_headless_endpoint() {
        std::env::remove_var("ORBIT_AGENT_SERVICE_URL");

        assert_eq!(agent_service_base_url(), "http://127.0.0.1:9477");
    }

    #[test]
    fn listener_security_embedded_agent_never_configures_a_wildcard_bind() {
        let source = include_str!("main.rs");
        let wildcard_bind = ["0.0.0.0", ":9477"].concat();

        assert!(!source.contains(&wildcard_bind));
    }

    #[test]
    fn dashboard_close_is_intercepted_and_quit_stops_the_child() {
        let source = include_str!("main.rs");

        assert!(source.contains("CloseRequested"));
        assert!(source.contains("prevent_close"));
        assert!(source.contains("window.hide()"));
        assert!(source.contains("stop_owned_child"));
        assert!(source.contains("quit_orbit_label"));
        assert!(source.contains("MacosLauncher::LaunchAgent"));
        assert!(!source.contains("Command::new(\"pgrep\")"));
    }

    #[test]
    fn restart_paths_do_not_call_provider_stop_but_quit_does() {
        let source = include_str!("main.rs");
        fn body<'a>(source: &'a str, name: &str) -> &'a str {
            let start = source.find(&format!("fn {name}")).unwrap();
            let end = source[start..].find("\n}\n").unwrap() + start;
            &source[start..end]
        }
        assert!(!body(source, "restart_orbit").contains("stop_owned_profile"));
        assert!(!body(source, "install_ready_update").contains("stop_owned_profile"));
        assert!(body(source, "quit_orbit").contains("stop_owned_profile"));
    }

    #[test]
    fn launch_at_login_requires_the_current_executable_path() {
        let current = Path::new("/Applications/Orbit Desktop.app/Contents/MacOS/orbit-macos");
        let current_plist = r#"
            <key>ProgramArguments</key>
            <array>
                <string>/Applications/Orbit Desktop.app/Contents/MacOS/orbit-macos</string>
            </array>
        "#;
        let stale_plist = r#"
            <key>ProgramArguments</key>
            <array>
                <string>/tmp/deleted-worktree/orbit-macos</string>
            </array>
        "#;

        assert!(launch_at_login_registration_matches(
            Some(current_plist),
            current
        ));
        assert!(!launch_at_login_registration_matches(
            Some(stale_plist),
            current
        ));
        assert!(!launch_at_login_registration_matches(None, current));
    }

    #[test]
    fn menu_state_from_tagged_connected_snapshot_renders_connected_label() {
        let state = menu_state_from_tagged_json(
            r#"{
                "kind": "loaded",
                "gateway_ip": "gateway.test",
                "node_ip": "10.6.0.3",
                "node_name": "NMBP",
                "connection": { "state": "connected" }
            }"#,
        );

        assert_eq!(state.status, ConnectionStatus::Connected);
        assert_eq!(state.status.label(), "Connected");
        assert_eq!(state.gateway_ip, "gateway.test");
        assert_eq!(state.node_ip.as_deref(), Some("10.6.0.3"));
        assert!(!state.status.label().contains("Disconnected: Disconnected:"));
    }

    #[test]
    fn menu_state_from_tagged_disconnected_snapshot_renders_single_label() {
        let state = menu_state_from_tagged_json(
            r#"{
                "kind": "loaded",
                "gateway_ip": "gateway.test",
                "node_ip": "10.6.0.3",
                "node_name": "NMBP",
                "connection": {
                    "state": "disconnected",
                    "reason": "gateway returned HTTP 503"
                }
            }"#,
        );

        assert_eq!(
            state.status,
            ConnectionStatus::Disconnected("gateway returned HTTP 503".to_string())
        );
        assert_eq!(
            state.status.label(),
            "Disconnected: gateway returned HTTP 503"
        );
        assert!(!state.status.label().contains("Disconnected: Disconnected:"));
        assert_eq!(state.gateway_ip, "gateway.test");
        assert_eq!(state.node_ip.as_deref(), Some("10.6.0.3"));
    }

    #[test]
    fn menu_state_from_tagged_missing_config_snapshot_renders_single_label() {
        let state = menu_state_from_tagged_json(
            r#"{
                "kind": "missing_config",
                "path": "/tmp/orbit-agent.toml"
            }"#,
        );

        assert_eq!(
            state.status,
            ConnectionStatus::MissingConfig(std::path::PathBuf::from("/tmp/orbit-agent.toml"))
        );
        assert_eq!(
            state.status.label(),
            "Disconnected: missing config at /tmp/orbit-agent.toml"
        );
        assert!(!state.status.label().contains("Disconnected: Disconnected:"));
        assert_eq!(state.gateway_ip, "unknown");
        assert_eq!(state.node_ip, None);
    }

    #[test]
    fn menu_state_from_tagged_invalid_config_snapshot_renders_single_label() {
        let state = menu_state_from_tagged_json(
            r#"{
                "kind": "invalid_config",
                "reason": "managed must be true for the Orbit Agent command listener"
            }"#,
        );

        assert_eq!(
            state.status,
            ConnectionStatus::Disconnected(
                "managed must be true for the Orbit Agent command listener".to_string()
            )
        );
        assert_eq!(
            state.status.label(),
            "Disconnected: managed must be true for the Orbit Agent command listener"
        );
        assert!(!state.status.label().contains("Disconnected: Disconnected:"));
        assert_eq!(state.gateway_ip, "unknown");
        assert_eq!(state.node_ip, None);
    }

    fn menu_state_from_tagged_json(json: &str) -> MenuState {
        let snapshot: ServiceStatusSnapshot =
            serde_json::from_str(json).expect("tagged service status should deserialize");

        menu_state_from_service_status_snapshot(snapshot)
    }

    fn node_list_payload(name: &str, ip: &str) -> NodeListPayload {
        NodeListPayload {
            name: name.to_string(),
            addresses: NodeAddresses {
                wireguard: Some(ip.to_string()),
            },
        }
    }

    fn granted_node_row(name: &str, ip: &str) -> GrantedNodeMenuRow {
        GrantedNodeMenuRow {
            name: name.to_string(),
            ip: Some(ip.to_string()),
        }
    }
}
