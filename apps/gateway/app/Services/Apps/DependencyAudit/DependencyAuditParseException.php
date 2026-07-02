<?php

declare(strict_types=1);

namespace App\Services\Apps\DependencyAudit;

use RuntimeException;

final class DependencyAuditParseException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
