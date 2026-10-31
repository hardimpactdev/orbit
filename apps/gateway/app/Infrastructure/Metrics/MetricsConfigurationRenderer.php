<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class MetricsConfigurationRenderer
{
    public function __construct(
        private PrometheusConfigRenderer $prometheus = new PrometheusConfigRenderer,
        private GrafanaConfigRenderer $grafana = new GrafanaConfigRenderer,
        private NodeResourcesDashboardRenderer $dashboard = new NodeResourcesDashboardRenderer,
    ) {}

    /** @param list<array{name: string, address: string}> $targets
     * @param  list<ServiceMetricsNode>  $services
     */
    public function render(array $targets, #[SensitiveParameter] string $password, array $services = []): MetricsConfigurationBundle
    {
        if ($password === '') {
            throw new InvalidArgumentException('The Grafana admin password is unavailable.');
        }

        $prometheusFiles = [
            '/etc/orbit/metrics/prometheus.yml' => $this->prometheus->render($targets, $services),
        ];
        $grafanaFiles = [
            '/etc/orbit/metrics/grafana/grafana.ini' => $this->grafana->rootUrl(),
            '/etc/orbit/metrics/grafana/provisioning/datasources/prometheus.yml' => $this->grafana->datasource(),
            '/etc/orbit/metrics/grafana/provisioning/dashboards/provider.yml' => $this->grafana->dashboardProvider(),
        ];
        $publicFiles = [
            ...$prometheusFiles,
            ...$grafanaFiles,
            '/etc/orbit/metrics/grafana/dashboards/orbit-node-resources.json' => $this->dashboard->render(
                array_map(static fn (array $target): string => $target['name'], $targets),
            ),
        ];
        foreach (['caddy', 'fpm'] as $kind) {
            $publicFiles['/etc/orbit/metrics/grafana/dashboards/orbit-'.$kind.'.json'] = new ServiceMetricsDashboardRenderer()->render($kind);
        }
        $files = [];

        foreach ($publicFiles as $path => $contents) {
            $files[] = new MetricsGeneratedFile($path, new ProtectedMetricsSecret($contents));
        }

        $files[] = new MetricsGeneratedFile(
            path: '/etc/orbit/metrics/grafana/admin-password',
            contents: new ProtectedMetricsSecret($password),
        );

        return new MetricsConfigurationBundle($files);
    }
}
