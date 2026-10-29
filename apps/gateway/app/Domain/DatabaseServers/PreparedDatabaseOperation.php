<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Validated command intent for either a public start or a Kit v2 child. */
final readonly class PreparedDatabaseOperation
{
    /** @param array<string, mixed> $input */
    public function __construct(public string $kind, public string $targetType, public int $targetId, public int $nodeId, public array $input) {}

    /** @return array{key: string, kind: string, node_id: int, input: array<string, mixed>} */
    public function child(?Operation $parent = null): array
    {
        if ($parent !== null) {
            $this->bindParent(ValidatedData::integer($this->input['intent_id']), $parent);
        }
        $intent = DatabaseExecutionIntent::recorded($this->input['intent_id']);
        $intent->update(['payload' => [...$intent->payload, 'admission' => ['kind' => $this->kind, 'input' => $this->input]]]);

        return ['key' => $this->kind.'.'.ValidatedData::integer($this->input['intent_id']), 'kind' => $this->kind, 'node_id' => $this->nodeId, 'input' => $this->input];
    }

    private function bindParent(int $id, Operation $parent): void
    {
        if (! $parent->exists || $parent->status->terminal()) {
            throw new ResourceOperationException('operation.child_forbidden', 'The prepared intent requires a live parent Operation.', 409);
        }
        $intent = DatabaseExecutionIntent::recorded($id);
        if ($intent->parent_operation_id !== null && $intent->parent_operation_id !== $parent->id) {
            throw new ResourceOperationException('operation.child_forbidden', 'The prepared intent belongs to another parent.', 409);
        }
        $intent->update(['parent_operation_id' => $parent->id]);
        foreach ($intent->payload['projection']['children'] ?? [] as $child) {
            $this->bindParent(ValidatedData::integer($child['input']['intent_id']), $parent);
        }
    }
}
