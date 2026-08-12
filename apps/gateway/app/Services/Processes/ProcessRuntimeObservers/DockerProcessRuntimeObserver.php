<?php

declare(strict_types=1);

namespace App\Services\Processes\ProcessRuntimeObservers;

use App\Data\Doctor\ProbeSnapshot;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Services\Processes\ProcessExpectedRuntimeUnits;
use App\Services\Tools\ToolScriptDispatcher;

final readonly class DockerProcessRuntimeObserver implements ProcessRuntimeObserver
{
    public function __construct(
        private ProcessExpectedRuntimeUnits $expectedRuntimeUnits,
        private ToolScriptDispatcher $scripts,
        private DockerRuntimeHibernationAnnotator $hibernation,
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
                'docker container inspect --format \'{{json .}}\' '.escapeshellarg($unit['name']),
            );

            if (! $result->successful()) {
                $stderr = $result->errorOutput();

                if (str_contains($stderr, 'No such container')) {
                    $runtimeUnits[$unit['name']] = [
                        'config_exists' => false,
                        'config_matches' => false,
                        'container_state' => null,
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
                    'container_state' => null,
                ];

                continue;
            }

            $inspection = json_decode($output, associative: true);

            if (! is_array($inspection)) {
                $runtimeUnits[$unit['name']] = [
                    'config_exists' => false,
                    'config_matches' => false,
                    'container_state' => null,
                ];

                continue;
            }

            $labels = $inspection['Config']['Labels'] ?? [];
            $observedHash = is_array($labels) ? $labels[$unit['config_hash_label']] ?? null : null;
            $containerState = $inspection['State']['Status'] ?? null;

            $runtimeUnits[$unit['name']] = [
                'config_exists' => true,
                'config_matches' => $observedHash === $unit['config_hash'],
                'container_state' => $containerState,
            ];
        }

        $runtimeUnitExtras = [];

        if ($backendAvailable) {
            $expectedNames = array_column($expectedUnits, 'name');
            $psResult = $this->scripts->run(
                $node,
                'orbit-process',
                'probe',
                $this->runtimeUnitExtraCommand($process),
            );

            if ($psResult->successful()) {
                foreach (explode("\n", trim($psResult->stdout)) as $containerName) {
                    $containerName = trim($containerName);

                    if ($containerName !== '' && ! in_array($containerName, $expectedNames, strict: true)) {
                        $runtimeUnitExtras[] = $containerName;
                    }
                }
            }
        }

        $runtimeUnits = $this->hibernation->annotate(
            $process,
            $node,
            $expectedUnits,
            $runtimeUnits,
        );

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

    private function runtimeUnitExtraCommand(Process $process): string
    {
        $parts = [
            'docker ps -a',
            '--filter label=orbit.managed=true',
        ];

        if ($process->app instanceof App) {
            $parts[] = '--filter label=orbit.app='.escapeshellarg($process->app->name);
        }

        $parts[] = '--filter label=orbit.process='.escapeshellarg($process->name);
        $parts[] = "--format '{{.Names}}'";

        return implode(' ', $parts);
    }
}
