<?php

declare(strict_types=1);

use App\Infrastructure\Nodes\NodeBootstrapCommandFactory;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

it('sends role-neutral bootstrap intent and preserves operator or overridden DNS', function (bool $operator, ?string $override, string $policy): void {
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->once()->andReturn('ssh-ed25519 test-key');
    $node = new Node(['dns_server_override' => $override]);
    $input = new NodeBootstrapCommandFactory($keys)->input($node, 'nckrtl', $operator);

    expect($input)->toBe([
        'user' => 'nckrtl',
        'public_key' => 'ssh-ed25519 test-key',
        'releases' => ['resolute'],
        'packages' => ['ca-certificates', 'curl', 'gnupg', 'libnss-resolve', 'openssh-client', 'sudo', 'ufw', 'wireguard'],
        'dns_policy' => $policy,
    ]);
})->with([[false, null, 'managed'], [true, null, 'preserve'], [false, '192.0.2.53', 'preserve']]);
