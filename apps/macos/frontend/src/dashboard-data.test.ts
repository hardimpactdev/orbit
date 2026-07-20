import assert from 'node:assert/strict';
import test from 'node:test';
import { createDashboardSummary, createEndpointStatus } from './dashboard-data.ts';

test('groups project instances, processes, and tools by node', () => {
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
            projects: {
                data: {
                    projects: [
                        {
                            name: 'orbit-docs',
                            instances: [{ name: 'local', node: 'mini', environment: 'dev', status: 'ready' }],
                        },
                        {
                            name: 'billing',
                            instances: [{ name: 'production', node: { name: 'prod-1' }, environment: 'prod', status: 'deployed' }],
                        },
                    ],
                },
            },
            processes: {
                data: {
                    processes: [
                        {
                            name: 'queue',
                            node_name: 'mini',
                            project_name: 'orbit-docs',
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
    assert.equal(summary.totals.projects, 2);
    assert.equal(summary.totals.instances, 2);
    assert.equal(summary.totals.databases, 1);
    assert.equal(summary.totals.processes, 1);
    assert.equal(summary.totals.tools, 2);
    assert.equal(summary.totals.onlineNodes, 1);
    assert.equal(summary.totals.offlineNodes, 1);
    assert.equal(summary.apiStatuses.every(status => status.status === 'loaded'), true);

    const mini = summary.nodeGroups.find(group => group.node.name === 'mini');
    assert.equal(mini?.instances[0]?.project, 'orbit-docs');
    assert.equal(mini?.instances[0]?.name, 'local');
    assert.equal(mini?.databases[0]?.name, 'mini');
    assert.deepEqual(mini?.node.roles, ['app-dev', 'database']);
    assert.equal(mini?.processes[0]?.runtime, 'launchd');
    assert.equal(mini?.tools[0]?.version, '2.9.2');
    assert.equal(mini?.statusTone, 'healthy');

    const production = summary.nodeGroups.find(group => group.node.name === 'prod-1');
    assert.equal(production?.instances[0]?.project, 'billing');
    assert.equal(production?.tools[0]?.status, 'missing');
    assert.equal(production?.statusTone, 'offline');
});

test('detects Valkey but not Redis as database inventory', () => {
    const summary = createDashboardSummary({
        nodes: { data: { nodes: [{ name: 'storage-1', status: 'active' }] } },
        projects: { data: { projects: [] } },
        processes: { data: { processes: [] } },
        tools: {
            data: {
                tools: [
                    { name: 'valkey', node_name: 'storage-1', expected_state: 'installed' },
                    { name: 'redis', node_name: 'storage-1', expected_state: 'installed' },
                    { name: 'composer', node_name: 'storage-1', expected_state: 'installed' },
                ],
            },
        },
    });

    assert.equal(summary.totals.databases, 1);
    assert.equal(summary.nodeGroups[0]?.databases[0]?.engine, 'valkey');
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
        projects: { success: { data: { projects: [] } } },
        processes: { success: { data: { processes: [] } } },
        tools: { success: { data: { tools: [] } } },
    });

    assert.deepEqual(summary.nodeGroups[0]?.node.roles, ['gateway', 'agent']);
});

test('preserves endpoint failures next to partial inventory', () => {
    const summary = createDashboardSummary({
        nodes: { data: { nodes: [{ name: 'mini', status: 'active' }] } },
        projects: undefined,
        processes: { data: { processes: [{ name: 'queue', node_name: 'mini' }] } },
        tools: undefined,
        apiStatuses: [
            createEndpointStatus('nodes', 'loaded', 'Loaded'),
            createEndpointStatus('projects', 'failed', 'A node, project, instance, or workspace context is required.'),
            createEndpointStatus('processes', 'loaded', 'Loaded'),
            createEndpointStatus('tools', 'failed', 'A node, project, instance, or workspace context is required.'),
        ],
    });

    assert.equal(summary.nodeGroups.length, 1);
    assert.equal(summary.nodeGroups[0]?.processes[0]?.name, 'queue');
    assert.equal(summary.apiStatuses.filter(status => status.status === 'failed').length, 2);
});

test('keeps runtime records visible when the node list omits their node', () => {
    const summary = createDashboardSummary({
        nodes: { data: { nodes: [] } },
        projects: {
            data: {
                projects: [{
                    name: 'orphan-project',
                    instances: [{ name: 'production', node: 'missing-node' }],
                }],
            },
        },
        processes: { data: { processes: [] } },
        tools: { data: { tools: [] } },
    });

    assert.equal(summary.nodeGroups.length, 1);
    assert.equal(summary.nodeGroups[0]?.node.name, 'missing-node');
    assert.equal(summary.nodeGroups[0]?.instances[0]?.project, 'orphan-project');
    assert.equal(summary.nodeGroups[0]?.hasRuntimeInventory, true);
});
