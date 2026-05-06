<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Schedules\ListSchedulesRequest;
use App\Http\Gateway\Responses\Schedules\ScheduleListResponse;
use App\Services\Nodes\CallerRoleResolver;
use App\Services\Schedules\SchedulePayload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('schedule:list
    {--app= : Filter by app scope}
    {--node= : Filter by node scope}
    {--json : Output JSON}')]
#[Description('List configured schedules')]
class ScheduleListCommand extends Command
{
    public function handle(SchedulePayload $payload, CallerRoleResolver $callerRoleResolver): int
    {
        $callerRole = $callerRoleResolver->resolve();

        if ($callerRole === 'unknown') {
            return $this->failCommand('caller_role_not_allowed', 'The local Orbit caller role could not be resolved.', [
                'caller_role' => 'unknown',
            ]);
        }

        try {
            $data = $this->fetchSchedules($payload, $callerRole);
        } catch (GatewayApiException $e) {
            return $this->failCommand($e->errorCode() ?? 'gateway_unavailable', $e->getMessage(), $e->errorMeta());
        } catch (Throwable) {
            return $this->failCommand('gateway_unavailable', 'Gateway connection is required to list schedules.', []);
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['schedules' => $data['schedules']], $data['meta']);
        }

        $this->renderHuman($data);

        return self::SUCCESS;
    }

    /**
     * @return array{schedules: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function fetchSchedules(SchedulePayload $payload, string $callerRole): array
    {
        $app = $this->stringOption('app');
        $node = $this->stringOption('node');

        if ($callerRole === 'gateway') {
            return $payload->list($app, $node);
        }

        /** @var ScheduleListResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new ListSchedulesRequest(app: $app, node: $node))
            ->dto();

        return [
            'schedules' => $dto->schedules,
            'meta' => $dto->meta,
        ];
    }

    /**
     * @param  array{schedules: list<array<string, mixed>>, meta: array<string, mixed>}  $data
     */
    private function renderHuman(array $data): void
    {
        $schedules = $data['schedules'];

        if ($schedules === []) {
            $this->line($this->emptyMessage($data['meta']));

            return;
        }

        $this->table(['Name', 'Scope', 'Target', 'Node', 'Interval', 'Execution', 'Last Run', 'Status'], array_map(
            fn (array $schedule): array => [
                $schedule['name'] ?? '',
                $schedule['scope'] ?? '',
                $this->targetName($schedule),
                $this->targetNode($schedule),
                $schedule['interval'] ?? '',
                $this->executionLabel($schedule),
                $this->lastRunLabel($schedule['last_run'] ?? null),
                $schedule['status'] ?? '',
            ],
            $schedules,
        ));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function emptyMessage(array $meta): string
    {
        if (is_string($meta['app'] ?? null)) {
            return "No schedules found for app {$meta['app']}.";
        }

        if (is_string($meta['node'] ?? null)) {
            return "No schedules found for node {$meta['node']}.";
        }

        return 'No schedules found.';
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function targetName(array $schedule): string
    {
        $target = $schedule['target'] ?? [];

        return is_array($target) && is_string($target['name'] ?? null) ? $target['name'] : '';
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
            return '';
        }

        $type = is_string($execution['type'] ?? null) ? $execution['type'] : '';
        $value = is_string($execution['value'] ?? null) ? $execution['value'] : '';

        return $type === '' ? $value : "{$type}: {$value}";
    }

    private function lastRunLabel(mixed $lastRun): string
    {
        if (! is_array($lastRun)) {
            return '-';
        }

        $status = is_string($lastRun['status'] ?? null) ? $lastRun['status'] : 'unknown';
        $startedAt = is_string($lastRun['started_at'] ?? null) ? $lastRun['started_at'] : null;

        return $startedAt === null ? $status : "{$status} at {$startedAt}";
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
