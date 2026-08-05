export type DashboardSummary = {
    nodes: NodeSummary[];
    apps: AppSummary[];
    instances: InstanceSummary[];
    databases: DatabaseSummary[];
    processes: ProcessSummary[];
    tools: ToolSummary[];
    nodeGroups: NodeRuntimeGroup[];
    apiStatuses: ApiEndpointStatus[];
    totals: DashboardTotals;
    loadedAt: Date;
};

export type ApiEndpointKey = 'runtimeInventory' | 'nodes' | 'apps' | 'processes' | 'tools';

export type ApiEndpointStatus = {
    endpoint: ApiEndpointKey;
    label: string;
    status: 'loaded' | 'failed';
    message: string;
};

export type DashboardTotals = {
    nodes: number;
    onlineNodes: number;
    offlineNodes: number;
    apps: number;
    instances: number;
    databases: number;
    processes: number;
    tools: number;
};

export type NodeRuntimeGroup = {
    node: NodeSummary;
    instances: InstanceSummary[];
    databases: DatabaseSummary[];
    processes: ProcessSummary[];
    tools: ToolSummary[];
    statusTone: StatusTone;
    hasRuntimeInventory: boolean;
};

export type StatusTone = 'healthy' | 'idle' | 'offline' | 'error';

export type NodeSummary = {
    name: string;
    roles: string[];
    environment: string;
    status: string;
    address: string;
};

export type AppSummary = {
    name: string;
    instances: InstanceSummary[];
    status: string;
};

export type InstanceSummary = {
    name: string;
    app: string;
    node: string;
    environment: string;
    status: string;
};

export type DatabaseSummary = {
    name: string;
    node: string;
    engine: string;
    status: string;
};

export type ProcessSummary = {
    name: string;
    node: string;
    app: string;
    runtime: string;
    status: string;
};

export type ToolSummary = {
    name: string;
    node: string;
    status: string;
    version: string;
};

export type GatewayPayloads = {
    nodes: unknown;
    apps: unknown;
    processes: unknown;
    tools: unknown;
    apiStatuses?: ApiEndpointStatus[];
};

const unknownNode: NodeSummary = {
    name: 'unassigned',
    roles: ['unassigned'],
    environment: 'unknown',
    status: 'unknown',
    address: 'unknown',
};

const databaseToolNames = new Set([
    'mariadb',
    'mysql',
    'mysql8',
    'postgres',
    'postgresql',
    'valkey',
]);

export function createDashboardSummary(payloads: GatewayPayloads, loadedAt = new Date()): DashboardSummary {
    const nodes = normalizeNodes(payloads.nodes);
    const apps = normalizeApps(payloads.apps);
    const instances = apps.flatMap(app => app.instances);
    const databases = normalizeDatabases(nodes, payloads.tools);
    const processes = normalizeProcesses(payloads.processes);
    const tools = normalizeTools(payloads.tools);
    const nodeGroups = groupRuntimeByNode(nodes, instances, databases, processes, tools);
    const apiStatuses = payloads.apiStatuses ?? defaultApiStatuses();

    return {
        nodes,
        apps,
        instances,
        databases,
        processes,
        tools,
        nodeGroups,
        apiStatuses,
        totals: {
            nodes: nodeGroups.length,
            onlineNodes: nodeGroups.filter(group => group.statusTone === 'healthy' || group.statusTone === 'idle').length,
            offlineNodes: nodeGroups.filter(group => group.statusTone === 'offline' || group.statusTone === 'error').length,
            apps: apps.length,
            instances: instances.length,
            databases: databases.length,
            processes: processes.length,
            tools: tools.length,
        },
        loadedAt,
    };
}

export function createEndpointStatus(
    endpoint: ApiEndpointKey,
    status: ApiEndpointStatus['status'],
    message: string,
): ApiEndpointStatus {
    return {
        endpoint,
        label: endpointLabel(endpoint),
        status,
        message,
    };
}

export function normalizeNodes(payload: unknown): NodeSummary[] {
    return extractArray(payload, 'nodes').map(item => {
        const node = objectRecord(item);
        const addresses = objectRecord(node.addresses);
        const roles = arrayValues(node.roles)
            .map(role => roleLabel(role))
            .filter(role => role !== '');

        return {
            name: stringValue(node.name, 'unknown'),
            roles: roles.length === 0 ? [stringValue(node.role, 'unassigned')] : roles,
            environment: stringValue(node.environment ?? node.platform, 'unknown'),
            status: stringValue(node.status ?? node.state, 'unknown'),
            address: stringValue(addresses.wireguard ?? node.wireguard_ip ?? node.ip, 'unknown'),
        };
    });
}

export function normalizeApps(payload: unknown): AppSummary[] {
    return extractArray(payload, 'apps').map(item => {
        const app = objectRecord(item);
        const appName = stringValue(app.name ?? app.app, 'unknown');
        const instances = arrayValues(app.instances).map(item => {
            const instance = objectRecord(item);
            const node = objectRecord(instance.node);

            return {
                name: stringValue(instance.name ?? instance.instance, 'unknown'),
                app: appName,
                node: stringValue(node.name ?? instance.node_name ?? instance.node, 'unknown'),
                environment: stringValue(instance.environment, 'unknown'),
                status: stringValue(instance.status ?? instance.state, 'registered'),
            };
        });

        return {
            name: appName,
            instances,
            status: stringValue(app.status ?? app.state, 'registered'),
        };
    });
}

