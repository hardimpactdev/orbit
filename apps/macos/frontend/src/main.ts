import './styles.css';
import { createOrbitGatewayClient } from '@orbit/sdk-typescript';
import { invoke } from '@tauri-apps/api/core';

type DashboardConfig = {
    baseUrl: string | null;
    configLoaded: boolean;
    connection: string;
    gatewayHost: string;
    gatewayName: string | null;
    nodeName: string | null;
};

type DashboardState =
    | { status: 'loading' }
    | { status: 'connected'; config: DashboardConfig; summary: DashboardSummary }
    | { status: 'empty'; config: DashboardConfig; summary: DashboardSummary }
    | { status: 'error'; config: DashboardConfig | null; message: string };

type DashboardSummary = {
    nodes: NodeSummary[];
    apps: AppSummary[];
    processes: ProcessSummary[];
    tools: ToolSummary[];
    loadedAt: Date;
};

type NodeSummary = {
    name: string;
    role: string;
    environment: string;
    status: string;
    address: string;
};

type AppSummary = {
    name: string;
    node: string;
    environment: string;
    status: string;
};

type ProcessSummary = {
    name: string;
    node: string;
    app: string;
    runtime: string;
    status: string;
};

type ToolSummary = {
    name: string;
    node: string;
    status: string;
    version: string;
};

const app = document.querySelector<HTMLElement>('#app');

if (app === null) {
    throw new Error('Dashboard root element is missing.');
}

const dashboardRoot = app;

render({ status: 'loading' });
void loadDashboard();

async function loadDashboard(): Promise<void> {
    let config: DashboardConfig | null = null;

    try {
        config = await invoke<DashboardConfig>('dashboard_config');

        if (config.baseUrl === null) {
            render({
                status: 'error',
                config,
                message: config.connection,
            });

            return;
        }

        const summary = await fetchDashboardSummary(config.baseUrl);

        render({
            status: summary.nodes.length === 0 ? 'empty' : 'connected',
            config,
            summary,
        });
    } catch (error) {
        render({
            status: 'error',
            config,
            message: errorMessage(error),
        });
    }
}

async function fetchDashboardSummary(baseUrl: string): Promise<DashboardSummary> {
    const client = createOrbitGatewayClient({ baseUrl });

    const [nodesResponse, appsResponse, processesResponse, toolsResponse] = await Promise.all([
        client.GET('/nodes'),
        client.GET('/apps'),
        client.GET('/processes'),
        client.GET('/tools'),
    ]);

    const error = nodesResponse.error ?? appsResponse.error ?? processesResponse.error ?? toolsResponse.error;

    if (error !== undefined) {
        throw new Error(gatewayErrorMessage(error));
    }

    return {
        nodes: normalizeNodes(nodesResponse.data),
        apps: normalizeApps(appsResponse.data),
        processes: normalizeProcesses(processesResponse.data),
        tools: normalizeTools(toolsResponse.data),
        loadedAt: new Date(),
    };
}

function normalizeNodes(payload: unknown): NodeSummary[] {
    return extractArray(payload, 'nodes').map(item => {
        const node = objectRecord(item);
        const addresses = objectRecord(node.addresses);

        return {
            name: stringValue(node.name, 'unknown'),
            role: stringValue(node.role ?? firstArrayValue(node.roles), 'unassigned'),
            environment: stringValue(node.environment, 'unknown'),
            status: stringValue(node.status ?? node.state, 'unknown'),
            address: stringValue(addresses.wireguard ?? node.wireguard_ip ?? node.ip, 'unknown'),
        };
    });
}

function normalizeApps(payload: unknown): AppSummary[] {
    return extractArray(payload, 'apps').map(item => {
        const appRecord = objectRecord(item);
        const nodeRecord = objectRecord(appRecord.node);

        return {
            name: stringValue(appRecord.name ?? appRecord.app, 'unknown'),
            node: stringValue(nodeRecord.name ?? appRecord.node_name ?? appRecord.node, 'unknown'),
            environment: stringValue(appRecord.environment, 'unknown'),
            status: stringValue(appRecord.status ?? appRecord.state, 'registered'),
        };
    });
}

