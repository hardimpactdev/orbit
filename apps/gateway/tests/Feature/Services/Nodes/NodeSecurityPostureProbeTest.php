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

it('forces the gateway host boundary for containerized gateway posture probes', function (): void {
    $previous = getenv('ORBIT_GATEWAY_EXPOSURE_MODE');
    putenv('ORBIT_GATEWAY_EXPOSURE_MODE=router-colocated');

    try {
        $captured = null;
        $executor = new class($captured) implements \App\Services\RemoteShell\RunsInternalCommands {
            /** @param array<string, mixed>|null $captured */
            public function __construct(
                public ?array &$captured,
            ) {}

            public function runInternal(
                Node $node,
                string $commandName,
                array $arguments = [],
                array $commandOptions = [],
                array $transportOptions = [],
            ): RemoteShellResult {
                $this->captured = [
                    'node' => $node->name,
                    'command' => $commandName,
                    'transportOptions' => $transportOptions,
                ];

                return new RemoteShellResult(
                    exitCode: 0,
                    stdout: json_encode([
                        'success' => [
                            'data' => [
                                'runtime_user' => true,
                                'sshd_config' => true,
                                'sshd_listen' => true,
                                'sysctl' => true,
                                'home_perms' => true,
                            ],
                            'meta' => [],
                        ],
                    ], JSON_THROW_ON_ERROR),
                    stderr: '',
                    durationMs: 1,
                );
            }
        };

        $node = Node::factory()
            ->gateway()
            ->managed()
            ->create([
                'platform' => 'ubuntu_24-04',
                'status' => NodeStatus::Active,
                'wireguard_address' => '10.44.0.1',
                'user' => 'orbit',
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

        $drift = new NodeSecurityPostureProbe(localExecutor: $executor)->diff($node);

        expect($drift)
            ->toBe([])
            ->and($captured)
            ->toMatchArray([
                'node' => $node->name,
                'command' => 'internal:node-security-posture:probe',
            ])
            ->and($captured['transportOptions']['force_remote_host'] ?? null)
            ->toBeTrue();
    } finally {
        if ($previous === false) {
            putenv('ORBIT_GATEWAY_EXPOSURE_MODE');
        } else {
            putenv("ORBIT_GATEWAY_EXPOSURE_MODE={$previous}");
        }
    }
});

it('does not treat client-owned bootstrap SSH host keys as gateway steady-state posture', function (): void {
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
        ->not
        ->toContain("node.security.host_key.{$node->name}")
        ->toContain('node.security.runtime_user')
        ->toContain('node.security.public_ssh_deny');
});

it('accepts a custom Orbit runtime user from the node record', function (): void {
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
        ->managed()
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
        ->managed()
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

it('reports unverifiable posture when the remote probe raises', function (): void {
    $node = node_security_posture_managed_node();
    $executor = new class implements \App\Services\RemoteShell\RunsInternalCommands {
        public function runInternal(
            Node $node,
            string $commandName,
            array $arguments = [],
            array $commandOptions = [],
            array $transportOptions = [],
        ): RemoteShellResult {
            throw new RuntimeException('agent transport exploded');
        }
    };

    $drift = new NodeSecurityPostureProbe(localExecutor: $executor)->diff($node);
    $issue = collect($drift)->first(
        fn (DriftEntry $entry): bool => $entry->key === 'node.security.posture_probe_failed',
    );

    expect($issue)
        ->not
        ->toBeNull()
        ->and($issue?->kind)
        ->toBe(\App\Enums\DriftKind::Unverifiable)
        ->and($issue?->detail)
        ->toMatchArray([
            'reason' => 'exception',
            'error' => 'agent transport exploded',
        ]);
});

it('reports unverifiable posture when the remote probe returns non-success', function (): void {
    $node = node_security_posture_managed_node();
    $executor = new class implements \App\Services\RemoteShell\RunsInternalCommands {
        public function runInternal(
            Node $node,
            string $commandName,
            array $arguments = [],
            array $commandOptions = [],
            array $transportOptions = [],
        ): RemoteShellResult {
            return new RemoteShellResult(
                exitCode: 7,
                stdout: '',
                stderr: "permission denied reading sshd\n",
                durationMs: 1,
            );
        }
    };

    $drift = new NodeSecurityPostureProbe(localExecutor: $executor)->diff($node);
    $issue = collect($drift)->first(
        fn (DriftEntry $entry): bool => $entry->key === 'node.security.posture_probe_failed',
    );

    expect($issue)
        ->not
        ->toBeNull()
        ->and($issue?->kind)
        ->toBe(\App\Enums\DriftKind::Unverifiable)
        ->and($issue?->detail)
        ->toMatchArray([
            'reason' => 'non_success',
            'error' => 'permission denied reading sshd',
            'exit_code' => 7,
        ]);
});

it('reports unverifiable posture when the remote probe payload is empty or malformed', function (): void {
    $node = node_security_posture_managed_node();
    $executor = new class implements \App\Services\RemoteShell\RunsInternalCommands {
        public function runInternal(
            Node $node,
            string $commandName,
            array $arguments = [],
            array $commandOptions = [],
            array $transportOptions = [],
        ): RemoteShellResult {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: 'not-json',
                stderr: '',
                durationMs: 1,
            );
        }
    };

    $drift = new NodeSecurityPostureProbe(localExecutor: $executor)->diff($node);
    $issue = collect($drift)->first(
        fn (DriftEntry $entry): bool => $entry->key === 'node.security.posture_probe_failed',
    );

    expect($issue)
        ->not
        ->toBeNull()
        ->and($issue?->kind)
        ->toBe(\App\Enums\DriftKind::Unverifiable)
        ->and($issue?->detail['reason'] ?? null)
        ->toBe('malformed_payload');
});

it('reports unverifiable posture for failure JSON envelopes with exit zero', function (): void {
    $node = node_security_posture_managed_node();
    $executor = new class implements \App\Services\RemoteShell\RunsInternalCommands {
        public function runInternal(
            Node $node,
            string $commandName,
            array $arguments = [],
            array $commandOptions = [],
            array $transportOptions = [],
        ): RemoteShellResult {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'error' => [
                        'code' => 'probe_failed',
                        'message' => 'posture script refused',
                    ],
                ], JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 1,
            );
        }
    };

    $drift = new NodeSecurityPostureProbe(localExecutor: $executor)->diff($node);
    $issue = collect($drift)->first(
        fn (DriftEntry $entry): bool => $entry->key === 'node.security.posture_probe_failed',
    );

    expect($issue)
        ->not->toBeNull()->and($issue?->kind)->toBe(\App\Enums\DriftKind::Unverifiable)->and(
            collect($drift)->pluck('key')->all(),
        )
        ->not->toContain('node.security.sshd_config');
});

it('never scans target SSH while snapshotting or adopting node security posture', function (): void {
    $node = Node::factory()->create([
        'host' => '203.0.113.44',
        'platform' => 'ubuntu_24-04',
        'status' => NodeStatus::Active,
        'host_key_type' => null,
        'host_key_public' => null,
        'host_key_fingerprint' => null,
    ]);
    Process::fake();
    Process::preventStrayProcesses();

    $probe = app(NodeSecurityPostureProbe::class);
    $snapshot = $probe->snapshotForAdopt($node);
    $results = $probe->adopt($node, $snapshot);

    expect($snapshot->items)
        ->toBeEmpty()
        ->and($results)
        ->toBe([]);
    Process::assertNothingRan();
});

function node_security_posture_managed_node(): Node
{
    $node = Node::factory()
        ->appDev()
        ->managed()
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

    return $node;
}

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
        && agentPushRequestOperationIdMatchesToken($request)
    );
}

