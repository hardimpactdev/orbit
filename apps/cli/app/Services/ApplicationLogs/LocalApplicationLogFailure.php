<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

use RuntimeException;

final class LocalApplicationLogFailure extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }
}
