<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Enums\ProcessCrashNotification;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\RuntimeBackend\RuntimeBackendProbe;
use InvalidArgumentException;

final readonly class ProcessesProbe
{
    public function __construct(
        private ?SupervisorProgramRenderer $supervisorProgramRenderer = null,
        private ?RuntimeBackendProbe $runtimeBackendProbe = null,
    ) {}

    public function key(): string
    {
        return 'processes';
    }

    public function label(): string
    {
        return 'Processes';
    }

    public function introspect(Process $process): ProbeSnapshot
    {
        $process->loadMissing('app.node');

        if (! $process->app instanceof App || ! $process->app->node instanceof Node) {
            return new ProbeSnapshot([]);
        }

        $probe = $this->runtimeBackendProbe()->check($process->app->node);
        $spec = $this->expectedRuntimeUnitSpecs($process);

        $items = [
            $process->name => [
                'runtime_backend_available' => $probe->available,
                'runtime_backend_exit_code' => $probe->exitCode,
                'runtime_backend_output' => $probe->output,
                'runtime_units' => [],
            ],
        ];

        if (! $probe->available) {
            return new ProbeSnapshot($items);
        }

        $script = <<<'BASH'
set -euo pipefail
php <<'PHP'
<?php
$units = json_decode(base64_decode((string) getenv('ORBIT_PROCESS_UNITS')), true);

foreach ($units as $unit) {
    $name = (string) ($unit['name'] ?? '');
    $path = (string) ($unit['config_path'] ?? '');
    $hash = (string) ($unit['config_hash'] ?? '');
    $exists = is_file($path) ? '1' : '0';
    $matches = $exists === '1' && hash_file('sha256', $path) === $hash ? '1' : '0';

    printf("%s\t%s\t%s\n", $name, $exists, $matches);
}
PHP
BASH;

        $result = $this->runtimeBackendProbe()
            ->remoteShell()
            ->run($process->app->node, $script, [
                'throw' => true,
                'env' => ['ORBIT_PROCESS_UNITS' => base64_encode((string) json_encode($spec))],
            ]);

        foreach (explode("\n", rtrim($result->stdout, "\n\r")) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line, 3);

            if (count($parts) !== 3) {
                continue;
            }

            [$name, $exists, $matches] = $parts;

            $items[$process->name]['runtime_units'][$name] = [
                'config_exists' => $exists === '1',
                'config_matches' => $matches === '1',
            ];
        }

        return new ProbeSnapshot($items);
    }

    /**
     * @return list<DriftEntry>
     */
    public function diff(Process $process, ProbeSnapshot $snapshot): array
    {
        $drift = [];

        $drift = array_merge($drift, $this->checkRecordCompleteness($process));
        $drift = array_merge($drift, $this->checkOwnerApp($process));
        $drift = array_merge($drift, $this->checkRuntimeContexts($process));
        $drift = array_merge($drift, $this->checkRuntimeBackend($process, $snapshot));
        $drift = array_merge($drift, $this->checkRuntimeUnits($process, $snapshot));

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(Process $process): array
    {
        $restartPolicy = $process->getRawOriginal('restart_policy');
        $crashNotification = $process->getRawOriginal('crash_notification');

        if (
            ! is_int($process->app_id)
            || ! is_string($process->name)
            || $process->name === ''
            || ! is_string($process->command)
            || trim($process->command) === ''
            || ! is_int($process->sort_order)
            || ! is_string($restartPolicy)
            || ProcessRestartPolicy::tryFrom($restartPolicy) === null
            || ! is_string($crashNotification)
            || ProcessCrashNotification::tryFrom($crashNotification) === null
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.record_incomplete',
                    kind: DriftKind::Missing,
                    summary: "Process record for {$process->name} is missing required fields.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkOwnerApp(Process $process): array
    {
        $process->loadMissing('app.node');

        if (! $process->app instanceof App) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.owner_app_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Process {$process->name} points at a missing app.",
                ),
            ];
        }

        if (
            ! $process->app->node instanceof Node
            || $process->app->node->role !== 'app'
            || $process->app->node->status !== 'active'
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.owner_app_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Process {$process->name} owner app {$process->app->name} is not on an active app node.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRuntimeUnits(Process $process, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($process->name);

        if (
            $observed === null
            || ($observed['runtime_backend_available'] ?? null) === false
            || ! is_array($observed['runtime_units'] ?? null)
        ) {
            return [];
        }

        $drift = [];

        foreach ($this->expectedRuntimeUnitSpecs($process) as $unit) {
            $name = $unit['name'];
            $runtimeUnit = $observed['runtime_units'][$name] ?? null;

            if (! is_array($runtimeUnit) || ($runtimeUnit['config_exists'] ?? null) === false) {
                $drift[] = new DriftEntry(
                    family: $this->key(),
                    key: 'process.runtime_unit_missing',
                    kind: DriftKind::Missing,
                    summary: "Process runtime unit {$name} is missing.",
                    detail: [
                        'runtime_unit' => $name,
                        'expected' => $unit['config_path'],
                    ],
                );

                continue;
            }

            if (($runtimeUnit['config_matches'] ?? null) === false) {
                $drift[] = new DriftEntry(
                    family: $this->key(),
                    key: 'process.runtime_unit_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Process runtime unit {$name} differs from gateway process intent.",
                    detail: [
                        'runtime_unit' => $name,
                        'expected' => $unit['config_path'],
                    ],
                );
            }
        }

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRuntimeBackend(Process $process, ProbeSnapshot $snapshot): array
    {
        $process->loadMissing('app.node');

        if (! $process->app instanceof App || ! $process->app->node instanceof Node) {
            return [];
        }

        $observed = $snapshot->get($process->name);

        if ($observed === null) {
            return [];
        }

        if (($observed['runtime_backend_available'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.runtime_backend_unavailable',
                    kind: DriftKind::Unverifiable,
                    summary: "Supervisor runtime backend is unavailable for process {$process->name} on app node {$process->app->node->name}.",
                    detail: [
                        'node' => $process->app->node->name,
                        'exit_code' => $observed['runtime_backend_exit_code'] ?? null,
                        'output' => $observed['runtime_backend_output'] ?? '',
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRuntimeContexts(Process $process): array
    {
        $process->loadMissing('app.node', 'app.workspaces');

        if (! $process->app instanceof App) {
            return [];
        }

        try {
            $runtimeUnits = $this->expectedRuntimeUnits($process);
        } catch (InvalidArgumentException $exception) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.runtime_context_unresolved',
                    kind: DriftKind::Unverifiable,
                    summary: "Process {$process->name} runtime contexts cannot be derived from gateway intent.",
                    detail: [
                        'reason' => $exception->getMessage(),
                    ],
                ),
            ];
        }

        if ($runtimeUnits === []) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.runtime_context_unresolved',
                    kind: DriftKind::Unverifiable,
                    summary: "Process {$process->name} has no derived runtime contexts.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function expectedRuntimeUnits(Process $process): array
    {
        $process->loadMissing('app.workspaces');

        if (! $process->app instanceof App) {
            return [];
        }

        return collect([null, ...$process->app->workspaces->all()])
            ->map(fn (?Workspace $workspace): string => $this->supervisorProgramRenderer()->programName($process->app, $process, $workspace))
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, config_path: string, config_hash: string}>
     */
    private function expectedRuntimeUnitSpecs(Process $process): array
    {
        $process->loadMissing('app.workspaces');

        if (! $process->app instanceof App) {
            return [];
        }

        return collect([null, ...$process->app->workspaces->all()])
            ->map(fn (?Workspace $workspace): array => [
                'name' => $this->supervisorProgramRenderer()->programName($process->app, $process, $workspace),
                'config_path' => $this->supervisorProgramRenderer()->configPath($process->app, $process, $workspace),
                'config_hash' => hash('sha256', $this->supervisorProgramRenderer()->render($process->app, $process, $workspace)),
            ])
            ->values()
            ->all();
    }

    private function supervisorProgramRenderer(): SupervisorProgramRenderer
    {
        return $this->supervisorProgramRenderer ?? app(SupervisorProgramRenderer::class);
    }

    private function runtimeBackendProbe(): RuntimeBackendProbe
    {
        return $this->runtimeBackendProbe ?? app(RuntimeBackendProbe::class);
    }
}
