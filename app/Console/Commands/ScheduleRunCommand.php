<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Schedules\RunSchedule;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Schedules\RunScheduleRequest;
use App\Http\Gateway\Responses\Schedules\ScheduleManualRunResponse;
use App\Services\Nodes\CallerRoleResolver;
use App\Services\Schedules\SchedulePayload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('schedule:run
    {name : Schedule name}
    {--app= : Filter by app scope}
    {--node= : Filter by node scope}
    {--json : Output JSON}')]
#[Description('Run one configured schedule immediately')]
class ScheduleRunCommand extends Command
{
    use WithSpinner;
    use WithStepTree;

    public function handle(SchedulePayload $payload, RunSchedule $runSchedule, CallerRoleResolver $callerRoleResolver): int
    {
        $callerRole = $callerRoleResolver->resolve();

        if ($callerRole === 'unknown') {
            return $this->failCommand('caller_role_not_allowed', 'The local Orbit caller role could not be resolved.', [
                'caller_role' => 'unknown',
            ]);
        }

        $result = null;
        $failure = null;
        $operation = function () use ($callerRole, $payload, $runSchedule, &$result, &$failure): string {
            try {
                if ($callerRole !== 'gateway') {
                    $result = $this->forwardRunResult();

                    return 'gateway accepted';
                }

                $schedule = $payload->find((string) $this->argument('name'), $this->stringOption('app'), $this->stringOption('node'));
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
    private function forwardRunResult(): array
    {
        /** @var ScheduleManualRunResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new RunScheduleRequest(
                name: (string) $this->argument('name'),
                app: $this->stringOption('app'),
                node: $this->stringOption('node'),
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
