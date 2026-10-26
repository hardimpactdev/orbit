<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

/** Contributes a compiled startup command and data, even while the extension is disabled. */
interface GatewayServiceStartHook
{
    public function command(): string;

    /** @return array<string, mixed> */
    public function input(string $checkoutPath, string $orbitHome): array;
}
