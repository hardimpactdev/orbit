<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Illuminate\Http\Request;

final readonly class OperationStreamOptions
{
    public function __construct(
        public ?int $lastSequence,
        public int $pollMicroseconds,
        public ?int $maxIdlePolls,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            lastSequence: self::lastEventSequence($request),
            pollMicroseconds: self::pollMicroseconds($request),
            maxIdlePolls: self::maxIdlePolls($request),
        );
    }

    private static function lastEventSequence(Request $request): ?int
    {
        $value = $request->headers->get('Last-Event-ID');

        if ($value === null) {
            $queryValue = $request->query('last_event_id');
            $value = is_scalar($queryValue) ? $queryValue : null;
        }

        if ($value === null) {
            $queryValue = $request->query('since');
            $value = is_scalar($queryValue) ? $queryValue : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        $lastSequence = (int) $value;

        return $lastSequence > 0 ? $lastSequence : null;
    }

    private static function pollMicroseconds(Request $request): int
    {
        $value = $request->query('poll_microseconds');

        if (! is_scalar($value) || ! ctype_digit($value)) {
            return 500_000;
        }

        return max(0, (int) $value);
    }

    private static function maxIdlePolls(Request $request): ?int
    {
        if ($request->boolean('once')) {
            return 0;
        }

        $value = $request->query('max_idle_polls');

        if (! is_scalar($value) || ! ctype_digit($value)) {
            return null;
        }

        return max(0, (int) $value);
    }
}
