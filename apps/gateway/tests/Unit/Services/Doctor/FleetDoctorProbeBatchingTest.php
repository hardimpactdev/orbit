<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Nodes\DevelopmentDnsMappingProbe;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $developmentDnsConfigDir = storage_path('framework/testing/fleet-batch-dns/'.bin2hex(random_bytes(6)));
    $developmentDnsMappingEnactor = new DevelopmentDnsMappingEnactor($developmentDnsConfigDir);

    app()->instance(DevelopmentDnsMappingEnactor::class, $developmentDnsMappingEnactor);
    app()->instance(DevelopmentDnsMappingProbe::class, new DevelopmentDnsMappingProbe($developmentDnsMappingEnactor));
});

afterEach(function (): void {
    File::deleteDirectory(app(DevelopmentDnsMappingEnactor::class)->configDir());
});

it('caps concurrent fleet node probes at five workers using subprocess worker reports', function (): void {
    if (! fleetDoctorProbeProcessWorkersAvailable()) {
        test()->markTestSkipped(
            'proc_open and a shared file-backed sqlite database are required for fleet doctor batch concurrency.',
        );
    }

    fleetDoctorProbeWithFileDatabase(function (): void {
        foreach (range(1, 7) as $index) {
            fleetDoctorProbeBatchNode(['name' => "fleet-batch-{$index}"]);
        }

        app()->instance(RemoteShell::class, new FleetDoctorProbeParentFallbackGuardRemoteShell);

        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process): mixed {
            $nodeName = fleetDoctorProbeNodeNameFromCommand(fleetDoctorProbeCommand($process));

            return Process::describe()
                ->runsFor(8)
                ->output(fleetDoctorProbeWorkerJson($nodeName, ['node']))
                ->exitCode(0);
        });

        $maxConcurrent = 0;
        /** @var array<string, true> $running */
        $running = [];

        $report = app(DoctorReportRunner::class)->probeFleet(
            families: ['node'],
            onNodeProgress: function (Node $node, string $phase) use (&$maxConcurrent, &$running): void {
                if ($phase === 'running') {
                    $running[$node->name] = true;
                    $maxConcurrent = max($maxConcurrent, count($running));

                    return;
                }

                unset($running[$node->name]);
            },
        );

        expect($maxConcurrent)
            ->toBe(DoctorReportRunner::FLEET_PROBE_BATCH_SIZE)
            ->and(
                collect(fleetDoctorProbeReportList($report, 'nodes'))->pluck('summary.worker_marker')->unique()->all(),
            )
            ->toBe(['process_fake']);

        Process::assertRanTimes(
            fn (PendingProcess $process): bool => fleetDoctorProbeIsWorkerLaunchCommand(fleetDoctorProbeCommand(
                $process,
            )),
            7,
        );

        Process::assertRan(function (PendingProcess $process): bool {
            fleetDoctorProbeAssertWorkerLaunchCommand(fleetDoctorProbeCommand($process));

            return true;
        });
    });
});

it('preserves fleet node and issue ordering when subprocess workers complete out of order', function (): void {
    if (! fleetDoctorProbeProcessWorkersAvailable()) {
        test()->markTestSkipped(
            'proc_open and a shared file-backed sqlite database are required for fleet doctor batch concurrency.',
        );
    }

    fleetDoctorProbeWithFileDatabase(function (): void {
        foreach (range(1, 5) as $index) {
            fleetDoctorProbeBatchNode([
                'name' => sprintf('fleet-order-%02d', $index),
                'platform' => 'ubuntu',
            ]);
        }

        app()->instance(RemoteShell::class, new FleetDoctorProbeParentFallbackGuardRemoteShell);

        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process): mixed {
            $nodeName = fleetDoctorProbeNodeNameFromCommand(fleetDoctorProbeCommand($process));
            $nodeNumber = (int) substr($nodeName, -2);
            $failingNodeNames = ['fleet-order-02', 'fleet-order-04'];

            return Process::describe()
                ->runsFor(6 - $nodeNumber)
                ->output(fleetDoctorProbeWorkerJson(
                    nodeName: $nodeName,
                    families: ['proxy'],
                    healthy: ! in_array($nodeName, $failingNodeNames, true),
                    issues: in_array($nodeName, $failingNodeNames, true)
                        ? [[
                            'node' => $nodeName,
                            'key' => 'proxy.worker_fake_issue',
                            'status' => 'unhealthy',
                        ]]
                        : [],
                ))
                ->exitCode(0);
        });

        $report = app(DoctorReportRunner::class)->probeFleet(families: ['proxy']);

        expect(collect(fleetDoctorProbeReportList($report, 'nodes'))->pluck('node')->all())
            ->toBe([
                'fleet-order-01',
                'fleet-order-02',
                'fleet-order-03',
                'fleet-order-04',
                'fleet-order-05',
            ])
            ->and(collect(fleetDoctorProbeReportList($report, 'issues'))->pluck('node')->all())
            ->toBe(['fleet-order-02', 'fleet-order-04'])
            ->and(collect(fleetDoctorProbeReportList($report, 'issues'))->pluck('key')->all())
            ->toBe(['proxy.worker_fake_issue', 'proxy.worker_fake_issue']);

        Process::assertRanTimes(
            fn (PendingProcess $process): bool => fleetDoctorProbeIsWorkerLaunchCommand(fleetDoctorProbeCommand(
                $process,
            )),
            5,
        );
    });
});

