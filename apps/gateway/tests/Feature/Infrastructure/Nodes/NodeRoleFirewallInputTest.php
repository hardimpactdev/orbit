<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

it('captures Grafana isolation before its Gateway allow so insertion gives the allow priority', function (): void {
    Http::preventStrayRequests();
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '10.44.0.1', 'user' => 'orbit',
    ]));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $metrics = new Node(['wireguard_ip' => '10.44.0.3', 'user' => 'orbit']);

    $commands = app(NodeRoleFirewallInput::class)->convergeCommands($metrics, RoleName::Metrics);

    expect(array_slice($commands['desired'], -2))->toBe([
        ['ufw', 'insert', '1', 'deny', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', 'any', 'to', '10.44.0.3', 'port', '3000', 'comment', 'orbit:metrics-grafana-isolation'],
        ['ufw', 'insert', '1', 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', '10.44.0.1', 'to', '10.44.0.3', 'port', '3000', 'comment', 'orbit:metrics-grafana-upstream'],
    ]);
    Http::assertNothingSent();
});

it('refuses a Metrics firewall intent without one active Gateway', function (): void {
    $metrics = new Node(['wireguard_ip' => '10.44.0.3', 'user' => 'orbit']);

    expect(fn () => app(NodeRoleFirewallInput::class)->convergeCommands($metrics, RoleName::Metrics))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('metrics.gateway_ambiguous'));
});
