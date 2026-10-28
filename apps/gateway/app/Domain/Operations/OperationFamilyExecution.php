<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/**
 * Family-owned reads, binding, guards and DB projection. Never executes Node work.
 *
 * @phpstan-import-type RunInput from SwampOperationExecutor
 *
 * @phpstan-type Descriptor array{key: string, step: string, transport: string, name: string, input: array<string, mixed>, resumable: bool, required: bool}
 * @phpstan-type CancellationBoundary array{key: string, transport: string, name: string, result: array<string, mixed>}
 */
interface OperationFamilyExecution
{
    /** @return array{descriptors: list<Descriptor>} */
    public function context(Operation $operation): array;

    /** @param RunInput $run
     * @return array<string, mixed> Exact expected input, including protected references.
     */
    public function input(Operation $operation, array $run): array;

    /** @param RunInput $run */
    public function beforeRun(Operation $operation, array $run): void;

    /** @param RunInput $run
     * @param  array<string, mixed>  $receipt  Transport-proven receipt, with known secrets already redacted.
     * @return array<string, mixed> Public result; generic result filtering runs before persistence.
     */
    public function received(Operation $operation, array $run, array $receipt): array;

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void;

    /** @return CancellationBoundary|null A successful keyed receipt after which cancellation cannot stop this family. */
    public function cancellationBoundary(Operation $operation): ?array;
}
