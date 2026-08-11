<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

final class DoctorIssueIdentityMismatch extends InvalidArgumentException
{
    public static function forCodes(string $key, string $code): self
    {
        return new self("Doctor issue key '{$key}' cannot use catalog code '{$code}'.");
    }

    public static function forDetailCode(string $code, string $detailCode): self
    {
        return new self("Doctor issue code '{$code}' does not match detail code '{$detailCode}'.");
    }

    public static function forFamily(string $family, string $code, string $catalogFamily): self
    {
        return new self(
            "Doctor issue family '{$family}' does not match catalog family '{$catalogFamily}' for code '{$code}'.",
        );
    }
}
