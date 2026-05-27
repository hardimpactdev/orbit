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
use Illuminate\Support\Facades\DB;

final readonly class AddProcess
{
    public function __construct(
        private EnsureAppProcessRuntimeUnits $ensureRuntimeUnits,
        private ProcessRuntimeUnitPayload $runtimeUnitPayload,
        private RemoteShell $remoteShell,
        private ProcessDockerRuntimeManager $dockerManager,
    ) {}

    /**
     * @return array{data: array<string, mixed>, warnings: list<array<string, mixed>>}
     */
    public function handle(App $app, string $name, string $command, ProcessRestartPolicy $restartPolicy, ProcessCrashNotification $crashNotification, bool $start, ?ProcessRuntime $runtime = null): array
    {
        $app->loadMissing(['node', 'workspaces']);

        $resolvedRuntime = $runtime ?? ProcessRuntime::defaultForApp($app);

        if (Process::query()->where('app_id', $app->id)->where('name', $name)->exists()) {
            throw new GatewayApiException("Process '{$name}' already exists for app '{$app->name}'.", 'process.name_collision', [
                'app' => $app->name,
                'name' => $name,
            ]);
        }

        $process = DB::transaction(function () use ($app, $name, $command, $restartPolicy, $crashNotification, $resolvedRuntime): Process {
            $maxOrder = Process::query()
                ->where('app_id', $app->id)
                ->lockForUpdate()
                ->max('sort_order') ?? 0;

            return Process::query()->create([
                'app_id' => $app->id,
                'name' => $name,
                'command' => $command,
                'restart_policy' => $restartPolicy,
                'crash_notification' => $crashNotification,
                'runtime' => $resolvedRuntime,
                'sort_order' => $maxOrder + 1,
            ]);
        });

        $app->unsetRelation('processes');
        $warnings = $this->ensureRuntimeUnits->handle($app);
        $runtimeUnits = $this->runtimeUnitPayload->forProcess($app, $process);

        if ($start) {
            $warnings = [
                ...$warnings,
                ...$this->startRuntimeUnits($app, $process, $runtimeUnits),
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
                'runtime_units' => $runtimeUnits,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * Start the rendered runtime units after a successful apply. The
     * lifecycle backend is dispatched by `$process->runtime` so Docker
     * processes use `docker start` and Supervisor processes use
     * `supervisorctl start`. The unit identity is shared between backends,
     * so the runtime_units payload addresses both.
     *
     * @param  list<array{name: string, context: string}>  $runtimeUnits
     * @return list<array<string, mixed>>
     */
    private function startRuntimeUnits(App $app, Process $process, array $runtimeUnits): array
    {
        if ($app->node === null) {
            return [[
                'code' => 'process.runtime_unit_start_failed',
                'family' => 'process',
                'message' => "Process runtime units for '{$app->name}' were rendered, but no owning node was available for start.",
                'next_command' => 'doctor --family=process --restore',
            ]];
        }

        $warnings = [];

        foreach ($runtimeUnits as $runtimeUnit) {
            $name = $runtimeUnit['name'];
            $started = match ($process->runtime) {
                ProcessRuntime::Docker => $this->dockerManager->start($app->node, $name),
                ProcessRuntime::Supervisor => $this->remoteShell->run($app->node, 'sudo supervisorctl start '.escapeshellarg($name))->successful(),
            };

            if (! $started) {
                $warnings[] = [
                    'code' => 'process.runtime_unit_start_failed',
                    'family' => 'process',
                    'message' => "Process runtime unit '{$name}' was rendered but could not be started.",
                    'next_command' => 'doctor --family=process --restore',
                ];
            }
        }

        return $warnings;
    }
}
