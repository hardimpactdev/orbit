<?php

declare(strict_types=1);

namespace App\Commands\Schedule;

use App\Commands\Concerns\PromptsForGatewayRegistryEntities;
use App\Commands\Concerns\RendersShowDetails;
use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Commands\Schedule\Concerns\ResolvesScheduleSelection;
use App\Exceptions\GatewayApiException;

final class ScheduleShowCommand extends GatewayCommand
{
    use PromptsForGatewayRegistryEntities;
    use RendersShowDetails;
    use ResolvesHostContext;
    use ResolvesScheduleSelection;

    #[\Override]
    protected $signature = 'schedule:show
        {name? : Schedule name}
        {--instance= : Filter by instance (app.instance; bare project only when unambiguous)}
        {--node= : Filter by node scope}
        {--json}';

    #[\Override]
    protected $description = 'Show one configured schedule.';

    public function handle(): int
    {
        if ($this->hasMutuallyExclusiveOptions('instance', 'node')) {
            return $this->renderFailure(
                'validation_failed',
                'The schedule filters are mutually exclusive.',
                ['fields' => ['instance', 'node']],
            );
        }

        $name = $this->resolveScheduleName();

        if (is_int($name)) {
            return $name;
        }

        try {
            $response = $this->gatewayGet('/api/schedules/'.rawurlencode($name), $this->filledQuery([
                'instance' => $this->resolvedScheduleInstance(),
                'node' => $this->resolvedScheduleNode(),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $schedule = $this->scheduleFromGatewayResponse($response);

        if ($schedule === null) {
            return $this->renderFailure('gateway_unavailable', 'Gateway response missing required schedule data.');
        }

        $this->renderSchedule($schedule);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    private function scheduleFromGatewayResponse(array $response): ?array
    {
        $schedule = $this->registrySuccessData($response)['schedule'] ?? null;

        return is_array($schedule) ? $schedule : null;
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function renderSchedule(array $schedule): void
    {
        $target = is_array($schedule['target'] ?? null) ? $schedule['target'] : [];
        $execution = is_array($schedule['execution'] ?? null) ? $schedule['execution'] : [];

        $name = is_scalar($schedule['name'] ?? null) ? (string) $schedule['name'] : 'unknown';

        $this->renderShowDetails("Schedule: {$name}", [
            'Scope' => $schedule['scope'] ?? null,
            'Target' => $target['name'] ?? null,
            'Node' => $target['node'] ?? null,
            'Interval' => $schedule['interval'] ?? null,
            'Timezone' => $schedule['timezone'] ?? null,
            'Execution' => $this->executionLabel($execution),
            'Timeout' => is_int($execution['timeout_seconds'] ?? null) ? $execution['timeout_seconds'].'s' : null,
            'Enabled' => $schedule['enabled'] ?? null,
            'Status' => $schedule['status'] ?? null,
            'Last run' => $this->lastRunLabel($schedule['last_run'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $execution
     */
    private function executionLabel(array $execution): string
    {
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
}
