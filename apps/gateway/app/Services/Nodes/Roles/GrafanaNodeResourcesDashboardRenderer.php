<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use JsonException;
use RuntimeException;

/** @mago-expect lint:halstead */
final readonly class GrafanaNodeResourcesDashboardRenderer
{
    /**
     * @param  list<string>  $nodeNames
     */
    public function render(string $selectedNodeName, array $nodeNames): string
    {
        try {
            $content = json_encode(
                [
                    'annotations' => [
                        'list' => [
                            [
                                'builtIn' => 1,
                                'datasource' => [
                                    'type' => 'grafana',
                                    'uid' => '-- Grafana --',
                                ],
                                'enable' => true,
                                'hide' => true,
                                'iconColor' => 'rgba(0, 211, 255, 1)',
                                'name' => 'Annotations & Alerts',
                                'type' => 'dashboard',
                            ],
                        ],
                    ],
                    'editable' => true,
                    'fiscalYearStartMonth' => 0,
                    'graphTooltip' => 0,
                    'id' => null,
                    'links' => [],
                    'liveNow' => false,
                    'panels' => [
                        [
                            'datasource' => [
                                'type' => 'prometheus',
                                'uid' => 'orbit-prometheus',
                            ],
                            'fieldConfig' => [
                                'defaults' => [
                                    'mappings' => [],
                                    'thresholds' => [
                                        'mode' => 'absolute',
                                        'steps' => [
                                            ['color' => 'green', 'value' => null],
                                            ['color' => 'red', 'value' => 1],
                                        ],
                                    ],
                                    'unit' => 'short',
                                ],
                                'overrides' => [],
                            ],
                            'gridPos' => ['h' => 4, 'w' => 4, 'x' => 0, 'y' => 0],
                            'id' => 1,
                            'options' => [
                                'colorMode' => 'value',
                                'graphMode' => 'area',
                                'justifyMode' => 'auto',
                                'orientation' => 'auto',
                                'reduceOptions' => [
                                    'calcs' => ['lastNotNull'],
                                    'fields' => '',
                                    'values' => false,
                                ],
                                'textMode' => 'auto',
                            ],
                            'targets' => [
                                $this->grafanaPrometheusTarget('A', 'up{job="orbit-node-exporter",node="$node"}'),
                            ],
                            'title' => 'Exporter Up',
                            'type' => 'stat',
                        ],
                        [
                            'datasource' => [
                                'type' => 'prometheus',
                                'uid' => 'orbit-prometheus',
                            ],
                            'fieldConfig' => [
                                'defaults' => [
                                    'mappings' => [],
                                    'max' => 100,
                                    'min' => 0,
                                    'thresholds' => [
                                        'mode' => 'percentage',
                                        'steps' => [
                                            ['color' => 'green', 'value' => null],
                                            ['color' => 'orange', 'value' => 70],
                                            ['color' => 'red', 'value' => 90],
                                        ],
                                    ],
                                    'unit' => 'percent',
                                ],
                                'overrides' => [],
                            ],
                            'gridPos' => ['h' => 4, 'w' => 5, 'x' => 4, 'y' => 0],
                            'id' => 2,
                            'options' => [
                                'colorMode' => 'value',
                                'graphMode' => 'area',
                                'justifyMode' => 'auto',
                                'orientation' => 'auto',
                                'reduceOptions' => [
                                    'calcs' => ['lastNotNull'],
                                    'fields' => '',
                                    'values' => false,
                                ],
                                'textMode' => 'auto',
                            ],
                            'targets' => [
                                $this->grafanaPrometheusTarget(
                                    'A',
                                    '100 - (avg by (node) (rate(node_cpu_seconds_total{job="orbit-node-exporter",mode="idle",node="$node"}[5m])) * 100)',
                                ),
                            ],
                            'title' => 'CPU Used',
                            'type' => 'stat',
                        ],
                        [
                            'datasource' => [
                                'type' => 'prometheus',
                                'uid' => 'orbit-prometheus',
                            ],
                            'fieldConfig' => [
                                'defaults' => [
                                    'mappings' => [],
                                    'max' => 100,
                                    'min' => 0,
                                    'thresholds' => [
                                        'mode' => 'percentage',
                                        'steps' => [
                                            ['color' => 'green', 'value' => null],
                                            ['color' => 'orange', 'value' => 75],
                                            ['color' => 'red', 'value' => 90],
                                        ],
                                    ],
                                    'unit' => 'percent',
                                ],
                                'overrides' => [],
                            ],
                            'gridPos' => ['h' => 4, 'w' => 5, 'x' => 9, 'y' => 0],
                            'id' => 3,
                            'options' => [
                                'colorMode' => 'value',
                                'graphMode' => 'area',
                                'justifyMode' => 'auto',
                                'orientation' => 'auto',
                                'reduceOptions' => [
                                    'calcs' => ['lastNotNull'],
                                    'fields' => '',
                                    'values' => false,
                                ],
                                'textMode' => 'auto',
                            ],
                            'targets' => [
                                $this->grafanaPrometheusTarget(
                                    'A',
                                    '100 * (1 - (node_memory_MemAvailable_bytes{job="orbit-node-exporter",node="$node"} / node_memory_MemTotal_bytes{job="orbit-node-exporter",node="$node"}))',
                                ),
                            ],
                            'title' => 'Memory Used',
                            'type' => 'stat',
                        ],
                        [
                            'datasource' => [
                                'type' => 'prometheus',
                                'uid' => 'orbit-prometheus',
                            ],
                            'fieldConfig' => [
                                'defaults' => [
                                    'mappings' => [],
                                    'max' => 100,
                                    'min' => 0,
                                    'thresholds' => [
                                        'mode' => 'percentage',
                                        'steps' => [
                                            ['color' => 'green', 'value' => null],
                                            ['color' => 'orange', 'value' => 80],
                                            ['color' => 'red', 'value' => 95],
                                        ],
                                    ],
                                    'unit' => 'percent',
                                ],
                                'overrides' => [],
                            ],
                            'gridPos' => ['h' => 4, 'w' => 5, 'x' => 14, 'y' => 0],
                            'id' => 4,
                            'options' => [
                                'colorMode' => 'value',
                                'graphMode' => 'area',
                                'justifyMode' => 'auto',
                                'orientation' => 'auto',
                                'reduceOptions' => [
                                    'calcs' => ['lastNotNull'],
                                    'fields' => '',
                                    'values' => false,
                                ],
                                'textMode' => 'auto',
                            ],
                            'targets' => [
                                $this->grafanaPrometheusTarget(
                                    'A',
                                    '100 * (1 - (node_filesystem_avail_bytes{job="orbit-node-exporter",node="$node",mountpoint="/",fstype!~"tmpfs|overlay|squashfs"} / node_filesystem_size_bytes{job="orbit-node-exporter",node="$node",mountpoint="/",fstype!~"tmpfs|overlay|squashfs"}))',
                                ),
                            ],
                            'title' => 'Root Disk Used',
                            'type' => 'stat',
                        ],
                        [
                            'datasource' => [
                                'type' => 'prometheus',
                                'uid' => 'orbit-prometheus',
                            ],
                            'fieldConfig' => [
                                'defaults' => [
                                    'custom' => [
                                        'drawStyle' => 'line',
                                        'fillOpacity' => 10,
                                        'lineInterpolation' => 'linear',
                                        'lineWidth' => 1,
                                        'pointSize' => 5,
                                        'showPoints' => 'never',
                                        'spanNulls' => false,
                                    ],
                                    'mappings' => [],
                                    'thresholds' => [
                                        'mode' => 'absolute',
                                        'steps' => [
                                            ['color' => 'green', 'value' => null],
                                        ],
                                    ],
                                    'unit' => 'percent',
                                ],
                                'overrides' => [],
                            ],
                            'gridPos' => ['h' => 8, 'w' => 12, 'x' => 0, 'y' => 4],
                            'id' => 5,
                            'options' => [
                                'legend' => [
                                    'calcs' => ['lastNotNull'],
                                    'displayMode' => 'list',
                                    'placement' => 'bottom',
                                ],
                                'tooltip' => [
                                    'mode' => 'single',
                                    'sort' => 'none',
                                ],
                            ],
                            'targets' => [
                                $this->grafanaPrometheusTarget(
                                    'A',
                                    '100 - (avg by (mode) (rate(node_cpu_seconds_total{job="orbit-node-exporter",node="$node",mode!="idle"}[5m])) * 100)',
                                    '{{mode}}',
                                ),
                            ],
                            'title' => 'CPU By Mode',
                            'type' => 'timeseries',
                        ],
                        [
                            'datasource' => [
                                'type' => 'prometheus',
                                'uid' => 'orbit-prometheus',
                            ],
                            'fieldConfig' => [
                                'defaults' => [
                                    'custom' => [
                                        'drawStyle' => 'line',
                                        'fillOpacity' => 10,
                                        'lineInterpolation' => 'linear',
                                        'lineWidth' => 1,
                                        'pointSize' => 5,
                                        'showPoints' => 'never',
                                        'spanNulls' => false,
                                    ],
                                    'mappings' => [],
                                    'thresholds' => [
                                        'mode' => 'absolute',
                                        'steps' => [
                                            ['color' => 'green', 'value' => null],
                                        ],
                                    ],
                                    'unit' => 'short',
                                ],
                                'overrides' => [],
                            ],
                            'gridPos' => ['h' => 8, 'w' => 12, 'x' => 12, 'y' => 4],
                            'id' => 6,
                            'options' => [
                                'legend' => [
                                    'calcs' => ['lastNotNull'],
                                    'displayMode' => 'list',
                                    'placement' => 'bottom',
                                ],
                                'tooltip' => [
                                    'mode' => 'single',
                                    'sort' => 'none',
                                ],
                            ],
                            'targets' => [
                                $this->grafanaPrometheusTarget(
                                    'A',
                                    'node_load1{job="orbit-node-exporter",node="$node"}',
                                    'load1',
                                ),
                                $this->grafanaPrometheusTarget(
                                    'B',
                                    'node_load5{job="orbit-node-exporter",node="$node"}',
                                    'load5',
                                ),
                                $this->grafanaPrometheusTarget(
                                    'C',
                                    'node_load15{job="orbit-node-exporter",node="$node"}',
                                    'load15',
                                ),
                            ],
                            'title' => 'Load Average',
                            'type' => 'timeseries',
                        ],
                        [
                            'datasource' => [
                                'type' => 'prometheus',
                                'uid' => 'orbit-prometheus',
                            ],
                            'fieldConfig' => [
                                'defaults' => [
                                    'custom' => [
                                        'drawStyle' => 'line',
                                        'fillOpacity' => 10,
                                        'lineInterpolation' => 'linear',
                                        'lineWidth' => 1,
                                        'pointSize' => 5,
                                        'showPoints' => 'never',
                                        'spanNulls' => false,
                                    ],
                                    'mappings' => [],
                                    'thresholds' => [
                                        'mode' => 'absolute',
                                        'steps' => [
                                            ['color' => 'green', 'value' => null],
                                        ],
                                    ],
                                    'unit' => 'decbytes',
                                ],
                                'overrides' => [],
                            ],
                            'gridPos' => ['h' => 8, 'w' => 12, 'x' => 0, 'y' => 12],
                            'id' => 7,
                            'options' => [
                                'legend' => [
                                    'calcs' => ['lastNotNull'],
                                    'displayMode' => 'list',
                                    'placement' => 'bottom',
                                ],
                                'tooltip' => [
                                    'mode' => 'single',
                                    'sort' => 'none',
                                ],
                            ],
                            'targets' => [
                                $this->grafanaPrometheusTarget(
                                    'A',
                                    'node_memory_MemTotal_bytes{job="orbit-node-exporter",node="$node"} - node_memory_MemAvailable_bytes{job="orbit-node-exporter",node="$node"}',
                                    'used',
                                ),
                                $this->grafanaPrometheusTarget(
                                    'B',
                                    'node_memory_MemAvailable_bytes{job="orbit-node-exporter",node="$node"}',
                                    'available',
                                ),
                            ],
                            'title' => 'Memory',
                            'type' => 'timeseries',
                        ],
                        [
                            'datasource' => [
                                'type' => 'prometheus',
                                'uid' => 'orbit-prometheus',
                            ],
                            'fieldConfig' => [
                                'defaults' => [
                                    'custom' => [
                                        'drawStyle' => 'line',
                                        'fillOpacity' => 10,
                                        'lineInterpolation' => 'linear',
                                        'lineWidth' => 1,
                                        'pointSize' => 5,
                                        'showPoints' => 'never',
                                        'spanNulls' => false,
                                    ],
                                    'mappings' => [],
                                    'thresholds' => [
                                        'mode' => 'absolute',
                                        'steps' => [
                                            ['color' => 'green', 'value' => null],
                                        ],
                                    ],
                                    'unit' => 'Bps',
                                ],
                                'overrides' => [],
                            ],
                            'gridPos' => ['h' => 8, 'w' => 12, 'x' => 12, 'y' => 12],
                            'id' => 8,
                            'options' => [
                                'legend' => [
                                    'calcs' => ['lastNotNull'],
                                    'displayMode' => 'list',
                                    'placement' => 'bottom',
                                ],
                                'tooltip' => [
                                    'mode' => 'single',
                                    'sort' => 'none',
                                ],
                            ],
                            'targets' => [
                                $this->grafanaPrometheusTarget(
                                    'A',
                                    'sum by (node) (rate(node_network_receive_bytes_total{job="orbit-node-exporter",node="$node",device!~"lo|docker.*|br-.*|veth.*"}[5m]))',
                                    'receive',
                                ),
                                $this->grafanaPrometheusTarget(
                                    'B',
                                    'sum by (node) (rate(node_network_transmit_bytes_total{job="orbit-node-exporter",node="$node",device!~"lo|docker.*|br-.*|veth.*"}[5m]))',
                                    'transmit',
                                ),
                            ],
                            'title' => 'Network Throughput',
                            'type' => 'timeseries',
                        ],
                    ],
                    'refresh' => '30s',
                    'schemaVersion' => 39,
                    'style' => 'dark',
                    'tags' => ['orbit', 'node-exporter'],
                    'templating' => [
                        'list' => [
                            [
                                'current' => [
                                    'selected' => true,
                                    'text' => $selectedNodeName,
                                    'value' => $selectedNodeName,
                                ],
                                'datasource' => [
                                    'type' => 'prometheus',
                                    'uid' => 'orbit-prometheus',
                                ],
                                'definition' => 'label_values(up{job="orbit-node-exporter"}, node)',
                                'hide' => 0,
                                'includeAll' => false,
                                'label' => 'Node',
                                'multi' => false,
                                'name' => 'node',
                                'options' => $this->nodeVariableOptions($selectedNodeName, $nodeNames),
                                'query' => 'label_values(up{job="orbit-node-exporter"}, node)',
                                'refresh' => 1,
                                'regex' => '',
                                'sort' => 1,
                                'type' => 'query',
                            ],
                        ],
                    ],
                    'time' => [
                        'from' => 'now-1h',
                        'to' => 'now',
                    ],
                    'timepicker' => [],
                    'timezone' => 'browser',
                    'title' => 'Orbit Node Resources',
                    'uid' => 'orbit-node-resources',
                    'version' => 1,
                    'weekStart' => '',
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'The Orbit node resources Grafana dashboard could not be encoded.',
                previous: $exception,
            );
        }

        return "{$content}\n";
    }

    /**
     * @param  list<string>  $nodeNames
     * @return list<array{selected: bool, text: string, value: string}>
     */
    private function nodeVariableOptions(string $selectedNodeName, array $nodeNames): array
    {
        return array_map(
            static fn (string $nodeName): array => [
                'selected' => $nodeName === $selectedNodeName,
                'text' => $nodeName,
                'value' => $nodeName,
            ],
            $nodeNames,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function grafanaPrometheusTarget(string $refId, string $expr, string $legendFormat = ''): array
    {
        return [
            'datasource' => [
                'type' => 'prometheus',
                'uid' => 'orbit-prometheus',
            ],
            'editorMode' => 'code',
            'expr' => $expr,
            'instant' => false,
            'legendFormat' => $legendFormat,
            'range' => true,
            'refId' => $refId,
        ];
    }
}
