<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Schedules\RemoveSchedule;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Schedules\RemoveScheduleRequest;
use App\Http\Gateway\Responses\Schedules\ScheduleRemoveResponse;
use App\Services\Nodes\CallerRoleResolver;
use App\Services\Schedules\SchedulePayload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\confirm;

#[Signature('schedule:remove
    {name : Schedule name}
    {--app= : Filter by app scope}
    {--node= : Filter by node scope}
    {--force : Confirm destructive operation without prompting}
    {--json : Output JSON}')]
#[Description('Remove a recurring schedule')]
class ScheduleRemoveCommand extends Command
{
    use WithSpinner;
    use WithStepTree;

    public function handle(SchedulePayload $payload, RemoveSchedule $removeSchedule, CallerRoleResolver $callerRoleResolver): int
    {
        $callerRole = $callerRoleResolver->resolve();

        $consent = $this->confirmRemoval((string) $this->argument('name'));

        if (is_int($consent)) {
            return $consent;
        }

        $result = null;
        $failure = null;
        $operation = function () use ($callerRole, $payload, $removeSchedule, &$result, &$failure): string {
            try {
                if ($callerRole !== 'gateway') {
                    $result = $this->forwardRemoveResult();

                    return 'gateway accepted';
                }

                $schedule = $payload->find((string) $this->argument('name'), $this->stringOption('app'), $this->stringOption('node'));
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
    private function forwardRemoveResult(): array
    {
        /** @var ScheduleRemoveResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new RemoveScheduleRequest(
                name: (string) $this->argument('name'),
                app: $this->stringOption('app'),
                node: $this->stringOption('node'),
            ))
            ->dto();

        return ['data' => $dto->data, 'meta' => $dto->meta];
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

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }
}
