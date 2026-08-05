<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use Illuminate\Support\Carbon;
use Throwable;

use function Laravel\Prompts\table;

final class WorkspaceHistoryCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'workspace:history
        {name? : Workspace name}
        {--instance= : Instance selector (app.instance)}
        {--limit= : Maximum number of runs to return}
        {--since= : ISO 8601 lower started_at bound}
        {--until= : ISO 8601 exclusive upper started_at bound}
        {--json}';

    #[\Override]
    protected $description = 'Show workspace run history.';

    public function handle(): int
    {
        $name = $this->stringArgument('name');

        if ($name === null) {
            return $this->historyFromPath();
        }

        $path = '/api/workspaces/'.rawurlencode($name).'/history';

        try {
            $response = $this->gatewayGet($path, $this->historyQuery());
        } catch (GatewayApiException $exception) {
            return $this->renderFailure($exception->cliFailureCode(), $exception->getMessage());
        }

        return $this->renderHistory($response);
    }

    private function historyFromPath(): int
    {
        $hostCwd = $this->hostCwd();

        if ($hostCwd === null) {
            return $this->renderFailure('validation_failed', 'Workspace name is required.', ['field' => 'name']);
        }

        try {
            $response = $this->gatewayGet('/api/workspaces/history/resolve-by-path', [
                ...$this->historyQuery(),
                'path' => $hostCwd,
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderFailure($exception->cliFailureCode(), $exception->getMessage());
        }

        return $this->renderHistory($response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderHistory(array $response): int
    {
        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $runs = $this->runsFromGatewayResponse($response);

        if ($runs === []) {
            $this->line('No run history found.');

            return self::SUCCESS;
        }

        $this->line('Workspace History: '.$this->historyHeader($runs));
        $this->newLine();

        table(
            headers: ['ID', 'ACTION', 'STATUS', 'STARTED', 'DURATION'],
            rows: array_map(fn (array $run): array => [
                $this->runString($run, 'id'),
                $this->runString($run, 'action'),
                $this->runString($run, 'status'),
                $this->relativeStarted($run),
                $this->runDuration($run),
            ], $runs),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function runsFromGatewayResponse(array $response): array
    {
        $runs = $response['success']['data']['runs'] ?? null;

        if (! is_array($runs)) {
            return [];
        }

        return array_values(array_filter($runs, is_array(...)));
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     */
    private function historyHeader(array $runs): string
    {
        $first = $runs[0];
        $app = $this->runString($first, 'app');
        $workspace = $this->runString($first, 'workspace');

        return "{$app}/{$workspace}";
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function relativeStarted(array $run): string
    {
        $startedAt = $this->parseTimestamp($run['started_at'] ?? null);

        return $startedAt instanceof Carbon ? $startedAt->diffForHumans() : '—';
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function runDuration(array $run): string
    {
        $startedAt = $this->parseTimestamp($run['started_at'] ?? null);
        $finishedAt = $this->parseTimestamp($run['finished_at'] ?? null);

        if (! $startedAt instanceof Carbon || ! $finishedAt instanceof Carbon) {
            return '—';
        }

        $seconds = $startedAt->diffInSeconds($finishedAt, absolute: true);

        if ($seconds < 1) {
            return '< 1s';
        }

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        return floor($seconds / 60).'m';
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function runString(array $run, string $key): string
    {
        $value = $run[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }

    /**
     * @return array<string, mixed>
     */
    private function historyQuery(): array
    {
        return $this->filledQuery([
            'instance' => $this->stringOption('instance'),
            'limit' => $this->stringOption('limit'),
            'since' => $this->stringOption('since'),
            'until' => $this->stringOption('until'),
        ]);
    }
}
