<?php

declare(strict_types=1);

namespace App\Commands\Schedule;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class ScheduleListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'schedule:list
        {--instance= : Filter by instance (app.instance; bare project only when unambiguous)}
        {--node= : Filter by node scope}
        {--json}';

    #[\Override]
    protected $description = 'List configured schedules.';

    public function handle(): int
    {
        if ($this->hasMutuallyExclusiveOptions('instance', 'node')) {
            return $this->renderFailure(
                'validation_failed',
                'The schedule filters are mutually exclusive.',
                ['fields' => ['instance', 'node']],
            );
        }

        try {
            $response = $this->gatewayGet('/api/schedules', $this->filledQuery([
                'instance' => $this->stringOption('instance'),
                'node' => $this->stringOption('node'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $schedules = $this->schedulesFromGatewayResponse($response);

        if ($schedules === []) {
            $this->line($this->emptyState());

            return self::SUCCESS;
        }

        table(
            headers: ['NAME', 'SCOPE', 'TARGET', 'NODE', 'INTERVAL', 'EXECUTION', 'LAST RUN', 'STATUS'],
            rows: array_map(fn (array $schedule): array => [
                $this->scheduleString($schedule, 'name'),
                $this->scheduleString($schedule, 'scope'),
                $this->nested($schedule, 'target', 'name'),
                $this->nested($schedule, 'target', 'node'),
                $this->scheduleString($schedule, 'interval'),
                $this->executionLabel($schedule['execution'] ?? null),
                $this->lastRunLabel($schedule['last_run'] ?? null),
                $this->scheduleString($schedule, 'status'),
            ], $schedules),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function schedulesFromGatewayResponse(array $response): array
    {
        $schedules = $response['success']['data']['schedules'] ?? null;

        if (! is_array($schedules)) {
            return [];
        }

        return array_values(array_filter($schedules, is_array(...)));
    }

    private function emptyState(): string
    {
        $instance = $this->stringOption('instance');
        $node = $this->stringOption('node');

        if ($instance !== null) {
            return "No schedules found for instance {$instance}.";
        }

        if ($node !== null) {
            return "No schedules found for node {$node}.";
        }

        return 'No schedules found.';
    }

    private function executionLabel(mixed $execution): string
    {
        if (! is_array($execution)) {
            return '—';
        }

        $type = is_scalar($execution['type'] ?? null) ? (string) $execution['type'] : null;
        $value = is_scalar($execution['value'] ?? null) ? (string) $execution['value'] : null;

        return $type !== null && $value !== null ? "{$type}: {$value}" : $value ?? $type ?? '—';
    }

    private function lastRunLabel(mixed $lastRun): string
    {
        if (! is_array($lastRun)) {
            return '—';
        }

        $status = is_scalar($lastRun['status'] ?? null) ? (string) $lastRun['status'] : 'unknown';
        $finished = is_scalar($lastRun['finished_at'] ?? null) ? (string) $lastRun['finished_at'] : null;

        return $finished === null || $finished === '' ? $status : "{$status}, finished {$finished}";
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function nested(array $schedule, string $key, string $childKey): string
    {
        $value = $schedule[$key] ?? null;

        if (! is_array($value)) {
            return '—';
        }

        $child = $value[$childKey] ?? null;

        if (is_scalar($child) && (string) $child !== '') {
            return (string) $child;
        }

        return '—';
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function scheduleString(array $schedule, string $key): string
    {
        $value = $schedule[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }
}
