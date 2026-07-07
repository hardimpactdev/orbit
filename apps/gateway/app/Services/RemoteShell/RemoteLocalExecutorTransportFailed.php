<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use RuntimeException;

final class RemoteLocalExecutorTransportFailed extends RuntimeException
{
    /**
     * @param  array<array-key, mixed>  $meta
     */
    public function __construct(
        string $message,
        public readonly array $meta = [],
        int $code = 0,
    ) {
        parent::__construct($message, $code);
    }
}
