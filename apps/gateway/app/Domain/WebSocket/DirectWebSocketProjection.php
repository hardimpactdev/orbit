<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Immutable inputs and public terminal projection; no registry re-read or family callback. */
final readonly class DirectWebSocketProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        $this->assertKind($operation);

        return ['intent' => ValidatedData::object($operation->input['native_registry']),
            'execution_secrets' => ValidatedData::object($operation->input['execution_secrets'])];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $raw = ValidatedData::object($data['result'] ?? []);
        $result = Arr::only($raw, ['outcome', 'error_code']);
        if (is_array($raw['data'] ?? null)) {
            $result['data'] = Arr::only($raw['data'], ['backend']);
        }

        return $this->direct->project($operation, $data, $result);
    }

    private function assertKind(Operation $operation): void
    {
        if (! DirectOperation::owns($operation) || $operation->node_id === null
            || ! in_array($operation->kind, ['websocket.sync', 'websocket.app_create', 'websocket.app_destroy', 'websocket.app_rotate'], true)) {
            throw new ResourceOperationException('operation.direct_invalid', 'A Node-bound WebSocket registry Operation is required.', 422);
        }
    }
}
