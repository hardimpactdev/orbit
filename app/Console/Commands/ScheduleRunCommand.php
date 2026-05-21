<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Schedules\RunSchedule;
use App\Concerns\PromptsForRegistryEntities;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Schedules\RunScheduleRequest;
use App\Http\Gateway\Responses\Schedules\ScheduleManualRunResponse;
use App\Services\Schedules\SchedulePayload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('schedule:run
    {name? : Schedule name}
    {--app= : Filter by app scope}
    {--node= : Filter by node scope}
    {--json : Output JSON}')]
#[Description('Run one configured schedule immediately')]
class ScheduleRunCommand extends Command
{
    use PromptsForRegistryEntities;
    use WithSpinner;
    use WithStepTree;

    private ?string $resolvedScheduleApp = null;

    private ?string $resolvedScheduleNode = null;

    public function handle(SchedulePayload $payload, RunSchedule $runSchedule): int
    {
        $name = $this->resolveNameInput();

        if (is_int($name)) {
            return $name;
        }

        $onGateway = (bool) config('orbit.is_gateway', false);

        $result = null;
        $failure = null;
        $operation = function () use ($name, $onGateway, $payload, $runSchedule, &$result, &$failure): string {
            try {
                if (! $onGateway) {
                    $result = $this->forwardRunResult($name);

                    return 'gateway accepted';
                }

                $schedule = $payload->find($name, $this->resolvedScheduleApp(), $this->resolvedScheduleNode());
                $result = $runSchedule->handle($schedule);

                return 'scheduled command completed';
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
                    'message' => 'Gateway connection is required to run schedules.',
                    'meta' => [],
                    'data' => [],
                ];

                return "fail:{$failure['message']}";
            }
        };

        if (! $this->wantsJson()) {
            $exitCode = $this->runStepTree('Running Schedule', [
                [
                    'label' => 'Resolve schedule',
                    'doneLabel' => 'Resolved schedule',
                    'run' => fn (): string => 'schedule resolved',
                ],
                [
                    'label' => 'Open gateway execution',
                    'doneLabel' => 'Opened gateway execution',
                    'run' => fn (): string => 'execution opened',
                ],
                [
                    'label' => 'Run scheduled command',
                    'doneLabel' => 'Ran scheduled command',
                    'run' => $operation,
                ],
                [
                    'label' => 'Record run history',
                    'doneLabel' => 'Recorded run history',
                    'run' => fn (): string => 'history recorded',
                ],
            ], doneFooter: 'Schedule run completed', failFooter: 'Schedule run failed');

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
    private function forwardRunResult(string $name): array
    {
        /** @var ScheduleManualRunResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new RunScheduleRequest(
                name: $name,
                app: $this->resolvedScheduleApp(),
                node: $this->resolvedScheduleNode(),
            ))
            ->dto();

        return ['data' => $dto->data, 'meta' => $dto->meta];
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

        $run = is_array($data['run'] ?? null) ? $data['run'] : [];
        $output = is_array($data['output'] ?? null) ? $data['output'] : [];

        $this->line("Schedule '".(string) ($run['schedule'] ?? '')."' completed with exit ".(string) ($run['exit_code'] ?? 'unknown').'.');
        $this->line('Run ID: '.(string) ($run['id'] ?? 'unknown'));

        if (($output['stdout'] ?? '') !== '') {
            $this->line((string) $output['stdout']);
        }

        if (($output['stderr'] ?? '') !== '') {
            $this->line((string) $output['stderr']);
        }

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

    private function resolvedScheduleApp(): ?string
    {
        return $this->resolvedScheduleApp ?? $this->stringOption('app');
    }

    private function resolvedScheduleNode(): ?string
    {
        return $this->resolvedScheduleNode ?? $this->stringOption('node');
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
