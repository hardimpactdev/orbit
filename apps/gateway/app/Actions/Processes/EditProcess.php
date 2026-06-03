<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Actions\Apps\EnsureAppProcessRuntimeUnits;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Process;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessRuntimeUnitPayload;

final readonly class EditProcess
{
    public function __construct(
        private EnsureAppProcessRuntimeUnits $ensureRuntimeUnits,
        private ProcessRuntimeUnitPayload $runtimeUnitPayload,
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
    ) {}

    /**
     * @param  array{command?: string, restart_policy?: ProcessRestartPolicy, crash_notification?: ProcessCrashNotification, runtime?: ProcessRuntime}  $changes
     * @return array{data: array<string, mixed>, warnings: list<array<string, mixed>>}
     */
    public function handle(App $app, string $name, array $changes, bool $restart): array
    {
        $app->loadMissing(['node', 'workspaces']);

        $process = $app->processes()
            ->where('name', $name)
            ->first();

        if (! $process instanceof Process) {
            throw new GatewayApiException("Process '{$name}' not found for app '{$app->name}'.", 'process.not_found', [
                'app' => $app->name,
                'name' => $name,
            ]);
        }

        $changed = [];

        if (isset($changes['command']) && $process->command !== $changes['command']) {
            $process->command = $changes['command'];
            $changed[] = 'command';
        }

        if (isset($changes['restart_policy']) && $process->restart_policy !== $changes['restart_policy']) {
            $process->restart_policy = $changes['restart_policy'];
            $changed[] = 'restart_policy';
        }

        if (isset($changes['crash_notification']) && $process->crash_notification !== $changes['crash_notification']) {
            $process->crash_notification = $changes['crash_notification'];
            $changed[] = 'crash_notification';
        }

        if (isset($changes['runtime']) && $process->runtime !== $changes['runtime']) {
            $process->runtime = $changes['runtime'];
            $changed[] = 'runtime';
        }

        $process->save();
        $app->unsetRelation('processes');
        $warnings = $this->ensureRuntimeUnits->handle($app);
        $runtimeUnits = $this->runtimeUnitPayload->forProcess($app, $process);

        if ($restart) {
            $warnings = [
                ...$warnings,
                ...$this->restartRuntimeUnits($app, $process, $runtimeUnits),
            ];
        }

        return [
            'data' => [
                'process' => [
                    'name' => $process->name,
                    'app' => $app->name,
                    'command' => $process->command,
                    'restart_policy' => $process->restart_policy->value,
                    'crash_notification' => $process->crash_notification->value,
                    'runtime' => $process->runtime->value,
                ],
                'changed' => $changed,
                'runtime_units' => $runtimeUnits,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * Restart the rendered runtime units after a successful apply through the
     * process runtime driver selected by `$process->runtime`.
     *
     * @param  list<array{name: string, context: string}>  $runtimeUnits
     * @return list<array<string, mixed>>
     */
    private function restartRuntimeUnits(App $app, Process $process, array $runtimeUnits): array
    {
        if ($app->node === null) {
            return [[
                'code' => 'process.runtime_unit_restart_failed',
                'family' => 'process',
                'message' => "Process runtime units for '{$app->name}' were rendered, but no owning node was available for restart.",
                'next_command' => 'doctor --family=process --restore',
            ]];
        }

        $warnings = [];
        $driver = $this->runtimeDrivers->for($process->runtime);

        foreach ($runtimeUnits as $runtimeUnit) {
            $name = $runtimeUnit['name'];
            $restarted = $driver->restart($app->node, $name);

            if (! $restarted) {
                $warnings[] = [
                    'code' => 'process.runtime_unit_restart_failed',
                    'family' => 'process',
                    'message' => "Process runtime unit '{$name}' was rendered but could not be restarted.",
                    'next_command' => 'doctor --family=process --restore',
                ];
            }
        }

        return $warnings;
    }
}
