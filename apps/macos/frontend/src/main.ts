import './styles.css';
import { createOrbitGatewayClient } from '@orbit/sdk-typescript';
import { invoke } from '@tauri-apps/api/core';
import {
    createDashboardSummary,
    createEndpointStatus,
    type ApiEndpointKey,
    type DashboardSummary,
    type DatabaseSummary,
    type NodeRuntimeGroup,
} from './dashboard-data.js';

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
    | { status: 'partial'; config: DashboardConfig; summary: DashboardSummary }
    | { status: 'empty'; config: DashboardConfig; summary: DashboardSummary }
    | { status: 'error'; config: DashboardConfig | null; message: string };

type DashboardSummaryStatus = 'connected' | 'partial' | 'empty';
type NavigationView = 'dashboard' | 'nodes' | 'settings';
type NodeDetailTab = 'roles' | 'apps' | 'databases' | 'processes' | 'tools';

type GatewayRequestResult = {
    endpoint: ApiEndpointKey;
    payload: unknown;
    status: ReturnType<typeof createEndpointStatus>;
};

type GatewayResponse = {
    data?: unknown;
    error?: unknown;
    response?: Response;
};

const app = document.querySelector<HTMLElement>('#app');

if (app === null) {
    throw new Error('Dashboard root element is missing.');
}

const dashboardRoot = app;
const viewState: {
    detailTab: NodeDetailTab;
    selectedNodeName: string | null;
    view: NavigationView;
} = {
    detailTab: 'apps',
    selectedNodeName: null,
    view: 'dashboard',
};

let lastState: DashboardState = { status: 'loading' };

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
            status: dashboardStatus(summary),
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

    const result = await gatewayRequest('runtimeInventory', () => client.GET('/dashboard/runtime-inventory'));

    if (result.status.status === 'loaded') {
        return createDashboardSummary({
            nodes: result.payload,
            apps: result.payload,
            processes: result.payload,
            tools: result.payload,
            apiStatuses: [result.status],
        });
    }

    return fetchLegacyDashboardSummary(client, result.status);
}

async function fetchLegacyDashboardSummary(
    client: ReturnType<typeof createOrbitGatewayClient>,
    runtimeInventoryStatus: ReturnType<typeof createEndpointStatus>,
): Promise<DashboardSummary> {
    const [nodesResult, appsResult, processesResult, toolsResult] = await Promise.all([
        gatewayRequest('nodes', () => client.GET('/nodes')),
        gatewayRequest('apps', () => client.GET('/apps')),
        gatewayRequest('processes', () => client.GET('/processes')),
        gatewayRequest('tools', () => client.GET('/tools')),
    ]);
    const results = [
        runtimeInventoryStatus,
        nodesResult.status,
        appsResult.status,
        processesResult.status,
        toolsResult.status,
    ];
    const loadedResults = results.filter(status => status.status === 'loaded');

    if (loadedResults.length === 0) {
        throw new Error(results.map(status => status.message).join(' '));
    }

    return createDashboardSummary({
        nodes: nodesResult.payload,
        apps: appsResult.payload,
        processes: processesResult.payload,
        tools: toolsResult.payload,
        apiStatuses: results,
    });
}

async function gatewayRequest(
    endpoint: ApiEndpointKey,
    request: () => Promise<GatewayResponse>,
): Promise<GatewayRequestResult> {
    try {
        const response = await request();

        if (response.error !== undefined) {
            return {
                endpoint,
                payload: undefined,
                status: createEndpointStatus(endpoint, 'failed', gatewayErrorMessage(response.error, response.response)),
            };
        }

        return {
            endpoint,
            payload: response.data,
            status: createEndpointStatus(endpoint, 'loaded', 'Loaded'),
        };
    } catch (error) {
        return {
            endpoint,
            payload: undefined,
            status: createEndpointStatus(endpoint, 'failed', errorMessage(error)),
        };
    }
}

