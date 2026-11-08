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
        // A refusal or failure on Node 12 is the documented settings code; its stderr carries the reason.
        $failure = is_array($data['failure'] ?? null) ? $data['failure'] : [];
        if ($status === OperationStatus::Failed && in_array($failure['failed_step'] ?? null, ['Create the apps folder', 'Check owner and mode'], true)) {
            $code = $data['error_code'] = 'node.settings_root_failed';
        }
        $result = ValidatedData::object($data['result'] ?? []);
        $storage = ($operation->input['root'] ?? null) !== null;
        $firewall = ($operation->input['task_host_input_comments'] ?? []) !== [];
        if ($status === OperationStatus::Succeeded && (($storage && NativeRunResult::stepStatus($result, 'Check owner and mode') !== 'succeeded')
            || ($firewall && NativeRunResult::stepStatus($result, 'Withdraw task host input') !== 'succeeded') || $code !== null)) {
            throw new ResourceOperationException('operation.result_invalid', 'The settings action has no successful result.', 422);
        }
        // The end check is the folder's stat in Swamp's run data: the managed user's real folder, mode 0755.
        if ($status === OperationStatus::Succeeded && $storage && ! $this->owned($operation, $result)) {
            $status = OperationStatus::Failed;
            $code = $data['error_code'] = 'node.settings_root_failed';
            $data['status'] = $status->value;
        }
        if (($status === OperationStatus::Failed && $code === null) || ($status !== OperationStatus::Failed && $code !== null)) {
            throw new ResourceOperationException('operation.result_invalid', 'The settings action needs its refusal code.', 422);
        }
        $result = match ($status) {
            OperationStatus::Succeeded => ['prepared' => true],
            OperationStatus::Failed => ['error_code' => $code],
            default => [],
        };

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

    /** @param array<string,mixed> $result */
    private function owned(Operation $operation, array $result): bool
    {
        $steps = is_array($result['steps'] ?? null) ? $result['steps'] : [];
        $check = is_array($steps['Check owner and mode'] ?? null) ? $steps['Check owner and mode'] : [];
        $stdout = is_string($check['stdout'] ?? null) ? trim($check['stdout']) : null;

        return $stdout === 'directory '.ValidatedData::string($operation->input['user']).':'.ValidatedData::string($operation->input['group']).' 755';
    }
}
