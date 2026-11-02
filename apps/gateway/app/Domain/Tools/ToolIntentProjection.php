<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AptRepository;
use App\Models\Operation;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Public intent and bounded state writes. Swamp owns package execution and policy. */
final readonly class ToolIntentProjection implements DirectOperationProjection, NativeOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return ['intent' => ['kind' => $operation->kind, ...array_intersect_key($operation->input, array_flip([
            'node_id', 'manager', 'package', 'user', 'platform', 'architecture', 'version_constraint', 'tool_id', 'tool_status', 'installed_version', 'setup', 'repository', 'remove_repository', 'repository_packages', 'repository_given', 'manager_ready',
        ]))], 'ssh' => $operation->input['ssh']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip([
            'native_error_code', 'manager_version', 'installed_version', 'outcome', 'block', 'error_code', 'repository_removed',
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
        if (isset($result['repository_removed']) && ! is_bool($result['repository_removed'])) {
            throw new ResourceOperationException('operation.result_invalid', 'The Tool repository result is invalid.', 422);
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

    /**
     * The reviewed Tool charts: install recorded its Tool as installing at admission; a failed manager setup drops it and
     * fails the manager; any other failure marks the Tool failed. Repository definitions change only on success (R2).
     *
     * @param  array<string, mixed>  $result
     */
    private function projectState(Operation $operation, array $result): void
    {
        $status = $operation->status;
        $verb = substr($operation->kind, 5);
        $input = $operation->input;
        $version = $result['manager_version'] ?? null;
        $record = ToolManagerRecord::query()->where('node_id', $operation->node_id)->where('name', $input['manager'])->first();
        if (is_string($version)) {
            $record ??= ToolManagerRecord::query()->create(['node_id' => $operation->node_id, 'name' => $input['manager'], 'status' => LifecycleStatus::Provisioning]);
            $record->update(['status' => LifecycleStatus::Active, 'installed_version' => $version, 'failed_step' => null, 'error_code' => null]);
        }
        $managerFailed = $verb === 'install' && $status === OperationStatus::Failed
            && in_array($operation->error_code, ['tool.manager_provision_failed', 'tool.manager_unavailable'], true);
        if ($managerFailed) {
            $record ??= ToolManagerRecord::query()->create(['node_id' => $operation->node_id, 'name' => $input['manager'], 'status' => LifecycleStatus::Provisioning]);
            $native = ValidatedData::string($result['native_error_code'] ?? '');
            $absent = str_ends_with($native, '_scope_absent');
            $conflict = str_ends_with($native, '_scope_conflict');
            $record->update(['status' => LifecycleStatus::Failed,
                'failed_step' => $absent ? 'manager-absent' : ($conflict ? 'manager-conflict' : 'materialize'),
                'error_code' => $absent ? 'node.tool_manager_absent' : ($conflict ? 'node.tool_manager_conflict' : 'node.tool_manager_materialization_failed')]);
        }
        $toolId = $input['tool_id'] ?? null;
        $tool = is_int($toolId) ? Tool::query()->find($toolId) : null;
        if ($status !== OperationStatus::Succeeded) {
            // An installing record that never became a Tool goes: a failed setup, a package Orbit did not install, a cancel.
            if ($tool?->status === ToolStatus::Installing && ($managerFailed || $status === OperationStatus::Cancelled
                || $operation->error_code === 'tool.package_already_installed')) {
                $tool->delete();

                return;
            }
            if ($status === OperationStatus::Cancelled || $verb === 'adopt') {
                return;
            }
            $tool?->update(['status' => ToolStatus::Failed, 'installed_version' => $result['installed_version'] ?? $tool->installed_version,
                'failed_operation' => ToolOperation::from($verb), 'error_code' => $operation->error_code]);

            return;
        }
        if ($verb === 'remove') {
            $tool?->delete();
            // Dropping the last Tool's apt source is best-effort; a kept source stays recorded for a later cleanup.
            if (($result['repository_removed'] ?? false) === true && is_int($input['repository_id'] ?? null)) {
                AptRepository::query()->whereKey($input['repository_id'])->delete();
            }

            return;
        }
        $repositoryId = $this->repository($operation);
        if ($tool !== null && $verb === 'adopt' && $tool->status === ToolStatus::Installed) {
            $tool->update(['apt_repository_id' => $repositoryId]);

            return;
        }
        if ($tool === null && $record !== null) {
            $tool = $this->createTool($operation, $record);
        }
        $tool?->update(['apt_repository_id' => $repositoryId, 'status' => ToolStatus::Installed, 'installed_version' => $result['installed_version'] ?? null, 'failed_operation' => null, 'error_code' => null]);
    }

    /** The admitted repository definition, stored once the run succeeded. */
    private function repository(Operation $operation): ?int
    {
        $definition = $operation->input['repository'] ?? null;
        if (! is_array($definition)) {
            return null;
        }
        $record = AptRepository::query()->firstOrCreate(['node_id' => $operation->node_id, 'name' => $definition['name']], ['definition' => $definition]);
        if ($record->definition != $definition) {
            $record->update(['definition' => $definition]);
        }

        return $record->id;
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