function render(state: DashboardState): void {
    lastState = state;

    if (state.status === 'loading') {
        dashboardRoot.innerHTML = shellTemplate({
            config: null,
            body: loadingTemplate(),
            stateLabel: 'Loading',
            subtitle: null,
            title: 'Dashboard',
        });
        bindShellActions();

        return;
    }

    if (state.status === 'error') {
        dashboardRoot.innerHTML = shellTemplate({
            config: state.config,
            body: errorTemplate(state.message),
            stateLabel: state.config?.baseUrl === null ? 'Offline' : 'Error',
            subtitle: null,
            title: 'Dashboard',
        });
        bindShellActions();

        return;
    }

    dashboardRoot.innerHTML = shellTemplate({
        config: state.config,
        body: contentTemplate(state.summary),
        stateLabel: stateLabel(state),
        subtitle: pageSubtitle(state.summary),
        title: pageTitle(state.summary),
    });
    bindShellActions();
}

function contentTemplate(summary: DashboardSummary): string {
    if (viewState.view === 'settings') {
        return settingsTemplate(summary);
    }

    if (viewState.view === 'nodes') {
        return nodesTemplate(summary);
    }

    if (summary.nodeGroups.length === 0) {
        return emptyTemplate();
    }

    return dashboardTemplate(summary);
}

function shellTemplate({ config, body, stateLabel, subtitle, title }: {
    config: DashboardConfig | null;
    body: string;
    stateLabel: string;
    subtitle: string | null;
    title: string;
}): string {
    return `
        <section class="app-shell">
            <nav class="rail" aria-label="Primary navigation">
                <div class="rail-top">
                    <div class="rail-logo" title="Orbit" aria-label="Orbit"><span aria-hidden="true"></span></div>
                    ${railButton('dashboard', 'dashboard', 'Dashboard')}
                    ${railButton('nodes', 'nodes', 'Nodes')}
                </div>
                <div class="rail-bottom">
                    ${railButton('settings', 'settings', 'Settings')}
                </div>
            </nav>
            <main class="workspace">
                <header class="topbar">
                    <div>
                        <p class="eyebrow">Orbit Gateway</p>
                        <h1>${escapeHtml(title)}</h1>
                        ${subtitle === null ? '' : `<p class="title-subtitle">${escapeHtml(subtitle)}</p>`}
                    </div>
                    <div class="topbar-actions">
                        <div class="connection ${connectionClass(stateLabel)}">
                            <span>${escapeHtml(stateLabel)}</span>
                            <strong>${escapeHtml(config?.gatewayHost ?? 'unknown gateway')}</strong>
                        </div>
                        <button type="button" data-refresh>Refresh</button>
                    </div>
                </header>
                ${body}
            </main>
        </section>
    `;
}

function railButton(view: NavigationView, icon: string, label: string): string {
    const isActive = viewState.view === view;

    return `
        <button
            type="button"
            class="rail-button ${isActive ? 'is-active' : ''}"
            data-nav="${view}"
            aria-label="${escapeHtml(label)}"
            title="${escapeHtml(label)}"
        >
            <span class="rail-icon icon-${escapeHtml(icon)}" aria-hidden="true"></span>
        </button>
    `;
}

function dashboardTemplate(summary: DashboardSummary): string {
    return `
        <section class="metric-strip is-compact" aria-label="Fleet summary">
            ${metricTemplate('Nodes', summary.totals.nodes)}
            ${metricTemplate('Apps', summary.totals.apps)}
            ${metricTemplate('Databases', summary.totals.databases)}
        </section>
        <section class="dashboard-lists">
            ${summaryList('Nodes', summary.nodeGroups.map(group => listRow({
                title: group.node.name,
                meta: group.node.roles.join(', '),
                value: `${group.apps.length} apps`,
                action: `data-node="${escapeAttribute(group.node.name)}"`,
            })))}
            ${summaryList('Apps', summary.apps.map(app => listRow({
                title: app.name,
                meta: app.node,
                value: app.status,
            })))}
            ${summaryList('Databases', summary.databases.map(database => listRow({
                title: database.name,
                meta: database.node,
                value: database.status,
            })))}
        </section>
    `;
}

