import assert from 'node:assert/strict';
import test from 'node:test';
import { createDashboardSummary, createEndpointStatus } from './dashboard-data.ts';

test('groups apps, processes, and tools by node', () => {
    const summary = createDashboardSummary(
        {
            nodes: {
                data: {
                    nodes: [
                        {
                            name: 'mini',
                            roles: ['app-dev', 'database'],
                            environment: 'macos',
                            status: 'active',
                            addresses: { wireguard: '10.6.0.10' },
                        },
                        {
                            name: 'prod-1',
                            role: 'app-prod',
                            environment: 'ubuntu',
                            status: 'offline',
                        },
                    ],
                },
            },
            apps: {
                data: {
                    apps: [
                        { name: 'orbit-docs', node_name: 'mini', environment: 'dev', status: 'ready' },
                        { name: 'billing', node: { name: 'prod-1' }, environment: 'prod', status: 'deployed' },
                    ],
                },
            },
            processes: {
                data: {
                    processes: [
                        {
                            name: 'queue',
                            node_name: 'mini',
                            app_name: 'orbit-docs',
                            runtime: 'launchd',
                            status: 'running',
                        },
                    ],
                },
            },
            tools: {
                data: {
                    tools: [
                        { name: 'composer', node_name: 'mini', installed_version: '2.9.2', status: 'installed' },
                        { name: 'docker', node_name: 'prod-1', installed_version: '27', status: 'missing' },
                    ],
                },
            },
        },
        new Date('2026-07-08T12:00:00Z'),
    );

    assert.equal(summary.totals.nodes, 2);
    assert.equal(summary.totals.apps, 2);
    assert.equal(summary.totals.databases, 1);
    assert.equal(summary.totals.processes, 1);
    assert.equal(summary.totals.tools, 2);
    assert.equal(summary.totals.onlineNodes, 1);
    assert.equal(summary.totals.offlineNodes, 1);
    assert.equal(summary.apiStatuses.every(status => status.status === 'loaded'), true);

    const mini = summary.nodeGroups.find(group => group.node.name === 'mini');
    assert.equal(mini?.apps[0]?.name, 'orbit-docs');
    assert.equal(mini?.databases[0]?.name, 'mini');
    assert.deepEqual(mini?.node.roles, ['app-dev', 'database']);
    assert.equal(mini?.processes[0]?.runtime, 'launchd');
    assert.equal(mini?.tools[0]?.version, '2.9.2');
    assert.equal(mini?.statusTone, 'healthy');

    const production = summary.nodeGroups.find(group => group.node.name === 'prod-1');
    assert.equal(production?.apps[0]?.name, 'billing');
    assert.equal(production?.tools[0]?.status, 'missing');
    assert.equal(production?.statusTone, 'offline');
});

test('detects database tools as database inventory', () => {
    const summary = createDashboardSummary({
        nodes: { data: { nodes: [{ name: 'storage-1', status: 'active' }] } },
        apps: { data: { apps: [] } },
        processes: { data: { processes: [] } },
        tools: {
            data: {
                tools: [
                    { name: 'postgresql', node_name: 'storage-1', expected_state: 'installed' },
                    { name: 'composer', node_name: 'storage-1', expected_state: 'installed' },
                ],
            },
        },
    });

    assert.equal(summary.totals.databases, 1);
    assert.equal(summary.nodeGroups[0]?.databases[0]?.engine, 'postgresql');
});

test('reads Scramble node role payloads', () => {
    const summary = createDashboardSummary({
        nodes: {
            success: {
                data: {
                    nodes: [
                        {
                            name: 'mini',
                            roles: [{ role: 'gateway' }, { role: 'agent' }],
                            status: 'active',
                        },
                    ],
                },
            },
        },
        apps: { success: { data: { apps: [] } } },
        processes: { success: { data: { processes: [] } } },
        tools: { success: { data: { tools: [] } } },
    });

    assert.deepEqual(summary.nodeGroups[0]?.node.roles, ['gateway', 'agent']);
});

test('preserves endpoint failures next to partial inventory', () => {
    const summary = createDashboardSummary({
        nodes: { data: { nodes: [{ name: 'mini', status: 'active' }] } },
        apps: undefined,
        processes: { data: { processes: [{ name: 'queue', node_name: 'mini' }] } },
        tools: undefined,
        apiStatuses: [
            createEndpointStatus('nodes', 'loaded', 'Loaded'),
            createEndpointStatus('apps', 'failed', 'A node, app, or workspace context is required.'),
            createEndpointStatus('processes', 'loaded', 'Loaded'),
            createEndpointStatus('tools', 'failed', 'A node, app, or workspace context is required.'),
        ],
    });

    assert.equal(summary.nodeGroups.length, 1);
    assert.equal(summary.nodeGroups[0]?.processes[0]?.name, 'queue');
    assert.equal(summary.apiStatuses.filter(status => status.status === 'failed').length, 2);
});

test('keeps runtime records visible when the node list omits their node', () => {
    const summary = createDashboardSummary({
        nodes: { data: { nodes: [] } },
        apps: { data: { apps: [{ name: 'orphan-app', node_name: 'missing-node' }] } },
        processes: { data: { processes: [] } },
        tools: { data: { tools: [] } },
    });

    assert.equal(summary.nodeGroups.length, 1);
    assert.equal(summary.nodeGroups[0]?.node.name, 'missing-node');
    assert.equal(summary.nodeGroups[0]?.apps[0]?.name, 'orphan-app');
    assert.equal(summary.nodeGroups[0]?.hasRuntimeInventory, true);
});