function node_security_posture_executor(): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: node_security_posture_gateway_secret(),
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        outputRedactor: app(\App\Services\RemoteShell\RemoteExecutorOutputRedactor::class),
        agentPush: app(\App\Services\NodeCommandTransport\NodeAgentPushDispatcher::class),
        applicationKey: node_security_posture_gateway_secret(),
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

it('restores home_perms through SecurityInstallerTransport for managed agent nodes', function (): void {
    $node = Node::factory()
        ->managed()
        ->create([
            'platform' => 'ubuntu_24-04',
            'status' => NodeStatus::Active,
            'wireguard_address' => '10.44.0.84',
            'user' => 'agent',
            'name' => 'agent-home',
        ]);

    $scripts = [];
    $shell = new class($scripts) implements \App\Contracts\RemoteShell {
        /** @param list<string> $scripts */
        public function __construct(
            public array &$scripts,
        ) {}

        public function run(
            \App\Models\Node $node,
            string $script,
            array $options = [],
        ): \App\Data\RemoteShell\RemoteShellResult {
            $this->scripts[] = $script;

            return new \App\Data\RemoteShell\RemoteShellResult(
                exitCode: 0,
                stdout: '',
                stderr: '',
                durationMs: 1,
            );
        }

        public function start(
            \App\Models\Node $node,
            string $script,
            array $options = [],
        ): \Illuminate\Contracts\Process\InvokedProcess {
            throw new RuntimeException('not used');
        }
    };
    app()->instance(\App\Contracts\RemoteShell::class, $shell);

    new NodeSecurityPostureProbe()->restore(
        $node,
        new DriftEntry(
            family: 'node',
            key: 'node.security.home_perms',
            kind: \App\Enums\DriftKind::Divergent,
            summary: 'home perms weak',
            detail: ['check' => 'home_perms'],
        ),
    );

    expect($scripts)
        ->not->toBeEmpty()->and(implode("\n", $scripts))->toContain("MANAGED_HOME='/home/agent'")->toContain(
            'sudo chmod 0700',
        )
        ->not->toContain('sudo install -d')->toContain('managed home missing');
});

it('keeps runtime_user restore report-only', function (): void {
    $node = Node::factory()->create([
        'platform' => 'ubuntu_24-04',
        'status' => NodeStatus::Active,
        'user' => '',
    ]);

    expect(fn () => new NodeSecurityPostureProbe()->restore(
        $node,
        new DriftEntry(
            family: 'node',
            key: 'node.security.runtime_user',
            kind: \App\Enums\DriftKind::Divergent,
            summary: 'missing user',
        ),
    ))
        ->toThrow(RuntimeException::class, 'report-only');
});
