<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeTool;
use App\Models\WireGuardPeer;
use App\Services\OrbitHostInstaller;
use App\Services\OrbitHostInstallResult;
use App\Services\Platform\PlatformDetector;
use App\Services\Tools\ToolInstaller;
use App\Services\Trust\TrustStoreInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => true]);

    $this->fakeHostInstaller = new class extends OrbitHostInstaller
    {
        public int $calls = 0;

        public function install(string $host, string $sshUser, string $runtimeUser = 'orbit', bool $asGateway = false): OrbitHostInstallResult
        {
            $this->calls++;

            return new OrbitHostInstallResult(successful: true);
        }
    };
    app()->instance(OrbitHostInstaller::class, $this->fakeHostInstaller);

    $this->fakeToolInstaller = new class
    {
        /** @var list<array{tool: string, node: ?string, expectedState: string}> */
        public array $installCalls = [];

        /**
         * @param  array<string, mixed>  $config
         * @return array{name: string, node: ?string, state: string}
         */
        public function install(string $tool, ?string $node = null, ?string $app = null, string $expectedState = 'installed', array $config = []): array
        {
            $this->installCalls[] = [
                'tool' => $tool,
                'node' => $node,
                'expectedState' => $expectedState,
            ];

            return [
                'name' => $tool,
                'node' => $node,
                'state' => $expectedState,
            ];
        }
    };
    app()->bind(ToolInstaller::class, fn () => $this->fakeToolInstaller);

    app()->instance(RemoteShell::class, new class implements RemoteShell
    {
        /** @var list<array{node: string, script: string, options: array<string, mixed>}> */
        public array $calls = [];

        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            $this->calls[] = [
                'node' => (string) $node->name,
                'script' => $script,
                'options' => $options,
            ];

            return new RemoteShellResult(
                exitCode: 0,
                stdout: '',
                stderr: '',
                durationMs: 0,
            );
        }
    });

    app()->instance(PlatformDetector::class, new class extends PlatformDetector
    {
        public function detectLocal(): string
        {
            return 'ubuntu_24-04';
        }
    });

    app()->instance(TrustStoreInstaller::class, new class implements TrustStoreInstaller
    {
        public function isCaTrusted(string $rootCaPath, string $label): bool
        {
            return true;
        }

        public function trustCa(string $rootCaPath, string $label, ?Closure $log = null): void {}
    });

    $gateway = Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'platform' => 'ubuntu',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'gateway_endpoint' => '10.6.0.2',
        'user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'active',
    ]);

    WireGuardPeer::query()->create([
        'node_id' => $gateway->id,
        'public_key' => 'gateway-public-key',
        'private_key' => 'gateway-private-key',
        'allowed_ips' => '10.6.0.2/32',
    ]);

    Process::fake(function ($process) {
        $command = (string) $process->command;

        if ($command === 'ssh-keygen -y -f ~/.ssh/id_ed25519') {
            return Process::result(output: "ssh-ed25519 AAAATEST gateway\n");
        }

        if (str_contains($command, 'ssh-keyscan')) {
            return Process::result(output: "192.0.2.10 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests\n");
        }

        if ($command === 'wg genkey') {
            return Process::result(output: "agent-private-key\n");
        }

        if ($command === 'wg pubkey') {
            return Process::result(output: "agent-public-key\n");
        }

        if (str_contains($command, 'authorized_keys')) {
            return Process::result();
        }

        if (str_contains($command, 'orbit:internal:detect-platform')) {
            return Process::result(output: "ubuntu_24-04\n");
        }

        if (str_contains($command, 'wg show wg0 public-key')) {
            return Process::result(output: "gateway-wg-public-key\n");
        }

        return Process::result();
    });
    Process::preventStrayProcesses();
});

afterEach(function (): void {
    File::deleteDirectory(storage_path('app/orbit/node-development-dns.d'));
});

it('provisions an agent node and verifies node doctor readiness', function (): void {
    $nodeNewExitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--self-grant' => 'default',
        '--agent-tool' => ['openclaw'],
        '--json' => true,
    ]);
    $nodeNewPayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    $node = Node::query()
        ->with('roleAssignments')
        ->where('name', 'agent-1')
        ->first();

    $selfGrant = $node instanceof Node
        ? NodeAccess::query()
            ->where('consumer_node_id', $node->id)
            ->where('serving_node_id', $node->id)
            ->first()
        : null;

    expect($nodeNewExitCode)->toBe(0, json_encode($nodeNewPayload, JSON_THROW_ON_ERROR))
        ->and($nodeNewPayload['success']['data']['node']['name'] ?? null)->toBe('agent-1')
        ->and($node)->toBeInstanceOf(Node::class)
        ->and($node->roleAssignments)->toHaveCount(1)
        ->and($node->roleAssignments->first()?->role)->toBe('agent')
        ->and($node->roleAssignments->first()?->status)->toBe(NodeRoleStatus::Active->value)
        ->and($node->roleAssignments->first()?->settings)->toBe(['tld' => 'agent'])
        ->and($selfGrant)->not->toBeNull()
        ->and($selfGrant->permissions)->toBe([
            'doctor:verify',
            'node:read',
            'tool:read',
            'tool:restart',
            'tool:update:agent-tools',
        ])
        ->and(WireGuardPeer::query()->where('node_id', $node->id)->exists())->toBeTrue()
        ->and(NodeTool::query()->where('node_id', $node->id)->where('name', 'caddy')->exists())->toBeTrue()
        ->and(NodeTool::query()->where('node_id', $node->id)->where('name', 'supervisor')->exists())->toBeTrue()
        ->and($this->fakeToolInstaller->installCalls)->toBe([
            [
                'tool' => 'openclaw',
                'node' => 'agent-1',
                'expectedState' => 'installed',
            ],
        ]);

    $doctorExitCode = Artisan::call('doctor', [
        '--family' => ['node'],
        '--node' => 'agent-1',
        '--json' => true,
    ]);
    $doctorPayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($doctorExitCode)->toBe(0, json_encode($doctorPayload, JSON_THROW_ON_ERROR))
        ->and($doctorPayload['success']['data']['doctor']['healthy'])->toBeTrue()
        ->and($doctorPayload['success']['data']['doctor']['scope']['families'])->toBe(['node'])
        ->and($doctorPayload['success']['data']['doctor']['scope']['node'])->toBe('agent-1')
        ->and($doctorPayload['success']['data']['doctor']['summary']['issues'])->toBe(0);
});
