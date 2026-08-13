<?php

declare(strict_types=1);

use App\Services\Nodes\Roles\GrafanaNodeResourcesDashboardRenderer;

it('renders the stable dashboard contract with ordered node options', function (): void {
    $content = new GrafanaNodeResourcesDashboardRenderer()->render(
        selectedNodeName: 'metrics-1',
        nodeNames: ['metrics-1', 'app-1'],
    );
    $dashboard = grafana_node_resources_dashboard($content);
    $nodeVariable = $dashboard['templating']['list'][0];

    expect($content)
        ->toEndWith("\n")
        ->and($dashboard)
        ->toMatchArray([
            'uid' => 'orbit-node-resources',
            'title' => 'Orbit Node Resources',
            'schemaVersion' => 39,
        ])
        ->and(array_column($dashboard['panels'], 'title'))
        ->toBe([
            'Exporter Up',
            'CPU Used',
            'Memory Used',
            'Root Disk Used',
            'CPU By Mode',
            'Load Average',
            'Memory',
            'Network Throughput',
        ])
        ->and($nodeVariable['current'])
        ->toBe([
            'selected' => true,
            'text' => 'metrics-1',
            'value' => 'metrics-1',
        ])
        ->and($nodeVariable['options'])
        ->toBe([
            [
                'selected' => true,
                'text' => 'metrics-1',
                'value' => 'metrics-1',
            ],
            [
                'selected' => false,
                'text' => 'app-1',
                'value' => 'app-1',
            ],
        ]);
});

it('pins the built-in dashboard PromQL contract', function (): void {
    $content = new GrafanaNodeResourcesDashboardRenderer()->render('metrics-1', ['metrics-1']);
    $dashboard = grafana_node_resources_dashboard($content);
    $expressions = array_merge(...array_map(
        static fn (array $panel): array => array_column($panel['targets'], 'expr'),
        $dashboard['panels'],
    ));

    expect($expressions)->toBe([
        'up{job="orbit-node-exporter",node="$node"}',
        '100 - (avg by (node) (rate(node_cpu_seconds_total{job="orbit-node-exporter",mode="idle",node="$node"}[5m])) * 100)',
        '100 * (1 - (node_memory_MemAvailable_bytes{job="orbit-node-exporter",node="$node"} / node_memory_MemTotal_bytes{job="orbit-node-exporter",node="$node"}))',
        '100 * (1 - (node_filesystem_avail_bytes{job="orbit-node-exporter",node="$node",mountpoint="/",fstype!~"tmpfs|overlay|squashfs"} / node_filesystem_size_bytes{job="orbit-node-exporter",node="$node",mountpoint="/",fstype!~"tmpfs|overlay|squashfs"}))',
        '100 - (avg by (mode) (rate(node_cpu_seconds_total{job="orbit-node-exporter",node="$node",mode!="idle"}[5m])) * 100)',
        'node_load1{job="orbit-node-exporter",node="$node"}',
        'node_load5{job="orbit-node-exporter",node="$node"}',
        'node_load15{job="orbit-node-exporter",node="$node"}',
        'node_memory_MemTotal_bytes{job="orbit-node-exporter",node="$node"} - node_memory_MemAvailable_bytes{job="orbit-node-exporter",node="$node"}',
        'node_memory_MemAvailable_bytes{job="orbit-node-exporter",node="$node"}',
        'sum by (node) (rate(node_network_receive_bytes_total{job="orbit-node-exporter",node="$node",device!~"lo|docker.*|br-.*|veth.*"}[5m]))',
        'sum by (node) (rate(node_network_transmit_bytes_total{job="orbit-node-exporter",node="$node",device!~"lo|docker.*|br-.*|veth.*"}[5m]))',
    ]);
});

/**
 * @return array{
 *     uid: string,
 *     title: string,
 *     schemaVersion: int,
 *     templating: array{list: list<array{
 *         name: string,
 *         current: array{selected: bool, text: string, value: string},
 *         options: list<array{selected: bool, text: string, value: string}>
 *     }>},
 *     panels: list<array{title: string, targets: list<array{expr: string}>}>
 * }
 */
function grafana_node_resources_dashboard(string $content): array
{
    /** @var array<array-key, mixed> $dashboard */
    $dashboard = json_decode(
        json: $content,
        associative: true,
        depth: 512,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($dashboard)->toBeArray();

    /**
     * @var array{
     *     uid: string,
     *     title: string,
     *     schemaVersion: int,
     *     templating: array{list: list<array{
     *         name: string,
     *         current: array{selected: bool, text: string, value: string},
     *         options: list<array{selected: bool, text: string, value: string}>
     *     }>},
     *     panels: list<array{title: string, targets: list<array{expr: string}>}>
     * } $dashboard
     */
    return $dashboard;
}
