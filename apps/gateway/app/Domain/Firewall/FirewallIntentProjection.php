<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Bounded intent and domain projection only. Swamp executes the Node action. */
final readonly class FirewallIntentProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private FirewallOperationCompletion $completion) {}

    public function input(Operation $operation): array
    {
        return ['node_id' => $operation->node_id, ...array_intersect_key($operation->input, array_flip(['name', 'action', 'source', 'port', 'protocol', 'agent_sha256']))];
    }

    /** @param array<string, mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['backend', 'error_code']));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        if ((isset($result['backend']) && ! in_array($result['backend'], ['active', 'inactive', 'absent'], true))
            || (isset($result['error_code']) && (! is_string($result['error_code']) || preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $result['error_code']) !== 1))) {
            throw new ResourceOperationException('operation.result_invalid', 'The Firewall result is invalid.', 422);
        }
        if ($status === OperationStatus::Succeeded && (($result['backend'] ?? null) !== ($operation->kind === 'firewall.remove' ? 'absent' : 'active') || $code !== null || isset($result['error_code']))) {
            throw new ResourceOperationException('operation.result_invalid', 'The Firewall result does not fit its intent.', 422);
        }
        if ($status === OperationStatus::Failed && ($code === null || ($result['error_code'] ?? null) !== $code)) {
            throw new ResourceOperationException('operation.result_invalid', 'A failed Firewall intent needs its refusal code.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $status, $result, $code): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = $this->direct->project($operation, $data, $result, 'firewall-rule');
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
