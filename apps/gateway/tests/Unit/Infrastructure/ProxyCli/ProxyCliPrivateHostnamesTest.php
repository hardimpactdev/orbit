<?php

declare(strict_types=1);

use App\Domain\Extensions\ConfigValue;
use App\Domain\ProxyCli\ProxyCliHostname;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Routes\ReservedPrivateHostname;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Infrastructure\ProxyCli\ProxyCliPrivateHostnames;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('reserves one safe collector hostname and publishes its VPN address only while setup holds it', function (): void {
    $contribution = app(ProxyCliPrivateHostnames::class);
    $names = ReservedPrivateHostname::names();

    expect($contribution->hostnames())->toBe(['collector.cli-proxy-api.orbit' => null])
        ->and(array_count_values($names)[ProxyCliHostname::Value])->toBe(1)
        ->and(ConfigValue::dnsName(ProxyCliHostname::Value))->toBeTrue()
        ->and(ProxyCliHostname::Value)->toBe(strtolower(ProxyCliHostname::Value))
        ->toEndWith('.orbit')->not->toMatch('/[,\s]/');

    $node = Node::query()->create([
        'name' => 'collector',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.8',
        'ssh_user' => 'orbit',
        'wireguard_ip' => '10.44.0.8',
    ]);
    $state = app(ProxyCliState::class);
    $state->enable($node->id, 'valkey', 'http://127.0.0.1:8317', 'management', 'read', 'control');

    expect($contribution->hostnames())->toBe(['collector.cli-proxy-api.orbit' => '10.44.0.8'])
        ->and(Ipv4Subnet::from('10.44.0.0/24')->containsUsableAddress('10.44.0.8'))->toBeTrue();

    $state->disable();

    expect($contribution->hostnames())->toBe(['collector.cli-proxy-api.orbit' => null])
        ->and(ReservedPrivateHostname::names())->toContain(ProxyCliHostname::Value);
});
