<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class WorkspaceTeardownStepListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'workspace-teardown-step:list
        {--app= : Parent app slug}
        {--json}';

    #[\Override]
    protected $description = 'List workspace teardown steps for an app.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayGet('/api/workspaces/steps/teardown', $this->stepQuery());
        } catch (GatewayApiException $exception) {
            return $this->renderFailure($exception->cliFailureCode(), $exception->getMessage());
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $steps = $this->stepsFromGatewayResponse($response);
        $app = $this->resolvedAppLabel($steps);

        if ($steps === []) {
            $this->line("No teardown steps defined for {$app}.");

            return self::SUCCESS;
        }

        $this->line("Teardown steps for {$app}:");

        table(
            headers: ['ID', 'ORDER', 'COMMAND', 'TIMEOUT'],
            rows: array_map(fn (array $step): array => [
                $this->stepString($step, 'id'),
                $this->stepString($step, 'order'),
                $this->stepString($step, 'command'),
                $this->stepTimeout($step),
            ], $steps),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function stepsFromGatewayResponse(array $response): array
    {
        $steps = $response['success']['data']['steps'] ?? null;

        if (! is_array($steps)) {
            return [];
        }

        return array_values(array_filter($steps, is_array(...)));
    }

    /**
     * Resolve the app slug for the header and empty-state prose. The payload
     * carries the resolved slug on each step; fall back to the caller-supplied
     * filter or local orbit marker when the result set is empty.
     *
     * @param  list<array<string, mixed>>  $steps
     */
    private function resolvedAppLabel(array $steps): string
    {
        $first = $steps[0] ?? null;

        if (is_array($first) && is_scalar($first['app'] ?? null) && (string) $first['app'] !== '') {
            return (string) $first['app'];
        }

        return $this->stringOption('app') ?? $this->appFromOrbitMarker() ?? '—';
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function stepTimeout(array $step): string
    {
        $timeout = $step['timeout_seconds'] ?? null;

        if (is_scalar($timeout) && (string) $timeout !== '') {
            return "{$timeout}s";
        }

        return '—';
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function stepString(array $step, string $key): string
    {
        $value = $step[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }

    /**
     * @return array<string, mixed>
     */
    private function stepQuery(): array
    {
        $app = $this->stringOption('app') ?? $this->appFromOrbitMarker();

        if ($app !== null) {
            return ['app' => $app];
        }

        $hostCwd = $this->hostCwd();

        return $hostCwd === null ? [] : ['path' => $hostCwd];
    }
}
