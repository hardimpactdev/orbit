<?php

declare(strict_types=1);

namespace App\Commands\Process;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class ProcessListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    /**
     * Latest durable lifecycle event types map onto a process runtime status
     * label; absent events render the documented empty cell.
     */
    private const array StatusForEvent = [
        'started' => 'running',
        'stopped' => 'stopped',
        'crashed' => 'crashed',
    ];

    #[\Override]
    protected $signature = 'process:list
        {--node= : Owning node name}
        {--app= : Parent app slug}
        {--workspace= : Workspace name}
        {--json}';

    #[\Override]
    protected $description = 'List configured processes.';

    public function handle(): int
    {
        $node = $this->stringOption('node');
        $app = $node === null ? $this->stringOption('app') ?? $this->appFromOrbitMarker() : $this->stringOption('app');
        $workspace = $this->stringOption('workspace');

        if ($node !== null && ($app !== null || $workspace !== null)) {
            return $this->renderFailure('validation_failed', 'A node context cannot be combined with app or workspace context.', [
                'field' => 'context',
                'node' => $node,
                'app' => $app,
                'workspace' => $workspace,
            ]);
        }

        try {
            $response = $this->gatewayGet('/api/processes', $this->filledQuery([
                'node' => $node,
                'app' => $app,
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
            headers: ['NAME', 'COMMAND', 'RESTART', 'TOOL', 'STATUS'],
            rows: array_map(fn (array $process): array => [
                $this->processString($process, 'name'),
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
        $workspace = $context['workspace'] ?? null;

        if (is_scalar($workspace) && (string) $workspace !== '') {
            return is_scalar($app) && (string) $app !== ''
                ? (string) $app.' / '.(string) $workspace
                : (string) $workspace;
        }

        if (is_scalar($app) && (string) $app !== '') {
            return (string) $app;
        }

        $node = $context['node'] ?? null;

        return is_scalar($node) && (string) $node !== '' ? (string) $node : null;
    }

    /**
     * @param  array<string, mixed>  $process
     */
    private function statusLabel(array $process): string
    {
        $event = $process['last_event'] ?? null;
        $type = is_array($event) ? ($event['type'] ?? null) : null;

        if (is_string($type) && isset(self::StatusForEvent[$type])) {
            return self::StatusForEvent[$type];
        }

        return '—';
    }

    /**
     * @param  array<string, mixed>  $process
     */
    private function processString(array $process, string $key): string
    {
        $value = $process[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }
}
