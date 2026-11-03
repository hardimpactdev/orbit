<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\Fleet\FleetFixtures;

/** Public enrollment tests fake only the worker transport and pinned artifacts. */
final class NativeNodeEnrollmentFixtures
{
    public static function bind(): \Closure
    {
        FleetFixtures::bind();
        $home = sys_get_temp_dir().'/orbit-enrollment-api-'.bin2hex(random_bytes(5));
        mkdir($home.'/wireguard', 0700, true);
        file_put_contents($home.'/bootstrap.key', 'fixture-bootstrap-private-value');
        file_put_contents($home.'/wireguard/public.key', base64_encode(str_repeat('w', 32)));
        file_put_contents($home.'/delivery.json', json_encode(['commit' => str_repeat('a', 40), 'version' => '0.9.0',
            'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('b', 64), 'url' => 'https://example.invalid/agent']]], JSON_THROW_ON_ERROR));
        config(['orbit.home' => $home, 'swamp.run_token' => 'fixture.token']);
        app()->instance(AgentDelivery::class, new AgentDelivery($home.'/delivery.json', $home.'/cache'));
        $keys = Mockery::mock(SshKeyProvider::class);
        $keys->shouldReceive('privateKeyPath')->andReturn($home.'/bootstrap.key');
        $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 fixture-public-key');
        app()->instance(SshKeyProvider::class, $keys);
        $ca = Mockery::mock(LeafCertificateSigner::class);
        $ca->shouldReceive('rootCertificate')->andReturn('fixture public root certificate');
        app()->instance(LeafCertificateSigner::class, $ca);
        $socket = new FakeRoutePublicationSocket;
        $socket->reply = fn (array $sent): array => [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => [
            'vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]];
        app()->instance(WebSocketClient::class, $socket);
        if (! Node::query()->whereKey(209)->exists()) {
            $control = Node::query()->forceCreate(['id' => 209, 'name' => 'native-control', 'platform' => 'linux',
                'architecture' => 'x86_64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.209', 'wireguard_ip' => '10.44.0.209',
                'status' => 'active', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'control-public-key']);
            $control->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
        }
        if (! Node::query()->whereHas('roles', fn ($query) => $query->where('role', 'vpn'))->exists()) {
            FleetFixtures::node('native-vpn', [RoleName::Vpn])->update(['wireguard_public_key' => base64_encode(str_repeat('w', 32))]);
        }
        Node::query()->whereHas('roles', fn ($query) => $query->where('role', 'gateway'))->update([
            'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit',
            'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'control-public-key']);
        $level = DB::transactionLevel();
        DB::beginTransaction();

        return function () use ($home, $level): void {
            while (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
            (new Filesystem)->deleteDirectory($home);
        };
    }
}
