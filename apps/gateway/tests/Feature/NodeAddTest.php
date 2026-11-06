<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);
beforeEach(fn () => SwampNodeFixture::ensure());

it('captures the configured node.add worker key and its rotation for managed SSH', function (): void {
    $firstKey = 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('f', 32));
    $rotatedKey = 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('r', 32));
    config(['swamp.ssh_public_key' => $firstKey]);
    $ssh = app(SwampSshExecutionInput::class);

    $bootstrap = $ssh->identity();

    expect($bootstrap['public_key'])->toBe($firstKey);

    config(['swamp.ssh_public_key' => $rotatedKey]);
    $node = (new Node)->forceFill(['id' => 214, 'wireguard_ip' => '192.0.2.90', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'approved-host-key']);

    $managed = $ssh->forNode($node);

    expect($managed['public_key'])->toBe($rotatedKey);
});

it('refuses node.add SSH intent with 503 when the worker key is unavailable', function (string $key): void {
    config(['swamp.ssh_public_key' => $key]);
    $ssh = app(SwampSshExecutionInput::class);
    $node = (new Node)->forceFill(['id' => 214, 'wireguard_ip' => '192.0.2.90', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'approved-host-key']);
    $refusal = function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('swamp.ssh_identity_unavailable');
        expect($exception->status)->toBe(503);
    };

    expect(fn (): array => $ssh->identity())->toThrow($refusal);
    expect(fn (): array => $ssh->forNode($node))->toThrow($refusal);
})->with([
    'missing' => '',
    'invalid encoding' => 'ssh-ed25519 not-a-key',
    'multiple keys' => "ssh-ed25519 AAAA\nssh-ed25519 BBBB",
    'wrong key structure' => 'ssh-ed25519 '.str_repeat('A', 68),
]);
