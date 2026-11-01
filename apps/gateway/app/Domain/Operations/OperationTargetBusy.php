<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use Throwable;

/** A start refused because another Operation holds its target. It carries the public intent for a refusal receipt. */
final class OperationTargetBusy extends ResourceOperationException
{
    /** @param array<string, mixed> $input public input only; private fields are already removed */
    public function __construct(
        public readonly string $kind,
        public readonly string $targetType,
        public readonly int $targetId,
        public readonly ?int $nodeId,
        public readonly array $input,
        public readonly ?string $triggeredBy,
        public readonly ?string $requestId,
        ?int $holder,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            'operation.target_busy',
            'Another operation on this target has not ended. Follow it, or cancel it, before you start another.',
            409,
            $previous,
            $holder === null ? [] : ['operation_id' => $holder],
        );
    }
}
