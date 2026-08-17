use orbit_agent::{
    gateway_host_from_config, ping_gateway_connection, AgentConfig, ConfigError, ConnectionStatus,
    GatewayClient, GatewayConnection, ServiceStatusSnapshot,
};
use serde::de::DeserializeOwned;
use serde::{Deserialize, Serialize};
use std::collections::{HashMap, HashSet};
use std::sync::{Arc, Mutex};
use std::thread;
use std::time::Duration;
use tauri::image::Image;
use tauri::menu::{Menu, MenuBuilder, MenuItem, MenuItemBuilder};
use tauri::tray::{MouseButton, TrayIconBuilder, TrayIconEvent};
use tauri::{AppHandle, Manager, PhysicalPosition, WebviewWindow, Wry};

const TRAY_ID: &str = "orbit-macos-tray";
const STATUS_MENU_ID: &str = "connection_status";
const NODE_IP_MENU_ID: &str = "node_peer_ip";
const GATEWAY_MENU_ID: &str = "gateway_ip";
const REFRESH_MENU_ID: &str = "refresh_connection";
const RESTART_MENU_ID: &str = "restart_app";
const QUIT_MENU_ID: &str = "quit_app";
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

fn main() {
    bootstrap_process_environment();

    tauri::Builder::default()
        .invoke_handler(tauri::generate_handler![dashboard_config])
        .setup(|app| {
            start_embedded_agent_service();

            let tray_menu = build_tray_menu(app.handle(), &load_menu_state())?;
            let menu_items = Arc::new(Mutex::new(tray_menu.items));
            let menu_command_items = Arc::clone(&menu_items);
            let tray_click_items = Arc::clone(&menu_items);

            TrayIconBuilder::with_id(TRAY_ID)
                .tooltip("Orbit macOS")
                .icon(tray_icon())
                .icon_as_template(true)
                .menu(&tray_menu.menu)
                .show_menu_on_left_click(true)
                .on_menu_event(move |app, event| match event.id().as_ref() {
                    REFRESH_MENU_ID => {
                        refresh_menu(app, &menu_command_items, RefreshSource::MenuCommand)
                    }
                    RESTART_MENU_ID => app.restart(),
                    QUIT_MENU_ID => app.exit(0),
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
        .run(tauri::generate_context!())
        .expect("failed to run Orbit macOS");
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

fn start_embedded_agent_service() {
    if load_menu_state_from_agent_service().is_some() {
        return;
    }

    thread::spawn(orbit_agent::run_agent_service_blocking);
}

struct TrayMenu {
    menu: Menu<Wry>,
    items: TrayMenuItems,
}

struct TrayMenuItems {
    status: MenuItem<Wry>,
    node_ip: MenuItem<Wry>,
    gateway: MenuItem<Wry>,
    granted_nodes: Vec<MenuItem<Wry>>,
}

impl TrayMenuItems {
    fn new(app: &AppHandle, state: &MenuState) -> tauri::Result<Self> {
        let mut granted_nodes = Vec::with_capacity(state.granted_nodes.len());
        let ip_row_layout = state.ip_row_layout();

        for (index, node) in state.granted_nodes.iter().enumerate() {
            granted_nodes.push(disabled_menu_item(
                app,
                format!("granted_node_{index}"),
                granted_node_label(node, ip_row_layout),
            )?);
        }

        Ok(Self {
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
        })
    }

    fn update(&self, state: &MenuState) {
        let ip_row_layout = state.ip_row_layout();

        let _ = self.status.set_text(state.status.label());
        let _ = self
            .node_ip
            .set_text(aligned_ip_label("IP", state.node_ip(), ip_row_layout));
        let _ = self.gateway.set_text(aligned_ip_label(
            "Gateway",
            &state.gateway_ip,
            ip_row_layout,
        ));

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
            if let Ok(tray_menu) = build_tray_menu(app, &menu_state) {
                if tray.set_menu(Some(tray_menu.menu)).is_ok() {
                    if let Ok(mut items) = menu_items.lock() {
                        *items = tray_menu.items;
                    }
                }
            }
        }
    } else if let Ok(items) = menu_items.lock() {
        items.update(&menu_state);
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

fn build_tray_menu(app: &AppHandle, state: &MenuState) -> tauri::Result<TrayMenu> {
    let items = TrayMenuItems::new(app, state)?;

    let mut menu = MenuBuilder::new(app)
        .item(&items.status)
        .item(&items.node_ip)
        .item(&items.gateway)
        .separator();

    for item in &items.granted_nodes {
        menu = menu.item(item);
    }

    let menu = menu
        .separator()
        .text(REFRESH_MENU_ID, "Refresh")
        .text(RESTART_MENU_ID, "Restart")
        .text(QUIT_MENU_ID, "Quit")
        .build()?;

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
