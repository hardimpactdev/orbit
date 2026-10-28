<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Optional wire conversion for strict schema validation. Never dispatches an effect. */
interface OperationTransportInput
{
    /**
     * @phpstan-import-type RunInput from SwampOperationExecutor
     * @param array{worker: string, key: string, transport: string, name: string, input: array<string, mixed>} $run
     * @param array<string, mixed> $input Hydrated values stay in request memory.
     */
    public function wireInput(Operation $operation, array $run, #[\SensitiveParameter] array $input): mixed;
}
