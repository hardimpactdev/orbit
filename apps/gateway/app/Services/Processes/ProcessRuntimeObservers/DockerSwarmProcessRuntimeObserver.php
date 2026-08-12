<?php

declare(strict_types=1);

namespace App\Services\Processes\ProcessRuntimeObservers;

use App\Data\Doctor\ProbeSnapshot;
use App\Models\Node;
use App\Models\Process;
use App\Services\Processes\ProcessExpectedRuntimeUnits;
use App\Services\Tools\ToolScriptDispatcher;

final readonly class DockerSwarmProcessRuntimeObserver implements ProcessRuntimeObserver
{
    public function __construct(
        private ProcessExpectedRuntimeUnits $expectedRuntimeUnits,
        private ToolScriptDispatcher $scripts,
    ) {}

    public function observe(Process $process, Node $node): ProbeSnapshot
    {
        $expectedUnits = $this->expectedRuntimeUnits->unlabeledSpecifications($process);
        $runtimeUnits = [];
        $backendAvailable = true;
        $backendExitCode = 0;
        $backendOutput = '';

        foreach ($expectedUnits as $unit) {
            $result = $this->scripts->run(
                $node,
                'orbit-process',
                'probe',
                'docker service inspect --format \'{{json .}}\' '.escapeshellarg($unit['name']),
            );

            if (! $result->successful()) {
                $message = $result->errorOutput().' '.$result->stdout;

                if (preg_match('/(no such service|not found)/i', $message) === 1) {
                    $runtimeUnits[$unit['name']] = [
                        'config_exists' => false,
                        'config_matches' => false,
                        'service_replicas' => null,
                    ];

                    continue;
                }

                $backendAvailable = false;
                $backendExitCode = $result->exitCode;
                $backendOutput = trim($result->output());

                break;
            }

            $output = trim($result->stdout);

            if ($output === '') {
                $runtimeUnits[$unit['name']] = [
                    'config_exists' => false,
                    'config_matches' => false,
                    'service_replicas' => null,
                ];

                continue;
            }

            $inspection = json_decode($output, associative: true);

            if (! is_array($inspection)) {
                $runtimeUnits[$unit['name']] = [
                    'config_exists' => false,
                    'config_matches' => false,
                    'service_replicas' => null,
                ];

                continue;
            }

            $labels = $inspection['Spec']['Labels'] ?? [];
            $observedHash = is_array($labels) ? $labels[$unit['config_hash_label']] ?? null : null;
            $replicas = $inspection['Spec']['Mode']['Replicated']['Replicas'] ?? null;

            $runtimeUnits[$unit['name']] = [
                'config_exists' => true,
                'config_matches' => $observedHash === $unit['config_hash'],
                'service_replicas' => is_numeric($replicas) ? (int) $replicas : null,
            ];
        }

        $runtimeUnitExtras = [];

        if ($backendAvailable) {
            $expectedNames = array_column($expectedUnits, 'name');
            $psResult = $this->scripts->run(
                $node,
                'orbit-process',
                'probe',
                'docker service ls --filter label=orbit.managed=true --filter label=orbit.process='
                .escapeshellarg($process->name)
                ." --format '{{.Name}}'",
            );

            if ($psResult->successful()) {
                foreach (explode("\n", trim($psResult->stdout)) as $serviceName) {
                    $serviceName = trim($serviceName);

                    if ($serviceName !== '' && ! in_array($serviceName, $expectedNames, strict: true)) {
                        $runtimeUnitExtras[] = $serviceName;
                    }
                }
            }
        }

        return new ProbeSnapshot([
            $process->name => [
                'runtime_backend_available' => $backendAvailable,
                'runtime_backend_exit_code' => $backendExitCode,
                'runtime_backend_output' => $backendOutput,
                'runtime_units' => $runtimeUnits,
                'runtime_unit_extras' => $runtimeUnitExtras,
            ],
        ]);
    }
}
