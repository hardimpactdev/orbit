<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Schedules\AddSchedule;
use App\Concerns\HandlesPromptCancellation;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Schedules\AddScheduleRequest;
use App\Http\Gateway\Responses\Schedules\ScheduleAddResponse;
use App\Models\App;
use App\Models\Node;
use App\Services\Nodes\CallerRoleResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\select;

#[Signature('schedule:add
    {name? : Schedule name}
    {--command= : Command to execute}
    {--script= : Managed script path to execute}
    {--interval= : Portable interval expression}
    {--app= : Target app scope}
    {--node= : Target node scope}
    {--timezone=UTC : IANA timezone}
    {--json : Output JSON}')]
#[Description('Add a recurring schedule')]
class ScheduleAddCommand extends Command
{
    use HandlesPromptCancellation;
    use WithSpinner;
    use WithStepTree;

    public function handle(AddSchedule $addSchedule, CallerRoleResolver $callerRoleResolver): int
    {
        $callerRole = $callerRoleResolver->resolve();

        $input = $this->validatedInput();

        if (is_int($input)) {
            return $input;
        }

        $target = null;

        if ($callerRole === 'gateway') {
            $target = $this->resolveTarget($input['app'], $input['node']);

            if (is_int($target)) {
                return $target;
            }
        }

        $result = null;
        $failure = null;
        $operation = function () use ($callerRole, $input, $addSchedule, $target, &$result, &$failure): string {
            try {
                if ($callerRole !== 'gateway') {
                    $result = $this->forwardAddResult($input);

                    return 'gateway accepted';
                }

                $result = $addSchedule->handle(
                    target: $target,
                    name: $input['name'],
                    interval: $input['interval'],
                    timezone: $input['timezone'],
                    executionType: $input['execution_type'],
                    executionValue: $input['execution_value'],
                );

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
                    'message' => 'Gateway connection is required to add schedules.',
                    'meta' => [],
                    'data' => [],
                ];

                return "fail:{$failure['message']}";
            }
        };

        if (! $this->wantsJson()) {
            $exitCode = $this->runStepTree('Adding Schedule', [
                [
                    'label' => 'Validate schedule',
                    'doneLabel' => 'Validated schedule',
                    'run' => fn (): string => 'input resolved',
                ],
                [
                    'label' => 'Create schedule intent',
                    'doneLabel' => 'Created schedule intent',
                    'run' => fn (): string => 'intent accepted',
                ],
                [
                    'label' => 'Confirm scheduler pickup',
                    'doneLabel' => 'Confirmed scheduler pickup',
                    'run' => $operation,
                ],
            ], doneFooter: 'Schedule added', failFooter: 'Schedule add failed');

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

        return $this->successPayload($result['data']);
    }

