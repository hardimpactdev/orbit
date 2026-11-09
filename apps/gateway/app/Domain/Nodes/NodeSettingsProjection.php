<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class NodeSettingsProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => ['ssh' => $operation->input['ssh'],
            'storage' => array_intersect_key($operation->input, array_flip(['root', 'user', 'group'])),
            'task_host_input_comments' => $operation->input['task_host_input_comments'] ?? []]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        // Ansible owns the filesystem check; the native phase carries its failure detail.
        $failure = is_array($data['failure'] ?? null) ? $data['failure'] : [];
        $failed = in_array($status, [OperationStatus::Failed, OperationStatus::Interrupted], true);
        if ($failed && ($failure['failed_step'] ?? null) === 'Prepare the apps folder') {
            $code = $data['error_code'] = 'node.settings_root_failed';
        }
        $result = ValidatedData::object($data['result'] ?? []);
        $storage = ($operation->input['root'] ?? null) !== null;
        $firewall = ($operation->input['task_host_input_comments'] ?? []) !== [];
        if ($status === OperationStatus::Succeeded && (($storage && NativeRunResult::stepStatus($result, 'Prepare the apps folder') !== 'succeeded')
            || ($firewall && NativeRunResult::stepStatus($result, 'Withdraw task host input') !== 'succeeded')
            || NativeRunResult::stepStatus($result, 'Cleanup') !== 'succeeded' || $code !== null)) {
            throw new ResourceOperationException('operation.result_invalid', 'The settings action has no successful result.', 422);
        }
        if (($failed && $code === null) || (! $failed && $code !== null)) {
            throw new ResourceOperationException('operation.result_invalid', 'The settings action needs its refusal code.', 422);
        }
        $cleanupComplete = NativeRunResult::stepStatus($result, 'Cleanup') === 'succeeded';
        $result = match ($status) {
            OperationStatus::Succeeded => ['prepared' => true],
            OperationStatus::Failed, OperationStatus::Interrupted => ['error_code' => $code],
            default => [],
        };
        $result['cleanup_complete'] = $cleanupComplete;

        return DB::transaction(function () use ($operation, $data, $status, $result): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $previous = $operation->status;
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($status === OperationStatus::Succeeded && $previous !== OperationStatus::Succeeded) {
                app(NodeSettingsOperationCompletion::class)->complete($projected, OperationOutcome::succeeded($result));
            }

            return $projected;
        });
    }
}
