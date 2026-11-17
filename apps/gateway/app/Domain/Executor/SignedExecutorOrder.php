<?php

declare(strict_types=1);

namespace App\Domain\Executor;

/**
 * One signed order: its wire form `base64(payload).base64(signature)`, the Operation id it carries as a numeric string, its nonce
 * and its expiry in Unix milliseconds.
 */
final readonly class SignedExecutorOrder
{
    public function __construct(
        public string $wire,
        public string $operation,
        public string $nonce,
        public int $expiresMs,
    ) {}
}
