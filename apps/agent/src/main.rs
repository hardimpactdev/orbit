use orbit_agent::{
    connection_status_from_ping, AgentConfig, ConfigError, ConnectionStatus, GatewayClient,
    HttpAgentGateway, PollingWorker,
};
use std::time::Duration;
use tauri::image::Image;
use tauri::menu::{MenuBuilder, MenuItem, MenuItemBuilder};
use tauri::tray::{MouseButton, TrayIconBuilder, TrayIconEvent};
use tauri::{AppHandle, Manager, Wry};
use url::Url;

const STATUS_MENU_ID: &str = "connection_status";
const NODE_MENU_ID: &str = "node_name";
const GATEWAY_MENU_ID: &str = "gateway_name";
const REFRESH_MENU_ID: &str = "refresh_connection";
const RESTART_MENU_ID: &str = "restart_agent";
const QUIT_MENU_ID: &str = "quit_agent";
const WORKER_FLAG: &str = "--worker";

fn main() {
    if is_worker_mode(std::env::args()) {
        run_worker_loop(Duration::from_secs(15));

        return;
    }

    tauri::Builder::default()
        .setup(|app| {
            let items = TrayMenuItems::new(app.handle())?;
            let menu = MenuBuilder::new(app)
                .item(&items.status)
                .item(&items.node)
                .item(&items.gateway)
                .separator()
                .text(REFRESH_MENU_ID, "Refresh")
                .text(RESTART_MENU_ID, "Restart")
                .text(QUIT_MENU_ID, "Quit")
                .build()?;

            refresh_menu(app.handle(), &items);

            let menu_items = items.clone();
            let tray_items = items.clone();

            TrayIconBuilder::new()
                .tooltip("Orbit Agent")
                .icon(tray_icon())
                .icon_as_template(true)
                .menu(&menu)
                .show_menu_on_left_click(true)
                .on_menu_event(move |app, event| match event.id().as_ref() {
                    REFRESH_MENU_ID => refresh_menu(app, &menu_items),
                    RESTART_MENU_ID => app.restart(),
                    QUIT_MENU_ID => app.exit(0),
                    _ => {}
                })
                .on_tray_icon_event(move |tray, event| {
                    if should_refresh_for_tray_event(&event) {
                        refresh_menu(tray.app_handle(), &tray_items);
                    }
                })
                .build(app)?;

            start_polling_worker();

            Ok(())
        })
        .run(tauri::generate_context!())
        .expect("failed to run Orbit Agent");
}

#[derive(Clone)]
struct TrayMenuItems {
    status: MenuItem<Wry>,
    node: MenuItem<Wry>,
    gateway: MenuItem<Wry>,
}

impl TrayMenuItems {
    fn new(app: &AppHandle) -> tauri::Result<Self> {
        Ok(Self {
            status: MenuItemBuilder::with_id(STATUS_MENU_ID, "Disconnected")
                .enabled(false)
                .build(app)?,
            node: MenuItemBuilder::with_id(NODE_MENU_ID, "Node: not configured")
                .enabled(false)
                .build(app)?,
            gateway: MenuItemBuilder::with_id(GATEWAY_MENU_ID, "Gateway: not configured")
                .enabled(false)
                .build(app)?,
        })
    }
}

fn refresh_menu(app: &AppHandle, items: &TrayMenuItems) {
    let menu_state = load_menu_state();

    let _ = items.status.set_text(menu_state.status.label());
    let _ = items.node.set_text(menu_state.node_label);
    let _ = items.gateway.set_text(menu_state.gateway_label);

    if let Some(window) = app.get_webview_window("main") {
        let _ = window.hide();
    }
}

fn load_menu_state() -> MenuState {
    match AgentConfig::load_default() {
        Ok(config) => {
            let status = ping_gateway(&config);

            MenuState {
                node_label: format!("Node: {}", config.node_name),
                gateway_label: gateway_label(&config),
                status,
            }
        }
        Err(ConfigError::MissingConfig(path)) => MenuState {
            node_label: "Node: not configured".to_string(),
            gateway_label: "Gateway: not configured".to_string(),
            status: ConnectionStatus::MissingConfig(path),
        },
        Err(error) => MenuState {
            node_label: "Node: config error".to_string(),
            gateway_label: "Gateway: config error".to_string(),
            status: ConnectionStatus::Disconnected(error.to_string()),
        },
    }
}

fn ping_gateway(config: &AgentConfig) -> ConnectionStatus {
    let client = GatewayClient::new(config.clone());
    let request = client.build_ping_request();

    let url = match client.absolute_url(&request.path) {
        Ok(url) => url,
        Err(error) => return ConnectionStatus::Disconnected(format!("{error:?}")),
    };

    let mut ping = ureq::get(&url);
    ping = ping.timeout(Duration::from_secs(5));

    if let Some(token) = request.bearer_token {
        ping = ping.set("Authorization", &format!("Bearer {token}"));
    }

    match ping.call() {
        Ok(response) => connection_status_from_ping(response.status()),
        Err(ureq::Error::Status(status, _)) => connection_status_from_ping(status),
        Err(error) => ConnectionStatus::Disconnected(error.to_string()),
    }
}

fn gateway_label(config: &AgentConfig) -> String {
    let host = Url::parse(&config.gateway_url)
        .ok()
        .and_then(|url| url.host_str().map(ToString::to_string))
        .unwrap_or_else(|| config.gateway_url.clone());

    format!("Gateway: {} ({host})", config.gateway_name)
}

fn start_polling_worker() {
    std::thread::spawn(|| run_worker_loop(Duration::from_secs(15)));
}

fn run_worker_loop(poll_interval: Duration) {
    loop {
        poll_once_from_default_config();
        std::thread::sleep(poll_interval);
    }
}

fn poll_once_from_default_config() {
    if let Ok(config) = AgentConfig::load_default() {
        let gateway = HttpAgentGateway::new(config);
        let mut worker = PollingWorker::new(gateway);

        if let Err(error) = worker.poll_once() {
            eprintln!("Orbit Agent poll failed: {error:?}");
        }
    }
}

fn is_worker_mode(args: impl IntoIterator<Item = String>) -> bool {
    args.into_iter().any(|argument| argument == WORKER_FLAG)
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

struct MenuState {
    node_label: String,
    gateway_label: String,
    status: ConnectionStatus,
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn detects_headless_worker_mode_flag() {
        assert!(is_worker_mode([
            "orbit-agent".to_string(),
            WORKER_FLAG.to_string(),
        ]));
        assert!(!is_worker_mode(["orbit-agent".to_string()]));
    }

    #[test]
    fn loads_orbit_tray_icon_asset() {
        let icon = tray_icon();

        assert_eq!(icon.width(), 18);
        assert_eq!(icon.height(), 9);
    }
}
