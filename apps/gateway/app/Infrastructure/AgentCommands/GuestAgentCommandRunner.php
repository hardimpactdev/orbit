<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

/** The same runner for a builder or guest that has no enrolled Node row yet. */
interface GuestAgentCommandRunner
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function runGuest(AgentGuest $guest, string $command, array $input = [], ?float $deadlineSeconds = null): array;

    /** Explicit bootstrap before the first recipe command; a missing agent never selects an alternate program. */
    public function installGuest(AgentGuest $guest): bool;
}
