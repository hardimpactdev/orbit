<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use App\Services\Updates\UnattendedUpgradesDriver;
use App\Services\Updates\UpdateTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    request()->headers->set(
        ExplicitRemoteShellFallback::HEADER,
        NodeTransportPreference::AgentPush->value,
    );
});

it('supports managed Ubuntu node update targets only', function (): void {
    $driver = new UnattendedUpgradesDriver(new UnattendedUpgradesDriverShell);
    $node = Node::factory()->make();

    expect($driver->supports(new UpdateTarget('node', $node, 'ubuntu_24-04', 'managed-server-node')))
        ->toBeTrue()
        ->and($driver->supports(new UpdateTarget('node', $node, 'ubuntu_26-04', 'managed-server-node')))
        ->toBeTrue()
        ->and($driver->supports(new UpdateTarget('node', $node, 'ubuntu_24-04', 'unsupported-node')))
        ->toBeFalse()
        ->and($driver->supports(new UpdateTarget('node', $node, 'macos_15', 'managed-server-node')))
        ->toBeFalse()
        ->and($driver->supports(new UpdateTarget('app', $node, 'ubuntu_24-04', 'managed-server-node')))
        ->toBeFalse();
});

it('reports healthy posture when unattended-upgrades is installed and clean', function (): void {
    $snapshot = probeSnapshot([
        'installed' => true,
        'auto_exists' => true,
        'unattended_exists' => true,
        'auto_hash_ok' => true,
        'unattended_hash_ok' => true,
        'dry_run_exit' => 0,
        'last_run_status' => 'completed',
        'reboot_required' => false,
        'reboot_required_packages' => [],
    ]);

    expect($snapshot->driver)->toBe('unattended-upgrades')->and($snapshot->issues)->toBe([]);
});

it('reports missing config when the package or apt files are absent', function (array $facts): void {
    $issue = probeSnapshot($facts)->issues[0];

    expect($issue->code)
        ->toBe('node.updates_config_missing')
        ->and($issue->kind)
        ->toBe(DriftKind::Missing)
        ->and($issue->restorable)
        ->toBeTrue()
        ->and($issue->detail['driver'])
        ->toBe('unattended-upgrades');
})->with([
    'package missing' => [[
        'installed' => false,
        'auto_exists' => true,
        'unattended_exists' => true,
        'auto_hash_ok' => true,
        'unattended_hash_ok' => true,
        'dry_run_exit' => 0,
        'last_run_status' => 'completed',
        'reboot_required' => false,
        'reboot_required_packages' => [],
    ]],
    'auto config missing' => [[
        'installed' => true,
        'auto_exists' => false,
        'unattended_exists' => true,
        'auto_hash_ok' => false,
        'unattended_hash_ok' => true,
        'dry_run_exit' => 0,
        'last_run_status' => 'completed',
        'reboot_required' => false,
        'reboot_required_packages' => [],
    ]],
    'unattended config missing' => [[
        'installed' => true,
        'auto_exists' => true,
        'unattended_exists' => false,
        'auto_hash_ok' => true,
        'unattended_hash_ok' => false,
        'dry_run_exit' => 0,
        'last_run_status' => 'completed',
        'reboot_required' => false,
        'reboot_required_packages' => [],
    ]],
]);

it('reports config mismatch when expected apt config hashes differ', function (): void {
    $issue = probeSnapshot([
        'installed' => true,
        'auto_exists' => true,
        'unattended_exists' => true,
        'auto_hash_ok' => false,
        'unattended_hash_ok' => true,
        'dry_run_exit' => 0,
        'last_run_status' => 'completed',
        'reboot_required' => false,
        'reboot_required_packages' => [],
    ])->issues[0];

    expect($issue->code)
        ->toBe('node.updates_config_mismatch')
        ->and($issue->kind)
        ->toBe(DriftKind::Divergent)
        ->and($issue->restorable)
        ->toBeTrue();
});

it('reports dry-run failure', function (): void {
    $issue = probeSnapshot([
        'installed' => true,
        'auto_exists' => true,
        'unattended_exists' => true,
        'auto_hash_ok' => true,
        'unattended_hash_ok' => true,
        'dry_run_exit' => 1,
        'last_run_status' => 'completed',
        'reboot_required' => false,
        'reboot_required_packages' => [],
    ])->issues[0];

    expect($issue->code)
        ->toBe('node.updates_dry_run_failed')
        ->and($issue->kind)
        ->toBe(DriftKind::Unverifiable)
        ->and($issue->detail['dry_run_exit'])
        ->toBe(1);
});

it('runs the unattended-upgrade dry-run only after expected config is present', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.42:9477/v1/commands' => unattended_upgrades_agent_response([
            'installed' => true,
            'auto_exists' => false,
            'unattended_exists' => false,
            'auto_hash_ok' => false,
            'unattended_hash_ok' => false,
            'dry_run_exit' => null,
            'last_run_status' => 'unknown',
            'reboot_required' => false,
            'reboot_required_packages' => [],
        ]),
    ]);

    new UnattendedUpgradesDriver(new UnattendedUpgradesDriverShell)->probe(updateTarget());

    Http::assertSent(fn (Request $request): bool => unattended_upgrades_probe_request_matches($request));
});