function normalizeProcesses(payload: unknown): ProcessSummary[] {
    return extractArray(payload, 'processes').map(item => {
        const process = objectRecord(item);
        const node = objectRecord(process.node);
        const appRecord = objectRecord(process.app);

        return {
            name: stringValue(process.name, 'unknown'),
            node: stringValue(node.name ?? process.node_name ?? process.node, 'unknown'),
            app: stringValue(appRecord.name ?? process.app_name ?? process.app ?? process.owner, 'none'),
            runtime: stringValue(process.runtime, 'unknown'),
            status: stringValue(process.status ?? process.state, 'unknown'),
        };
    });
}

function normalizeTools(payload: unknown): ToolSummary[] {
    return extractArray(payload, 'tools').map(item => {
        const tool = objectRecord(item);
        const node = objectRecord(tool.node);

        return {
            name: stringValue(tool.name ?? tool.tool, 'unknown'),
            node: stringValue(node.name ?? tool.node_name ?? tool.node, 'unknown'),
            status: stringValue(tool.status ?? tool.state, 'unknown'),
            version: stringValue(tool.version ?? tool.installed_version, 'unknown'),
        };
    });
}

function extractArray(payload: unknown, key: string): unknown[] {
    const root = objectRecord(payload);
    const success = objectRecord(root.success);
    const data = objectRecord(success.data ?? root.data);
    const value = data[key] ?? root[key];

    if (Array.isArray(value)) {
        return value;
    }

    if (isRecord(value)) {
        return Object.values(value);
    }

    return [];
}

function render(state: DashboardState): void {
    if (state.status === 'loading') {
        dashboardRoot.innerHTML = loadingTemplate();

        return;
    }

    if (state.status === 'error') {
        dashboardRoot.innerHTML = shellTemplate({
            config: state.config,
            body: errorTemplate(state.message),
            stateLabel: 'Offline',
        });

        bindRefresh();

        return;
    }

    dashboardRoot.innerHTML = shellTemplate({
        config: state.config,
        body: state.status === 'empty' ? emptyTemplate() : dashboardTemplate(state.summary),
        stateLabel: state.status === 'empty' ? 'Connected, empty' : 'Connected',
    });

    bindRefresh();
}

function loadingTemplate(): string {
    return `
        <section class="boot-state">
            <div>
                <p class="eyebrow">Orbit Gateway</p>
                <h1>Loading dashboard</h1>
                <p>Reading local gateway config and public API status.</p>
            </div>
        </section>
    `;
}

function shellTemplate({ config, body, stateLabel }: {
    config: DashboardConfig | null;
    body: string;
    stateLabel: string;
}): string {
    return `
        <section class="workspace">
            <header class="topbar">
                <div>
                    <p class="eyebrow">Orbit Gateway</p>
                    <h1>Node Operations</h1>
                </div>
                <div class="connection ${stateLabel.startsWith('Connected') ? 'is-connected' : 'is-offline'}">
                    <span>${escapeHtml(stateLabel)}</span>
                    <strong>${escapeHtml(config?.gatewayHost ?? 'unknown gateway')}</strong>
                </div>
            </header>
            <dl class="context-row">
                <div>
                    <dt>Gateway</dt>
                    <dd>${escapeHtml(config?.gatewayName ?? 'unknown')}</dd>
                </div>
                <div>
                    <dt>Local node</dt>
                    <dd>${escapeHtml(config?.nodeName ?? 'not configured')}</dd>
                </div>
                <div>
                    <dt>Connection</dt>
                    <dd>${escapeHtml(config?.connection ?? 'unavailable')}</dd>
                </div>
            </dl>
            ${body}
        </section>
    `;
}

function dashboardTemplate(summary: DashboardSummary): string {
    const groupedNodes = summary.nodes.map(node => nodePanel(node, summary)).join('');

    return `
        <section class="metric-strip" aria-label="Fleet summary">
            ${metricTemplate('Nodes', summary.nodes.length)}
            ${metricTemplate('Apps', summary.apps.length)}
            ${metricTemplate('Processes', summary.processes.length)}
            ${metricTemplate('Tools', summary.tools.length)}
            <div class="timestamp">Updated ${escapeHtml(summary.loadedAt.toLocaleTimeString())}</div>
        </section>
        <section class="node-grid" aria-label="Node runtime inventory">
            ${groupedNodes}
        </section>
    `;
}

