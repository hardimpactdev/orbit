<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Public intent and bounded state writes. Swamp owns package execution and policy. */
final readonly class ToolIntentProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return ['node_id' => $operation->node_id, ...array_intersect_key($operation->input, array_flip([
            'manager', 'package', 'user', 'agent_sha256', 'platform', 'architecture',
            'version_constraint', 'tool_id', 'tool_status', 'installed_version', 'intent_only', 'materialize',
        ]))];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip([
            'native_error_code', 'manager_version', 'installed_version', 'outcome', 'block', 'error_code',
        ]));
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

        return DB::transaction(function () use ($operation, $data, $result): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = $this->direct->project($operation, $data, $result);
            if (! $terminal && $projected->status->terminal()) {
                $this->projectState($projected, $result);
            }

            return $projected;
        });
    }

    /** @param array<string, mixed> $result */
    private function projectState(Operation $operation, array $result): void
    {
        $status = $operation->status;
        $verb = substr($operation->kind, 5);
        if ($verb === 'adopt' && $status !== OperationStatus::Succeeded) {
            return;
        }
        $input = $operation->input;
        $version = $result['manager_version'] ?? null;
        $record = ToolManagerRecord::query()->where('node_id', $operation->node_id)->where('name', $input['manager'])->first();
        if (is_string($version)) {
            $record ??= ToolManagerRecord::query()->create(['node_id' => $operation->node_id, 'name' => $input['manager'], 'status' => LifecycleStatus::Provisioning]);
            $record->update(['status' => LifecycleStatus::Active, 'installed_version' => $version, 'failed_step' => null, 'error_code' => null]);
        } elseif (in_array($verb, ['install', 'manager_converge'], true) && $status === OperationStatus::Failed) {
            $record ??= ToolManagerRecord::query()->create(['node_id' => $operation->node_id, 'name' => $input['manager'], 'status' => LifecycleStatus::Provisioning]);
            $native = ValidatedData::string($result['native_error_code'] ?? '');
            $absent = str_ends_with($native, '_scope_absent');
            $conflict = str_ends_with($native, '_scope_conflict');
            $record->update(['status' => LifecycleStatus::Failed,
                'failed_step' => $absent ? 'manager-absent' : ($conflict ? 'manager-conflict' : 'manager-version'),
                'error_code' => $absent ? 'node.tool_manager_absent' : ($conflict ? 'node.tool_manager_conflict' : 'node.tool_manager_materialization_failed')]);
        }
        if ($verb === 'manager_converge' || $status === OperationStatus::Cancelled) {
            return;
        }
        $toolId = $input['tool_id'] ?? null;
        $tool = is_int($toolId) ? Tool::query()->find($toolId) : null;
        if ($status !== OperationStatus::Succeeded) {
            if (! is_string($version) || ($tool === null && $operation->error_code === 'tool.package_already_installed')) {
                return;
            }
            if ($tool === null && $verb === 'install') {
                $tool = $this->createTool($operation, $record);
            }
            $tool?->update(['status' => ToolStatus::Failed, 'installed_version' => $result['installed_version'] ?? $tool->installed_version,
                'failed_operation' => ToolOperation::from($verb), 'error_code' => $operation->error_code]);

            return;
        }
        if ($verb === 'remove') {
            $tool?->delete();

            return;
        }
        if ($tool !== null && $verb === 'adopt' && $tool->status === ToolStatus::Installed) {
            return;
        }
        if ($tool === null && $record !== null) {
            $tool = $this->createTool($operation, $record);
        }
        $tool?->update(['status' => ToolStatus::Installed, 'installed_version' => $result['installed_version'] ?? null, 'failed_operation' => null, 'error_code' => null]);
    }

    private function createTool(Operation $operation, ToolManagerRecord $record): Tool
    {
        return Tool::query()->create([
            'node_id' => $operation->node_id, 'tool_manager_id' => $record->id,
            'package' => $operation->input['package'], 'version_constraint' => $operation->input['version_constraint'],
            'status' => ToolStatus::Installing,
        ]);
    }
}
