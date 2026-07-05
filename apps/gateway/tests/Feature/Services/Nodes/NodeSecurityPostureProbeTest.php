<?php

declare(strict_types=1);

use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeStatus;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Nodes\NodeSecurityPostureProbe;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\Security\SshHostKeyPinner;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Security\OperationTokenSigner;

uses(RefreshDatabase::class);

it('does not depend on host PHP for host-lane node security probes', function (): void {
    expect((string) file_get_contents(app_path('Services/Nodes/NodeSecurityPostureProbe.php')))
        ->not
        ->toContain('php -r');
});

it('reports missing host key material and missing runtime users under node security keys', function (): void {
    $node = Node::factory()->create([
        'platform' => 'ubuntu_24-04',
        'status' => NodeStatus::Active,
        'user' => '',
        'host_key_type' => null,
        'host_key_public' => null,
        'host_key_fingerprint' => null,
    ]);

    $drift = app(NodeSecurityPostureProbe::class)->diff($node);

    expect(array_map(fn (DriftEntry $entry): string => $entry->key, $drift))
        ->toContain("node.security.host_key.{$node->name}")
        ->toContain('node.security.runtime_user')
        ->toContain('node.security.public_ssh_deny');
});

it('accepts a custom steady-state SSH runtime user from the node record', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.84:9477/v1/commands' => node_security_posture_agent_response([
            'runtime_user' => true,
            'sshd_config' => true,
            'sshd_listen' => true,
            'sysctl' => true,
            'home_perms' => true,
        ]),
    ]);
    $node = Node::factory()
        ->appDev()
        ->orbitAgentCapable()
        ->create([
            'platform' => 'ubuntu_24-04',
            'status' => NodeStatus::Active,
            'wireguard_address' => '10.44.0.84',
            'user' => 'nckrtl',
            'host_key_type' => 'ssh-ed25519',
            'host_key_public' => 'AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests',
            'host_key_fingerprint' => 'SHA256:test',
            'host_key_pin_mode' => 'verified',
            'host_key_pinned_at' => now(),
        ]);
    FirewallRule::factory()->create([
        'node_id' => $node->id,
        'address_family' => 'v4',
        'owner' => 'node-security',
        'protected' => true,
        'port' => '22',
        'action' => 'deny',
        'direction' => 'incoming',
        'interface' => 'public',
    ]);
    FirewallRule::factory()->create([
        'node_id' => $node->id,
        'address_family' => 'v6',
        'owner' => 'node-security',
        'protected' => true,
        'port' => '22',
        'action' => 'deny',
        'direction' => 'incoming',
        'interface' => 'public',
    ]);
    $drift = new NodeSecurityPostureProbe(
        remoteShell: node_security_posture_unused_transport(),
        localExecutor: node_security_posture_executor(),
    )->diff($node);

    expect($drift)
        ->toBe([]);

    Http::assertSent(fn (Request $request): bool => node_security_posture_request_matches(
        request: $request,
        managedUser: 'nckrtl',
    ));
});

it('reports remote node security drift from the posture script', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.84:9477/v1/commands' => node_security_posture_agent_response([
            'runtime_user' => true,
            'sshd_config' => false,
            'sshd_listen' => true,
            'unattended_upgrades' => false,
            'sysctl' => true,
            'home_perms' => false,
        ]),
    ]);
    $node = Node::factory()
        ->appDev()
        ->orbitAgentCapable()
        ->create([
            'platform' => 'ubuntu_24-04',
            'status' => NodeStatus::Active,
            'wireguard_address' => '10.44.0.84',
            'user' => 'orbit',
            'host_key_type' => 'ssh-ed25519',
            'host_key_public' => 'AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests',
            'host_key_fingerprint' => 'SHA256:test',
            'host_key_pin_mode' => 'verified',
            'host_key_pinned_at' => now(),
        ]);
    FirewallRule::factory()->create([
        'node_id' => $node->id,
        'address_family' => 'v4',
        'owner' => 'node-security',
        'protected' => true,
        'port' => '22',
        'action' => 'deny',
        'direction' => 'incoming',
        'interface' => 'public',
    ]);
    FirewallRule::factory()->create([
        'node_id' => $node->id,
        'address_family' => 'v6',
        'owner' => 'node-security',
        'protected' => true,
        'port' => '22',
        'action' => 'deny',
        'direction' => 'incoming',
        'interface' => 'public',
    ]);

    $drift = new NodeSecurityPostureProbe(
        remoteShell: node_security_posture_unused_transport(),
        localExecutor: node_security_posture_executor(),
    )->diff($node);

    expect(array_map(fn (DriftEntry $entry): string => $entry->key, $drift))
        ->toBe([
            'node.security.sshd_config',
            'node.security.home_perms',
        ]);

    Http::assertSent(fn (Request $request): bool => node_security_posture_request_matches(
        request: $request,
        managedUser: 'orbit',
    ));
});

it('can adopt the first host key pin for legacy nodes', function (): void {
    $publicKey = 'AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests';
    $node = Node::factory()->create([
        'host' => '203.0.113.44',
        'platform' => 'ubuntu_24-04',
        'status' => NodeStatus::Active,
        'host_key_type' => null,
        'host_key_public' => null,
        'host_key_fingerprint' => null,
    ]);
    Process::fake([
        'ssh-keyscan*' => Process::result(output: "203.0.113.44 ssh-ed25519 {$publicKey}\n"),
    ]);
    Process::preventStrayProcesses();

    $probe = app(NodeSecurityPostureProbe::class);
    $results = $probe->adopt($node, $probe->snapshotForAdopt($node, includeHostKey: true));

    expect($results[0]->action->value)
        ->toBe('updated')
        ->and($node->refresh()->host_key_type)
        ->toBe('ssh-ed25519')
        ->and($node->host_key_fingerprint)
        ->toBe(SshHostKeyPinner::fingerprintForPublicKey($publicKey));
});

/**
 * @param  array<string, mixed>  $data
 */
function node_security_posture_agent_response(array $data): mixed
{
    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => 'node-security-posture.probe',
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

function node_security_posture_request_matches(Request $request, string $managedUser): bool
{
    return (
        $request->url() === 'http://10.44.0.84:9477/v1/commands'
        && $request['binary'] === 'orbit'
        && $request['argv'][0] === 'internal:node-security-posture:probe'
        && $request['argv'][1] === $managedUser
        && str_starts_with((string) $request['argv'][2], '--operation-token=')
        && $request['argv'][3] === '--json'
        && $request['operation_id'] === 'node-security-posture.probe'
    );
}

function node_security_posture_executor(): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
        transport: node_security_posture_unused_transport(),
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: node_security_posture_gateway_secret(),
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        operationTokenSecret: node_security_posture_gateway_secret(),
    );
}

function node_security_posture_gateway_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

function node_security_posture_unused_transport(): RemoteExecutor
{
    return new class implements RemoteExecutor {
        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            throw new RuntimeException('SSH transport should not be called for node security posture probes.');
        }

        public function start(Node $node, string $script, array $options = []): InvokedProcess
        {
            throw new RuntimeException('Node security posture tests do not start long-running transports.');
        }
    };
}
