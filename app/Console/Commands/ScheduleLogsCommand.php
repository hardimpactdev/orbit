<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\PromptsForRegistryEntities;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Schedules\ShowScheduleLogsRequest;
use App\Http\Gateway\Responses\Schedules\ScheduleLogsResponse;
use App\Services\Nodes\CallerRoleResolver;
use App\Services\Schedules\ScheduleLogsPayload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('schedule:logs
    {name? : Schedule name}
    {--app= : Filter by app scope}
    {--node= : Filter by node scope}
    {--run= : Run history id}
    {--lines=100 : Number of stdout/stderr lines}
    {--json : Output JSON}')]
#[Description('Show captured output for a schedule run')]
class ScheduleLogsCommand extends Command
{
    use PromptsForRegistryEntities;

    private ?string $resolvedScheduleApp = null;

    private ?string $resolvedScheduleNode = null;

    public function handle(ScheduleLogsPayload $payload, CallerRoleResolver $callerRoleResolver): int
    {
        $name = $this->resolveNameInput();

        if (is_int($name)) {
            return $name;
        }

        $callerRole = $callerRoleResolver->resolve();

        $run = $this->positiveIntegerOption('run');
        $lines = $this->positiveIntegerOption('lines') ?? 100;

        if ($run === false) {
            return $this->failValidation('run', 'The run id must be a positive integer.');
        }

        if ($lines === false) {
            return $this->failValidation('lines', 'The line limit must be a positive integer.');
        }

        try {
            if ($callerRole !== 'gateway') {
                return $this->forwardLogs($name, $run, $lines);
            }

            $result = $payload->forSchedule(
                name: $name,
                app: $this->resolvedScheduleApp(),
                node: $this->resolvedScheduleNode(),
                runId: $run,
                lines: $lines,
            );
        } catch (GatewayApiException $e) {
            return $this->failCommand($e->errorCode() ?? 'gateway_unavailable', $e->getMessage(), $e->errorMeta());
        } catch (Throwable) {
            return $this->failCommand('gateway_unavailable', 'Gateway connection is required to read schedule logs.', []);
        }

        return $this->successPayload($result['data'], $result['meta']);
    }

    private function forwardLogs(string $name, ?int $run, int $lines): int
    {
        /** @var ScheduleLogsResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new ShowScheduleLogsRequest(
                name: $name,
                app: $this->resolvedScheduleApp(),
                node: $this->resolvedScheduleNode(),
                run: $run,
                lines: $lines,
            ))
            ->dto();

        return $this->successPayload($dto->data, $dto->meta);
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
        $stdout = is_string($output['stdout'] ?? null) ? $output['stdout'] : '';
        $stderr = is_string($output['stderr'] ?? null) ? $output['stderr'] : '';

        $this->line('Schedule: '.(string) ($run['schedule'] ?? ''));
        $this->line('Run ID: '.(string) ($run['id'] ?? ''));
        $this->line('Status: '.(string) ($run['status'] ?? ''));
        $this->line('Exit Code: '.(string) ($run['exit_code'] ?? ''));
        $this->line('Started: '.(string) ($run['started_at'] ?? ''));
        $this->line('Finished: '.(string) ($run['finished_at'] ?? ''));
        $this->newLine();
        $this->line('stdout');
        $this->line($stdout === '' ? '-' : $stdout);
        $this->line('stderr');
        $this->line($stderr === '' ? '-' : $stderr);

        return self::SUCCESS;
    }

    private function failValidation(string $field, string $message): int
    {
        return $this->failCommand('validation_failed', $message, ['field' => $field]);
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

    private function positiveIntegerOption(string $key): int|false|null
    {
        $value = $this->option($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value < 1) {
            return false;
        }

        return (int) $value;
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
