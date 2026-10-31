<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Domain validation around shared result recording; never executes work. */
interface DirectOperationResultHook
{
    /** @return array<string,mixed> */
    public function input(Operation $operation): array;

    /** @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public function validate(Operation $operation, array $result, OperationStatus $status): array;

    public function complete(Operation $operation, OperationOutcome $outcome): void;
}
