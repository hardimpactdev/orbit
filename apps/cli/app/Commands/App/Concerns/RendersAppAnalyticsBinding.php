<?php

declare(strict_types=1);

namespace App\Commands\App\Concerns;

use Illuminate\Console\Command;

/**
 * @mixin Command
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
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
        $this->line('  instance: '.$this->analyticsStringField($binding, 'instance'));
        $this->line('  enabled: '.($this->analyticsBoolField($binding, 'enabled') ? 'true' : 'false'));

        if (array_key_exists('site_domain', $binding)) {
            $this->line('  site_domain: '.$this->analyticsStringField($binding, 'site_domain'));
        }

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

            if (array_key_exists('script_url', $endpoint)) {
                $this->line('      script_url: '.$this->analyticsStringField($endpoint, 'script_url'));
            }

            $this->line('      event_endpoint: '.$this->analyticsStringField($endpoint, 'event_endpoint'));

            if (array_key_exists('data_domain', $endpoint)) {
                $this->line('      data_domain: '.$this->analyticsStringField($endpoint, 'data_domain'));
            }

            if (array_key_exists('snippet', $endpoint)) {
                $this->line('      snippet: '.$this->analyticsStringField($endpoint, 'snippet'));
            }
        }
    }

    /** @param array<array-key, mixed> $response */
    private function renderAnalyticsEnableGuidance(array $response): void
    {
        $payload = $this->analyticsResponseData($response);
        $enactment = $payload['route_enactment'] ?? null;
        $dns = $payload['dns_expectation'] ?? null;
        $readiness = $payload['public_readiness'] ?? null;

        $this->line('route_enactment:');
        $this->line('  status: '.$this->analyticsStringField(is_array($enactment) ? $enactment : [], 'status'));

        if (is_array($enactment)) {
            $this->renderAnalyticsNamedList('placements', $this->analyticsListField($enactment, 'placements'));
        }

        $this->line('dns_expectation:');
        $this->line('  ingress_node: '.$this->analyticsStringField(is_array($dns) ? $dns : [], 'ingress_node'));

        if (is_array($dns)) {
            $this->renderAnalyticsDnsTargets($dns['targets'] ?? null);
            $this->line(
                '  provider_managed: '.($this->analyticsBoolField($dns, 'provider_managed') ? 'true' : 'false'),
            );
        }

        $this->line('public_readiness:');
        $this->line('  status: '.$this->analyticsStringField(is_array($readiness) ? $readiness : [], 'status'));
        $this->renderAnalyticsNamedList(
            'remaining_actions',
            $this->analyticsListField($payload, 'remaining_actions'),
            0,
        );
    }

    /** @param mixed $targets */
    private function renderAnalyticsDnsTargets(mixed $targets): void
    {
        if (! is_array($targets) || $targets === []) {
            $this->line('  targets: []');

            return;
        }

        $this->line('  targets:');

        foreach ($targets as $target) {
            if (! is_array($target)) {
                continue;
            }

            $this->line('    - type: '.$this->analyticsStringField($target, 'type'));
            $this->line('      value: '.$this->analyticsStringField($target, 'value'));
        }
    }

    /** @param list<string> $values */
    private function renderAnalyticsNamedList(string $name, array $values, int $indent = 2): void
    {
        $prefix = str_repeat(' ', max(0, $indent));

        if ($values === []) {
            $this->line("{$prefix}{$name}: []");

            return;
        }

        $this->line("{$prefix}{$name}:");

        foreach ($values as $value) {
            $this->line("{$prefix}  - {$value}");
        }
    }

    /**
     * @param  array<array-key, mixed>  $response
     * @return array<array-key, mixed>
     */
    private function analyticsResponseData(array $response): array
    {
        $success = $response['success'] ?? null;
        $data = is_array($success) ? $success['data'] ?? null : null;

        return is_array($data) ? $data : $response;
    }
}
