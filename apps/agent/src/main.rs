use orbit_agent::{
    connection_status_from_ping, AgentConfig, ConfigError, ConnectionStatus, GatewayClient,
    HttpAgentGateway, PollingWorker,
};
use serde::de::DeserializeOwned;
use serde::Deserialize;
use std::collections::{HashMap, HashSet};
use std::sync::{Arc, Mutex};
use std::time::Duration;
use tauri::image::Image;
use tauri::menu::{Menu, MenuBuilder, MenuItem, MenuItemBuilder};
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
// Tauri's native menu rows expose one title string, so these approximate
// macOS menu-font widths keep IP suffixes aligned without a wide fixed column.
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
    if is_worker_mode(std::env::args()) {
        run_worker_loop(Duration::from_secs(15));

        return;
    }

    tauri::Builder::default()
        .setup(|app| {
            let tray_menu = build_tray_menu(app.handle(), &load_menu_state())?;
            let menu_items = Arc::new(Mutex::new(tray_menu.items));
            let menu_command_items = Arc::clone(&menu_items);
            let tray_click_items = Arc::clone(&menu_items);

            TrayIconBuilder::with_id(TRAY_ID)
                .tooltip("Orbit Agent")
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

            start_polling_worker();

            Ok(())
        })
        .run(tauri::generate_context!())
        .expect("failed to run Orbit Agent");
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

    if let Some(window) = app.get_webview_window("main") {
        let _ = window.hide();
    }
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

    #[test]
    fn tray_click_refresh_does_not_replace_the_native_menu() {
        assert!(!should_replace_menu_for_refresh(
            RefreshSource::TrayClick,
            0,
            5
        ));
        assert!(!should_replace_menu_for_refresh(
            RefreshSource::TrayClick,
            5,
            0
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
    fn ip_labels_use_compact_visual_padding_for_native_menu_alignment() {
        let state = MenuState {
            status: ConnectionStatus::Connected,
            node_ip: Some("10.6.0.8".to_string()),
            gateway_ip: "10.6.0.2".to_string(),
            granted_nodes: vec![
                granted_node_row("agent", "10.6.0.11"),
                granted_node_row("mini", "10.6.0.8"),
                granted_node_row("gateway", "10.6.0.2"),
                granted_node_row("beast", "10.6.0.7"),
                granted_node_row("ingress1", "10.6.0.10"),
            ],
        };
        let layout = state.ip_row_layout();

        let labels = vec![
            aligned_ip_label("IP", state.node_ip(), layout),
            aligned_ip_label("Gateway", &state.gateway_ip, layout),
            granted_node_label(&state.granted_nodes[0], layout),
            granted_node_label(&state.granted_nodes[1], layout),
            granted_node_label(&state.granted_nodes[2], layout),
            granted_node_label(&state.granted_nodes[3], layout),
            granted_node_label(&state.granted_nodes[4], layout),
        ];
        let visual_widths = labels
            .iter()
            .map(|label| menu_title_width_units(label))
            .collect::<Vec<_>>();
        let narrowest = visual_widths.iter().min().copied().unwrap();
        let widest = visual_widths.iter().max().copied().unwrap();

        assert!(labels.iter().all(|label| !label.contains(':')));
        assert!(labels[0].ends_with("10.6.0.8"));
        assert!(labels[1].ends_with("10.6.0.2"));
        assert!(labels[2].ends_with("10.6.0.11"));
        assert!(labels[6].ends_with("10.6.0.10"));

        for label in labels {
            assert!(!label.contains('\t'));
            assert!(!label.contains(' '));
            assert!(!label.contains('\u{2007}'));
        }

        assert!(widest - narrowest <= IP_ROW_PAD_NARROW_WIDTH_UNITS);
        assert!(layout.target_title_width_units < 13_000);
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
