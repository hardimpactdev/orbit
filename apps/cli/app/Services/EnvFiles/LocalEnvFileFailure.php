<?php

declare(strict_types=1);

namespace App\Services\EnvFiles;

use RuntimeException;

final class LocalEnvFileFailure extends RuntimeException
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
