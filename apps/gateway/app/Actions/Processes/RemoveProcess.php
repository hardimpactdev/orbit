<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Process;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessRuntimeUnitPayload;

final readonly class RemoveProcess
{
    public function __construct(
        private ProcessRuntimeUnitPayload $runtimeUnitPayload,
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
    ) {}

    /**
     * @return array{data: array<string, mixed>, warnings: list<array<string, mixed>>}
     */
    public function handle(App $app, string $name): array
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

        $runtimeUnits = $this->runtimeUnitPayload->forProcess($app, $process);
        $warnings = $this->removeRuntimeUnits($app, $process, $runtimeUnits);
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
    private function removeRuntimeUnits(App $app, Process $process, array $runtimeUnits): array
    {
        if ($app->node === null) {
            return [[
                'code' => 'process.runtime_unit_extra',
                'family' => 'process',
                'message' => "Process intent for '{$app->name}' was removed, but no owning node was available for runtime-unit cleanup.",
                'next_command' => 'doctor --family=process --restore',
            ]];
        }

        $warnings = [];

        foreach ($runtimeUnits as $runtimeUnit) {
            $name = $runtimeUnit['name'];
            $ok = $this->removeRuntimeUnit($app, $process, $name);

            if (! $ok) {
                $warnings[] = [
                    'code' => 'process.runtime_unit_extra',
                    'family' => 'process',
                    'message' => "Process runtime unit '{$name}' may still exist after process intent removal.",
                    'next_command' => 'doctor --family=process --restore',
                ];
            }
        }

        return $warnings;
    }

    private function removeRuntimeUnit(App $app, Process $process, string $name): bool
    {
        return $this->runtimeDrivers->forProcess($process)->remove($app->node, $name);
    }
}