it('reports latest unattended-upgrades run failure', function (): void {
    $issue = probeSnapshot([
        'installed' => true,
        'auto_exists' => true,
        'unattended_exists' => true,
        'auto_hash_ok' => true,
        'unattended_hash_ok' => true,
        'dry_run_exit' => 0,
        'last_run_status' => 'failed',
        'reboot_required' => false,
        'reboot_required_packages' => [],
    ])->issues[0];

    expect($issue->code)
        ->toBe('node.updates_last_run_failed')
        ->and($issue->kind)
        ->toBe(DriftKind::Divergent)
        ->and($issue->detail['last_run_status'])
        ->toBe('failed');
});

it('reports reboot-required drift with package names', function (): void {
    $issue = probeSnapshot([
        'installed' => true,
        'auto_exists' => true,
        'unattended_exists' => true,
        'auto_hash_ok' => true,
        'unattended_hash_ok' => true,
        'dry_run_exit' => 0,
        'last_run_status' => 'completed',
        'reboot_required' => true,
        'reboot_required_packages' => ['linux-image-6.8.0-60-generic'],
    ])->issues[0];

    expect($issue->code)
        ->toBe('node.updates_reboot_required')
        ->and($issue->kind)
        ->toBe(DriftKind::Divergent)
        ->and($issue->restorable)
        ->toBeFalse()
        ->and($issue->summary)
        ->toBe(
            'This node requires an explicit reboot to finish installed updates. Orbit will not reboot it automatically. Reboot this server as soon as possible.',
        )
        ->and($issue->detail['reboot_required_packages'])
        ->toBe(['linux-image-6.8.0-60-generic']);
});

it('reports unverifiable posture when the shell probe fails', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.42:9477/v1/commands' => unattended_upgrades_agent_response(
            [],
            exitCode: 1,
            stderr: 'permission denied',
        ),
    ]);

    $snapshot = new UnattendedUpgradesDriver(new UnattendedUpgradesDriverShell)->probe(updateTarget());
    $issue = $snapshot->issues[0];

    expect($issue->code)
        ->toBe('node.updates_unverifiable')
        ->and($issue->kind)
        ->toBe(DriftKind::Unverifiable)
        ->and($issue->restorable)
        ->toBeTrue()
        ->and($issue->detail['stderr'])
        ->toBe('permission denied');
});

it('repairs configuration and runs unattended-upgrade during apply', function (): void {
    Http::preventStrayRequests();
    Http::fake(fn (Request $request): mixed => unattended_upgrades_apply_http_response($request));
    $shell = new UnattendedUpgradesDriverShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    $result = new UnattendedUpgradesDriver($shell)->apply(updateTarget());

    expect($result->status)
        ->toBe('completed')
        ->and($result->driver)
        ->toBe('unattended-upgrades')
        ->and($shell->scripts)
        ->toHaveCount(1)
        ->and($shell->scripts[0])
        ->toContain('install -y -qq unattended-upgrades');

    Http::assertSentCount(5);
    Http::assertSent(fn (Request $request): bool => unattended_upgrades_managed_file_request_matches(
        request: $request,
        action: 'probe',
        path: '/etc/apt/apt.conf.d/20auto-upgrades',
    ));
    Http::assertSent(fn (Request $request): bool => unattended_upgrades_managed_file_request_matches(
        request: $request,
        action: 'write',
        path: '/etc/apt/apt.conf.d/20auto-upgrades',
    ));
    Http::assertSent(fn (Request $request): bool => unattended_upgrades_managed_file_request_matches(
        request: $request,
        action: 'probe',
        path: '/etc/apt/apt.conf.d/50unattended-upgrades',
    ));
    Http::assertSent(fn (Request $request): bool => unattended_upgrades_managed_file_request_matches(
        request: $request,
        action: 'write',
        path: '/etc/apt/apt.conf.d/50unattended-upgrades',
    ));
    Http::assertSent(fn (Request $request): bool => unattended_upgrades_apply_request_matches($request));
});

it('does not run unattended-upgrade when config repair fails', function (): void {
    $shell = new UnattendedUpgradesDriverShell([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'apt failed', durationMs: 1),
    ]);

    $result = new UnattendedUpgradesDriver($shell)->apply(updateTarget());

    expect($result->status)
        ->toBe('failed')
        ->and($result->summary)
        ->toContain('Failed to install unattended security upgrades')
        ->and($shell->scripts)
        ->toHaveCount(1);
});

function probeSnapshot(array $facts)
{
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.42:9477/v1/commands' => unattended_upgrades_agent_response($facts),
    ]);

    return new UnattendedUpgradesDriver(new UnattendedUpgradesDriverShell)->probe(updateTarget());
}

function updateTarget(): UpdateTarget
{
    return new UpdateTarget(
        family: 'node',
        node: Node::factory()
            ->appDev()
            ->managed()
            ->create([
                'platform' => 'ubuntu_24-04',
                'wireguard_address' => '10.44.0.42',
            ]),
        platform: 'ubuntu_24-04',
        scope: 'managed-server-node',
    );
}

