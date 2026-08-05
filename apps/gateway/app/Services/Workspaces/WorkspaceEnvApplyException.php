<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use RuntimeException;
use Throwable;

final class WorkspaceEnvApplyException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $phase,
        public readonly bool $envWritten,
        string $message,
        public readonly array $meta = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
