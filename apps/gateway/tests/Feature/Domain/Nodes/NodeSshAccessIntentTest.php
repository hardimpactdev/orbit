<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeSshAccessAction;
use App\Domain\Nodes\NodeSshAccessProjection;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Operation;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

it('names the Node\'s platform in the SSH access intent, which picks how the keys are written, and keeps it out of the digest', function (): void {
    SwampNodeFixture::ensure();
    $intents = collect(['linux', 'macos'])->mapWithKeys(fn (string $platform): array => [$platform => (new ReflectionMethod(AdmitNodeSshAccessAction::class, 'intent'))
        ->invoke(app(AdmitNodeSshAccessAction::class), Node::withoutEvents(static fn (): Node => Node::query()->create([
            'name' => 'node-'.$platform, 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => $platform === 'linux' ? '10.44.0.30' : '10.44.0.31', 'user' => 'orbit',
            'status' => LifecycleStatus::Active, 'platform' => $platform, 'architecture' => 'arm64',
            'ssh_host_fingerprint' => 'SHA256:node', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'node-public-key'])))]);

    expect($intents['linux']['platform'])->toBe('linux')
        ->and($intents['macos']['platform'])->toBe('macos')
        // The digest covers the target and the keys only, so Nodes that converged before keep their recorded digest.
        ->and($intents['macos']['digest'])->toBe(hash('sha256', json_encode(['target' => $intents['macos']['target'], 'keys' => $intents['macos']['keys']], JSON_THROW_ON_ERROR)));
});

it('hands Swamp a Linux platform for an intent stored before the intent named one, and keeps a named platform', function (): void {
    $input = fn (array $intent): array => (new NodeSshAccessProjection)->input(new Operation(['input' => ['intent' => $intent, 'execution_secrets' => []]]));
    $stored = ['target' => ['host' => '192.0.2.9', 'user' => 'orbit'], 'keys' => ['ssh-ed25519 AAAAswamp'], 'digest' => 'recorded'];

    expect($input($stored))->toBe(['intent' => $stored + ['platform' => 'linux'], 'execution_secrets' => []])
        ->and($input($stored + ['platform' => 'macos'])['intent']['platform'])->toBe('macos');
});
