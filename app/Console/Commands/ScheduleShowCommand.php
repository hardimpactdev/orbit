<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Schedules\ShowScheduleRequest;
use App\Http\Gateway\Responses\Schedules\ScheduleShowResponse;
use App\Services\Nodes\CallerRoleResolver;
use App\Services\Schedules\SchedulePayload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('schedule:show
    {name : Schedule name}
    {--app= : Filter by app scope}
    {--node= : Filter by node scope}
    {--json : Output JSON}')]
#[Description('Show one configured schedule')]
class ScheduleShowCommand extends Command
{
    public function handle(SchedulePayload $payload, CallerRoleResolver $callerRoleResolver): int
    {
        $callerRole = $callerRoleResolver->resolve();

        try {
            $data = $this->fetchSchedule($payload, $callerRole);
        } catch (GatewayApiException $e) {
            return $this->failCommand($e->errorCode() ?? 'gateway_unavailable', $e->getMessage(), $e->errorMeta());
        } catch (Throwable) {
            return $this->failCommand('gateway_unavailable', 'Gateway connection is required to show schedules.', []);
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['schedule' => $data['schedule']], $data['meta']);
        }

        $this->renderHuman($data['schedule']);

        return self::SUCCESS;
    }

    /**
     * @return array{schedule: array<string, mixed>, meta: array<string, mixed>}
     */
    private function fetchSchedule(SchedulePayload $payload, string $callerRole): array
    {
        $name = (string) $this->argument('name');
        $app = $this->stringOption('app');
        $node = $this->stringOption('node');

        if ($callerRole === 'gateway') {
            return $payload->show($name, $app, $node);
        }

        /** @var ScheduleShowResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new ShowScheduleRequest(name: $name, app: $app, node: $node))
            ->dto();

        return [
            'schedule' => $dto->schedule,
            'meta' => $dto->meta,
        ];
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function renderHuman(array $schedule): void
    {
        $this->line((string) ($schedule['name'] ?? ''));
        $this->newLine();
        $this->line('Scope: '.$this->value($schedule['scope'] ?? null));
        $this->line('Target: '.$this->targetName($schedule));
        $this->line('Node: '.$this->targetNode($schedule));
        $this->line('Interval: '.$this->value($schedule['interval'] ?? null));
        $this->line('Timezone: '.$this->value($schedule['timezone'] ?? null));
        $this->line('Execution: '.$this->executionLabel($schedule));
        $this->line('Enabled: '.(($schedule['enabled'] ?? false) === true ? 'yes' : 'no'));
        $this->line('Status: '.$this->value($schedule['status'] ?? null));
        $this->line('Last Run: '.$this->lastRunLabel($schedule['last_run'] ?? null));
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function targetName(array $schedule): string
    {
        $target = $schedule['target'] ?? [];

        return is_array($target) && is_string($target['name'] ?? null) ? $target['name'] : '-';
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function targetNode(array $schedule): string
    {
        $target = $schedule['target'] ?? [];

        return is_array($target) && is_string($target['node'] ?? null) ? $target['node'] : '-';
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function executionLabel(array $schedule): string
    {
        $execution = $schedule['execution'] ?? [];

        if (! is_array($execution)) {
            return '-';
        }

        $type = is_string($execution['type'] ?? null) ? $execution['type'] : '';
        $value = is_string($execution['value'] ?? null) ? $execution['value'] : '';

        return $type === '' ? $this->value($value) : "{$type}: {$value}";
    }

    private function lastRunLabel(mixed $lastRun): string
    {
        if (! is_array($lastRun)) {
            return '-';
        }

        $status = is_string($lastRun['status'] ?? null) ? $lastRun['status'] : 'unknown';
        $exitCode = is_int($lastRun['exit_code'] ?? null) ? " exit {$lastRun['exit_code']}" : '';
        $startedAt = is_string($lastRun['started_at'] ?? null) ? $lastRun['started_at'] : null;

        return $startedAt === null ? "{$status}{$exitCode}" : "{$status}{$exitCode} at {$startedAt}";
    }

    private function value(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : '-';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function jsonSuccess(array $data, array $meta): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => $meta,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => empty($meta) ? (object) [] : $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }
}
