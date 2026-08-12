<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Doctor\DoctorProcessExtraRuntimeRemover;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('removes an orphaned Orbit app container and preserves the action contract', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-node']);
    $executor = doctor_process_extra_runtime_executor();
    app()->instance(RunsInternalCommands::class, $executor);

    $action = app(DoctorProcessExtraRuntimeRemover::class)->remove(
        $node,
        'process.runtime_unit_extra',
        [
            'runtime_unit' => ' orbit-app-removed ',
            'app' => 'removed',
            'reason' => 'orphaned_managed_app_runtime',
        ],
    );

    expect($action)
        ->toBe([
            'family' => 'process',
            'node' => 'app-node',
            'code' => 'process.runtime_unit_extra',
            'key' => 'process.runtime_unit_extra',
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => 'Removed orphaned managed process runtime orbit-app-removed.',
            'details' => [
                'runtime_unit' => 'orbit-app-removed',
                'app' => 'removed',
                'reason' => 'orphaned_managed_app_runtime',
            ],
        ])
        ->and($executor->calls)
        ->toHaveCount(1)
        ->and($executor->calls[0]['command'])
        ->toBe('internal:process-docker-container')
        ->and($executor->calls[0]['transport']['input'] ?? null)
        ->toBe('{"action":"remove","container":"orbit-app-removed"}');
});

it('removes a legacy over-length Orbit systemd unit at its canonical path', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'linux-node']);
    $runtimeUnit = 'orbit_'.str_repeat(string: 'a', times: 70);
    $executor = doctor_process_extra_runtime_executor();
    app()->instance(RunsInternalCommands::class, $executor);

    $action = app(DoctorProcessExtraRuntimeRemover::class)->remove(
        $node,
        'process.runtime_unit_extra',
        [
            'runtime_unit' => $runtimeUnit,
            'runtime' => 'systemd',
            'expected_path' => "/etc/systemd/system/{$runtimeUnit}.service",
        ],
    );

    expect($action)
        ->toMatchArray([
            'family' => 'process',
            'node' => 'linux-node',
            'code' => 'process.runtime_unit_extra',
            'key' => 'process.runtime_unit_extra',
            'mode' => 'restore',
            'status' => 'completed',
            'details' => [
                'runtime_unit' => $runtimeUnit,
                'runtime' => 'systemd',
                'expected_path' => "/etc/systemd/system/{$runtimeUnit}.service",
            ],
        ])
        ->and($action['details']['runtime_unit'] ?? null)
        ->toBe($runtimeUnit)
        ->and($executor->calls)
        ->toHaveCount(1)
        ->and($executor->calls[0]['command'])
        ->toBe('internal:process-systemd-service');
});

it('removes an Orbit launchd unit only when its expected identity matches', function (): void {
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'mac-node',
            'platform' => 'darwin',
            'user' => 'nckrtl',
        ]);
    $runtimeUnit = 'orbit_blog_development_vite';
    $expectedPath = "/Users/nckrtl/Library/LaunchAgents/dev.hardimpact.orbit.{$runtimeUnit}.plist";
    $executor = doctor_process_extra_runtime_executor();
    app()->instance(RunsInternalCommands::class, $executor);

    $action = app(DoctorProcessExtraRuntimeRemover::class)->remove(
        $node,
        'process.runtime_unit_extra',
        [
            'runtime_unit' => $runtimeUnit,
            'runtime' => 'launchd',
            'expected_path' => $expectedPath,
            'expected' => $runtimeUnit,
        ],
    );

    expect($action)
        ->toMatchArray([
            'family' => 'process',
            'node' => 'mac-node',
            'code' => 'process.runtime_unit_extra',
            'key' => 'process.runtime_unit_extra',
            'mode' => 'restore',
            'status' => 'completed',
            'details' => [
                'runtime_unit' => $runtimeUnit,
                'runtime' => 'launchd',
                'expected_path' => $expectedPath,
                'expected' => $runtimeUnit,
            ],
        ])
        ->and($executor->calls)
        ->toHaveCount(1)
        ->and($executor->calls[0]['command'])
        ->toBe('internal:process-launchd-service');
});

