<?php

declare(strict_types=1);

namespace App\Services\Profile;

final readonly class ProfileInput
{
    public function __construct(
        public string $target,
        public string $uri,
        public string $authMode,
        public ?string $user,
        public ?string $node,
        public bool $targetWasOmitted,
    ) {}

    public function withTarget(string $target): self
    {
        return new self(
            target: $target,
            uri: $this->uri,
            authMode: $this->authMode,
            user: $this->user,
            node: $this->node,
            targetWasOmitted: false,
        );
    }

    /**
     * @return array<string, string>
     */
    public function query(): array
    {
        return array_filter([
            'target' => $this->target,
            'uri' => $this->uri,
            'auth_mode' => $this->authMode,
            'user' => $this->user,
            'node' => $this->node,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
