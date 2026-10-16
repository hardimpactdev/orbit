<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;

interface AgentDriver
{
    public function key(): string;

    public function create(AgentThreadStart $intent): string;

    public function send(AgentThread $thread, string $message, ?string $key = null): void;

    /** @param array<string, mixed> $answers */
    public function respond(AgentThread $thread, AgentInputRequest $request, array $answers): void;

    public function interrupt(AgentThread $thread): void;

    public function archive(AgentThread $thread, string $commandId): void;

    public function observe(AgentThread $thread): AgentObservation;

    /**
     * Reads the thread for a viewer, within an interactive time budget. With a cursor from the driver's current
     * run, the observation holds only the entries after it and is a delta. Otherwise it is a full snapshot.
     */
    public function read(AgentThread $thread, ?string $cursor): AgentObservation;

    /** @return iterable<AgentThreadEvent> */
    public function events(AgentThread $thread, ?string $cursor, ?float $timeoutSeconds = null): iterable;
}
