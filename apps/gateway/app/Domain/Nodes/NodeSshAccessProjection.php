<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\NodeFootprint;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Record only the SSH-access contribution; do not claim the full observer footprint. */
final readonly class NodeSshAccessProjection implements DirectOperationProjection
{
    /** @param array<string,mixed> $intent */
    public static function digest(array $intent): string
    {
        $target = ValidatedData::object($intent['target']);

        return hash('sha256', json_encode(['target' => $target, 'public_key' => $intent['public_key']], JSON_THROW_ON_ERROR));
    }

    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['intent'], 'execution_secrets' => $operation->input['execution_secrets']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $terminal = $operation->status->terminal();
            $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['ssh_access_ready', 'error_code']));
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($terminal || $projected->status->value !== 'succeeded' || ($result['ssh_access_ready'] ?? false) !== true) {
                return $projected;
            }
            $intent = ValidatedData::object($operation->input['intent']);
            NodeFootprint::query()->firstOrCreate(['node_id' => $operation->target_id]);
            $record = NodeFootprint::query()->where('node_id', $operation->target_id)->lockForUpdate()->firstOrFail();
            $record->update(['ssh_access_digest' => ValidatedData::string($intent['digest'])]);

            return $projected;
        });
    }
}
