use orbit_agent::{
    connection_status_from_ping, AgentConfig, ConfigError, ConnectionStatus, GatewayClient,
    HttpAgentGateway, PollingWorker,
};
use serde::de::DeserializeOwned;
use serde::Deserialize;
use std::collections::{HashMap, HashSet};
use std::time::Duration;
use tauri::image::Image;
use tauri::menu::{Menu, MenuBuilder, MenuItemBuilder};
use tauri::tray::{MouseButton, TrayIconBuilder, TrayIconEvent};
use tauri::{AppHandle, Manager, Wry};
use url::Url;

const TRAY_ID: &str = "orbit-agent-tray";
const STATUS_MENU_ID: &str = "connection_status";
const NODE_IP_MENU_ID: &str = "node_peer_ip";
const GATEWAY_MENU_ID: &str = "gateway_ip";
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
            let menu = build_tray_menu(app.handle(), &load_menu_state())?;

            TrayIconBuilder::with_id(TRAY_ID)
                .tooltip("Orbit Agent")
                .icon(tray_icon())
                .icon_as_template(true)
                .menu(&menu)
                .show_menu_on_left_click(true)
                .on_menu_event(move |app, event| match event.id().as_ref() {
                    REFRESH_MENU_ID => refresh_menu(app),
                    RESTART_MENU_ID => app.restart(),
                    QUIT_MENU_ID => app.exit(0),
                    _ => {}
                })
                .on_tray_icon_event(move |tray, event| {
                    if should_refresh_for_tray_event(&event) {
                        refresh_menu(tray.app_handle());
                    }
                })
                .build(app)?;

            start_polling_worker();

            Ok(())
        })
        .run(tauri::generate_context!())
        .expect("failed to run Orbit Agent");
}

fn refresh_menu(app: &AppHandle) {
    let menu_state = load_menu_state();

    if let Some(tray) = app.tray_by_id(TRAY_ID) {
        if let Ok(menu) = build_tray_menu(app, &menu_state) {
            let _ = tray.set_menu(Some(menu));
        }
    }

    if let Some(window) = app.get_webview_window("main") {
        let _ = window.hide();
    }
}

fn build_tray_menu(app: &AppHandle, state: &MenuState) -> tauri::Result<Menu<Wry>> {
    let status = disabled_menu_item(app, STATUS_MENU_ID, state.status.label())?;
    let node_ip = disabled_menu_item(app, NODE_IP_MENU_ID, format!("IP: {}", state.node_ip()))?;
    let gateway = disabled_menu_item(
        app,
        GATEWAY_MENU_ID,
        format!("Gateway: {}", state.gateway_ip),
    )?;

    let mut grant_items = Vec::with_capacity(state.granted_nodes.len());

    for (index, node) in state.granted_nodes.iter().enumerate() {
        grant_items.push(disabled_menu_item(
            app,
            format!("granted_node_{index}"),
            format!("{}: {}", node.name, node.ip()),
        )?);
    }

    let mut menu = MenuBuilder::new(app)
        .item(&status)
        .item(&node_ip)
        .item(&gateway)
        .separator();

    for item in &grant_items {
        menu = menu.item(item);
    }

    menu.separator()
        .text(REFRESH_MENU_ID, "Refresh")
        .text(RESTART_MENU_ID, "Restart")
        .text(QUIT_MENU_ID, "Quit")
        .build()
}

fn disabled_menu_item(
    app: &AppHandle,
    id: impl Into<tauri::menu::MenuId>,
    text: impl AsRef<str>,
) -> tauri::Result<tauri::menu::MenuItem<Wry>> {
    MenuItemBuilder::with_id(id, text).enabled(false).build(app)
}

fn load_menu_state() -> MenuState {
    match AgentConfig::load_default() {
        Ok(config) => {
            let gateway_ip = gateway_ip(&config);
            let status = ping_gateway(&config);

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
    let mut request = ureq::get(&url).timeout(Duration::from_secs(5));

    if let Some(token) = client.config().bearer_token.as_deref() {
        request = request.set("Authorization", &format!("Bearer {token}"));
    }

    let response = request
        .call()
        .map_err(|error| orbit_agent::GatewayError::Transport(error.to_string()))?;
    let body = response
        .into_string()
        .map_err(|error| orbit_agent::GatewayError::Transport(error.to_string()))?;

    serde_json::from_str(&body)
        .map_err(|error| orbit_agent::GatewayError::InvalidResponse(error.to_string()))
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

fn gateway_ip(config: &AgentConfig) -> String {
    Url::parse(&config.gateway_url)
        .ok()
        .and_then(|url| url.host_str().map(ToString::to_string))
        .unwrap_or_else(|| config.gateway_url.clone())
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