function nodesTemplate(summary: DashboardSummary): string {
    const selectedGroup = selectedNodeGroup(summary);

    if (selectedGroup !== null) {
        return nodeDetailTemplate(selectedGroup);
    }

    const rows = summary.nodeGroups.map(group => `
        <tr data-node="${escapeAttribute(group.node.name)}">
            <td><button type="button" class="text-link" data-node="${escapeAttribute(group.node.name)}">${escapeHtml(group.node.name)}</button></td>
            <td>${escapeHtml(group.node.roles.join(', '))}</td>
            <td>${group.apps.length}</td>
            <td>${group.databases.length}</td>
            <td>${group.tools.length}</td>
            <td>${group.processes.length}</td>
            <td><span class="status-pill is-${group.statusTone}">${escapeHtml(group.node.status)}</span></td>
        </tr>
    `).join('');

    return `
        <section class="table-panel">
            <header>
                <h2>Nodes</h2>
                <p>${summary.totals.nodes} nodes across ${summary.totals.apps} apps and ${summary.totals.databases} databases.</p>
            </header>
            ${tableTemplate(['Node', 'Roles', 'Apps', 'Databases', 'Tools', 'Processes', 'Status'], rows)}
        </section>
    `;
}

function nodeDetailTemplate(group: NodeRuntimeGroup): string {
    return `
        <section class="node-detail">
            <div class="node-detail-actions">
                <button type="button" class="text-link" data-node-back>Back to nodes</button>
                <span class="status-pill is-${group.statusTone}">${escapeHtml(group.node.status)}</span>
            </div>
            <dl class="node-meta">
                <div>
                    <dt>Address</dt>
                    <dd>${escapeHtml(group.node.address)}</dd>
                </div>
                <div>
                    <dt>Apps</dt>
                    <dd>${group.apps.length}</dd>
                </div>
                <div>
                    <dt>Databases</dt>
                    <dd>${group.databases.length}</dd>
                </div>
                <div>
                    <dt>Processes</dt>
                    <dd>${group.processes.length}</dd>
                </div>
                <div>
                    <dt>Tools</dt>
                    <dd>${group.tools.length}</dd>
                </div>
            </dl>
            <section class="detail-grid">
                <nav class="detail-tabs" aria-label="Node inventory categories">
                    ${detailTabButton('roles', `Roles ${group.node.roles.length}`)}
                    ${detailTabButton('apps', `Apps ${group.apps.length}`)}
                    ${detailTabButton('databases', `Databases ${group.databases.length}`)}
                    ${detailTabButton('processes', `Processes ${group.processes.length}`)}
                    ${detailTabButton('tools', `Tools ${group.tools.length}`)}
                </nav>
                <div class="table-panel detail-table">
                    ${nodeDetailTable(group)}
                </div>
            </section>
        </section>
    `;
}

function detailTabButton(tab: NodeDetailTab, label: string): string {
    return `
        <button
            type="button"
            class="${viewState.detailTab === tab ? 'is-active' : ''}"
            data-node-tab="${tab}"
        >
            ${escapeHtml(label)}
        </button>
    `;
}

function nodeDetailTable(group: NodeRuntimeGroup): string {
    if (viewState.detailTab === 'roles') {
        return tableTemplate(
            ['Role', 'Node', 'Status'],
            group.node.roles.map(role => `<tr><td>${escapeHtml(role)}</td><td>${escapeHtml(group.node.name)}</td><td>${escapeHtml(group.node.status)}</td></tr>`).join(''),
        );
    }

    if (viewState.detailTab === 'databases') {
        return databaseTable(group.databases);
    }

    if (viewState.detailTab === 'processes') {
        return tableTemplate(
            ['Process', 'App', 'Runtime', 'Status'],
            group.processes.map(process => `
                <tr>
                    <td>${escapeHtml(process.name)}</td>
                    <td>${escapeHtml(process.app)}</td>
                    <td>${escapeHtml(process.runtime)}</td>
                    <td>${escapeHtml(process.status)}</td>
                </tr>
            `).join(''),
        );
    }

    if (viewState.detailTab === 'tools') {
        return tableTemplate(
            ['Tool', 'Version', 'Status'],
            group.tools.map(tool => `
                <tr>
                    <td>${escapeHtml(tool.name)}</td>
                    <td>${escapeHtml(tool.version)}</td>
                    <td>${escapeHtml(tool.status)}</td>
                </tr>
            `).join(''),
        );
    }

    return tableTemplate(
        ['App', 'Environment', 'Status'],
        group.apps.map(appSummary => `
            <tr>
                <td>${escapeHtml(appSummary.name)}</td>
                <td>${escapeHtml(appSummary.environment)}</td>
                <td>${escapeHtml(appSummary.status)}</td>
            </tr>
        `).join(''),
    );
}

