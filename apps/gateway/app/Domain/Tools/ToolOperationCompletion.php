<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Tool;
use App\Models\ToolManagerRecord;

/** Projects proven receipts into Gateway intent. Contains no Node work. */
final readonly class ToolOperationCompletion implements OperationCompletion
{
    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $installed = $outcome->result['installed_version'] ?? null;
        if ($outcome->status === OperationStatus::Succeeded
            && (($operation->kind === 'tool.remove' && $installed !== null)
                || (in_array($operation->kind, ['tool.install', 'tool.update', 'tool.adopt'], true) && $installed === null))) {
            throw new ResourceOperationException('operation.completion_unproven', 'The installed version does not fit the Tool operation.', 409);
        }
        if ($operation->kind === 'tool.adopt' && $outcome->status !== OperationStatus::Succeeded) {
            return;
        }
        $input = $operation->input;
        $managerReady = DirectOperation::owns($operation) ? ($outcome->result['manager_version'] ?? null) : data_get($operation->state, 'executor_runs.manager-ready.result.version');
        $record = ToolManagerRecord::query()->where('node_id', $operation->node_id)->where('name', $input['manager'])->first();
        $verb = substr($operation->kind, 5);
        if (is_string($managerReady)) {
            $record ??= ToolManagerRecord::query()->create(['node_id' => $operation->node_id, 'name' => $input['manager'], 'status' => LifecycleStatus::Provisioning]);
            $record->update(['status' => LifecycleStatus::Active, 'installed_version' => $managerReady, 'failed_step' => null, 'error_code' => null]);
        } elseif (in_array($verb, ['install', 'manager_converge'], true) && $outcome->status === OperationStatus::Failed) {
            $record ??= ToolManagerRecord::query()->create(['node_id' => $operation->node_id, 'name' => $input['manager'], 'status' => LifecycleStatus::Provisioning]);
            $native = DirectOperation::owns($operation) ? ($outcome->result['native_error_code'] ?? '') : ($outcome->result['error_code'] ?? '');
            $absent = is_string($native) && str_ends_with($native, '_scope_absent');
            $conflict = is_string($native) && str_ends_with($native, '_scope_conflict');
            $record->update(['status' => LifecycleStatus::Failed,
                'failed_step' => $absent ? 'manager-absent' : ($conflict ? 'manager-conflict' : 'manager-version'),
                'error_code' => $absent ? 'node.tool_manager_absent' : ($conflict ? 'node.tool_manager_conflict' : 'node.tool_manager_materialization_failed')]);
        }
        if ($verb === 'manager_converge' || $outcome->status === OperationStatus::Cancelled) {
            return;
        }
        $toolId = $input['tool_id'] ?? null;
        $tool = is_int($toolId) ? Tool::query()->find($toolId) : null;
        if ($outcome->status !== OperationStatus::Succeeded) {
            if ($verb === 'adopt' || ! is_string($managerReady)
                || ($tool === null && $outcome->errorCode === 'tool.package_already_installed')) {
                return;
            }
            if ($tool === null && $verb === 'install') {
                $tool = $this->createTool($operation, $record);
            }
            $observed = DirectOperation::owns($operation) ? ($outcome->result['installed_version'] ?? null) : data_get($operation->state, 'executor_runs.tool-verified.result.installed_version');
            $version = is_string($observed) && $observed !== '' && strlen($observed) <= 255 && preg_match('/[\x00-\x1F\x7F]/', $observed) !== 1 ? $observed : $tool?->installed_version;
            $tool?->update(['status' => ToolStatus::Failed, 'installed_version' => $version, 'failed_operation' => ToolOperation::from($verb), 'error_code' => $outcome->errorCode]);

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
        $tool?->update(['status' => ToolStatus::Installed, 'installed_version' => $outcome->result['installed_version'] ?? null, 'failed_operation' => null, 'error_code' => null]);
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