it('refuses runtime units that Orbit cannot prove it owns', function (array $detail): void {
    $node = Node::factory()
        ->appDev()
        ->create([
            'platform' => 'darwin',
            'user' => 'nckrtl',
        ]);
    $executor = doctor_process_extra_runtime_executor();
    app()->instance(RunsInternalCommands::class, $executor);

    expect(app(DoctorProcessExtraRuntimeRemover::class)->remove(
        $node,
        'process.runtime_unit_extra',
        $detail,
    ))
        ->toBeNull()
        ->and($executor->calls)
        ->toBeEmpty();
})->with([
    'missing unit' => [[]],
    'blank unit' => [['runtime_unit' => '  ', 'runtime' => 'systemd']],
    'non-Orbit systemd unit' => [[
        'runtime_unit' => 'nginx',
        'runtime' => 'systemd',
        'expected_path' => '/etc/systemd/system/nginx.service',
    ]],
    'systemd absolute path' => [[
        'runtime_unit' => '/orbit_bad',
        'runtime' => 'systemd',
    ]],
    'systemd relative path' => [[
        'runtime_unit' => 'orbit_../bad',
        'runtime' => 'systemd',
    ]],
    'systemd nul byte' => [[
        'runtime_unit' => "orbit_bad\0unit",
        'runtime' => 'systemd',
    ]],
    'systemd canonical path mismatch' => [[
        'runtime_unit' => 'orbit_blog_vite',
        'runtime' => 'systemd',
        'expected_path' => '/tmp/orbit_blog_vite.service',
    ]],
    'launchd over-length unit' => [[
        'runtime_unit' => 'orbit_'.str_repeat(string: 'a', times: 70),
        'runtime' => 'launchd',
    ]],
    'launchd canonical path mismatch' => [[
        'runtime_unit' => 'orbit_blog_vite',
        'runtime' => 'launchd',
        'expected_path' => '/tmp/dev.hardimpact.orbit.orbit_blog_vite.plist',
    ]],
    'launchd expected identity mismatch' => [[
        'runtime_unit' => 'orbit_blog_vite',
        'runtime' => 'launchd',
        'expected' => 'orbit_other_vite',
    ]],
    'app container without orphan reason' => [[
        'runtime_unit' => 'orbit-app-blog',
    ]],
]);

it('returns a failed action with the removal error instead of throwing', function (array $detail): void {
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-node',
            'platform' => 'darwin',
            'user' => 'nckrtl',
        ]);
    $executor = doctor_process_extra_runtime_executor(exception: new RuntimeException('remove failed'));
    app()->instance(RunsInternalCommands::class, $executor);

    $action = app(DoctorProcessExtraRuntimeRemover::class)->remove(
        $node,
        'process.runtime_unit_extra',
        $detail,
    );

    expect($action)
        ->toMatchArray([
            'family' => 'process',
            'node' => 'app-node',
            'code' => 'process.runtime_unit_extra',
            'key' => 'process.runtime_unit_extra',
            'mode' => 'restore',
            'status' => 'failed',
        ])
        ->and($action['details']['error'] ?? null)
        ->toBe('remove failed');
})->with([
    'Docker app container' => [[
        'runtime_unit' => 'orbit-app-removed',
        'reason' => 'orphaned_managed_app_runtime',
    ]],
    'systemd unit' => [[
        'runtime_unit' => 'orbit_blog_vite',
        'runtime' => 'systemd',
        'expected_path' => '/etc/systemd/system/orbit_blog_vite.service',
    ]],
    'launchd unit' => [[
        'runtime_unit' => 'orbit_blog_vite',
        'runtime' => 'launchd',
        'expected_path' => '/Users/nckrtl/Library/LaunchAgents/dev.hardimpact.orbit.orbit_blog_vite.plist',
    ]],
]);

it('returns a failed action without an error when removal returns false', function (array $detail): void {
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-node',
            'platform' => 'darwin',
            'user' => 'nckrtl',
        ]);
    $executor = doctor_process_extra_runtime_executor([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'remove failed', durationMs: 1),
    ]);
    app()->instance(RunsInternalCommands::class, $executor);

    $action = app(DoctorProcessExtraRuntimeRemover::class)->remove(
        $node,
        'process.runtime_unit_extra',
        $detail,
    );

    expect($action)
        ->toMatchArray([
            'family' => 'process',
            'node' => 'app-node',
            'code' => 'process.runtime_unit_extra',
            'key' => 'process.runtime_unit_extra',
            'mode' => 'restore',
            'status' => 'failed',
        ])
        ->and($action['details'] ?? null)
        ->not->toHaveKey('error');
})->with([
    'Docker app container' => [[
        'runtime_unit' => 'orbit-app-removed',
        'reason' => 'orphaned_managed_app_runtime',
    ]],
    'systemd unit' => [[
        'runtime_unit' => 'orbit_blog_vite',
        'runtime' => 'systemd',
        'expected_path' => '/etc/systemd/system/orbit_blog_vite.service',
    ]],
    'launchd unit' => [[
        'runtime_unit' => 'orbit_blog_vite',
        'runtime' => 'launchd',
        'expected_path' => '/Users/nckrtl/Library/LaunchAgents/dev.hardimpact.orbit.orbit_blog_vite.plist',
    ]],
]);

/**
 * @param  list<RemoteShellResult>  $results
 */
function doctor_process_extra_runtime_executor(
    array $results = [],
    ?Throwable $exception = null,
): object {
    return new class($results, $exception) implements RunsInternalCommands {
        /** @var list<array{command: string, transport: array<int|string, mixed>}> */
        public array $calls = [];

        /** @param list<RemoteShellResult> $results */
        public function __construct(
            private array $results,
            private readonly ?Throwable $exception,
        ) {}

        public function runInternal(
            Node $node,
            string $commandName,
            array $arguments = [],
            array $commandOptions = [],
            array $transportOptions = [],
        ): RemoteShellResult {
            $this->calls[] = [
                'command' => $commandName,
                'transport' => $transportOptions,
            ];

            if ($this->exception instanceof Throwable) {
                throw $this->exception;
            }

            return (
                array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1)
            );
        }
    };
}
