<?php

declare(strict_types=1);

namespace Orbit\Extensions\Documents\Infrastructure;

use App\Domain\Gateway\GatewayServiceStartHook;

/** The compiled Documents startup hook invalidates cleanup before PHP-FPM activates a pool. */
final readonly class DocumentsServiceStartHook implements GatewayServiceStartHook
{
    public function command(): string
    {
        return 'documents.prepare-cleanup';
    }

    public function input(string $checkoutPath, string $orbitHome): array
    {
        return ['checkout' => $checkoutPath, 'orbit_home' => $orbitHome];
    }
}
