<?php

declare(strict_types=1);

namespace App\Domain\Releases;

use InvalidArgumentException;

/** The failing resource and attempt, independent of the Gateway code that observed it. */
final readonly class ReleaseIncident
{
    public function __construct(public string $resource, public string $attempt, public string $fault)
    {
        foreach ([$resource, $attempt, $fault] as $part) {
            if (preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/', $part) !== 1) {
                throw new InvalidArgumentException('An incident identity needs bounded resource, attempt, and fault names.');
            }
        }
    }

    public function key(ReleaseAlertKind $kind): string
    {
        return implode('|', [$kind->value, $this->resource, $this->attempt, $this->fault]);
    }
}
