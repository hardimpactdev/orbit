<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

/** Recorded database intent for one native parent workflow. */
final readonly class PreparedDatabaseOperation
{
    /** @param array<string, mixed> $input */
    public function __construct(public string $kind, public string $targetType, public int $targetId, public int $nodeId, public array $input) {}
}
