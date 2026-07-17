<?php

declare(strict_types=1);

namespace App\Commands\App\Concerns;

use Illuminate\Console\Command;

/**
 * @mixin Command
 * @mago-expect lint:cyclomatic-complexity
 */
trait RendersAppAnalyticsBinding
{
    /** @param array<array-key, mixed> $response */
    private function renderAnalyticsBinding(array $response): void
    {
        $binding = $this->analyticsBindingData($response);

        $this->renderAnalyticsBindingHeader($binding);
        $this->renderAnalyticsBindingRoutes($binding);
    }

    /** @param array<array-key, mixed> $response */
    private function renderAnalyticsBindingWithDashboard(array $response): void
    {
        $binding = $this->analyticsBindingData($response);

        $this->renderAnalyticsBindingHeader($binding);
        $this->line('  dashboard_url: '.$this->analyticsStringField($binding, 'dashboard_url'));
        $this->renderAnalyticsBindingRoutes($binding);
    }

    /** @param array<array-key, mixed> $binding */
    private function renderAnalyticsBindingHeader(array $binding): void
    {
        $this->line('binding:');
        $this->line('  app: '.$this->analyticsStringField($binding, 'app'));
        $this->line('  enabled: '.($this->analyticsBoolField($binding, 'enabled') ? 'true' : 'false'));
        $this->line('  internal_host: '.$this->analyticsStringField($binding, 'internal_host'));
    }

    /** @param array<array-key, mixed> $binding */
    private function renderAnalyticsBindingRoutes(array $binding): void
    {
        $this->renderAnalyticsHostList($this->analyticsListField($binding, 'public_hosts'));
        $this->renderAnalyticsTrackingEndpoints($binding);
    }

    /**
     * @param  array<array-key, mixed>  $response
     * @return array<array-key, mixed>
     */
    private function analyticsBindingData(array $response): array
    {
        $success = $response['success'] ?? null;
        $data = is_array($success) ? $success['data'] ?? null : null;
        $payload = is_array($data) ? $data : $response;
        $binding = $payload['binding'] ?? null;

        return is_array($binding) ? $binding : [];
    }

    /** @param array<array-key, mixed> $binding */
    private function analyticsStringField(array $binding, string $key): string
    {
        $value = $binding[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /** @param array<array-key, mixed> $binding */
    private function analyticsBoolField(array $binding, string $key): bool
    {
        $value = $binding[$key] ?? null;

        return is_bool($value) ? $value : false;
    }

    /**
     * @param  array<array-key, mixed>  $binding
     * @return list<string>
     */
    private function analyticsListField(array $binding, string $key): array
    {
        $value = $binding[$key] ?? null;

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    /** @param list<string> $hosts */
    private function renderAnalyticsHostList(array $hosts): void
    {
        if ($hosts === []) {
            $this->line('  public_hosts: []');

            return;
        }

        $this->line('  public_hosts:');

        foreach ($hosts as $host) {
            $this->line('    - '.$host);
        }
    }

    /** @param array<array-key, mixed> $binding */
    private function renderAnalyticsTrackingEndpoints(array $binding): void
    {
        $endpoints = $binding['tracking_endpoints'] ?? null;

        if (! is_array($endpoints) || $endpoints === []) {
            $this->line('  tracking_endpoints: []');

            return;
        }

        $this->line('  tracking_endpoints:');

        foreach ($endpoints as $endpoint) {
            if (! is_array($endpoint)) {
                continue;
            }

            $this->line('    - host: '.$this->analyticsStringField($endpoint, 'host'));
            $this->line('      script_base_url: '.$this->analyticsStringField($endpoint, 'script_base_url'));
            $this->line('      event_endpoint: '.$this->analyticsStringField($endpoint, 'event_endpoint'));
        }
    }
}
