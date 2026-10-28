<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/**
 * @phpstan-import-type EffectRunInput from SwampOperationExecutor
 *
 * Target-local receipts for a named transport. Read receipts without repeating effects.
 */
interface OperationEffectTransport extends OperationTransport
{
    /** @param EffectRunInput $run */
    public function effectful(Operation $operation, array $run): bool;

    /**
     * @param  EffectRunInput  $run  Includes the Gateway-derived effect_key.
     * @param  array<string, mixed>  $input  Original references; payload sources remain unhydrated. Only OperationSecrets::connection may read existing connection authentication.
     * @return array{effect_key: string, state: string, result: array<string, mixed>|null, sanitized: bool, exit_code: int|null, finished_at: string|null}
     */
    public function receipt(Operation $operation, array $run, #[\SensitiveParameter] array $input): array;
}
