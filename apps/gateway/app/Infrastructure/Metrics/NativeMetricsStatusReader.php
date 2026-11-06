<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Data\Metrics\MetricsAssignmentData;
use App\Data\Metrics\MetricsExporterData;
use App\Data\Metrics\MetricsStatusData;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\ExporterDegradationRepository;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsReconcileDegradationRepository;
use App\Domain\Metrics\MetricsStatusReader;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class NativeMetricsStatusReader implements MetricsStatusReader
{
    public function __construct(
        private MetricsExporterProjection $projection,
        private GrafanaPrometheusClient $prometheus,
        private ExporterDegradationRepository $degradations,
        private MetricsReconcileDegradationRepository $reconcileDegradation = new MetricsReconcileDegradationRepository,
    ) {}

    public function status(): MetricsStatusData
    {
        $assignments = NodeRole::query()->where('role', RoleName::Metrics->value)->with('node')->get();
        if ($assignments->count() !== 1) {
            if ($assignments->count() > 1) {
                throw new RoleAssignmentException('Metrics role assignment drift detected.');
            }

            return new MetricsStatusData(false, null, null, 'disabled', 'disabled', []);
        }
        $assignment = $assignments->firstOrFail();
        $metrics = $assignment->node;
        $url = 'https://metrics.orbit';
        $items = [];
        $reconcileErrorCode = null;
        $exporterErrorCode = null;
        $up = $this->targetsUp();
        foreach ($this->projection->for($metrics) as $item) {
            $node = $item->node;
            $selection = $item->selection;
            // A node the last convergence skipped has no exporter state
            // anyone could read, so status reports the recorded reason instead
            // of waiting on a probe that is expected to fail.
            $degradedErrorCode = $this->reconcileDegradation->errorCode($node->id);
            $degradation = $degradedErrorCode === null
                ? $this->degradations->get($node->id)
                : ExporterDegradationReason::ReconcileFailed;
            $reconcileErrorCode ??= $degradedErrorCode;
            $actual = $degradation === null ? $this->actual($up, $node) : 'unknown';
            if ($selection->selected && $actual !== 'active') {
                $exporterErrorCode ??= 'metrics.exporter_'.($degradation->value ?? 'not_active');
            }

            $items[] = new MetricsExporterData(
                $node->id,
                $node->name,
                $selection->selected,
                $actual,
                $selection->reason,
                $degradation,
                $degradedErrorCode,
            );
        }

        usort($items, static fn (MetricsExporterData $left, MetricsExporterData $right): int => strcmp(
            $left->name,
            $right->name,
        ));

        $reconcileErrorCode ??= $exporterErrorCode;

        return new MetricsStatusData(
            true,
            $url,
            MetricsAssignmentData::fromModel($assignment),
            $up === null ? 'unhealthy' : 'healthy',
            $up === null ? 'unhealthy' : 'healthy',
            $items,
            $reconcileErrorCode,
            $reconcileErrorCode === null ? 'healthy' : 'degraded',
        );
    }

    /**
     * Which scrape targets Prometheus has up, by `address:port`, or null when Prometheus did not answer
     * through Grafana.
     *
     * @return array<string, bool>|null
     */
    private function targetsUp(): ?array
    {
        try {
            $decoded = $this->prometheus->query('up{job=~"orbit-node-exporter|orbit-cadvisor"}');
        } catch (\Throwable) {
            return null;
        }

        $data = $decoded['data'] ?? null;
        $result = is_array($data) ? ($data['result'] ?? null) : null;
        if (($decoded['status'] ?? null) !== 'success' || ! is_array($result)) {
            return null;
        }

        $up = [];
        foreach ($result as $series) {
            $metric = is_array($series) ? ($series['metric'] ?? null) : null;
            $instance = is_array($metric) ? ($metric['instance'] ?? null) : null;
            $value = is_array($series) ? ($series['value'] ?? null) : null;
            if (is_string($instance)) {
                $up[$instance] = is_array($value) && ($value[1] ?? null) === '1';
            }
        }

        return $up;
    }

    /** @param array<string, bool>|null $up */
    private function actual(?array $up, Node $node): string
    {
        $address = $node->wireguard_ip;
        if ($up === null || ! is_string($address)) {
            return 'unknown';
        }

        return ($up["{$address}:".MetricsFootprint::ExporterPort] ?? false) && ($up["{$address}:".MetricsFootprint::CadvisorPort] ?? false)
            ? 'active'
            : 'inactive';
    }
}
