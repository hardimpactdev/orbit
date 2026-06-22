<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use RuntimeException;

final class OperatorNodeManagementException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
