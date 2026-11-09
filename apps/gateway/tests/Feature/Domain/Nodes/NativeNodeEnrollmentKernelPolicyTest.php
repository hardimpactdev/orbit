<?php

declare(strict_types=1);

use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\NativeNodeEnrollmentInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Swamp\SwampConnection;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

it('gives a new Linux Node the kernel policy that node:converge applies', function (): void {
    $gateway = SwampNodeFixture::ensure();
    $gateway->forceFill(['ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key'])->saveQuietly();
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $gateway->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    config(['swamp.run_token' => 'test-token', 'swamp.read_token' => 'read-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
    config(['swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' control']);
    $folder = sys_get_temp_dir().'/orbit-enrollment-kernel-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($folder.'/wireguard');
    file_put_contents($folder.'/wireguard/public.key', base64_encode(str_repeat('h', 32))."\n");
    config(['orbit.home' => $folder]);
    file_put_contents($folder.'/id_ed25519', 'private-test-key');
    file_put_contents($folder.'/agent.json', json_encode(['commit' => str_repeat('a', 40), 'version' => '1.2.3',
        'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('b', 64), 'url' => 'https://artifacts.example/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($folder.'/agent.json', '/unused'));
    app()->instance(SshKeyProvider::class, new class($folder) implements SshKeyProvider
    {
        public function __construct(private string $folder) {}

        public function privateKeyPath(): string
        {
            return $this->folder.'/id_ed25519';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 unused';
        }
    });
    $this->mock(LeafCertificateSigner::class, fn ($mock) => $mock->shouldReceive('rootCertificate')->andReturn("-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----\n"));
    $frames = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $frame) use (&$frames): void {
        $frames[] = $frame;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frames): array {
        $frame = end($frames);

        return [['type' => 'vault.put', 'id' => $frame['id'], 'payload' => ['data' => ['vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
    });
    app()->instance(WebSocketClient::class, $socket);
    $candidate = ['name' => 'joining', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.9', 'public_ssh_port' => 22, 'wireguard_ip' => '10.44.0.30', 'user' => 'orbit'];
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create([...$candidate, 'status' => LifecycleStatus::Provisioning]));
    $operation = Operation::query()->create(['kind' => 'node.add', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => 'queued', 'input' => [], 'triggered_by' => 'test']);

    try {
        $intent = app(NativeNodeEnrollmentInput::class)->capture($node, $operation, ['candidate' => $candidate, 'bootstrap_user' => 'root',
            'requested_architecture' => null, 'managed_user' => 'orbit', 'fingerprint' => 'SHA256:joining', 'roleless_operator' => true],
            new ProvisionNodeData(name: 'joining', publicSshHost: '192.0.2.9'))['intent'];
    } finally {
        app(SwampConnection::class)->release();
        File::deleteDirectory($folder);
    }

    expect($intent['kernel'])->toBe(['files' => [['path' => '/etc/modprobe.d/orbit-no-sctp.conf', 'content' => "install sctp /bin/false\n"]],
        'sysctl' => [['name' => 'net.ipv4.ip_nonlocal_bind', 'value' => '1', 'file' => '/etc/sysctl.d/60-orbit-nonlocal-bind.conf']]]);
});
