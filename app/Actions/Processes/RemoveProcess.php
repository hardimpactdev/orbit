<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Contracts\RemoteShell;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Process;
use App\Services\Processes\ProcessRuntimeUnitPayload;

final readonly class RemoveProcess
{
    public function __construct(
        private ProcessRuntimeUnitPayload $runtimeUnitPayload,
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return array{data: array<string, mixed>, warnings: list<array<string, mixed>>}
     */
    public function handle(App $app, string $name): array
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

        $runtimeUnits = $this->runtimeUnitPayload->forProcess($app, $process);
        $warnings = $this->removeRuntimeUnits($app, $runtimeUnits);
        $process->delete();

        return [
            'data' => [
                'process' => [
                    'name' => $name,
                    'app' => $app->name,
                ],
                'removed_runtime_units' => array_column($runtimeUnits, 'name'),
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<array{name: string, context: string}>  $runtimeUnits
     * @return list<array<string, mixed>>
     */
    private function removeRuntimeUnits(App $app, array $runtimeUnits): array
    {
        if ($app->node === null) {
            return [[
                'code' => 'process.runtime_unit_extra',
                'family' => 'process',
                'message' => "Process intent for '{$app->name}' was removed, but no owning node was available for runtime-unit cleanup.",
                'next_command' => 'doctor --family=process --fix',
            ]];
        }

        $warnings = [];

        foreach ($runtimeUnits as $runtimeUnit) {
            $name = $runtimeUnit['name'];
            $result = $this->remoteShell->run($app->node, $this->removeScript($name));

            if (! $result->successful()) {
                $warnings[] = [
                    'code' => 'process.runtime_unit_extra',
                    'family' => 'process',
                    'message' => "Process runtime unit '{$name}' may still exist after process intent removal.",
                    'next_command' => 'doctor --family=process --fix',
                ];
            }
        }

        return $warnings;
    }

    private function removeScript(string $name): string
    {
        $configPath = "/etc/supervisor/conf.d/{$name}.conf";

        return sprintf(
            <<<'SH'
sudo supervisorctl stop %1$s >/dev/null 2>&1 || true
sudo rm -f %2$s
sudo supervisorctl reread
sudo supervisorctl remove %1$s >/dev/null 2>&1 || true
sudo supervisorctl update
SH,
            escapeshellarg($name),
            escapeshellarg($configPath),
        );
    }
}
