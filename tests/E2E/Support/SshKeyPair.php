<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class SshKeyPair
{
    public function __construct(
        public string $privateKeyPath,
        public string $publicKeyPath,
        public ?string $providerReference = null,
    ) {}
}