    /**
     * @param  array{name: string, app: string|null, node: string|null, interval: string, timezone: string, command: string|null, script: string|null, execution_type: string, execution_value: string}  $input
     * @return array{data: array<string, mixed>}
     */
    private function forwardAddResult(array $input): array
    {
        /** @var ScheduleAddResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new AddScheduleRequest(
                name: $input['name'],
                app: $input['app'],
                node: $input['node'],
                interval: $input['interval'],
                timezone: $input['timezone'],
                command: $input['command'],
                script: $input['script'],
            ))
            ->dto();

        return ['data' => $dto->data];
    }

    /**
     * @return array{name: string, app: string|null, node: string|null, interval: string, timezone: string, command: string|null, script: string|null, execution_type: string, execution_value: string}|int
     */
    private function validatedInput(): array|int
    {
        $name = $this->stringArgument('name');
        $app = $this->stringOption('app');
        $node = $this->stringOption('node');
        $interval = $this->stringOption('interval');
        $timezone = $this->stringOption('timezone') ?? 'UTC';
        $command = $this->stringOption('command');
        $script = $this->stringOption('script');

        // schedule.add.name
        if ($name === null) {
            if (! $this->isInteractiveInput()) {
                return $this->failValidation('name', 'The schedule name is required.');
            }

            try {
                $name = $this->promptText(label: 'Schedule name', required: true);
            } catch (PromptAborted) {
                return $this->promptAbortedExit();
            }
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $name)) {
            return $this->failValidation('name', 'The schedule name must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.', ['value' => $name]);
        }

        // Conflict check before prompting for target
        if ($app !== null && $node !== null) {
            return $this->failValidation('target', 'Exactly one schedule target is required.', ['fields' => ['app', 'node']]);
        }

        // schedule.add.target_type / schedule.add.app / schedule.add.node
        if ($app === null && $node === null) {
            if (! $this->isInteractiveInput()) {
                return $this->failValidation('target', 'Exactly one schedule target is required.', ['fields' => ['app', 'node']]);
            }

            try {
                $targetType = select(
                    label: 'Scope',
                    options: ['app' => 'App', 'node' => 'Node'],
                );

                if ($targetType === 'app') {
                    $app = $this->promptText(label: 'App name', required: true);
                } else {
                    $node = $this->promptText(label: 'Node name', required: true);
                }
            } catch (PromptAborted) {
                return $this->promptAbortedExit();
            }
        }

        // Conflict check before prompting for execution source
        if ($command !== null && $script !== null) {
            return $this->failValidation('execution_source', 'Exactly one schedule execution source is required.', ['fields' => ['command', 'script']]);
        }

        // schedule.add.execution_type / schedule.add.command / schedule.add.script
        if ($command === null && $script === null) {
            if (! $this->isInteractiveInput()) {
                return $this->failValidation('execution_source', 'Exactly one schedule execution source is required.', ['fields' => ['command', 'script']]);
            }

            try {
                $executionType = select(
                    label: 'Source',
                    options: ['command' => 'Inline command', 'script' => 'Repo script'],
                );

                if ($executionType === 'command') {
                    $command = $this->promptText(label: 'Command', required: true);
                } else {
                    $script = $this->promptText(label: 'Script path', required: true);
                }
            } catch (PromptAborted) {
                return $this->promptAbortedExit();
            }
        }

        // schedule.add.interval
        if ($interval === null) {
            if (! $this->isInteractiveInput()) {
                return $this->failCommand('schedule.interval_invalid', 'The schedule interval is required.', ['field' => 'interval']);
            }

            try {
                $interval = $this->promptText(label: 'Interval (cron expression)', required: true);
            } catch (PromptAborted) {
                return $this->promptAbortedExit();
            }
        }

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            return $this->failValidation('timezone', 'The schedule timezone must be a valid IANA timezone.', ['value' => $timezone]);
        }

        return [
            'name' => $name,
            'app' => $app,
            'node' => $node,
            'interval' => $interval,
            'timezone' => $timezone,
            'command' => $command,
            'script' => $script,
            'execution_type' => $command === null ? 'script' : 'command',
            'execution_value' => $command ?? (string) $script,
        ];
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    private function promptAbortedExit(): int
    {
        return $this->failCommand('validation_failed', 'Operation cancelled.', []);
    }

    private function resolveTarget(?string $app, ?string $node): App|Node|int
    {
        if ($app !== null) {
            $target = App::query()->with('node.schedulerState')->where('name', $app)->first();

            return $target instanceof App
                ? $target
                : $this->failValidation('app', "App '{$app}' not found.", ['value' => $app]);
        }

        $target = Node::query()->with('schedulerState')->where('name', $node)->whereIn('role', ['gateway', 'app'])->first();

        return $target instanceof Node
            ? $target
            : $this->failValidation('node', "Node '{$node}' not found.", ['value' => $node]);
    }

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    private function failValidation(string $field, string $message, array $extraMeta = []): int
    {
        return $this->failCommand('validation_failed', $message, [
            'field' => $field,
            ...$extraMeta,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function successPayload(array $data): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode(['success' => ['data' => $data]], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $schedule = is_array($data['schedule'] ?? null) ? $data['schedule'] : [];
        $this->line("Schedule '".(string) ($schedule['name'] ?? '')."' added.");

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

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }
}