export function normalizeDatabases(nodes: NodeSummary[], toolsPayload: unknown): DatabaseSummary[] {
    const databases = new Map<string, DatabaseSummary>();

    nodes
        .filter(node => node.roles.includes('database'))
        .forEach(node => {
            databases.set(`role:${normalizedNodeName(node.name)}`, {
                name: node.name,
                node: node.name,
                engine: 'database node',
                status: node.status,
            });
        });

    normalizeTools(toolsPayload)
        .filter(tool => isDatabaseTool(tool.name))
        .forEach(tool => {
            databases.set(`tool:${normalizedNodeName(tool.node)}:${normalizedNodeName(tool.name)}`, {
                name: tool.name,
                node: tool.node,
                engine: tool.name,
                status: tool.status,
            });
        });

    return [...databases.values()].sort((left, right) => (
        [left.node, left.name].join(':').localeCompare([right.node, right.name].join(':'))
    ));
}

export function normalizeProcesses(payload: unknown): ProcessSummary[] {
    return extractArray(payload, 'processes').map(item => {
        const process = objectRecord(item);
        const node = objectRecord(process.node);
        const app = objectRecord(process.app);

        return {
            name: stringValue(process.name, 'unknown'),
            node: stringValue(node.name ?? process.node_name ?? process.node, 'unknown'),
            app: stringValue(app.name ?? process.app_name ?? process.app ?? process.owner, 'none'),
            runtime: stringValue(process.runtime ?? process.runtime_backend, 'unknown'),
            status: stringValue(process.status ?? process.state, 'unknown'),
        };
    });
}

export function normalizeTools(payload: unknown): ToolSummary[] {
    return extractArray(payload, 'tools').map(item => {
        const tool = objectRecord(item);
        const node = objectRecord(tool.node);

        return {
            name: stringValue(tool.name ?? tool.tool, 'unknown'),
            node: stringValue(node.name ?? tool.node_name ?? tool.node, 'unknown'),
            status: stringValue(tool.status ?? tool.state ?? tool.expected_state, 'unknown'),
            version: stringValue(tool.version ?? tool.installed_version, 'unknown'),
        };
    });
}

function groupRuntimeByNode(
    nodes: NodeSummary[],
    instances: InstanceSummary[],
    databases: DatabaseSummary[],
    processes: ProcessSummary[],
    tools: ToolSummary[],
): NodeRuntimeGroup[] {
    const groups = new Map<string, NodeRuntimeGroup>();

    nodes.forEach(node => {
        groups.set(normalizedNodeName(node.name), {
            node,
            instances: [],
            databases: [],
            processes: [],
            tools: [],
            statusTone: statusTone(node.status),
            hasRuntimeInventory: false,
        });
    });

    instances.forEach(instance => runtimeGroup(groups, instance.node).instances.push(instance));
    databases.forEach(database => runtimeGroup(groups, database.node).databases.push(database));
    processes.forEach(process => runtimeGroup(groups, process.node).processes.push(process));
    tools.forEach(tool => runtimeGroup(groups, tool.node).tools.push(tool));

    return [...groups.values()]
        .map(group => ({
            ...group,
            hasRuntimeInventory: group.instances.length + group.databases.length + group.processes.length + group.tools.length > 0,
        }))
        .sort((left, right) => left.node.name.localeCompare(right.node.name));
}

function runtimeGroup(groups: Map<string, NodeRuntimeGroup>, nodeName: string): NodeRuntimeGroup {
    const key = normalizedNodeName(nodeName);
    const existing = groups.get(key);

    if (existing !== undefined) {
        return existing;
    }

    const node = {
        ...unknownNode,
        name: nodeName === 'unknown' ? 'unassigned' : nodeName,
    };

    const group = {
        node,
        instances: [],
        databases: [],
        processes: [],
        tools: [],
        statusTone: 'idle' as const,
        hasRuntimeInventory: false,
    };

    groups.set(key, group);

    return group;
}

function isDatabaseTool(name: string): boolean {
    const normalizedName = normalizedNodeName(name);

    return databaseToolNames.has(normalizedName)
        || normalizedName.includes('mysql')
        || normalizedName.includes('postgres')
        || normalizedName.includes('valkey');
}

function statusTone(status: string): StatusTone {
    const normalizedStatus = status.toLowerCase();

    if (['active', 'connected', 'healthy', 'online', 'ready', 'running'].includes(normalizedStatus)) {
        return 'healthy';
    }

    if (['error', 'failed', 'unhealthy'].includes(normalizedStatus)) {
        return 'error';
    }

    if (['disconnected', 'inactive', 'offline', 'unreachable'].includes(normalizedStatus)) {
        return 'offline';
    }

    return 'idle';
}

function defaultApiStatuses(): ApiEndpointStatus[] {
    return [
        createEndpointStatus('runtimeInventory', 'loaded', 'Loaded'),
    ];
}

function endpointLabel(endpoint: ApiEndpointKey): string {
    if (endpoint === 'runtimeInventory') {
        return 'Runtime inventory';
    }

    if (endpoint === 'nodes') {
        return 'Nodes';
    }

    if (endpoint === 'apps') {
        return 'Apps';
    }

    if (endpoint === 'processes') {
        return 'Processes';
    }

    return 'Tools';
}

function roleLabel(role: unknown): string {
    const roleRecord = objectRecord(role);

    return stringValue(roleRecord.role ?? roleRecord.name ?? role, '');
}

function normalizedNodeName(value: string): string {
    return value.trim().toLowerCase();
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

function arrayValues(value: unknown): unknown[] {
    if (Array.isArray(value)) {
        return value;
    }

    if (value === undefined || value === null) {
        return [];
    }

    return [value];
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
