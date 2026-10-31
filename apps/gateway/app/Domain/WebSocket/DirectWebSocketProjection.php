<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\WebSocketRegistryOperation;
use App\Support\ValidatedData;

/** Registry state stays here; confirmation waits belong to Swamp. */
final readonly class DirectWebSocketProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private WebSocketOperationCompletion $completion) {}

    public function input(Operation $operation): array
    {
        $this->assertKind($operation);
        $id = $operation->input['registry_operation_id'] ?? null;
        if (! is_string($id) || preg_match('/\A[a-f0-9-]{36}\z/D', $id) !== 1) {
            throw new ResourceOperationException('websocket.registry_invalid', 'A recorded registry change is required.', 422);
        }
        $registry = WebSocketRegistryOperation::query()->findOrFail($id);
        $kind = $registry->kind === 'sync' ? 'websocket.sync' : 'websocket.app_'.$registry->kind;
        if ($kind !== $operation->kind || $registry->epoch !== ($operation->input['epoch'] ?? null)
            || $registry->revision !== ($operation->input['revision'] ?? null)) {
            throw new ResourceOperationException('websocket.registry_invalid', 'The registry change does not match its admitted Operation.', 422);
        }

        return ['registry_operation_id' => $id, 'epoch' => $registry->epoch, 'revision' => $registry->revision];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->input($operation);
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $raw = ValidatedData::object($data['result'] ?? []);
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        $result = [];
        if ($status === OperationStatus::Succeeded) {
            if (($raw['outcome'] ?? null) !== 'succeeded' || ! is_array($raw['data'] ?? null)
                || ($raw['data']['backend'] ?? null) !== 'applied' || $code !== null) {
                throw new ResourceOperationException('operation.result_invalid', 'WebSocket confirmation requires an applied registry result.', 422);
            }
            $result = ['outcome' => 'succeeded', 'data' => ['backend' => 'applied']];
            if (! $operation->status->terminal()) {
                $this->completion->complete($operation, OperationOutcome::succeeded($result));
            }
        } elseif ($status === OperationStatus::Failed) {
            if (! in_array($code, ['websocket.registry_pending', 'websocket.registry_superseded'], true) || ($raw['error_code'] ?? null) !== $code) {
                throw new ResourceOperationException('operation.result_invalid', 'WebSocket confirmation requires a typed registry refusal.', 422);
            }
            $result = ['error_code' => $code];
        }

        return $this->direct->project($operation, $data, $result, 'reverb-registry-applied');
    }

    private function assertKind(Operation $operation): void
    {
        if (! DirectOperation::owns($operation) || $operation->node_id === null
            || ! in_array($operation->kind, ['websocket.sync', 'websocket.app_create', 'websocket.app_destroy'], true)) {
            throw new ResourceOperationException('operation.direct_invalid', 'A Node-bound WebSocket registry Operation is required.', 422);
        }
    }
}
