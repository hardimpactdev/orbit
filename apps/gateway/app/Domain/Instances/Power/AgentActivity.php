<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

/**
 * What the idle sleep needs from a container's agent server. `turnRuns` is a turn that is preparing, queued, starting or running: it
 * keeps the Instance awake at any age. `lastActivityUnix` is the newest user message, turn end, or request that waits for the user.
 * An unreadable server never lets the Instance sleep.
 */
final readonly class AgentActivity
{
    private function __construct(public bool $readable, public bool $turnRuns, public ?int $lastActivityUnix) {}

    public static function read(bool $turnRuns, ?int $lastActivityUnix): self
    {
        return new self(true, $turnRuns, $lastActivityUnix);
    }

    public static function unreadable(): self
    {
        return new self(false, false, null);
    }
}
