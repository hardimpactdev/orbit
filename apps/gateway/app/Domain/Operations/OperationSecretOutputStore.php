<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Family-owned encrypted output storage. The secret_outputs capability remains disabled until H enforces it. */
interface OperationSecretOutputStore
{
    public function name(): string;

    /** @return list<string> */
    public function targetTypes(): array;

    public function ownerId(Operation $operation): ?int;

    /** Validate and write only the existing encrypted owner record, inside the keyed receipt transaction. */
    public function store(Operation $operation, int $ownerId, #[\SensitiveParameter] mixed $value): void;
}
