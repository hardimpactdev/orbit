<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\PromptsForRegistryEntities;
use App\Console\Commands\Concerns\RendersShowDetails;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Schedules\ShowScheduleRequest;
use App\Http\Gateway\Responses\Schedules\ScheduleShowResponse;
use App\Services\Schedules\SchedulePayload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('schedule:show
    {name? : Schedule name}
    {--app= : Filter by app scope}
    {--node= : Filter by node scope}
    {--json : Output JSON}')]
#[Description('Show one configured schedule')]
class ScheduleShowCommand extends Command
{
    use PromptsForRegistryEntities;
    use RendersShowDetails;

    private ?string $resolvedScheduleApp = null;

    private ?string $resolvedScheduleNode = null;

    public function handle(SchedulePayload $payload): int
    {
        $name = $this->resolveNameInput();

        if (is_int($name)) {
            return $name;
        }

        $onGateway = (bool) config('orbit.is_gateway', false);

        try {
            $data = $this->fetchSchedule($payload, $onGateway, $name);
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

    private function resolveNameInput(): string|int
    {
        $name = $this->stringArgument('name');

        if ($name !== null) {
            return $name;
        }

        if (! $this->isInteractiveInput()) {
            return $this->failCommand(
                'validation_failed',
                'The schedule name is required.',
                ['field' => 'name', 'reason' => 'missing'],
            );
        }

        try {
            $selection = $this->promptForVisibleSchedule(
                app: $this->stringOption('app'),
                node: $this->stringOption('node'),
            );

            if ($selection instanceof GatewayApiException) {
                return $this->failCommand(
                    code: $selection->errorCode() ?? 'gateway_unavailable',
                    message: $selection->getMessage(),
                    meta: $selection->errorMeta(),
                );
            }

            $this->resolvedScheduleApp = $selection['app'];
            $this->resolvedScheduleNode = $selection['node'];

            return $selection['name'];
        } catch (PromptAborted) {
            return $this->failCommand('validation_failed', 'Operation cancelled.', []);
        }
    }

    /**
     * @return array{schedule: array<string, mixed>, meta: array<string, mixed>}
     */
    private function fetchSchedule(SchedulePayload $payload, bool $onGateway, string $name): array
    {
        $app = $this->resolvedScheduleApp ?? $this->stringOption('app');
        $node = $this->resolvedScheduleNode ?? $this->stringOption('node');

        if ($onGateway) {
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
        $this->renderShowDetails('Schedule: '.(string) ($schedule['name'] ?? ''), [
            'Scope' => $schedule['scope'] ?? null,
            'Target' => $this->targetName($schedule),
            'Node' => $this->targetNode($schedule),
            'Interval' => $schedule['interval'] ?? null,
            'Timezone' => $schedule['timezone'] ?? null,
            'Execution' => $this->executionLabel($schedule),
            'Enabled' => ($schedule['enabled'] ?? false) === true,
            'Status' => $schedule['status'] ?? null,
            'Last run' => $this->lastRunLabel($schedule['last_run'] ?? null),
        ]);
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

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }
}
