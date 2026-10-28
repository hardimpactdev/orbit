<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;

final readonly class RouteKitAvailability
{
    public static function parents(): void
    {
        if (! OperationKinds::supports('children') || ! OperationKinds::supports('list_of')) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Route composition requires the shared child and result contracts.', 409);
        }
    }

    public static function kind(string $kind): void
    {
        self::parents();
        if (! array_key_exists($kind, OperationKinds::definitions())) {
            throw new ResourceOperationException('operation.kind_unavailable', 'This Route contract is not enabled by the shared kit.', 409);
        }
    }
}
