<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use App\Domain\Nodes\RoleName;
use App\Models\Node;
use App\Models\NodeRole;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class NodeRoleAssignmentData extends Data
{
    public function __construct(
        public int $id,
        public string $role,
        public string $status,
        public ?string $failedStep,
        public ?string $errorCode,
    ) {}

    /** @return list<self> */
    public static function forNode(Node $node): array
    {
        $order = array_flip(array_map(
            static fn (RoleName $role): string => $role->value,
            RoleName::cases(),
        ));

        return array_values($node->roles
            ->sort(static function (NodeRole $first, NodeRole $second) use ($order): int {
                $roleOrder = $order[$first->role->value] <=> $order[$second->role->value];

                return $roleOrder !== 0 ? $roleOrder : $first->id <=> $second->id;
            })
            ->map(self::fromModel(...))
            ->all());
    }

    public static function fromModel(NodeRole $assignment): self
    {
        return new self(
            id: $assignment->id,
            role: $assignment->role->value,
            status: $assignment->status->value,
            failedStep: $assignment->failed_step,
            errorCode: $assignment->error_code,
        );
    }
}
