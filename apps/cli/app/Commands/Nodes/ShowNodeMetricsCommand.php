<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Nodes\ShowNodeMetricsRequest;
use Orbit\Sdk\Responses\Nodes\NodeMetricsResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;

final class ShowNodeMetricsCommand extends NodeCommand
{
    #[\Override]
    protected $signature = 'node:metrics
        {node? : Numeric node ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show the latest recorded metrics snapshot for a node.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $nodeId = null;

        if ($this->argument('node') !== null || ! $this->consoleMode()->mayPrompt) {
            $nodeId = $this->positiveId('node', 'Node', 'node.id_invalid');

            if ($nodeId === null) {
                if ($this->option('json') !== true) {
                    ConsoleWriter::write($this->output, "Code: node.id_invalid\n");
                }

                return self::FAILURE;
            }
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        if ($nodeId === null) {
            $nodes = $this->sendWithProgress($connector, new ListNodesRequest, NodesResponse::class,
                ['List Nodes', 'Loading Nodes', 'Loaded Nodes']);

            if (! $nodes instanceof NodesResponse) {
                return self::FAILURE;
            }

            $rows = [];

            foreach ($nodes->nodes as $node) {
                $rows[$node->id] = [(string) $node->id, $node->name];
            }

            $nodeId = (int) $this->commandPrompts()->selectEntity('Node', ['ID', 'Name'], $rows);
        }

        $metrics = $this->sendWithProgress(
            $connector,
            new ShowNodeMetricsRequest($nodeId),
            NodeMetricsResponse::class,
            ['Show Node metrics', 'Loading Node metrics', 'Loaded Node metrics'],
        );

        if (! $metrics instanceof NodeMetricsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($metrics->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Node {$metrics->nodeName} metrics", [
            'Node ID' => $metrics->nodeId,
            'Observed at' => $metrics->observedAt,
            'Agent' => $metrics->agent?->label() ?? '—',
            'Freshness' => $metrics->possiblyOutdated ? 'possibly outdated' : 'current',
            'Cores' => implode(', ', array_map(
                static fn (float $ratio): string => round($ratio * 100).'%',
                $metrics->cores,
            )),
            'Memory' => $this->bytesFraction($metrics->memory['used'], $metrics->memory['total']),
            'Swap' => $this->bytesFraction($metrics->swap['used'], $metrics->swap['total']),
            'Load' => sprintf('%.2f, %.2f, %.2f', $metrics->load['one'], $metrics->load['five'], $metrics->load['fifteen']),
            'Uptime' => $this->humanDuration($metrics->uptimeSeconds),
            'CPU pressure' => sprintf('%.1f%%', $metrics->pressure['cpu']['some_avg10']),
            'Memory pressure' => sprintf('%.1f%%', $metrics->pressure['memory']['some_avg10']),
            'IO pressure' => sprintf('%.1f%%', $metrics->pressure['io']['some_avg10']),
            'Disks' => $this->disksSummary($metrics->disks),
            'Request ID' => $metrics->requestId,
        ]));

        return self::SUCCESS;
    }

    /** @param list<array{mount: string, used: int|null, total: int|null, observed_at: string|null, stale: bool}> $disks */
    private function disksSummary(array $disks): string
    {
        if ($disks === []) {
            return '—';
        }

        return implode(', ', array_map(
            fn (array $disk): string => "{$disk['mount']}: "
                .($disk['used'] === null || $disk['total'] === null ? '—' : $this->bytesFraction($disk['used'], $disk['total']))
                .($disk['stale'] ? ' (stale)' : ''),
            $disks,
        ));
    }

    private function bytesFraction(int $used, int $total): string
    {
        return $this->humanBytes($used).' / '.$this->humanBytes($total);
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) max(0, $bytes);
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) (int) $value : number_format($value, 1)).' '.$units[$unit];
    }

    private function humanDuration(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return "{$days}d {$hours}h {$minutes}m";
        }

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        return "{$minutes}m";
    }
}
