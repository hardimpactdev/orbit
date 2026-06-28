<?php

declare(strict_types=1);

namespace App\Services\Solo;

use RuntimeException;

final class SoloProxyException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $meta,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }
}
