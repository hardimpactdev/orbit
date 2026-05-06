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
use App\Models\Workspace;
use App\Services\Processes\SupervisorProgramRenderer;
use Illuminate\Support\Facades\DB;

final readonly class AddProcess
{
    public function __construct(
        private EnsureAppProcessRuntimeUnits $ensureRuntimeUnits,
        private SupervisorProgramRenderer $renderer,
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return array{data: array<string, mixed>, warnings: list<array<string, mixed>>}
     */
    public function handle(App $app, string $name, string $command, ProcessRestartPolicy $restartPolicy, ProcessCrashNotification $crashNotification, bool $start): array
    {
        $app->loadMissing(['node', 'workspaces']);

        if (Process::query()->where('app_id', $app->id)->where('name', $name)->exists()) {
            throw new GatewayApiException("Process '{$name}' already exists for app '{$app->name}'.", 'process.name_collision', [
                'app' => $app->name,
                'name' => $name,
            ]);
        }

        $process = DB::transaction(function () use ($app, $name, $command, $restartPolicy, $crashNotification): Process {
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
                'sort_order' => $maxOrder + 1,
            ]);
        });

        $app->unsetRelation('processes');
        $warnings = $this->ensureRuntimeUnits->handle($app);
        $runtimeUnits = $this->runtimeUnits($app, $process);

        if ($start) {
            $warnings = [
                ...$warnings,
                ...$this->startRuntimeUnits($app, $runtimeUnits),
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
                'runtime_units' => $runtimeUnits,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<array{name: string, context: string}>
     */
    private function runtimeUnits(App $app, Process $process): array
    {
        return collect([null, ...$app->workspaces->all()])
            ->map(fn (?Workspace $workspace): array => [
                'name' => $this->renderer->programName($app, $process, $workspace),
                'context' => $workspace instanceof Workspace ? $workspace->name : 'main',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{name: string, context: string}>  $runtimeUnits
     * @return list<array<string, mixed>>
     */
    private function startRuntimeUnits(App $app, array $runtimeUnits): array
    {
        if ($app->node === null) {
            return [[
                'code' => 'process.runtime_unit_start_failed',
                'family' => 'process',
                'message' => "Process runtime units for '{$app->name}' were rendered, but no owning node was available for start.",
                'next_command' => 'doctor --family=process --fix',
            ]];
        }

        $warnings = [];

        foreach ($runtimeUnits as $runtimeUnit) {
            $name = $runtimeUnit['name'];
            $result = $this->remoteShell->run($app->node, 'sudo supervisorctl start '.escapeshellarg($name));

            if (! $result->successful()) {
                $warnings[] = [
                    'code' => 'process.runtime_unit_start_failed',
                    'family' => 'process',
                    'message' => "Process runtime unit '{$name}' was rendered but could not be started.",
                    'next_command' => 'doctor --family=process --fix',
                ];
            }
        }

        return $warnings;
    }
}
