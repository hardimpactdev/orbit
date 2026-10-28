<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use LogicException;
use SensitiveParameter;

final readonly class DependencyReadToken
{
    public function __construct(
        #[SensitiveParameter] private string $token,
        public string $expiresAt,
    ) {}

    public function reveal(): string
    {
        return $this->token;
    }

    /** @return array{type: class-string} */
    public function __debugInfo(): array
    {
        return ['type' => self::class];
    }

    public function __serialize(): array
    {
        throw new LogicException('Dependency credentials cannot be serialized.');
    }
}
