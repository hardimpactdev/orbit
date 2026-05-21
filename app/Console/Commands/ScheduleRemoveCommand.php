<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Schedules\RemoveSchedule;
use App\Concerns\PromptsForRegistryEntities;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Schedules\RemoveScheduleRequest;
use App\Http\Gateway\Responses\Schedules\ScheduleRemoveResponse;
use App\Services\Schedules\SchedulePayload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\confirm;

#[Signature('schedule:remove
    {name? : Schedule name}
    {--app= : Filter by app scope}
    {--node= : Filter by node scope}
    {--force : Confirm destructive operation without prompting}
    {--json : Output JSON}')]
#[Description('Remove a recurring schedule')]
class ScheduleRemoveCommand extends Command
{
    use PromptsForRegistryEntities;
    use WithSpinner;
    use WithStepTree;

    private ?string $resolvedScheduleApp = null;

    private ?string $resolvedScheduleNode = null;

    public function handle(SchedulePayload $payload, RemoveSchedule $removeSchedule): int
    {
        $onGateway = (bool) config('orbit.is_gateway', false);
        $name = $this->resolveNameInput();

        if (is_int($name)) {
            return $name;
        }

        $consent = $this->confirmRemoval($name);

        if (is_int($consent)) {
            return $consent;
        }

        $result = null;
        $failure = null;
        $operation = function () use ($onGateway, $payload, $removeSchedule, $name, &$result, &$failure): string {
            try {
                if (! $onGateway) {
                    $result = $this->forwardRemoveResult($name);

                    return 'gateway accepted';
                }

                $schedule = $payload->find($name, $this->resolvedScheduleApp(), $this->resolvedScheduleNode());
                $result = $removeSchedule->handle($schedule);

                return 'scheduler pickup confirmed';
            } catch (GatewayApiException $e) {
                $failure = [
                    'code' => $e->errorCode() ?? 'gateway_unavailable',
                    'message' => $e->getMessage(),
                    'meta' => $e->errorMeta(),
                    'data' => $e->errorData(),
                ];

                return "fail:{$failure['message']}";
            } catch (Throwable) {
                $failure = [
                    'code' => 'gateway_unavailable',
                    'message' => 'Gateway connection is required to remove schedules.',
                    'meta' => [],
                    'data' => [],
                ];

                return "fail:{$failure['message']}";
            }
        };

        if (! $this->wantsJson()) {
            $exitCode = $this->runStepTree('Removing Schedule', [
                [
                    'label' => 'Resolve schedule',
                    'doneLabel' => 'Resolved schedule',
                    'run' => fn (): string => 'schedule resolved',
                ],
                [
                    'label' => 'Apply and verify removal',
                    'doneLabel' => 'Applied and verified removal',
                    'run' => fn (): string => 'removal accepted',
                ],
                [
                    'label' => 'Notify Orbit Scheduler',
                    'doneLabel' => 'Notified Orbit Scheduler',
                    'run' => $operation,
                ],
            ], doneFooter: 'Schedule removed', failFooter: 'Schedule remove failed');

            if ($exitCode !== self::SUCCESS) {
                return is_array($failure)
                    ? $this->failCommand($failure['code'], $failure['message'], $failure['meta'], $failure['data'])
                    : self::FAILURE;
            }
        } else {
            $operation();

            if (is_array($failure)) {
                return $this->failCommand($failure['code'], $failure['message'], $failure['meta'], $failure['data']);
            }
        }

        return $this->successPayload($result['data'], $result['meta']);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function forwardRemoveResult(string $name): array
    {
        /** @var ScheduleRemoveResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new RemoveScheduleRequest(
                name: $name,
                app: $this->resolvedScheduleApp(),
                node: $this->resolvedScheduleNode(),
            ))
            ->dto();

        return ['data' => $dto->data, 'meta' => $dto->meta];
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

    private function confirmRemoval(string $name): ?int
    {
        if ($this->option('force') === true) {
            return null;
        }

        if (! $this->isInteractiveInput()) {
            return $this->failCommand('destructive_consent_required', 'Use --force to remove this schedule.', [
                'field' => 'force',
            ]);
        }

        if (confirm(label: "Remove schedule '{$name}'?", default: false)) {
            return null;
        }

        return $this->failCommand('destructive_consent_required', 'No schedule was removed.', [
            'field' => 'force',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function successPayload(array $data, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => $data,
                    'meta' => $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $schedule = is_array($data['schedule'] ?? null) ? $data['schedule'] : [];
        $this->line("Schedule '".(string) ($schedule['name'] ?? '')."' removed.");
        $this->line('Scheduler pickup: '.(string) ($meta['scheduler_pickup'] ?? 'unknown'));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    private function failCommand(string $code, string $message, array $meta, array $data = []): int
    {
        if ($this->wantsJson()) {
            $error = [
                'code' => $code,
                'message' => $message,
                'meta' => empty($meta) ? (object) [] : $meta,
            ];

            if ($data !== []) {
                $error['data'] = $data;
            }

            $this->line(json_encode(['error' => $error], JSON_THROW_ON_ERROR));

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

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function resolvedScheduleApp(): ?string
    {
        return $this->resolvedScheduleApp ?? $this->stringOption('app');
    }

    private function resolvedScheduleNode(): ?string
    {
        return $this->resolvedScheduleNode ?? $this->stringOption('node');
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }
}
