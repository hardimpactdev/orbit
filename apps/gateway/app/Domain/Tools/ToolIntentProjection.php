<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Safe admitted inputs and bounded inventory projection. Swamp executes policy. */
final readonly class ToolIntentProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private ToolOperationCompletion $completion) {}

    public function input(Operation $operation): array
    {
        return ['node_id' => $operation->node_id, ...array_intersect_key($operation->input, array_flip([
            'manager', 'package', 'user', 'agent_sha256', 'platform', 'architecture',
            'version_constraint', 'tool_id', 'tool_status',
            'installed_version', 'intent_only', 'materialize',
        ]))];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip([
            'native_error_code', 'manager_version', 'installed_version', 'outcome', 'block', 'error_code',
        ]));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        foreach (['manager_version', 'installed_version'] as $field) {
            if (isset($result[$field]) && (! is_string($result[$field]) || $result[$field] === '' || strlen($result[$field]) > 255 || preg_match('/[\x00-\x1F\x7F]/', $result[$field]) === 1)) {
                throw new ResourceOperationException('operation.result_invalid', 'The Tool version is invalid.', 422);
            }
        }
        foreach (['native_error_code', 'error_code', 'block'] as $field) {
            if (isset($result[$field]) && (! is_string($result[$field]) || preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $result[$field]) !== 1)) {
                throw new ResourceOperationException('operation.result_invalid', 'The Tool refusal is invalid.', 422);
            }
        }
        if (isset($result['outcome']) && ! in_array($result['outcome'], ['applied', 'unchanged', 'blocked_by_constraint'], true)) {
            throw new ResourceOperationException('operation.result_invalid', 'The Tool outcome is invalid.', 422);
        }
        if ($status === OperationStatus::Succeeded && (! is_string($result['manager_version'] ?? null) || $code !== null || isset($result['error_code']) || isset($result['native_error_code']) || isset($result['block']))) {
            throw new ResourceOperationException('operation.result_invalid', 'A successful Tool intent requires its manager version and no refusal.', 422);
        }
        if ($status === OperationStatus::Failed && ($code === null || ($result['error_code'] ?? null) !== $code)) {
            throw new ResourceOperationException('operation.result_invalid', 'A failed Tool intent needs its refusal code.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $status, $result, $code): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = $this->direct->project($operation, $data, $result, 'tool-policy');
            if (! $terminal && $status->terminal()) {
                $outcome = match ($status) {
                    OperationStatus::Succeeded => OperationOutcome::succeeded($result),
                    OperationStatus::Failed => OperationOutcome::failed($code, $result),
                    default => OperationOutcome::cancelled($result),
                };
                $this->completion->complete($projected, $outcome);
            }

            return $projected;
        });
    }
}
