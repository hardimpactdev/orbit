<?php

declare(strict_types=1);

namespace App\Commands\Process;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class ProcessListCommand extends ProcessGatewayCommand
{
    /**
     * Latest durable lifecycle event types map onto a process runtime status
     * label; absent events render the documented empty cell.
     */
    private const array StatusForEvent = [
        'started' => 'running',
        'stopped' => 'stopped',
        'crashed' => 'crashed',
    ];

    private const array KnownStatuses = ['running', 'stopped', 'crashed', 'unknown'];

    #[\Override]
    protected $signature = 'process:list
        {--app= : App-instance or workspace hostname (proxy_routes.domain)}
        {--node= : Owning node name}
        {--instance= : Instance selector}
        {--workspace= : Workspace name}
        {--json}';

    #[\Override]
    protected $description = 'List configured processes.';

    public function handle(): int
    {
        $appHostname = $this->stringOption('app');
        $node = $this->stringOption('node');
        $app =
            $node === null && $appHostname === null
                ? $this->stringOption('instance') ?? $this->instanceFromOrbitMarker()
                : $this->stringOption('instance');
        $workspace = $this->stringOption('workspace');

        $conflictFailure = $this->rejectConflictingProcessSelectors(
            appHostname: $appHostname,
            node: $node,
            instance: $app,
            workspace: $workspace,
        );

        if ($conflictFailure !== null) {
            return $conflictFailure;
        }

        try {
            $response = $this->gatewayGet('/api/processes', $this->filledQuery([
                'app' => $appHostname,
                'node' => $node,
                'instance' => $app,
                'workspace' => $workspace,
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $processes = $this->processesFromGatewayResponse($response);

        if ($processes === []) {
            $this->line('No processes found.');

            return self::SUCCESS;
        }

        $scope = $this->scopeLabel($response);

        if ($scope !== null) {
            $this->line("Processes for {$scope}");
            $this->newLine();
        }

        table(
            headers: ['KEY', 'LABEL', 'SERVICE', 'VERSION', 'ENDPOINT', 'COMMAND', 'RESTART', 'TOOL', 'STATUS'],
            rows: array_map(fn (array $process): array => [
                $this->processKey($process),
                $this->processLabel($process),
                $this->serviceString($process, 'service'),
                $this->serviceString($process, 'version'),
                $this->serviceEndpoint($process),
                $this->processString($process, 'command'),
                $this->processString($process, 'restart_policy'),
                $this->processString($process, 'tool'),
                $this->statusLabel($process),
            ], $processes),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function processesFromGatewayResponse(array $response): array
    {
        $processes = $response['success']['data']['processes'] ?? null;

        if (! is_array($processes)) {
            return [];
        }

        return array_values(array_filter($processes, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function scopeLabel(array $response): ?string
    {
        $context = $response['success']['data']['context'] ?? null;

        if (! is_array($context)) {
            return null;
        }

        $app = $context['app'] ?? null;
        $instance = $context['instance'] ?? null;
        $workspace = $context['workspace'] ?? null;
        $appLabel = is_scalar($app) && (string) $app !== ''
            ? (string) $app
            : null;

        if ($appLabel !== null && is_scalar($instance) && (string) $instance !== '') {
            $appLabel .= '.'.(string) $instance;
        }

        if (is_scalar($workspace) && (string) $workspace !== '') {
            return $appLabel !== null
                ? $appLabel.' / '.(string) $workspace
                : (string) $workspace;
        }

        if ($appLabel !== null) {
            return $appLabel;
        }

        $node = $context['node'] ?? null;

        return is_scalar($node) && (string) $node !== '' ? (string) $node : null;
    }

    /**
     * @param  array<string, mixed>  $process
     */
    private function statusLabel(array $process): string
    {
        $status = $process['status'] ?? null;

        if (is_string($status) && in_array($status, self::KnownStatuses, true)) {
            return $status === 'unknown' ? '—' : $status;
        }

        $event = $process['last_event'] ?? null;
        $type = is_array($event) ? $event['type'] ?? null : null;

        if (is_string($type) && isset(self::StatusForEvent[$type])) {
            return self::StatusForEvent[$type];
        }

        return '—';
    }

    /**
     * @param  array<string, mixed>  $process
     */
    /**
     * @param  array<string, mixed>  $process
     */
    private function processKey(array $process): string
    {
        $key = $process['key'] ?? $process['name'] ?? null;

        return is_string($key) && $key !== '' ? $key : '—';
    }

    /**
     * @param  array<string, mixed>  $process
     */
    private function processLabel(array $process): string
    {
        $label = $process['label'] ?? null;

        if (is_string($label) && $label !== '') {
            return $label;
        }

        return $this->processKey($process);
    }

    private function processString(array $process, string $key): string
    {
        $value = $process[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }

    /** @param  array<string, mixed>  $process */
    private function serviceString(array $process, string $key): string
    {
        $service = $process['service'] ?? null;
        $value = is_array($service) ? $service[$key] ?? null : null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : '—';
    }

    /** @param  array<string, mixed>  $process */
    private function serviceEndpoint(array $process): string
    {
        $service = $process['service'] ?? null;
        $endpoint = is_array($service) ? $service['endpoint'] ?? null : null;
        $host = is_array($endpoint) ? $endpoint['host'] ?? null : null;
        $port = is_array($endpoint) ? $endpoint['port'] ?? null : null;

        return is_string($host) && $host !== '' && is_int($port) && $port > 0
            ? "{$host}:{$port}"
            : '—';
    }
}
