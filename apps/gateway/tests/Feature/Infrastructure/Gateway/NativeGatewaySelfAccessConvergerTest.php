<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Gateway\NativeGatewaySelfAccessConverger;
use App\Infrastructure\Ssh\KnownHostsRepository;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentCommandRunner;

it('asks the agent to authorize the Gateway account and pins its returned host identity on repeat', function (): void {
    [$converger, $agents, $home, $node] = gateway_self_access_fixture();
    try {
        foreach ([1, 2] as $_) {
            expect($converger->converge($node))->toBe('SHA256:'.str_repeat('a', 43));
        }
        expect(file_get_contents($home.'/known_hosts'))->toBe("10.44.0.1 ssh-ed25519 HOSTKEY\n")
            ->and($agents->calls)->toHaveCount(2)
            ->and($agents->calls[0])->toBe([
                'node' => 'gateway', 'command' => 'gateway.converge-self-access',
                'input' => ['user' => 'orbit', 'public_key' => 'ssh-ed25519 MANAGEDKEY orbit-gateway'],
            ]);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('maps native refusal without recording an unverified host key', function (): void {
    [$converger, $agents, $home, $node] = gateway_self_access_fixture();
    $agents->refuse('gateway.converge-self-access', 'gateway.self_access_failed');
    try {
        expect(fn () => $converger->converge($node))->toThrow(function (NodeProvisioningException $exception): void {
            expect($exception->step)->toBe('gateway-self-access')->and($exception->errorCode)->toBe('gateway.self_access_failed');
        });
        expect(is_file($home.'/known_hosts'))->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('refuses missing addresses before calling the agent', function (): void {
    [$converger, $agents, $home, $node] = gateway_self_access_fixture();
    $node->wireguard_ip = null;
    expect(fn () => $converger->converge($node))->toThrow(NodeProvisioningException::class);
    expect($agents->calls)->toBe([])->and(is_dir($home))->toBeFalse();
});

it('refuses malformed agent identity before recording a host pin', function (array $result): void {
    [$converger, $agents, $home, $node] = gateway_self_access_fixture();
    $agents->answerMalformed('gateway.converge-self-access', $result);
    expect(fn () => $converger->converge($node))->toThrow(NodeProvisioningException::class);
    expect(is_file($home.'/known_hosts'))->toBeFalse();
})->with([
    'missing fields' => [[]],
    'wrong algorithm' => [['key_type' => 'ssh-rsa', 'value' => 'HOSTKEY', 'fingerprint' => 'SHA256:'.str_repeat('a', 43)]],
    'invalid fingerprint' => [['key_type' => 'ssh-ed25519', 'value' => 'HOSTKEY', 'fingerprint' => 'bad']],
]);

/** @return array{NativeGatewaySelfAccessConverger, FakeAgentCommandRunner, string, Node} */
function gateway_self_access_fixture(): array
{
    $home = sys_get_temp_dir().'/orbit-self-access-'.Str::uuid();
    $agents = new FakeAgentCommandRunner()->answer('gateway.converge-self-access', [
        'key_type' => 'ssh-ed25519', 'value' => 'HOSTKEY', 'fingerprint' => 'SHA256:'.str_repeat('a', 43),
    ]);
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/unused';
        }

        public function publicKey(): string
        {
            return "ssh-ed25519 MANAGEDKEY orbit-gateway\n";
        }
    };
    $node = new Node(['name' => 'gateway', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.1']);

    return [new NativeGatewaySelfAccessConverger($agents, new KnownHostsRepository($home.'/known_hosts'), $keys), $agents, $home, $node];
}
