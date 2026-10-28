<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Models\DatabaseExecutionIntent;
use App\Support\ValidatedData;

/** Validated command intent for either a public start or a Kit v2 child. */
final readonly class PreparedDatabaseOperation
{
    /** @param array<string, mixed> $input */
    public function __construct(public string $kind, public string $targetType, public int $targetId, public int $nodeId, public array $input) {}

    /** @return array{key: string, kind: string, node_id: int, input: array<string, mixed>} */
    public function child(): array
    {
        $intent = DatabaseExecutionIntent::recorded($this->input['intent_id']);
        $intent->update(['payload' => [...$intent->payload, 'admission' => ['kind' => $this->kind, 'input' => $this->input]]]);

        return ['key' => $this->kind.'.'.ValidatedData::integer($this->input['intent_id']), 'kind' => $this->kind, 'node_id' => $this->nodeId, 'input' => $this->input];
    }
}