/**
 * @param  array<string, mixed>  $facts
 */
function unattended_upgrades_agent_response(array $facts, int $exitCode = 0, string $stderr = ''): mixed
{
    $frames = $exitCode === 0
        ? [[
            'type' => 'stdout',
            'message' => json_encode([
                'success' => [
                    'data' => $facts,
                    'meta' => [],
                ],
            ], JSON_THROW_ON_ERROR),
        ]]
        : [[
            'type' => 'stderr',
            'message' => $stderr,
        ]];

    $frames[] = [
        'type' => 'exit',
        'message' => (string) $exitCode,
    ];

    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => 'unattended-upgrades.probe',
        'binary' => 'orbit',
        'status' => $exitCode === 0 ? 'succeeded' : 'failed',
        'exit_code' => $exitCode,
        'frames' => $frames,
    ]);
}

function unattended_upgrades_apply_agent_response(int $exitCode = 0, string $stderr = ''): mixed
{
    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => 'unattended-upgrades.apply',
        'binary' => 'orbit',
        'status' => $exitCode === 0 ? 'succeeded' : 'failed',
        'exit_code' => $exitCode,
        'frames' => [
            [
                'type' => $stderr === '' ? 'stdout' : 'stderr',
                'message' => $stderr,
            ],
            [
                'type' => 'exit',
                'message' => (string) $exitCode,
            ],
        ],
    ]);
}

function unattended_upgrades_apply_http_response(Request $request): mixed
{
    if ($request->url() !== 'http://10.44.0.42:9477/v1/commands') {
        return Http::response('Unexpected request '.$request->url(), 500);
    }

    $command = $request['argv'][0] ?? null;

    if ($command === 'internal:managed-file') {
        return unattended_upgrades_managed_file_agent_response($request);
    }

    if ($command === 'internal:unattended-upgrades:apply') {
        return unattended_upgrades_apply_agent_response();
    }

    return Http::response('Unexpected command '.json_encode($command), 500);
}

function unattended_upgrades_managed_file_agent_response(Request $request): mixed
{
    $action = $request['argv'][1] ?? null;
    $input = json_decode((string) $request['input'], associative: true, flags: JSON_THROW_ON_ERROR);
    $path = is_array($input) && is_string($input['path'] ?? null) ? $input['path'] : '';

    $data = match ($action) {
        'probe' => [
            'exists' => $path === '/etc/apt/apt.conf.d/50unattended-upgrades',
            'hash' => $path === '/etc/apt/apt.conf.d/50unattended-upgrades' ? str_repeat('b', 64) : null,
            'mode' => $path === '/etc/apt/apt.conf.d/50unattended-upgrades' ? '0644' : null,
        ],
        'write' => [
            'updated' => true,
        ],
        default => [
            'error' => 'unexpected managed-file action',
        ],
    };

    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => $request['operation_id'],
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => $data,
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => '0',
            ],
        ],
    ]);
}

function unattended_upgrades_probe_request_matches(Request $request): bool
{
    return (
        $request->url() === 'http://10.44.0.42:9477/v1/commands'
        && $request['binary'] === 'orbit'
        && $request['argv'][0] === 'internal:unattended-upgrades:probe'
        && is_string($request['argv'][1] ?? null)
        && strlen($request['argv'][1]) === 64
        && is_string($request['argv'][2] ?? null)
        && strlen($request['argv'][2]) === 64
        && str_starts_with((string) $request['argv'][3], '--operation-token=')
        && $request['argv'][4] === '--json'
        && $request['operation_id'] === 'unattended-upgrades.probe'
    );
}

function unattended_upgrades_apply_request_matches(Request $request): bool
{
    return (
        $request->url() === 'http://10.44.0.42:9477/v1/commands'
        && $request['binary'] === 'orbit'
        && $request['argv'][0] === 'internal:unattended-upgrades:apply'
        && str_starts_with((string) $request['argv'][1], '--operation-token=')
        && $request['argv'][2] === '--json'
        && $request['operation_id'] === 'unattended-upgrades.apply'
    );
}

function unattended_upgrades_managed_file_request_matches(Request $request, string $action, string $path): bool
{
    if (
        $request->url() !== 'http://10.44.0.42:9477/v1/commands'
        || $request['binary'] !== 'orbit'
        || $request['argv'][0] !== 'internal:managed-file'
        || $request['argv'][1] !== $action
        || ! str_starts_with((string) $request['argv'][2], '--operation-token=')
        || $request['argv'][3] !== '--json'
    ) {
        return false;
    }

    $input = json_decode((string) $request['input'], associative: true);

    return is_array($input) && ($input['path'] ?? null) === $path;
}

function managedFileProbeResult(bool $exists, ?string $hash = null, ?string $mode = null): RemoteShellResult
{
    return new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode([
            'exists' => $exists,
            'hash' => $hash,
            'mode' => $mode,
        ], JSON_THROW_ON_ERROR),
        stderr: '',
        durationMs: 1,
    );
}

final class UnattendedUpgradesDriverShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results = [],
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $this->options[] = $options;

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
