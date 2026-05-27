<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Processes\AddProcess;
use App\Concerns\PromptsForRegistryEntities;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Processes\AddProcessRequest;
use App\Http\Gateway\Responses\Processes\ProcessAddResponse;
use App\Models\App;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\text;

#[Signature('process:add
    {name? : Process name}
    {processCommand? : Command to run}
    {--app= : Parent app slug}
    {--restart-policy=never : Restart policy (never|on_failure|always)}
    {--crash-notification=none : Crash notification policy (none|agent_ide)}
    {--runtime= : Process runtime (docker|supervisor); defaults to docker for PHP apps and supervisor for non-PHP apps}
    {--start : Start rendered runtime units after creation}
    {--json : Output JSON}')]
#[Description('Add an app process definition')]
class ProcessAddCommand extends Command
{
    use PromptsForRegistryEntities;
    use WithSpinner;
    use WithStepTree;

    private const string NAME_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/';

    public function handle(AddProcess $addProcess): int
    {
        $onGateway = (bool) config('orbit.is_gateway', false);

        $input = $this->validatedInput();

        if (is_int($input)) {
            return $input;
        }

        $result = null;
        $failure = null;
        $operation = function () use ($onGateway, $input, $addProcess, &$result, &$failure): string {
            try {
                if (! $onGateway) {
                    $result = $this->forwardAddResult($input);

                    return 'gateway accepted';
                }

                $app = App::query()->with(['node', 'workspaces'])->where('name', $input['app'])->first();

                if (! $app instanceof App) {
                    $failure = [
                        'code' => 'validation_failed',
                        'message' => "App '{$input['app']}' not found.",
                        'meta' => ['field' => 'app', 'value' => $input['app']],
                    ];

                    return "fail:{$failure['message']}";
                }

                $result = $addProcess->handle(
                    app: $app,
                    name: $input['name'],
                    command: $input['command'],
                    restartPolicy: $input['restart_policy'],
                    crashNotification: $input['crash_notification'],
                    start: $input['start'],
                    runtime: $input['runtime'],
                );

                return $input['start'] ? 'runtime units started' : 'runtime units rendered';
            } catch (GatewayApiException $e) {
                $failure = [
                    'code' => $e->errorCode() ?? 'gateway_unavailable',
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Gateway connection is required to add processes.',
                    'meta' => $e->errorMeta(),
                ];

                return "fail:{$failure['message']}";
            } catch (Throwable) {
                $failure = [
                    'code' => 'gateway_unavailable',
                    'message' => 'Gateway connection is required to add processes.',
                    'meta' => [],
                ];

                return "fail:{$failure['message']}";
            }
        };

        if (! $this->wantsJson()) {
            $exitCode = $this->runStepTree('Adding Process', $this->progressSteps(
                validateDoneLabel: 'Validated process',
                workLabel: 'Create process intent',
                workDoneLabel: 'Created process intent',
                finalLabel: $input['start'] ? 'Start runtime units' : 'Render runtime units',
                finalDoneLabel: $input['start'] ? 'Started runtime units' : 'Rendered runtime units',
                operation: $operation,
            ), doneFooter: 'Process added', failFooter: 'Process add failed');

            if ($exitCode !== self::SUCCESS) {
                return is_array($failure)
                    ? $this->failCommand($failure['code'], $failure['message'], $failure['meta'])
                    : self::FAILURE;
            }
        } else {
            $operation();

            if (is_array($failure)) {
                return $this->failCommand($failure['code'], $failure['message'], $failure['meta']);
            }
        }

        return $this->successPayload($result['data'], $result['warnings']);
    }

