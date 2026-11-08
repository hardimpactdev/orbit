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
}
