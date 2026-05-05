<?php

declare(strict_types=1);

namespace App\Services\WireGuard;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final class WireGuardKeyGenerator
{
    /**
     * Generate a WireGuard key pair using the wg binary.
     *
     * @return array{private_key: string, public_key: string}
     */
    public function generateKeyPair(): array
    {
        $privateKeyResult = Process::run('wg genkey');

        if (! $privateKeyResult->successful()) {
            throw new RuntimeException("Failed to generate WireGuard private key: {$privateKeyResult->errorOutput()}");
        }

        $privateKey = trim($privateKeyResult->output());

        if ($privateKey === '') {
            throw new RuntimeException('WireGuard private key generation returned empty output.');
        }

        $publicKeyResult = Process::input($privateKey)->run('wg pubkey');

        if (! $publicKeyResult->successful()) {
            throw new RuntimeException("Failed to derive WireGuard public key: {$publicKeyResult->errorOutput()}");
        }

        $publicKey = trim($publicKeyResult->output());

        if ($publicKey === '') {
            throw new RuntimeException('WireGuard public key derivation returned empty output.');
        }

        return [
            'private_key' => $privateKey,
            'public_key' => $publicKey,
        ];
    }
}