    /**
     * @param  array{name: string, command: string, app: string, restart_policy: ProcessRestartPolicy, crash_notification: ProcessCrashNotification, runtime: ?ProcessRuntime, start: bool}  $input
     * @return array{data: array<string, mixed>, warnings: list<array<string, mixed>>}
     */
    private function forwardAddResult(array $input): array
    {
        /** @var ProcessAddResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new AddProcessRequest(
                app: $input['app'],
                name: $input['name'],
                command: $input['command'],
                restartPolicy: $input['restart_policy']->value,
                crashNotification: $input['crash_notification']->value,
                start: $input['start'],
                runtime: $input['runtime']?->value,
            ))
            ->dto();

        return ['data' => $dto->data, 'warnings' => $dto->warnings];
    }

    /**
     * @return array{name: string, command: string, app: string, restart_policy: ProcessRestartPolicy, crash_notification: ProcessCrashNotification, runtime: ?ProcessRuntime, start: bool}|int
     */
    private function validatedInput(): array|int
    {
        $isInteractive = ! $this->wantsJson() && $this->input->isInteractive();

        $app = $this->stringOption('app');
        $name = $this->stringArgument('name');
        $command = $this->stringArgument('processCommand');
        $restartPolicyInput = $this->stringOption('restart-policy') ?? ProcessRestartPolicy::Never->value;
        $crashNotificationInput = $this->stringOption('crash-notification') ?? ProcessCrashNotification::None->value;
        $runtimeInput = $this->stringOption('runtime');

        if ($app === null) {
            if (! $isInteractive) {
                return $this->failValidation('app', 'An app context is required.');
            }

            try {
                $selected = $this->promptForVisibleApp(label: 'Select app');

                if ($selected instanceof GatewayApiException) {
                    return $this->failCommand(
                        code: $selected->errorCode() ?? 'gateway_unavailable',
                        message: $selected->getMessage(),
                        meta: $selected->errorMeta(),
                    );
                }

                $app = $selected;
            } catch (PromptAborted) {
                return $this->promptAborted();
            }
        }

        if ($name === null) {
            if (! $isInteractive) {
                return $this->failValidation('name', 'The process name is required.');
            }

            try {
                $name = trim($this->promptText(label: 'Process name', required: true));
            } catch (PromptAborted) {
                return $this->promptAborted();
            }
        }

        if (! preg_match(self::NAME_PATTERN, $name)) {
            return $this->failValidation('name', 'The process name must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.', ['value' => $name]);
        }

        if ($command === null) {
            if (! $isInteractive) {
                return $this->failValidation('command', 'The process command is required.');
            }

            try {
                $command = trim(text(label: 'Command', required: true));
            } catch (PromptAborted) {
                return $this->promptAborted();
            }
        }

        $restartPolicy = ProcessRestartPolicy::tryFrom($restartPolicyInput);

        if (! $restartPolicy instanceof ProcessRestartPolicy) {
            return $this->failValidation('restart_policy', 'Invalid restart policy.', [
                'value' => $restartPolicyInput,
                'allowed' => array_column(ProcessRestartPolicy::cases(), 'value'),
            ]);
        }

        $crashNotification = ProcessCrashNotification::tryFrom($crashNotificationInput);

        if (! $crashNotification instanceof ProcessCrashNotification) {
            return $this->failValidation('crash_notification', 'Invalid crash notification policy.', [
                'value' => $crashNotificationInput,
                'allowed' => array_column(ProcessCrashNotification::cases(), 'value'),
            ]);
        }

        $runtime = null;

        if ($runtimeInput !== null) {
            $runtime = ProcessRuntime::tryFrom($runtimeInput);

            if (! $runtime instanceof ProcessRuntime) {
                return $this->failValidation('runtime', 'Invalid process runtime.', [
                    'value' => $runtimeInput,
                    'allowed' => array_column(ProcessRuntime::cases(), 'value'),
                ]);
            }
        }

        return [
            'app' => $app,
            'name' => $name,
            'command' => $command,
            'restart_policy' => $restartPolicy,
            'crash_notification' => $crashNotification,
            'runtime' => $runtime,
            'start' => $this->option('start') === true,
        ];
    }

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    private function failValidation(string $field, string $message, array $extraMeta = []): int
    {
        return $this->failCommand(
            code: 'validation_failed',
            message: $message,
            meta: [
                'field' => $field,
                ...$extraMeta,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $warnings
     */
    private function successPayload(array $data, array $warnings): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => $data,
                    'meta' => [
                        'warnings' => $warnings,
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $process = is_array($data['process'] ?? null) ? $data['process'] : [];
        $this->line("Process '".(string) ($process['name'] ?? '')."' added for app '".(string) ($process['app'] ?? '')."'");

        foreach ($warnings as $warning) {
            $this->line('  Drift detected: '.(string) ($warning['code'] ?? 'warning'));
        }

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

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }

    private function promptAborted(): int
    {
        return $this->failCommand(
            code: 'validation_failed',
            message: 'Operation cancelled.',
            meta: [],
        );
    }

    /**
     * @return list<array{label: string, doneLabel: string, run: callable}>
     */
    private function progressSteps(
        string $validateDoneLabel,
        string $workLabel,
        string $workDoneLabel,
        string $finalLabel,
        string $finalDoneLabel,
        callable $operation,
    ): array {
        return [
            [
                'label' => 'Validate process',
                'doneLabel' => $validateDoneLabel,
                'run' => fn (): string => 'input resolved',
            ],
            [
                'label' => $workLabel,
                'doneLabel' => $workDoneLabel,
                'run' => fn (): string => 'intent accepted',
            ],
            [
                'label' => $finalLabel,
                'doneLabel' => $finalDoneLabel,
                'run' => $operation,
            ],
        ];
    }
}
