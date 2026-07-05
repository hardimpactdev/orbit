<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use RuntimeException;

final class LocalWorkspaceSourceCreateFailure extends RuntimeException
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
