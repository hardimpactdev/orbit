<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records the outcome of Swamp's Node-local checkout inspection. */
final readonly class InstanceRenameProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private InstanceLifecycleState $state) {}

    public function input(Operation $operation): array
    {
        $input = $operation->input;

        return ['agent_sha256' => $input['agent_sha256'], 'repository_identity' => $input['repository_identity'],
            'branch' => $input['branch'], 'source' => [
                'repository' => $input['repository'], 'checkout' => $input['checkout'], 'root' => $input['source_root'],
                'user' => $input['user'], 'group' => $input['group'], 'prepare_id' => $input['prepare_id'] ?? null,
                'seed_repository' => $input['seed_repository'] ?? null, 'verify_origin' => false, 'require_idle' => true,
            ]];
    }

    /** @param array<string, mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = ValidatedData::object($data['result'] ?? []);
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        $valid = match ($status) {
            OperationStatus::Succeeded => $code === null && ($result['source_verified'] ?? null) === true
                && array_key_exists('branch', $result) && (is_string($result['branch']) || $result['branch'] === null)
                && (($operation->input['branch'] ?? null) === null || $result['branch'] === $operation->input['branch'])
                && array_diff(array_keys($result), ['source_verified', 'branch']) === [],
            OperationStatus::Failed => $code !== null && $result === ['error_code' => $code],
            OperationStatus::Running, OperationStatus::Cancelled => $code === null && $result === [],
            default => false,
        };
        if (! $valid) {
            throw new ResourceOperationException('operation.result_invalid', 'Rename requires a checked source and the requested branch, or a bounded refusal.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $status, $result): Operation {
            $operation->refresh();
            $terminal = $operation->status->terminal();
            $projected = $this->direct->project($operation, $data, $result, 'rename-inspect');
            if (! $terminal && $status === OperationStatus::Succeeded) {
                $this->state->renamed($operation, $result);
            }

            return $projected;
        });
    }
}
