<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Infrastructure\AgentCommands\AgentEffectReceipts;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\AgentCommands\GuestAgentCommandRunner;
use App\Models\Node;

/** Adapts the shared agent receipt reader; the family owns no receipt store. */
trait TaskVmTargetReceipt
{
    /** @return array{effect_key:string,state:string,result:array<string,mixed>|null,sanitized:bool,effect_nonce:string|null,effect_created_at:int|null,exit_code:int|null,finished_at:string|null} */
    private function targetReceipt(Node|AgentGuest $target, string $command, string $key): array
    {
        if ($target instanceof Node) {
            return app(AgentEffectReceipts::class)->read($target, $command, $key);
        }

        return AgentEffectReceipts::decode(app(GuestAgentCommandRunner::class)->runGuest($target, 'agent.effect-receipt', ['command' => $command, 'effect_key' => $key, 'owner' => null]), $key);
    }
}
