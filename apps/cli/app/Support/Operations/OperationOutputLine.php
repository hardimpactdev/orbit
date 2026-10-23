<?php

declare(strict_types=1);

namespace App\Support\Operations;

/** One output line of a running deploy step, which the Node agent publishes on the operation's channel. */
final readonly class OperationOutputLine
{
    public function __construct(
        public string $step,
        public string $line,
    ) {}

    /** The line of a `client-output` event, or null when the payload is not one. */
    public static function fromPayload(mixed $payload): ?self
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, associative: true);
        }

        $step = is_array($payload) ? ($payload['step'] ?? null) : null;
        $line = is_array($payload) ? ($payload['line'] ?? null) : null;

        return is_string($step) && is_string($line) ? new self($step, $line) : null;
    }
}
