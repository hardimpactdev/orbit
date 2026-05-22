<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Actions\Apps\EnsureAppProcessRuntimeUnits;
use App\Contracts\RemoteShell;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Process;
use App\Services\Processes\ProcessDockerRuntimeManager;
use App\Services\Processes\ProcessRuntimeUnitPayload;

final readonly class EditProcess
{
    public function __construct(
        private EnsureAppProcessRuntimeUnits $ensureRuntimeUnits,
        private ProcessRuntimeUnitPayload $runtimeUnitPayload,
        private RemoteShell $remoteShell,
        private ProcessDockerRuntimeManager $dockerManager,
    ) {}

    /**
     * @param  array{command?: string, restart_policy?: ProcessRestartPolicy, crash_notification?: ProcessCrashNotification, runtime?: ProcessRuntime}  $changes
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
     * Restart the rendered runtime units after a successful apply. The
     * lifecycle backend is dispatched by `$process->runtime` so Docker
     * processes use `docker restart` and Supervisor processes use
     * `supervisorctl restart`. The unit identity is shared between
     * backends.
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
                'next_command' => 'doctor --fix --family=process --restore',
            ]];
        }

        $warnings = [];

        foreach ($runtimeUnits as $runtimeUnit) {
            $name = $runtimeUnit['name'];
            $restarted = match ($process->runtime) {
                ProcessRuntime::Docker => $this->dockerManager->restart($app->node, $name),
                ProcessRuntime::Supervisor => $this->remoteShell->run($app->node, 'sudo supervisorctl restart '.escapeshellarg($name))->successful(),
            };

            if (! $restarted) {
                $warnings[] = [
                    'code' => 'process.runtime_unit_restart_failed',
                    'family' => 'process',
                    'message' => "Process runtime unit '{$name}' was rendered but could not be restarted.",
                    'next_command' => 'doctor --fix --family=process --restore',
                ];
            }
        }

        return $warnings;
    }
}
