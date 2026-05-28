<?php

declare(strict_types=1);

namespace App\Services\Dns;

interface ResolvesLocalDns
{
    public function platform(): string;

    public function isSupported(): bool;

    public function supportsMutation(): bool;

    public function backend(): string;

    /**
     * @return array<int, array{tld: string, target: string, source: string, resolver_backend: string, status: string}>
     */
    public function listOverrides(): array;

    public function existingTarget(string $tld): ?string;

    public function isDnsmasqInstalled(): bool;

    /**
     * @return array{status: string, changed: bool, error?: string}
     */
    public function resolve(string $tld, string $target): array;

    /**
     * @return array{status: string, changed: bool, error?: string}
     */
    public function reset(string $tld): array;
}
