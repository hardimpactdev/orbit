<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Family-owned encrypted output storage. The caller checks ownership and provides an ordinary record transaction. */
interface OperationSecretOutputStore
{
    public function name(): string;

    /** @return list<string> */
    public function targetTypes(): array;

    public function ownerId(Operation $operation): ?int;

    /** Validate and write only the existing encrypted owner record, inside the caller’s record transaction. */
    public function store(Operation $operation, int $ownerId, #[\SensitiveParameter] mixed $value): void;
}
