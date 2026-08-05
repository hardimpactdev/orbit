<?php

declare(strict_types=1);

namespace App\Commands\Schedule;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use App\Exceptions\OrbitConfigStoreException;
use App\Services\OrbitConfigStore;
use RuntimeException;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

final class ScheduleAddCommand extends ScheduleGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'schedule:add
        {name? : Schedule name}
        {--command= : Command to execute}
        {--script= : Managed script path to execute}
        {--interval= : Portable interval expression}
        {--instance= : Target instance (app.instance; bare project only when unambiguous)}
        {--node= : Target node scope}
        {--timezone=UTC : IANA timezone}
        {--timeout=900 : Maximum execution time in seconds}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Add a recurring schedule.';

    public function handle(): int
    {
        $name = $this->resolveName();

        if ($name === null) {
            return $this->failValidation('name', 'The schedule name is required.');
        }

        $nameValidation = $this->validateName($name);

        if ($nameValidation !== null) {
            return $nameValidation;
        }

        $target = $this->resolveTarget();

        if (is_int($target)) {
            return $target;
        }

        $execution = $this->resolveExecutionSource();

        if (is_int($execution)) {
            return $execution;
        }

        $interval = $this->resolveInterval();

        if ($interval === null) {
            return $this->renderFailure('schedule.interval_invalid', 'The schedule interval is required.', [
                'field' => 'interval',
            ]);
        }

        $timezone = $this->stringOption('timezone') ?? 'UTC';

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            return $this->failValidation('timezone', 'The schedule timezone must be a valid IANA timezone.', [
                'value' => $timezone,
            ]);
        }

        $timeout = $this->resolveTimeout();

        if ($timeout === false) {
            return $this->failValidation('timeout', 'The schedule timeout must be between 1 and 86400 seconds.');
        }

        $payload = $this->filledQuery([
            'name' => $name,
            'instance' => $target['instance'],
            'node' => $target['node'],
            'interval' => $interval,
            'timezone' => $timezone,
            'timeout' => $timeout,
            'command' => $execution['command'],
            'script' => $execution['script'],
        ]);

        if ($this->wantsJson()) {
            try {
                $response = $this->addSchedule($payload);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderAddTree($name, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderAddTree(string $name, array $payload): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Adding Schedule',
            [
                ['label' => 'Validate schedule target'],
                ['label' => 'Validate execution source'],
                ['label' => 'Apply and verify schedule'],
                ['label' => 'Notify Orbit Scheduler'],
            ],
            work: function () use ($payload, &$response): array {
                return $response = $this->addScheduleForHuman($payload);
            },
            doneFooter: "Schedule '{$name}' added",
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderScheduleDetail($response);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function addSchedule(array $payload): array
    {
        return $this->gatewayPost('/api/schedules', $payload);
    }

    /**
     * Run the gateway write inside the progress tree, re-throwing gateway
     * failures with their operator-facing message so the failed footer renders
     * the documented prose rather than a JSON envelope.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function addScheduleForHuman(array $payload): array
    {
        try {
            return $this->addSchedule($payload);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * Render the documented schedule detail block: name, scope, target,
     * interval, timezone, execution source, enabled state, and the Orbit
     * Scheduler pickup result.
     *
     * @param  array<string, mixed>  $response
     */
    private function renderScheduleDetail(array $response): void
    {
        $data = $this->successData($response);
        $schedule = is_array($data['schedule'] ?? null) ? $data['schedule'] : [];

        if ($schedule !== []) {
            $this->line('  Name: '.$this->stringField($schedule, 'name'));
            $this->line('  Scope: '.$this->stringField($schedule, 'scope'));
            $this->line('  Target: '.$this->targetSummary($schedule));
            $this->line('  Interval: '.$this->stringField($schedule, 'interval'));
            $this->line('  Timezone: '.$this->stringField($schedule, 'timezone'));
            $this->line('  Execution: '.$this->executionSummary($schedule));
            $execution = is_array($schedule['execution'] ?? null) ? $schedule['execution'] : [];
            $this->line(
                '  Timeout: '.(is_int($execution['timeout_seconds'] ?? null) ? $execution['timeout_seconds'].'s' : '—'),
            );
            $this->line('  Enabled: '.(($schedule['enabled'] ?? null) === true ? 'true' : 'false'));
        }

        $result = is_array($data['result'] ?? null) ? $data['result'] : [];
        $this->line('  Scheduler pickup: '.$this->stringField($result, 'scheduler_pickup'));
    }

    private function resolveName(): ?string
    {
        $name = $this->stringArgument('name');

        if ($name !== null) {
            return $name;
        }

        if ($this->isInteractiveInput()) {
            return trim(text(label: 'Schedule name', required: true));
        }

        return null;
    }

    private function resolveTimeout(): int|false
    {
        $value = $this->stringOption('timeout') ?? '900';

        if (! ctype_digit($value)) {
            return false;
        }

        $timeout = (int) $value;

        return $timeout >= 1 && $timeout <= 86_400 ? $timeout : false;
    }

    private function validateName(string $name): ?int
    {
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $name)) {
            return null;
        }

        return $this->failValidation(
            'name',
            'The schedule name must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.',
            [
                'value' => $name,
            ],
        );
    }

    /**
     * @return array{instance: ?string, node: ?string}|int
     */
    private function resolveTarget(): array|int
    {
        $instance = $this->stringOption('instance');
        $node = $this->stringOption('node');

        if ($instance !== null && $node !== null) {
            return $this->failValidation('target', 'Exactly one schedule target is required.', ['fields' => [
                'instance',
                'node',
            ]]);
        }

        if ($instance !== null || $node !== null) {
            return [
                'instance' => $instance,
                'node' => $node,
            ];
        }

        try {
            $defaultNode = app(OrbitConfigStore::class)->defaultNode();
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
        }

        if ($defaultNode !== null) {
            return [
                'instance' => null,
                'node' => $defaultNode,
            ];
        }

        if (! $this->isInteractiveInput()) {
            return $this->failValidation('target', 'Exactly one schedule target is required.', ['fields' => [
                'instance',
                'node',
            ]]);
        }

        $targetType = (string) select(
            label: 'Scope',
            options: ['instance' => 'Instance', 'node' => 'Node'],
            default: 'instance',
        );

        if ($targetType === 'instance') {
            return [
                'instance' => trim(text(label: 'Target instance', required: true)),
                'node' => null,
            ];
        }

        return [
            'instance' => null,
            'node' => trim(text(label: 'Target node', required: true)),
        ];
    }

    /**
     * @return array{command: ?string, script: ?string}|int
     */
    private function resolveExecutionSource(): array|int
    {
        $command = $this->stringOption('command');
        $script = $this->stringOption('script');

        if ($command !== null && $script !== null) {
            return $this->failValidation(
                'execution_source',
                'Exactly one schedule execution source is required.',
                ['fields' => ['command', 'script']],
            );
        }

        if ($command !== null || $script !== null) {
            return [
                'command' => $command,
                'script' => $script,
            ];
        }

        if (! $this->isInteractiveInput()) {
            return $this->failValidation(
                'execution_source',
                'Exactly one schedule execution source is required.',
                ['fields' => ['command', 'script']],
            );
        }

        $source = (string) select(
            label: 'Source',
            options: ['command' => 'Inline command', 'script' => 'Repo script'],
            default: 'command',
        );

        if ($source === 'command') {
            return [
                'command' => trim(text(label: 'Command', required: true)),
                'script' => null,
            ];
        }

        return [
            'command' => null,
            'script' => trim(text(label: 'Script path', required: true)),
        ];
    }

    private function resolveInterval(): ?string
    {
        $interval = $this->stringOption('interval');

        if ($interval !== null) {
            return $interval;
        }

        if ($this->isInteractiveInput()) {
            return trim(text(label: 'Interval (cron expression)', required: true));
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function targetSummary(array $schedule): string
    {
        $target = $schedule['target'] ?? null;

        if (! is_array($target)) {
            return '-';
        }

        $type = is_string($target['type'] ?? null) ? $target['type'] : '-';
        $name = is_string($target['name'] ?? null) ? $target['name'] : '-';
        $node = is_string($target['node'] ?? null) && $target['node'] !== '' ? $target['node'] : null;

        $summary = "{$type} {$name}";

        return $node === null ? $summary : "{$summary} on {$node}";
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function executionSummary(array $schedule): string
    {
        $execution = $schedule['execution'] ?? null;

        if (! is_array($execution)) {
            return '-';
        }

        $type = is_string($execution['type'] ?? null) ? $execution['type'] : '-';
        $value = is_string($execution['value'] ?? null) ? $execution['value'] : '-';

        return "{$type} {$value}";
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function stringField(array $schedule, string $key): string
    {
        return is_string($schedule[$key] ?? null) && $schedule[$key] !== '' ? $schedule[$key] : '-';
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successData(array $response): array
    {
        $data = $response['success']['data'] ?? null;

        return is_array($data) ? $data : [];
    }
}
