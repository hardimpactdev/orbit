<?php

declare(strict_types=1);

use App\Infrastructure\Swamp\SwampWorkerKnownHostsInput;
use Tests\Support\Fleet\FleetFixtures;

it('captures the stored WireGuard pins as deterministic public known-hosts content', function (): void {
    $later = FleetFixtures::node('later-pin');
    $later->updateQuietly(['wireguard_ip' => '10.44.0.9', 'ssh_host_key' => 'later-public-key', 'public_ssh_host' => '192.0.2.9']);
    $first = FleetFixtures::node('first-pin');
    $first->updateQuietly(['wireguard_ip' => '10.44.0.1', 'ssh_host_key' => 'first-public-key', 'public_ssh_host' => '192.0.2.1']);

    $input = app(SwampWorkerKnownHostsInput::class)->capture();
    $content = "10.44.0.1 ssh-ed25519 first-public-key\n10.44.0.9 ssh-ed25519 later-public-key\n";

    expect($input)->toBe(['control_node_id' => 209, 'known_hosts_content' => $content, 'digest' => hash('sha256', $content)]);
    expect(app(SwampWorkerKnownHostsInput::class)->capture())->toBe($input);
});

it('omits Nodes without a complete recorded pin and never supplies first-contact trust', function (string $field, mixed $value): void {
    $node = FleetFixtures::node('incomplete-pin');
    $node->updateQuietly([$field => $value]);

    expect(app(SwampWorkerKnownHostsInput::class)->capture())->toBe([
        'control_node_id' => 209, 'known_hosts_content' => '', 'digest' => hash('sha256', ''),
    ]);
})->with([
    ['wireguard_ip', null], ['wireguard_ip', ''],
    ['ssh_host_key_type', null], ['ssh_host_key_type', ''],
    ['ssh_host_key', null], ['ssh_host_key', ''],
]);

it('uses the new stored pin in a new snapshot without altering an earlier admitted input', function (): void {
    $node = FleetFixtures::node('changed-pin');
    $node->updateQuietly(['wireguard_ip' => '10.44.0.9', 'ssh_host_key' => 'first-public-key']);
    $supplier = app(SwampWorkerKnownHostsInput::class);
    $before = $supplier->capture();
    $node->updateQuietly(['ssh_host_key' => 'replacement-public-key']);
    $after = $supplier->capture();

    expect($before['known_hosts_content'])->toBe("10.44.0.9 ssh-ed25519 first-public-key\n");
    expect($after['known_hosts_content'])->toBe("10.44.0.9 ssh-ed25519 replacement-public-key\n");
    expect($after['digest'])->not->toBe($before['digest']);
});