function databaseTable(databases: DatabaseSummary[]): string {
    return tableTemplate(
        ['Database', 'Engine', 'Status'],
        databases.map(database => `
            <tr>
                <td>${escapeHtml(database.name)}</td>
                <td>${escapeHtml(database.engine)}</td>
                <td>${escapeHtml(database.status)}</td>
            </tr>
        `).join(''),
    );
}

function settingsTemplate(summary: DashboardSummary): string {
    const state = lastState;
    const config = state.status === 'loading' ? null : state.config;

    return `
        <section class="settings-grid">
            <section class="connection-card">
                <h2>Connection</h2>
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
                        <dd>${escapeHtml(config?.connection ?? 'checking local config')}</dd>
                    </div>
                    <div>
                        <dt>API base</dt>
                        <dd>${escapeHtml(config?.baseUrl ?? 'not configured')}</dd>
                    </div>
                </dl>
            </section>
            ${apiStatusTemplate(summary)}
        </section>
    `;
}

function loadingTemplate(): string {
    return `
        <section class="state-panel">
            <h2>Loading dashboard</h2>
            <p>Reading local gateway config and public API status.</p>
        </section>
    `;
}

function emptyTemplate(): string {
    return `
        <section class="state-panel">
            <h2>No nodes returned</h2>
            <p>The gateway is reachable, but the public dashboard API did not return node inventory yet.</p>
        </section>
    `;
}

function errorTemplate(message: string): string {
    return `
        <section class="state-panel error-state">
            <h2>Gateway unavailable</h2>
            <p>${escapeHtml(message)}</p>
        </section>
    `;
}

function apiStatusTemplate(summary: DashboardSummary): string {
    const rows = summary.apiStatuses.map(status => `
        <li class="api-status-row is-${status.status}">
            <span>${escapeHtml(status.label)}</span>
            <strong>${escapeHtml(status.status === 'loaded' ? 'Loaded' : 'Needs context')}</strong>
            <small>${escapeHtml(status.message)}</small>
        </li>
    `).join('');

    return `
        <section class="api-status-panel" aria-label="Gateway API status">
            <header>
                <h2>Gateway API</h2>
                <p>${escapeHtml(apiStatusSummary(summary))}</p>
            </header>
            <ul>${rows}</ul>
        </section>
    `;
}

function apiStatusSummary(summary: DashboardSummary): string {
    const loadedCount = summary.apiStatuses.filter(status => status.status === 'loaded').length;
    const failedCount = summary.apiStatuses.length - loadedCount;

    if (failedCount === 0) {
        return 'The public SDK runtime inventory endpoint returned dashboard data.';
    }

    if (loadedCount === 0) {
        return 'The gateway is reachable, but the runtime inventory endpoint rejected this dashboard context.';
    }

    return `${loadedCount} endpoint(s) loaded; ${failedCount} need a gateway context or permission update.`;
}

function metricTemplate(label: string, value: number): string {
    return `
        <div class="metric">
            <span>${escapeHtml(label)}</span>
            <strong>${value}</strong>
        </div>
    `;
}

function summaryList(label: string, rows: string[]): string {
    const listItems = rows.length === 0
        ? '<li class="muted">None reported</li>'
        : rows.join('');

    return `
        <section class="summary-list">
            <h2>${escapeHtml(label)}</h2>
            <ul>${listItems}</ul>
        </section>
    `;
}

function listRow({ title, meta, value, action = '' }: {
    action?: string;
    meta: string;
    title: string;
    value: string;
}): string {
    return `
        <li ${action}>
            <span>${escapeHtml(title)}</span>
            <small>${escapeHtml(meta)}</small>
            <strong>${escapeHtml(value)}</strong>
        </li>
    `;
}

function tableTemplate(headers: string[], rows: string): string {
    const headerCells = headers.map(header => `<th scope="col">${escapeHtml(header)}</th>`).join('');
    const tableRows = rows === ''
        ? `<tr><td colspan="${headers.length}" class="muted">None reported</td></tr>`
        : rows;

    return `
        <table>
            <thead><tr>${headerCells}</tr></thead>
            <tbody>${tableRows}</tbody>
        </table>
    `;
}

