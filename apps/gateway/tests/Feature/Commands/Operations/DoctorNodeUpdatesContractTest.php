<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Platform\PlatformDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(PlatformDetector::class, new class extends PlatformDetector
    {
        public function detectLocal(): string
        {
            return 'ubuntu_24-04';
        }
    });
});

function createDoctorNodeUpdatesGateway(array $attributes = []): Node
{
    config(['orbit.is_gateway' => true]);

    $node = Node::factory()->create([
        'name' => 'updates-gateway',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.6.0.1',
        'wireguard_address' => null,
        'user' => 'orbit',
        'host_key_type' => 'ed25519',
        'host_key_public' => 'ssh-ed25519 AAAATEST',
        'host_key_fingerprint' => 'SHA256:test',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
        ...$attributes,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
        'settings' => [],
    ]);

    return $node;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function doctorNodeUpdatesFacts(array $overrides = []): array
{
    return [
        'installed' => true,
        'auto_exists' => true,
        'unattended_exists' => true,
        'auto_hash_ok' => true,
        'unattended_hash_ok' => true,
        'dry_run_exit' => 0,
        'last_run_status' => 'completed',
        'reboot_required' => false,
        'reboot_required_packages' => [],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function doctorNodeUpdatesProbeResult(array $overrides = []): RemoteShellResult
{
    return new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode(doctorNodeUpdatesFacts($overrides), JSON_THROW_ON_ERROR),
        stderr: '',
        durationMs: 1,
    );
}

/**
 * @return array<string, mixed>
 */
function doctorNodeUpdatesPayload(): array
{
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    return $payload['success']['data']['doctor'] ?? $payload['error']['data']['doctor'];
}

describe('doctor node updates contract', function (): void {
    it('returns healthy JSON for a supported node with healthy update posture', function (): void {
        createDoctorNodeUpdatesGateway();
        $shell = new DoctorNodeUpdatesShell([
            doctorNodeUpdatesProbeResult(),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('doctor', [
            '--family' => ['node'],
            '--key' => 'node.updates',
            '--json' => true,
        ]);
        $doctor = doctorNodeUpdatesPayload();

        expect($exitCode)->toBe(0)
            ->and($doctor['healthy'])->toBeTrue()
            ->and($doctor['issues'])->toBe([])
            ->and($shell->scripts)->toHaveCount(1)
            ->and($shell->scripts[0])->toContain('sudo unattended-upgrade --dry-run');
    });

    it('reports reboot-required update drift as non-restorable JSON', function (): void {
        createDoctorNodeUpdatesGateway();
        app()->instance(RemoteShell::class, new DoctorNodeUpdatesShell([
            doctorNodeUpdatesProbeResult([
                'reboot_required' => true,
                'reboot_required_packages' => ['linux-image-generic'],
            ]),
        ]));

        $exitCode = Artisan::call('doctor', [
            '--family' => ['node'],
            '--key' => 'node.updates',
            '--json' => true,
        ]);
        $doctor = doctorNodeUpdatesPayload();

        expect($exitCode)->toBe(1)
            ->and($doctor['issues'][0])->toMatchArray([
                'family' => 'node',
                'node' => 'updates-gateway',
                'key' => 'node.updates',
                'code' => 'node.updates_reboot_required',
                'restorable' => false,
                'summary' => 'This node requires an explicit reboot to finish installed updates. Orbit will not reboot it automatically. Reboot this server as soon as possible.',
            ]);
    });

    it('renders explicit reboot guidance in human output', function (): void {
        createDoctorNodeUpdatesGateway();
        app()->instance(RemoteShell::class, new DoctorNodeUpdatesShell([
            doctorNodeUpdatesProbeResult([
                'reboot_required' => true,
                'reboot_required_packages' => ['linux-image-generic'],
            ]),
        ]));

        $exitCode = Artisan::call('doctor', [
            '--family' => ['node'],
            '--key' => 'node.updates',
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('This node requires an explicit reboot to finish installed updates.')
            ->and($output)->toContain('Orbit will not reboot it automatically. Reboot this server as soon as possible.');
    });

    it('returns healthy JSON for a node without a supported update driver', function (): void {
        createDoctorNodeUpdatesGateway(['platform' => 'macos_15']);
        $shell = new DoctorNodeUpdatesShell([]);
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('doctor', [
            '--family' => ['node'],
            '--key' => 'node.updates',
            '--json' => true,
        ]);
        $doctor = doctorNodeUpdatesPayload();

        expect($exitCode)->toBe(0)
            ->and($doctor['healthy'])->toBeTrue()
            ->and($doctor['issues'])->toBe([])
            ->and($shell->scripts)->toBe([]);
    });

    it('re-probes after restoring node updates and keeps reboot drift visible', function (): void {
        createDoctorNodeUpdatesGateway();
        $shell = new DoctorNodeUpdatesShell([
            doctorNodeUpdatesProbeResult(['auto_hash_ok' => false]),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'completed', stderr: '', durationMs: 1),
            doctorNodeUpdatesProbeResult(['reboot_required' => true]),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('doctor', [
            '--family' => ['node'],
            '--restore' => true,
            '--key' => 'node.updates',
            '--json' => true,
        ]);
        $doctor = doctorNodeUpdatesPayload();

        expect($exitCode)->toBe(1)
            ->and($doctor['actions'][0])->toMatchArray([
                'family' => 'node',
                'node' => 'updates-gateway',
                'key' => 'node.updates',
                'code' => 'node.updates_config_mismatch',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($doctor['issues'][0])->toMatchArray([
                'family' => 'node',
                'node' => 'updates-gateway',
                'key' => 'node.updates',
                'code' => 'node.updates_reboot_required',
                'restorable' => false,
            ])
            ->and($shell->scripts)->toHaveCount(4)
            ->and($shell->scripts)->toContain('sudo unattended-upgrade');
    });

    it('reports unsupported adopt for node updates without applying upgrades', function (): void {
        createDoctorNodeUpdatesGateway();
        $shell = new DoctorNodeUpdatesShell([
            doctorNodeUpdatesProbeResult(['auto_hash_ok' => false]),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('doctor', [
            '--family' => ['node'],
            '--adopt' => true,
            '--key' => 'node.updates',
            '--json' => true,
        ]);
        $doctor = doctorNodeUpdatesPayload();

        expect($exitCode)->toBe(1)
            ->and($doctor['actions'][0])->toMatchArray([
                'family' => 'node',
                'node' => 'updates-gateway',
                'key' => 'node.updates',
                'code' => 'node.updates_config_mismatch',
                'mode' => 'adopt',
                'status' => 'skipped',
            ])
            ->and($doctor['summary']['skipped'])->toBe(1)
            ->and($shell->scripts)->toHaveCount(1)
            ->and($shell->scripts)->not->toContain('sudo unattended-upgrade');
    });

    it('no longer reports unattended-upgrades through node security posture', function (): void {
        createDoctorNodeUpdatesGateway(['wireguard_address' => '10.6.0.1']);
        app()->instance(RemoteShell::class, new DoctorNodeUpdatesShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'runtime_user' => true,
                    'sshd_config' => true,
                    'sshd_listen' => true,
                    'unattended_upgrades' => false,
                    'sysctl' => true,
                    'home_perms' => true,
                ], JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 1,
            ),
        ]));

        $exitCode = Artisan::call('doctor', [
            '--family' => ['node'],
            '--key' => 'node.security.unattended_upgrades',
            '--json' => true,
        ]);
        $doctor = doctorNodeUpdatesPayload();

        expect($exitCode)->toBe(0)
            ->and($doctor['healthy'])->toBeTrue()
            ->and($doctor['issues'])->toBe([]);
    });
});

final class DoctorNodeUpdatesShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(private array $results) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(
            exitCode: 1,
            stdout: '',
            stderr: 'unexpected remote shell call',
            durationMs: 1,
        );
    }
}
