<?php

declare(strict_types=1);

namespace App\Services\Database;

use RuntimeException;
use Throwable;

final class LocalDatabaseQueryFailure extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $meta = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