function bindShellActions(): void {
    document.querySelectorAll<HTMLButtonElement>('[data-refresh]').forEach(button => {
        button.addEventListener('click', () => {
            render({ status: 'loading' });
            void loadDashboard();
        });
    });

    document.querySelectorAll<HTMLButtonElement>('[data-nav]').forEach(button => {
        button.addEventListener('click', () => {
            const view = button.dataset.nav;

            if (view === 'dashboard' || view === 'nodes' || view === 'settings') {
                viewState.view = view;
                viewState.selectedNodeName = null;
                rerenderLastState();
            }
        });
    });

    document.querySelectorAll<HTMLElement>('[data-node]').forEach(element => {
        element.addEventListener('click', () => {
            const nodeName = element.dataset.node;

            if (nodeName !== undefined && nodeName !== '') {
                viewState.view = 'nodes';
                viewState.selectedNodeName = nodeName;
                viewState.detailTab = 'apps';
                rerenderLastState();
            }
        });
    });

    document.querySelectorAll<HTMLButtonElement>('[data-node-tab]').forEach(button => {
        button.addEventListener('click', () => {
            const tab = button.dataset.nodeTab;

            if (isNodeDetailTab(tab)) {
                viewState.detailTab = tab;
                rerenderLastState();
            }
        });
    });

    document.querySelectorAll<HTMLButtonElement>('[data-node-back]').forEach(button => {
        button.addEventListener('click', () => {
            viewState.selectedNodeName = null;
            rerenderLastState();
        });
    });
}

function rerenderLastState(): void {
    render(lastState);
}

function selectedNodeGroup(summary: DashboardSummary): NodeRuntimeGroup | null {
    if (viewState.selectedNodeName === null) {
        return null;
    }

    return summary.nodeGroups.find(group => group.node.name === viewState.selectedNodeName) ?? null;
}

function pageTitle(summary: DashboardSummary): string {
    if (viewState.view === 'settings') {
        return 'Settings';
    }

    if (viewState.view === 'nodes') {
        const selectedGroup = selectedNodeGroup(summary);

        return selectedGroup === null ? 'Nodes' : selectedGroup.node.name;
    }

    return 'Dashboard';
}

function pageSubtitle(summary: DashboardSummary): string | null {
    if (viewState.view !== 'nodes') {
        return null;
    }

    const selectedGroup = selectedNodeGroup(summary);

    if (selectedGroup === null) {
        return null;
    }

    return selectedGroup.node.roles.join(', ');
}

function isNodeDetailTab(value: string | undefined): value is NodeDetailTab {
    return value === 'roles'
        || value === 'apps'
        || value === 'databases'
        || value === 'processes'
        || value === 'tools';
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

function gatewayErrorMessage(error: unknown, response?: Response): string {
    const record = objectRecord(error);
    const nestedError = objectRecord(record.error);
    const statusMessage = response === undefined ? null : `Gateway request failed with HTTP ${response.status}.`;

    return stringValue(nestedError.message ?? record.message, statusMessage ?? 'Gateway request failed.');
}

function errorMessage(error: unknown): string {
    if (error instanceof Error) {
        return error.message;
    }

    return stringValue(error, 'Dashboard could not load.');
}

function connectionClass(stateLabel: string): string {
    if (stateLabel.startsWith('Connected')) {
        return 'is-connected';
    }

    if (stateLabel === 'Partial') {
        return 'is-partial';
    }

    if (stateLabel === 'Loading') {
        return 'is-loading';
    }

    return 'is-offline';
}

function dashboardStatus(summary: DashboardSummary): DashboardSummaryStatus {
    const hasFailure = summary.apiStatuses.some(status => status.status === 'failed');

    if (summary.nodeGroups.length === 0) {
        return 'empty';
    }

    return hasFailure ? 'partial' : 'connected';
}

function stateLabel(state: DashboardState): string {
    if (state.status === 'partial') {
        return 'Partial';
    }

    if (state.status === 'empty') {
        return 'Connected, empty';
    }

    return 'Connected';
}

function escapeAttribute(value: string): string {
    return escapeHtml(value);
}

function escapeHtml(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