function nodePanel(node: NodeSummary, summary: DashboardSummary): string {
    const apps = summary.apps.filter(appSummary => sameNode(appSummary.node, node.name));
    const processes = summary.processes.filter(process => sameNode(process.node, node.name));
    const tools = summary.tools.filter(tool => sameNode(tool.node, node.name));

    return `
        <article class="node-panel">
            <header>
                <div>
                    <h2>${escapeHtml(node.name)}</h2>
                    <p>${escapeHtml(node.role)} · ${escapeHtml(node.environment)}</p>
                </div>
                <span>${escapeHtml(node.status)}</span>
            </header>
            <dl class="node-meta">
                <div>
                    <dt>Address</dt>
                    <dd>${escapeHtml(node.address)}</dd>
                </div>
                <div>
                    <dt>Apps</dt>
                    <dd>${apps.length}</dd>
                </div>
                <div>
                    <dt>Processes</dt>
                    <dd>${processes.length}</dd>
                </div>
                <div>
                    <dt>Tools</dt>
                    <dd>${tools.length}</dd>
                </div>
            </dl>
            ${sectionList('Apps', apps.map(appSummary => `${appSummary.name} · ${appSummary.status}`))}
            ${sectionList('Processes', processes.map(process => `${process.name} · ${process.runtime} · ${process.status}`))}
            ${sectionList('Tools', tools.map(tool => `${tool.name} · ${tool.version} · ${tool.status}`))}
        </article>
    `;
}

function emptyTemplate(): string {
    return `
        <section class="empty-state">
            <h2>No nodes returned</h2>
            <p>The gateway is reachable, but the public dashboard API did not return node inventory.</p>
            <button type="button" data-refresh>Refresh</button>
        </section>
    `;
}

function errorTemplate(message: string): string {
    return `
        <section class="empty-state error-state">
            <h2>Gateway unavailable</h2>
            <p>${escapeHtml(message)}</p>
            <button type="button" data-refresh>Retry</button>
        </section>
    `;
}

function metricTemplate(label: string, value: number): string {
    return `
        <div class="metric">
            <span>${escapeHtml(label)}</span>
            <strong>${value}</strong>
        </div>
    `;
}

function sectionList(label: string, rows: string[]): string {
    const listItems = rows.length === 0
        ? '<li class="muted">None reported</li>'
        : rows.map(row => `<li>${escapeHtml(row)}</li>`).join('');

    return `
        <section class="runtime-list">
            <h3>${escapeHtml(label)}</h3>
            <ul>${listItems}</ul>
        </section>
    `;
}

function bindRefresh(): void {
    document.querySelectorAll<HTMLButtonElement>('[data-refresh]').forEach(button => {
        button.addEventListener('click', () => {
            render({ status: 'loading' });
            void loadDashboard();
        });
    });
}

function sameNode(value: string, nodeName: string): boolean {
    return value.toLowerCase() === nodeName.toLowerCase();
}

function firstArrayValue(value: unknown): unknown {
    return Array.isArray(value) ? value[0] : undefined;
}

function objectRecord(value: unknown): Record<string, unknown> {
    return isRecord(value) ? value : {};
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function stringValue(value: unknown, fallback: string): string {
    if (typeof value === 'string' && value.trim() !== '') {
        return value;
    }

    if (typeof value === 'number' || typeof value === 'boolean') {
        return value.toString();
    }

    return fallback;
}

function gatewayErrorMessage(error: unknown): string {
    const record = objectRecord(error);
    const nestedError = objectRecord(record.error);

    return stringValue(nestedError.message ?? record.message, 'Gateway request failed.');
}

function errorMessage(error: unknown): string {
    if (error instanceof Error) {
        return error.message;
    }

    return stringValue(error, 'Dashboard could not load.');
}

function escapeHtml(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
