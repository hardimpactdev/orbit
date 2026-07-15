<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Actions\Apps\EnsureAppProcessRuntimeUnits;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\Process;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeUnitPayload;
use Orbit\Sdk\Laravel\GatewayApiException;

final readonly class EditProcess
{
    public function __construct(
        private EnsureAppProcessRuntimeUnits $ensureRuntimeUnits,
        private EditProcessRuntimeUnits $runtimeUnits,
        private ProcessRuntimeUnitPayload $runtimeUnitPayload,
    ) {}

    /**
     * @param  array{name?: string, command?: string, restart_policy?: ProcessRestartPolicy, crash_notification?: ProcessCrashNotification, runtime?: ProcessRuntime}  $changes
     * @return array{data: array<string, mixed>, warnings: list<array<string, mixed>>}
     */
    public function handle(ProcessOwnerContext $context, string $name, array $changes, bool $restart): array
    {
        $app = $context->runtimeApp();
        $app->loadMissing(['node', 'workspaces']);

        $process = $context
            ->ownerProcesses()
            ->where('name', $name)
            ->first();

        if (! $process instanceof Process) {
            throw new GatewayApiException(
                "Process '{$name}' not found for {$context->label()}.",
                'process.not_found',
                $context->errorMeta($name),
            );
        }

        $changed = [];
        $oldName = $process->name;
        $previousRuntime = $process->runtime;
        $previousRuntimeUnits = $this->runtimeUnitPayload->forProcess(
            $app,
            $process,
            $context->runtimeWorkspaceFor($process),
        );

        if (array_key_exists('name', $changes) && $process->name !== $changes['name']) {
            $fixedRuntimeUnitName = $this->runtimeUnits->fixedRuntimeUnitName($process);

            if ($fixedRuntimeUnitName !== null) {
                throw new GatewayApiException(
                    "Process '{$process->name}' cannot be renamed because its {$process->runtime->value} runtime unit is explicitly named '{$fixedRuntimeUnitName}'.",
                    'process.rename_unsupported',
                    [
                        ...$context->errorMeta($process->name),
                        'field' => 'name',
                        'reason' => 'fixed_runtime_unit_name',
                        'runtime' => $process->runtime->value,
                        'runtime_unit_name' => $fixedRuntimeUnitName,
                    ],
                );
            }

            $conflict = $context
                ->ownerProcesses()
                ->where('name', $changes['name'])
                ->where('id', '!=', $process->id)
                ->exists();

            if ($conflict) {
                throw new GatewayApiException(
                    "Process '{$changes['name']}' already exists for {$context->label()}.",
                    'process.name_conflict',
                    [
                        ...$context->errorMeta($changes['name']),
                        'field' => 'name',
                    ],
                );
            }

            $process->name = $changes['name'];
            $changed[] = 'name';
        }

        if (array_key_exists('command', $changes) && $process->command !== $changes['command']) {
            $process->command = $changes['command'];
            $changed[] = 'command';
        }

        if (array_key_exists('restart_policy', $changes) && $process->restart_policy !== $changes['restart_policy']) {
            $process->restart_policy = $changes['restart_policy'];
            $changed[] = 'restart_policy';
        }

        if (
            array_key_exists('crash_notification', $changes)
            && $process->crash_notification !== $changes['crash_notification']
        ) {
            $process->crash_notification = $changes['crash_notification'];
            $changed[] = 'crash_notification';
        }

        if (array_key_exists('runtime', $changes) && $process->runtime !== $changes['runtime']) {
            $context->assertRuntimeAllowed($changes['runtime']);
            $process->runtime = $changes['runtime'];
            $changed[] = 'runtime';
        }

        $effectiveRuntime = $changes['runtime'] ?? $process->runtime;
        $effectiveCrashNotification = $changes['crash_notification'] ?? $process->crash_notification;

        if (
            $effectiveRuntime === ProcessRuntime::Launchd
            && $effectiveCrashNotification === ProcessCrashNotification::AgentIde
        ) {
            throw new GatewayApiException(
                'Crash notification via agent_ide is deferred for launchd runtime.',
                'validation_failed',
                [
                    'field' => 'crash_notification',
                    'reason' => 'launchd_crash_notification_deferred',
                ],
            );
        }

        if ($changed === []) {
            throw new GatewayApiException(
                'At least one editable field is required.',
                'validation_failed',
                [
                    ...$context->errorMeta($name),
                    'field' => 'editable_fields',
                ],
            );
        }

        $process->save();
        $app->unsetRelation('processes');
        $runtimeUnits = $this->runtimeUnitPayload->forProcess($app, $process, $context->runtimeWorkspaceFor($process));
        $restartableRuntimeUnits = $runtimeUnits;

        if ($context->app !== null && $context->workspace === null) {
            $warnings = $this->ensureRuntimeUnits->handle($app, $context->appInstance);

            if ($warnings === []) {
                $warnings = $this->runtimeUnits->cleanupPrevious(
                    $context,
                    $previousRuntime,
                    $previousRuntimeUnits,
                    $runtimeUnits,
                );
            }
        } else {
            $applyResult = $this->runtimeUnits->apply(
                [
                    'context' => $context,
                    'app' => $app,
                    'process' => $process,
                    'runtime_units' => $runtimeUnits,
                    'previous_runtime' => $previousRuntime,
                    'previous_runtime_units' => $previousRuntimeUnits,
                ],
            );
            $warnings = $applyResult['warnings'];
            $restartableRuntimeUnits = $applyResult['applied_runtime_units'];
        }

        if ($restart) {
            $warnings = [
                ...$warnings,
                ...$this->runtimeUnits->restart($context, $process, $restartableRuntimeUnits),
            ];
        }

        return [
            'data' => [
                'process' => [
                    ...$context->processPayload($process),
                ],
                'old_name' => in_array('name', $changed, strict: true) ? $oldName : null,
                'changed' => $changed,
                'runtime_units' => $runtimeUnits,
            ],
            'warnings' => $warnings,
        ];
    }
}
