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
        private ?ProcessEventNotifierRenderer $processEventNotifierRenderer = null,
    ) {}

    public function key(): string
    {
        return 'process';
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
        $notifier = [
            'required' => $this->requiresEventNotifier($process),
            'script_hash' => $this->processEventNotifierRenderer()->hash(),
            'gateway_endpoint' => $this->processEventNotifierRenderer()->expectedGatewayEndpoint(),
        ];

        $items = [
            $process->name => [
                'runtime_backend_available' => $probe->available,
                'runtime_backend_exit_code' => $probe->exitCode,
                'runtime_backend_output' => $probe->output,
                'runtime_units' => [],
                'runtime_unit_extras' => [],
                'event_notifier' => null,
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
$notifier = json_decode(base64_decode((string) getenv('ORBIT_PROCESS_EVENT_NOTIFIER')), true);
$expectedNames = [];

foreach ($units as $unit) {
    $name = (string) ($unit['name'] ?? '');
    $expectedNames[$name] = true;
    $path = (string) ($unit['config_path'] ?? '');
    $hash = (string) ($unit['config_hash'] ?? '');
    $restartPolicy = (string) ($unit['restart_policy'] ?? '');
    $environmentLine = (string) ($unit['environment_line'] ?? '');
    $exists = is_file($path) ? '1' : '0';
    $content = $exists === '1' ? (string) file_get_contents($path) : '';
    $matches = $exists === '1' && hash('sha256', $content) === $hash ? '1' : '0';
    $restartMatches = $exists === '1' && preg_match('/^autorestart='.preg_quote($restartPolicy, '/').'$/m', $content) === 1 ? '1' : '0';
    $environmentMatches = $exists === '1' && preg_match('/^'.preg_quote($environmentLine, '/').'$/m', $content) === 1 ? '1' : '0';

    printf("%s\t%s\t%s\t%s\t%s\n", $name, $exists, $matches, $restartMatches, $environmentMatches);
}

$notifierPath = '/usr/local/bin/orbit-notify-exit';
$endpointPath = '/etc/orbit/gateway-endpoint';
$notifierExists = is_file($notifierPath) ? '1' : '0';
$notifierExecutable = is_executable($notifierPath) ? '1' : '0';
$notifierMatches = $notifierExists === '1' && hash_file('sha256', $notifierPath) === (string) ($notifier['script_hash'] ?? '') ? '1' : '0';
$expectedEndpoint = (string) ($notifier['gateway_endpoint'] ?? '');
$endpointExists = is_file($endpointPath) ? '1' : '0';
$endpointMatches = $expectedEndpoint !== '' && $endpointExists === '1' && rtrim(trim((string) file_get_contents($endpointPath)), '/') === $expectedEndpoint ? '1' : '0';

printf("__notifier\t%s\t%s\t%s\t%s\t%s\n", $notifierExists, $notifierExecutable, $notifierMatches, $endpointExists, $endpointMatches);

foreach (glob('/etc/supervisor/conf.d/orbit_*.conf') ?: [] as $path) {
    $name = basename($path, '.conf');

    if (! isset($expectedNames[$name])) {
        printf("__extra\t%s\n", $name);
    }
}
PHP
BASH;

        $result = $this->runtimeBackendProbe()
            ->remoteShell()
            ->run($process->app->node, $script, [
                'throw' => true,
                'env' => [
                    'ORBIT_PROCESS_UNITS' => base64_encode((string) json_encode($spec)),
                    'ORBIT_PROCESS_EVENT_NOTIFIER' => base64_encode((string) json_encode($notifier)),
                ],
            ]);

        foreach (explode("\n", rtrim($result->stdout, "\n\r")) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line, 6);
            $name = $parts[0] ?? '';

            if ($name === '__notifier') {
                if (count($parts) !== 6) {
                    continue;
                }

                [, $scriptExists, $scriptExecutable, $scriptMatches, $endpointExists, $endpointMatches] = $parts;

                $items[$process->name]['event_notifier'] = [
                    'script_exists' => $scriptExists === '1',
                    'script_executable' => $scriptExecutable === '1',
                    'script_matches' => $scriptMatches === '1',
                    'gateway_endpoint_exists' => $endpointExists === '1',
                    'gateway_endpoint_matches' => $endpointMatches === '1',
                ];

                continue;
            }

            if ($name === '__extra') {
                if (count($parts) !== 2) {
                    continue;
                }

                $items[$process->name]['runtime_unit_extras'][] = $parts[1];

                continue;
            }

            if (count($parts) !== 5) {
                continue;
            }

            [$name, $exists, $matches, $restartMatches, $environmentMatches] = $parts;

            $items[$process->name]['runtime_units'][$name] = [
                'config_exists' => $exists === '1',
                'config_matches' => $matches === '1',
                'restart_policy_matches' => $restartMatches === '1',
                'environment_matches' => $environmentMatches === '1',
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
        $drift = array_merge($drift, $this->checkRestartPolicy($process, $snapshot));
        $drift = array_merge($drift, $this->checkRuntimeEnvironment($process, $snapshot));
        $drift = array_merge($drift, $this->checkEventNotifier($process, $snapshot));
        $drift = array_merge($drift, $this->checkRuntimeUnitExtras($process, $snapshot));

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
    private function checkRuntimeUnitExtras(Process $process, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($process->name);

        if (
            $observed === null
            || ($observed['runtime_backend_available'] ?? null) === false
            || ! is_array($observed['runtime_unit_extras'] ?? null)
        ) {
            return [];
        }

        return collect($observed['runtime_unit_extras'])
            ->filter(fn (mixed $runtimeUnit): bool => is_string($runtimeUnit) && $runtimeUnit !== '')
            ->map(fn (string $runtimeUnit): DriftEntry => new DriftEntry(
                family: $this->key(),
                key: 'process.runtime_unit_extra',
                kind: DriftKind::Extra,
                summary: "Process runtime unit {$runtimeUnit} has no matching active gateway process intent.",
                detail: [
                    'runtime_unit' => $runtimeUnit,
                    'expected_path' => "/etc/supervisor/conf.d/{$runtimeUnit}.conf",
                ],
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkEventNotifier(Process $process, ProbeSnapshot $snapshot): array
    {
        if (! $this->requiresEventNotifier($process)) {
            return [];
        }

        $observed = $snapshot->get($process->name);

        if (
            $observed === null
            || ($observed['runtime_backend_available'] ?? null) === false
            || ! is_array($observed['event_notifier'] ?? null)
        ) {
            return [];
        }

        $notifier = $observed['event_notifier'];

        if (
            ($notifier['script_exists'] ?? null) === false
            || ($notifier['gateway_endpoint_exists'] ?? null) === false
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.event_notifier_missing',
                    kind: DriftKind::Missing,
                    summary: "Process {$process->name} crash event notifier material is missing.",
                    detail: [
                        'script' => $this->processEventNotifierRenderer()->installPath(),
                        'gateway_endpoint' => $this->processEventNotifierRenderer()->gatewayEndpointPath(),
                    ],
                ),
            ];
        }

        if (
            ($notifier['script_executable'] ?? null) === false
            || ($notifier['script_matches'] ?? null) === false
            || ($notifier['gateway_endpoint_matches'] ?? null) === false
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.event_notifier_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Process {$process->name} crash event notifier material differs from gateway intent.",
                    detail: [
                        'script' => $this->processEventNotifierRenderer()->installPath(),
                        'gateway_endpoint' => $this->processEventNotifierRenderer()->gatewayEndpointPath(),
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRestartPolicy(Process $process, ProbeSnapshot $snapshot): array
    {
        return $this->checkRuntimeUnitField(
            process: $process,
            snapshot: $snapshot,
            field: 'restart_policy_matches',
            key: 'process.restart_policy_mismatch',
            summary: fn (string $name): string => "Process runtime unit {$name} restart policy differs from gateway process intent.",
        );
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRuntimeEnvironment(Process $process, ProbeSnapshot $snapshot): array
    {
        return $this->checkRuntimeUnitField(
            process: $process,
            snapshot: $snapshot,
            field: 'environment_matches',
            key: 'process.runtime_environment_mismatch',
            summary: fn (string $name): string => "Process runtime unit {$name} environment differs from gateway runtime intent.",
        );
    }

    /**
     * @param  callable(string): string  $summary
     * @return list<DriftEntry>
     */
    private function checkRuntimeUnitField(
        Process $process,
        ProbeSnapshot $snapshot,
        string $field,
        string $key,
        callable $summary,
    ): array {
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
                continue;
            }

            if (($runtimeUnit[$field] ?? null) === false) {
                $drift[] = new DriftEntry(
                    family: $this->key(),
                    key: $key,
                    kind: DriftKind::Divergent,
                    summary: $summary($name),
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

            if (
                ($runtimeUnit['config_matches'] ?? null) === false
                && ($runtimeUnit['restart_policy_matches'] ?? null) !== false
                && ($runtimeUnit['environment_matches'] ?? null) !== false
            ) {
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
     * @return list<array{name: string, config_path: string, config_hash: string, restart_policy: string, environment_line: string}>
     */
    private function expectedRuntimeUnitSpecs(Process $process): array
    {
        $process->loadMissing('app.workspaces');

        if (! $process->app instanceof App) {
            return [];
        }

        return collect([null, ...$process->app->workspaces->all()])
            ->map(function (?Workspace $workspace) use ($process): array {
                $definition = $this->supervisorProgramRenderer()->definition($process->app, $process, $workspace);
                $content = $this->supervisorProgramRenderer()->render($process->app, $process, $workspace);

                return [
                    'name' => $definition->name,
                    'config_path' => $this->supervisorProgramRenderer()->configPath($process->app, $process, $workspace),
                    'config_hash' => hash('sha256', $content),
                    'restart_policy' => $definition->restartPolicy,
                    'environment_line' => $this->environmentLine($content),
                ];
            })
            ->values()
            ->all();
    }

    private function environmentLine(string $content): string
    {
        foreach (explode("\n", $content) as $line) {
            if (str_starts_with($line, 'environment=')) {
                return $line;
            }
        }

        return 'environment=';
    }

    private function requiresEventNotifier(Process $process): bool
    {
        return ProcessCrashNotification::tryFrom((string) $process->getRawOriginal('crash_notification')) === ProcessCrashNotification::AgentIde;
    }

    private function supervisorProgramRenderer(): SupervisorProgramRenderer
    {
        return $this->supervisorProgramRenderer ?? app(SupervisorProgramRenderer::class);
    }

    private function runtimeBackendProbe(): RuntimeBackendProbe
    {
        return $this->runtimeBackendProbe ?? app(RuntimeBackendProbe::class);
    }

    private function processEventNotifierRenderer(): ProcessEventNotifierRenderer
    {
        return $this->processEventNotifierRenderer ?? app(ProcessEventNotifierRenderer::class);
    }
}
