<?php

declare(strict_types=1);

use App\Domain\Engine\NodeBusCredentials;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Engine\NatsCredentials;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '10.44.0.1', 'user' => 'orbit',
    ]));
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'app-dev', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit',
    ]));
});

it('gives a Node no bus settings while the Gateway lacks any of the three keys', function (): void {
    config(['orbit.engine.nats.operator_seed' => null, 'orbit.engine.nats.account_seed' => NatsCredentials::newSeed(NatsCredentials::PrefixAccount),
        'orbit.engine.nats.system_seed' => NatsCredentials::newSeed(NatsCredentials::PrefixAccount)]);

    expect(NodeBusCredentials::for($this->node))->toBeNull();
});

it('limits a Node\'s NATS user to its own consumer, receipts, content, report and inbox', function (): void {
    config(['orbit.engine.nats.operator_seed' => NatsCredentials::newSeed(NatsCredentials::PrefixOperator),
        'orbit.engine.nats.account_seed' => NatsCredentials::newSeed(NatsCredentials::PrefixAccount),
        'orbit.engine.nats.system_seed' => NatsCredentials::newSeed(NatsCredentials::PrefixAccount), 'orbit.engine.nats.port' => 4222]);
    $settings = json_decode((string) NodeBusCredentials::for($this->node), true, flags: JSON_THROW_ON_ERROR);
    preg_match('/BEGIN NATS USER JWT-----\n([^\n]+)\n/', $settings['credentials'], $match);
    $claims = json_decode((string) base64_decode(strtr(explode('.', $match[1])[1], '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);
    $id = $this->node->id;

    expect($settings['url'])->toBe('nats://10.44.0.1:4222')
        ->and($claims['name'])->toBe("node-{$id}")
        ->and($claims['nats']['sub']['allow'])->toBe(["_INBOX.node-{$id}.>"])
        ->and($claims['nats']['pub']['allow'])->toBe(['$JS.API.INFO', '$JS.API.STREAM.INFO.ORDERS', "\$JS.API.CONSUMER.INFO.ORDERS.node-{$id}",
            "\$JS.API.CONSUMER.MSG.NEXT.ORDERS.node-{$id}", "\$JS.ACK.ORDERS.node-{$id}.>", "orbit.receipts.{$id}",
            "\$JS.API.DIRECT.GET.KV_CONTENT.\$KV.CONTENT.n{$id}.>", "\$KV.NODES.n{$id}"])
        ->and(NodeBusCredentials::for($this->node))->toBe(json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
});
