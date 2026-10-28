<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;

/** Keep unenforced Kit B/C contracts out of registration and admission. */
final class ToolOperationAvailability
{
    public static function available(): bool
    {
        return OperationKinds::supports('children') && OperationKinds::supports('list_of');
    }

    public static function assertAvailable(): void
    {
        if (! self::available()) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Tool writes require the shared child admission and result contracts.', 503);
        }
    }
}
