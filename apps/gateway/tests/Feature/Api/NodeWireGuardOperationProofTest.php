<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeWireGuardKeyPolicy;
use App\Domain\Nodes\NodeWireGuardOperationExecution;
use App\Domain\Nodes\PrepareNodeWireGuardCommand;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\EffectReceiptMetadata;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\AgentCommands\AgentEffectReceipts;
use App\Infrastructure\AgentCommands\OperationAgentTransport;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Nodes\NodeWireGuardOperationTransport;
use App\Infrastructure\Nodes\NodeWireGuardProjectionLock;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\Fleet\FleetFixtures;

it('renders current peers and publishes only to the Operation hub under the fleet lock', function (bool $exclude): void {
    $home = sys_get_temp_dir().'/orbit-node-wg-'.bin2hex(random_bytes(8));
    $hub = FleetFixtures::node('wg-hub', [RoleName::Vpn]);
    $peer = FleetFixtures::node('wg-peer');
    $peer->update(['wireguard_public_key' => base64_encode(str_repeat('a', 32))]);
    $other = FleetFixtures::node('wg-other');
    $other->update(['wireguard_public_key' => base64_encode(str_repeat('b', 32))]);
    $operation = (new Operation)->forceFill(['id' => 7, 'kind' => 'node.wireguard_hub_converge', 'node_id' => $hub->id,
        'target_type' => 'node', 'target_id' => $hub->id, 'input' => ['context_node_id' => $peer->id]]);
    $private = 'test-private-key-that-must-never-be-returned';
    $input = ['configuration' => ['hub_id' => $hub->id, 'address' => '10.44.0.1/24', 'port' => 51820, 'private_key' => $private],
        'excluded_node_id' => $exclude ? $peer->id : null];
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(function (Node $target, string $name, array $input) use ($hub, $home, $peer, $other, $exclude, $private): bool {
        $lock = fopen($home.'/locks/wireguard-server.lock', 'c+');
        expect(flock($lock, LOCK_EX | LOCK_NB))->toBeFalse();
        fclose($lock);
        expect($input['config'])->toContain('PrivateKey = '.$private, $other->wireguard_public_key);
        if ($exclude) {
            expect($input['config'])->not->toContain($peer->wireguard_public_key);
        } else {
            expect($input['config'])->toContain($peer->wireguard_public_key);
        }

        return $target->is($hub) && $name === 'wireguard.project-hub';
    })->andReturn(['raw' => $private, 'config' => $private]);
    $transport = new NodeWireGuardOperationTransport(new OperationAgentTransport($runner, app(AgentContract::class), new AgentEffectReceipts($runner)), new NodeWireGuardProjectionLock($home),
        new WireGuardServerConfigRenderer, new ProtectedFileWriter, $home);
    try {
        expect($transport->run($operation, ['name' => 'wireguard.project-hub', 'effect_key' => 'op-7.hub-project', 'effect_metadata' => new EffectReceiptMetadata([$private])], $input))->toBe(['projected' => true]);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
})->with([false, true]);

it('captures only a secret reference and explicit null before hub publication', function (): void {
    $hub = FleetFixtures::node('prepare-wg-hub', [RoleName::Vpn]);
    $peer = FleetFixtures::node('prepare-wg-peer');
    $operation = Operation::query()->create(['kind' => 'node.wireguard_hub_converge', 'target_type' => 'node', 'target_id' => $hub->id,
        'node_id' => $hub->id, 'input' => ['context_node_id' => $peer->id, 'excluded_node_id' => null],
        'status' => 'running', 'worker' => 'wg-worker', 'sequence' => 1, 'attempts' => 1]);
    $command = new PrepareNodeWireGuardCommand(app(OperationStore::class));
    expect($command->run($operation, $operation->input))->toBe(['prepared' => true])
        ->and($operation->refresh()->input['_wireguard'])->toBe([
            'configuration' => ['$secret' => 'node.vpn_configuration', 'id' => $hub->id], 'excluded_node_id' => null,
        ]);
});

it('normalizes recovered native hub replies just like live replies', function (?string $error, array $expected): void {
    $execution = new NodeWireGuardOperationExecution;
    $reply = $error === null ? [] : ['error_code' => $error];
    expect($execution->received(new Operation, ['name' => 'wireguard.project-hub'], $reply))->toBe($expected);
})->with([
    [null, ['projected' => true]],
    ['wireguard.hub_install_failed', ['error_code' => 'vpn.server_config_install_failed']],
    ['wireguard.hub_config_invalid', ['error_code' => 'vpn.server_config_invalid']],
    ['wireguard.hub_start_failed', ['error_code' => 'vpn.server_start_failed']],
]);

it('keeps the Gateway-first, managed-peer and address guards', function (string $variant, string $expected): void {
    $node = FleetFixtures::node('wg-policy', $variant === 'gateway' ? [RoleName::Gateway] : []);
    if ($variant === 'address') {
        $node->wireguard_ip = null;
    } elseif ($variant === 'unmanaged') {
        $node->ssh_host_fingerprint = null;
    }
    $policy = new NodeWireGuardKeyPolicy;
    if ($variant === 'unmanaged') {
        $policy->assertMutable($node);
        expect(true)->toBeTrue();
    } else {
        expect(fn () => $policy->assertMutable($node))->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe($expected));
    }
})->with([
    ['gateway', 'node.wireguard_key_gateway'], ['managed', 'node.wireguard_key_managed'],
    ['address', 'node.wireguard_ip_required'], ['unmanaged', ''],
]);
