<?php

declare(strict_types=1);

namespace Tests\Unit\Services\WireGuard;

use App\Models\Node;
use App\Models\WireGuardPeer;
use App\Services\WireGuard\WireGuardKeyGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->generator = new WireGuardKeyGenerator;
});

describe('key generation', function (): void {
    it('generates a valid key pair using wg binaries', function (): void {
        $result = $this->generator->generateKeyPair();

        expect($result)->toHaveKey('private_key');
        expect($result)->toHaveKey('public_key');
        expect($result['private_key'])->not->toBeEmpty();
        expect($result['public_key'])->not->toBeEmpty();
        expect($result['private_key'])->not->toBe($result['public_key']);
    });

    it('throws when wg genkey fails', function (): void {
        Process::fake([
            'wg genkey' => Process::result(
                errorOutput: 'wg: command not found',
                exitCode: 127,
            ),
        ]);

        expect(fn () => $this->generator->generateKeyPair())
            ->toThrow(\RuntimeException::class, 'Failed to generate WireGuard private key');
    });

    it('throws when wg pubkey fails', function (): void {
        Process::fake([
            'wg genkey' => Process::result(
                output: 'cGQPVU5UQUNLRURURU1PTkVURVNUQ0xJRU5US0VZ',
                exitCode: 0,
            ),
            'wg pubkey' => Process::result(
                errorOutput: 'invalid private key',
                exitCode: 1,
            ),
        ]);

        expect(fn () => $this->generator->generateKeyPair())
            ->toThrow(\RuntimeException::class, 'Failed to derive WireGuard public key');
    });

    it('throws when private key output is empty', function (): void {
        Process::fake([
            'wg genkey' => Process::result(
                output: '',
                exitCode: 0,
            ),
        ]);

        expect(fn () => $this->generator->generateKeyPair())
            ->toThrow(\RuntimeException::class, 'WireGuard private key generation returned empty output');
    });

    it('throws when public key output is empty', function (): void {
        Process::fake([
            'wg genkey' => Process::result(
                output: 'cGQPVU5UQUNLRURURU1PTkVURVNUQ0xJRU5US0VZ',
                exitCode: 0,
            ),
            'wg pubkey' => Process::result(
                output: '',
                exitCode: 0,
            ),
        ]);

        expect(fn () => $this->generator->generateKeyPair())
            ->toThrow(\RuntimeException::class, 'WireGuard public key derivation returned empty output');
    });
});

describe('peer persistence', function (): void {
    it('persists a wireguard peer with a node', function (): void {
        $node = Node::factory()->create();

        $peer = WireGuardPeer::create([
            'node_id' => $node->id,
            'public_key' => 'abc123',
            'private_key' => 'def456',
            'pre_shared_key' => 'ghi789',
            'allowed_ips' => '10.0.0.2/32',
        ]);

        expect($peer->fresh())->toBeInstanceOf(WireGuardPeer::class);
        expect($peer->node->id)->toBe($node->id);
        expect($peer->public_key)->toBe('abc123');
        expect($peer->private_key)->toBe('def456');
        expect($peer->pre_shared_key)->toBe('ghi789');
        expect($peer->allowed_ips)->toBe('10.0.0.2/32');
    });

    it('enforces unique node constraint', function (): void {
        $node = Node::factory()->create();

        WireGuardPeer::factory()->create(['node_id' => $node->id]);

        expect(fn () => WireGuardPeer::factory()->create(['node_id' => $node->id]))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('allows nullable pre_shared_key and allowed_ips', function (): void {
        $node = Node::factory()->create();

        $peer = WireGuardPeer::create([
            'node_id' => $node->id,
            'public_key' => 'pubkey',
            'private_key' => 'privkey',
        ]);

        expect($peer->pre_shared_key)->toBeNull();
        expect($peer->allowed_ips)->toBeNull();
    });
});
