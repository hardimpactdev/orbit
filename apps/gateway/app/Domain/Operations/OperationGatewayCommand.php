<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** A named Gateway-local effect. Implementations never choose another Node transport. */
interface OperationGatewayCommand
{
    /** @return array<string, mixed> */
    public function schema(): array;

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function run(Operation $operation, #[\SensitiveParameter] array $input): array;
}
