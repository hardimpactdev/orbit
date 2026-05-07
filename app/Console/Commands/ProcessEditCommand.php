<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Processes\EditProcess;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Enums\ProcessCrashNotification;
use App\Enums\ProcessRestartPolicy;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Processes\EditProcessRequest;
use App\Http\Gateway\Responses\Processes\ProcessEditResponse;
use App\Models\App;
use App\Services\Nodes\CallerRoleResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('process:edit
    {name? : Existing process name}
    {--app= : Parent app slug}
    {--command= : New command}
    {--restart-policy= : Restart policy (never|on_failure|always)}
    {--crash-notification= : Crash notification policy (none|agent_ide)}
    {--restart : Restart affected runtime units after update}
    {--json : Output JSON}')]
#[Description('Edit an app process definition')]
class ProcessEditCommand extends Command
{
    use WithSpinner;
    use WithStepTree;

    public function handle(EditProcess $editProcess, CallerRoleResolver $callerRoleResolver): int
    {
        $callerRole = $callerRoleResolver->resolve();

        if ($callerRole === 'app' || $callerRole === 'unknown') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control or gateway node.',
                meta: ['caller_role' => $callerRole],
            );
        }

        $input = $this->validatedInput();

        if (is_int($input)) {
            return $input;
        }

        $result = null;
        $failure = null;
        $operation = function () use ($callerRole, $input, $editProcess, &$result, &$failure): string {
            try {
                if ($callerRole === 'control') {
                    $result = $this->forwardEditResult($input);

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

                $result = $editProcess->handle(
                    app: $app,
                    name: $input['name'],
                    changes: $input['changes'],
                    restart: $input['restart'],
                );

                return $input['restart'] ? 'runtime units restarted' : 'runtime units rendered';
            } catch (GatewayApiException $e) {
                $failure = [
                    'code' => $e->errorCode() ?? 'gateway_unavailable',
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Gateway connection is required to edit processes.',
                    'meta' => $e->errorMeta(),
                ];

                return "fail:{$failure['message']}";
            } catch (Throwable) {
                $failure = [
                    'code' => 'gateway_unavailable',
                    'message' => 'Gateway connection is required to edit processes.',
                    'meta' => [],
                ];

                return "fail:{$failure['message']}";
            }
        };

        if (! $this->wantsJson()) {
            $exitCode = $this->runStepTree('Editing Process', [
                [
                    'label' => 'Validate process',
                    'doneLabel' => 'Validated process',
                    'run' => fn (): string => 'input resolved',
                ],
                [
                    'label' => 'Apply and verify process change',
                    'doneLabel' => 'Applied and verified process change',
                    'run' => fn (): string => 'change accepted',
                ],
                [
                    'label' => $input['restart'] ? 'Restart runtime units' : 'Render runtime units',
                    'doneLabel' => $input['restart'] ? 'Restarted runtime units' : 'Rendered runtime units',
                    'run' => $operation,
                ],
            ], doneFooter: 'Process updated', failFooter: 'Process update failed');

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
     * @param  array{name: string, app: string, changes: array{command?: string, restart_policy?: ProcessRestartPolicy, crash_notification?: ProcessCrashNotification}, restart: bool}  $input
     * @return array{data: array<string, mixed>, warnings: list<array<string, mixed>>}
     */
    private function forwardEditResult(array $input): array
    {
        /** @var ProcessEditResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new EditProcessRequest(
                app: $input['app'],
                name: $input['name'],
                command: $input['changes']['command'] ?? null,
                restartPolicy: isset($input['changes']['restart_policy']) ? $input['changes']['restart_policy']->value : null,
                crashNotification: isset($input['changes']['crash_notification']) ? $input['changes']['crash_notification']->value : null,
                restart: $input['restart'],
            ))
            ->dto();

        return ['data' => $dto->data, 'warnings' => $dto->warnings];
    }

    /**
     * @return array{name: string, app: string, changes: array{command?: string, restart_policy?: ProcessRestartPolicy, crash_notification?: ProcessCrashNotification}, restart: bool}|int
     */
    private function validatedInput(): array|int
    {
        $app = $this->stringOption('app');
        $name = $this->stringArgument('name');
        $command = $this->stringOption('command');
        $restartPolicyInput = $this->stringOption('restart-policy');
        $crashNotificationInput = $this->stringOption('crash-notification');

        if ($app === null) {
            return $this->failValidation('app', 'An app context is required.');
        }

        if ($name === null) {
            return $this->failValidation('name', 'The process name is required.');
        }

        if ($command === null && $restartPolicyInput === null && $crashNotificationInput === null) {
            return $this->failValidation('editable_fields', 'At least one editable field is required.');
        }

        $changes = [];

        if ($command !== null) {
            $changes['command'] = $command;
        }

        if ($restartPolicyInput !== null) {
            $restartPolicy = ProcessRestartPolicy::tryFrom($restartPolicyInput);

            if (! $restartPolicy instanceof ProcessRestartPolicy) {
                return $this->failValidation('restart_policy', 'Invalid restart policy.', [
                    'value' => $restartPolicyInput,
                    'allowed' => array_column(ProcessRestartPolicy::cases(), 'value'),
                ]);
            }

            $changes['restart_policy'] = $restartPolicy;
        }

        if ($crashNotificationInput !== null) {
            $crashNotification = ProcessCrashNotification::tryFrom($crashNotificationInput);

            if (! $crashNotification instanceof ProcessCrashNotification) {
                return $this->failValidation('crash_notification', 'Invalid crash notification policy.', [
                    'value' => $crashNotificationInput,
                    'allowed' => array_column(ProcessCrashNotification::cases(), 'value'),
                ]);
            }

            $changes['crash_notification'] = $crashNotification;
        }

        return [
            'app' => $app,
            'name' => $name,
            'changes' => $changes,
            'restart' => $this->option('restart') === true,
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
        $this->line("Process '".(string) ($process['name'] ?? '')."' updated for app '".(string) ($process['app'] ?? '')."'");

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
}
