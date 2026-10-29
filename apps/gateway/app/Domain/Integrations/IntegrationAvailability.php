<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;

final readonly class IntegrationAvailability
{
    public static function assertKind(string $kind): void
    {
        if (! in_array($kind, OperationKinds::names(), true)) {
            throw new ResourceOperationException('operation.kind_unavailable', 'This integration needs its matching executor capability.', 409);
        }
    }
}
