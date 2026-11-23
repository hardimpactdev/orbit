<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

/**
 * What the idle sleep needs from a container's agent server. `turnRuns` is a turn that is preparing, queued, starting or running: it
 * keeps the Instance awake at any age. `lastActivityUnix` is the newest user message, turn end, or request that waits for the user.
 * An unreadable server never lets the Instance sleep. `held` names a reason that keeps the Instance awake whatever its server says, such
 * as a Conn reservation whose user still types its first message.
 */
final readonly class AgentActivity
{
    private function __construct(public bool $readable, public bool $turnRuns, public ?int $lastActivityUnix, public ?string $held = null, public bool $pendingRequest = false) {}

    public static function held(string $reason): self
    {
        return new self(true, false, null, $reason);
    }

    public static function read(bool $turnRuns, ?int $lastActivityUnix, bool $pendingRequest = false): self
    {
        return new self(true, $turnRuns, $lastActivityUnix, null, $pendingRequest);
    }

    public static function unreadable(): self
    {
        return new self(false, false, null);
    }
}
