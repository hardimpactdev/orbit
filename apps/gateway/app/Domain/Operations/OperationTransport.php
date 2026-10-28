<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Family-registered named transport. Constructor and schema are pure preparation. */
interface OperationTransport
{
    /** @phpstan-import-type RunInput from SwampOperationExecutor
     * @param  array{worker: string, key: string, transport: string, name: string, input: array<string, mixed>}  $run
     * @return array<string, mixed>|bool
     */
    public function schema(Operation $operation, array $run): array|bool;

    /** @param array{worker: string, key: string, transport: string, name: string, input: array<string, mixed>, effect_key: string} $run
     * @param  array<string, mixed>  $input  Hydrated in request memory; never retain or return it.
     * @return array<string, mixed>
     */
    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array;
}
