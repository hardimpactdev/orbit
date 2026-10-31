<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeWireGuardCommandInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\VpnSettings;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    app(VpnSettings::class)->configure('10.44.0.0/24');
    $this->hub = FleetFixtures::node('command-hub', [RoleName::Vpn]);
    $this->peer = FleetFixtures::node('command-peer');
    $this->peer->update(['wireguard_public_key' => base64_encode(str_repeat('a', 32))]);
});

it('captures public peer context while Removing without reading hub private custody', function (): void {
    $other = FleetFixtures::node('command-other');
    $other->update(['wireguard_public_key' => base64_encode(str_repeat('b', 32))]);
    $this->peer->update(['status' => LifecycleStatus::Removing]);
    $snapshot = app(NodeWireGuardCommandInput::class)->capture($this->peer);

    expect($snapshot['hub_id'])->toBe($this->hub->id)
        ->and($snapshot['port'])->toBe(51820)
        ->and($snapshot['before'])->toBe(['node_id' => $this->peer->id, 'name' => $this->peer->name,
            'address' => $this->peer->wireguard_ip, 'public_key' => $this->peer->wireguard_public_key])
        ->and(array_column($snapshot['inventory'], 'node_id'))->toContain($other->id, $this->peer->id)
        ->and(json_encode($snapshot, JSON_THROW_ON_ERROR))->not->toContain('private_key', 'configuration', '$secret');
});

it('preserves prior absence and admits only the captured context prospective address', function (): void {
    $this->peer->update(['wireguard_public_key' => null]);
    $snapshot = app(NodeWireGuardCommandInput::class)->capture($this->peer, '10.44.0.240');
    expect($snapshot['before'])->toBeNull()
        ->and($snapshot['desired']['public_key'])->toBeNull()
        ->and($snapshot['desired']['address'])->toBe('10.44.0.240');
});

it('refuses prospective hub address ownership', function (): void {
    expect(fn () => app(NodeWireGuardCommandInput::class)->capture($this->peer, $this->hub->wireguard_ip))
        ->toThrow(ResourceOperationException::class);
});

it('separates captured desired and retiring peers without private config or fake Node identity', function (): void {
    $retiring = FleetFixtures::node('retiring-hub-peer');
    $retiring->update(['wireguard_public_key' => base64_encode(str_repeat('r', 32)), 'status' => LifecycleStatus::Removing]);
    $intent = app(NodeWireGuardCommandInput::class)->captureHub($this->hub);
    expect(array_column($intent['inventory'], 'node_id'))->toContain($this->peer->id)->not->toContain($retiring->id)
        ->and(array_column($intent['retiring'], 'node_id'))->toBe([$retiring->id])
        ->and(json_encode($intent))->not->toContain('private_key', '$secret', 'configuration');
});

it('captures a candidate name and address without replacing the actual Node or prior peer', function (): void {
    $prior = $this->peer->only(['name', 'wireguard_ip', 'wireguard_public_key', 'status']);
    $intent = app(NodeWireGuardCommandInput::class)->capture($this->peer, '10.44.0.240', 'candidate-peer');
    expect($intent['context_node_id'])->toBe($this->peer->id)
        ->and($intent['before']['name'])->toBe($this->peer->name)
        ->and($intent['before']['address'])->toBe($this->peer->wireguard_ip)
        ->and($intent['desired']['name'])->toBe('candidate-peer')
        ->and($intent['desired']['address'])->toBe('10.44.0.240')
        ->and($this->peer->refresh()->only(array_keys($prior)))->toBe($prior);
});

it('refuses an invalid candidate name without changing the actual Node', function (string $name): void {
    expect(fn () => app(NodeWireGuardCommandInput::class)->capture($this->peer, null, $name))
        ->toThrow(ResourceOperationException::class);
})->with(['', 'invalid name', 'bad\nname', str_repeat('a', 64)]);
