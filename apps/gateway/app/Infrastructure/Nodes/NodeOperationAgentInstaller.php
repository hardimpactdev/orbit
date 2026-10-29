<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Infrastructure\Ssh\SshConnection;
use App\Models\Node;

/** Keyed installer calls and receipt reads, without changing ordinary runner callers. */
interface NodeOperationAgentInstaller
{
    /** @param array<string, mixed> $run */
    public function installEffect(Node $node, string $pin, #[\SensitiveParameter] array $run, ?SshConnection $connection = null): bool;

    /** @return array{effect_key: string, state: string, result: array<string, mixed>|null, result_digest: string|null, sanitized: bool, effect_nonce: string|null, effect_created_at: int|null, exit_code: int|null, finished_at: string|null} */
    public function effectReceipt(Node $node, string $pin, string $command, string $effectKey, ?SshConnection $connection = null): array;
}
