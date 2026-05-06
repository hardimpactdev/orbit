<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Actions\Apps\EnsureAppProcessRuntimeUnits;
use App\Contracts\RemoteShell;
use App\Enums\ProcessCrashNotification;
use App\Enums\ProcessRestartPolicy;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Process;
use App\Services\Processes\ProcessRuntimeUnitPayload;

final readonly class EditProcess
{
    public function __construct(
        private EnsureAppProcessRuntimeUnits $ensureRuntimeUnits,
        private ProcessRuntimeUnitPayload $runtimeUnitPayload,
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @param  array{command?: string, restart_policy?: ProcessRestartPolicy, crash_notification?: ProcessCrashNotification}  $changes
     * @return array{data: array<string, mixed>, warnings: list<array<string, mixed>>}
     */
    public function handle(App $app, string $name, array $changes, bool $restart): array
    {
        $app->loadMissing(['node', 'workspaces']);

        $process = Process::query()
            ->where('app_id', $app->id)
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

        $process->save();
        $app->unsetRelation('processes');
        $warnings = $this->ensureRuntimeUnits->handle($app);
        $runtimeUnits = $this->runtimeUnitPayload->forProcess($app, $process);

        if ($restart) {
            $warnings = [
                ...$warnings,
                ...$this->restartRuntimeUnits($app, $runtimeUnits),
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
                ],
                'changed' => $changed,
                'runtime_units' => $runtimeUnits,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<array{name: string, context: string}>  $runtimeUnits
     * @return list<array<string, mixed>>
     */
    private function restartRuntimeUnits(App $app, array $runtimeUnits): array
    {
        if ($app->node === null) {
            return [[
                'code' => 'process.runtime_unit_restart_failed',
                'family' => 'process',
                'message' => "Process runtime units for '{$app->name}' were rendered, but no owning node was available for restart.",
                'next_command' => 'doctor --family=process --fix',
            ]];
        }

        $warnings = [];

        foreach ($runtimeUnits as $runtimeUnit) {
            $name = $runtimeUnit['name'];
            $result = $this->remoteShell->run($app->node, 'sudo supervisorctl restart '.escapeshellarg($name));

            if (! $result->successful()) {
                $warnings[] = [
                    'code' => 'process.runtime_unit_restart_failed',
                    'family' => 'process',
                    'message' => "Process runtime unit '{$name}' was rendered but could not be restarted.",
                    'next_command' => 'doctor --family=process --fix',
                ];
            }
        }

        return $warnings;
    }
}
