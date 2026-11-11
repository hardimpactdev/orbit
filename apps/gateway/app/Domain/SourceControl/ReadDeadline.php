<?php

declare(strict_types=1);

namespace App\Domain\SourceControl;

use Illuminate\Support\Carbon;
use RuntimeException;

/** One budget shared by credential acquisition and its read. Carbon permits elapsed-time tests without sleeping. */
final readonly class ReadDeadline
{
    private float $expiresAt;

    public function __construct(float $seconds)
    {
        $this->expiresAt = self::now() + $seconds;
    }

    public function remaining(): float
    {
        $remaining = $this->expiresAt - self::now();
        if ($remaining <= 0) {
            throw new RuntimeException('The read deadline expired.');
        }

        return $remaining;
    }

    private static function now(): float
    {
        return (float) Carbon::now()->format('U.u');
    }
}