it('returns a valid single-node report from the internal artisan command', function (): void {
    if (! fleetDoctorProbeProcessWorkersAvailable()) {
        test()->markTestSkipped(
            'proc_open and a shared file-backed sqlite database are required for fleet doctor batch concurrency.',
        );
    }

    fleetDoctorProbeWithFileDatabase(function (): void {
        fleetDoctorProbeBatchNode(['name' => 'fleet-cmd-node']);

        app()->instance(RemoteShell::class, new FleetDoctorProbeBatchDelayRemoteShell(delayMicroseconds: 0));

        $exitCode = Artisan::call('orbit:internal:doctor-fleet-probe-node', [
            '--node' => 'fleet-cmd-node',
            '--families' => ['node'],
            '--no-ansi' => true,
        ]);

        expect($exitCode)->toBe(0);

        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        expect($payload['report'] ?? null)
            ->toBeArray()
            ->and($payload['report']['scope']['node'] ?? null)
            ->toBe('fleet-cmd-node')
            ->and($payload['report']['scope']['families'] ?? null)
            ->toBe(['node']);
    });
});

/**
 * @param  callable(): void  $callback
 */
function fleetDoctorProbeWithFileDatabase(callable $callback): void
{
    $originalDatabase = config('database.connections.sqlite.database');
    $databasePath = storage_path('framework/testing/fleet-batch-'.bin2hex(random_bytes(6)).'.sqlite');
    File::ensureDirectoryExists(dirname($databasePath));
    config(['database.connections.sqlite.database' => $databasePath]);
    DB::purge('sqlite');
    test()->artisan('migrate:fresh', ['--no-interaction' => true]);

    try {
        $callback();
    } finally {
        DB::disconnect('sqlite');
        config(['database.connections.sqlite.database' => $originalDatabase]);
        DB::purge('sqlite');
        if (File::exists($databasePath)) {
            File::delete($databasePath);
        }
    }
}

function fleetDoctorProbeProcessWorkersAvailable(): bool
{
    return function_exists('proc_open');
}

function fleetDoctorProbeBatchNode(array $attributes = []): Node
{
    $node = Node::factory()->create([
        'name' => 'app-1',
        'status' => 'active',
        ...$attributes,
    ]);
    assert($node instanceof Node);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
        'settings' => ['tld' => 'test'],
    ]);

    return $node;
}

/**
 * @param  array<array-key, string>|string  $command
 */
function fleetDoctorProbeNodeNameFromCommand(array|string $command): string
{
    $commandLine = is_array($command) ? implode(' ', $command) : $command;

    preg_match('/--node=([^\s]+)/', $commandLine, $matches);

    return $matches[1] ?? 'unknown-node';
}

/**
 * @param  array<array-key, string>|string  $command
 */
function fleetDoctorProbeIsWorkerLaunchCommand(array|string $command): bool
{
    if (! is_array($command)) {
        return false;
    }

    return (
        ($command[0] ?? null) === 'php'
        && ($command[1] ?? null) === 'artisan'
        && ($command[2] ?? null) === 'orbit:internal:doctor-fleet-probe-node'
    );
}

/**
 * @param  array<array-key, string>|string  $command
 */
function fleetDoctorProbeAssertWorkerLaunchCommand(array|string $command): void
{
    expect($command)->toBeArray();
    assert(is_array($command));

    expect($command[0] ?? null)
        ->toBe('php')
        ->and($command[1] ?? null)
        ->toBe('artisan')
        ->and($command[2] ?? null)
        ->toBe('orbit:internal:doctor-fleet-probe-node');
}

/**
 * @return array<array-key, string>|string
 */
function fleetDoctorProbeCommand(PendingProcess $process): array|string
{
    $command = $process->command;

    return is_array($command) || is_string($command) ? $command : [];
}

/**
 * @param  array<string, mixed>  $report
 * @return list<array<string, mixed>>
 */
function fleetDoctorProbeReportList(array $report, string $key): array
{
    $items = $report[$key] ?? [];
    $list = [];

    if (! is_array($items)) {
        return $list;
    }

    foreach ($items as $item) {
        if (is_array($item)) {
            /** @var array<string, mixed> $item */
            $list[] = $item;
        }
    }

    return $list;
}

/**
 * @param  list<string>  $families
 * @param  list<array<string, mixed>>  $issues
 */
function fleetDoctorProbeWorkerJson(
    string $nodeName,
    array $families,
    bool $healthy = true,
    array $issues = [],
): string {
    return json_encode([
        'report' => [
            'healthy' => $healthy,
            'mode' => 'verify',
            'scope' => [
                'families' => $families,
                'node' => $nodeName,
            ],
            'summary' => [
                'worker_marker' => 'process_fake',
                'status' => $healthy ? 'healthy' : 'issues',
                'issues' => count($issues),
            ],
            'issues' => $issues,
        ],
    ], JSON_THROW_ON_ERROR);
}

final readonly class FleetDoctorProbeParentFallbackGuardRemoteShell implements RemoteShell
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        throw new RuntimeException(
            'Parent fleet probe fallback must not invoke RemoteShell when subprocess workers succeed.',
        );
    }
}

final readonly class FleetDoctorProbeBatchDelayRemoteShell implements RemoteShell
{
    public function __construct(
        private int $delayMicroseconds = 100_000,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if ($this->delayMicroseconds > 0) {
            usleep($this->delayMicroseconds);
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
