<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class DoctorUncataloguedIssueException extends RuntimeException
{
    public static function forCode(string $code): self
    {
        return new self(
            "Doctor issue code '{$code}' is not registered in the explicit Doctor issue catalog. "
            .'Add a family-owned definition with disposition and, for genuine_drift, a restore action.',
        );
    }
}
