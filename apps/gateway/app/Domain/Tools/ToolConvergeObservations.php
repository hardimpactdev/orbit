<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AptRepository;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Validator;

final readonly class ToolConvergeObservations
{
    /** @param array<int, array<string, mixed>> $plans
     * @return list<array<string, mixed>>
     */
    public function validate(mixed $raw, array $plans, bool $complete): array
    {
        $valid = Validator::make(['tools' => $raw], [
            'tools' => ['present', 'array', 'list', 'max:10000'],
            'tools.*' => ['required', 'array:id,status,installed_version'],
            'tools.*.id' => ['required', 'integer', 'min:1', 'distinct:strict'],
            'tools.*.status' => ['required', 'in:installed,failed'],
            'tools.*.installed_version' => ['present', 'nullable', 'string', 'max:255', 'not_regex:/[\x00-\x20\x7f]/'],
        ]);
        if (! is_array($raw) || $valid->fails() || ($complete && count($raw) !== count($plans))) {
            $this->invalid();
        }
        $observations = [];
        foreach ($raw as $value) {
            $observation = ValidatedData::object($value);
            $id = $observation['id'];
            $plan = is_int($id) ? ($plans[$id] ?? null) : null;
            $version = $observation['installed_version'];
            if ($plan === null || ($observation['status'] === 'installed' && ! is_string($version))) {
                $this->invalid();
            }
            $desired = $plan['desired_version'] ?? null;
            if ($observation['status'] === 'installed' && is_string($desired) && ! new ToolDefinition($desired, null, null)->matches(ToolManagerName::from(ValidatedData::string($plan['manager'])), $version)) {
                $this->invalid();
            }
            $observations[] = $observation;
        }

        return $observations;
    }

    /** @param array<int, array<string, mixed>> $plans
     * @param  list<array<string, mixed>>  $observations
     */
    public function record(int $nodeId, array $plans, array $observations): void
    {
        foreach ($observations as $observation) {
            $id = ValidatedData::integer($observation['id']);
            $plan = $plans[$id];
            $tool = Tool::query()->whereKey($id)->where('node_id', $nodeId)->lockForUpdate()->first();
            if ($tool === null || $tool->package !== $plan['package'] || $tool->tool_manager_id !== $plan['manager_id']) {
                continue;
            }
            $tool->desired_version = ValidatedData::nullableString($plan['desired_version'] ?? null);
            $tool->version_constraint = ValidatedData::nullableString($plan['version_constraint']);
            $tool->desired_repository = is_array($plan['repository'] ?? null) ? ValidatedData::object($plan['repository']) : null;
            $tool->status = $observation['status'] === 'installed' ? ToolStatus::Installed : ToolStatus::Failed;
            $repair = $tool->installed_version !== null || ($plan['tool_status'] ?? null) === ToolStatus::Installed->value
                || $tool->failed_operation === ToolOperation::Update;
            $tool->failed_operation = $tool->status === ToolStatus::Failed ? ($repair ? ToolOperation::Update : ToolOperation::Install) : null;
            $tool->error_code = $tool->status === ToolStatus::Failed
                ? (($plan['manager_unavailable'] ?? false) ? 'tool.manager_unsupported' : ($repair ? 'tool.update_failed' : 'tool.install_failed')) : null;
            if (is_string($observation['installed_version'])) {
                $tool->installed_version = $observation['installed_version'];
            }
            if ($tool->status === ToolStatus::Installed) {
                ToolManagerRecord::query()->whereKey($tool->tool_manager_id)->where('status', '!=', LifecycleStatus::Active->value)
                    ->update(['status' => LifecycleStatus::Active->value, 'failed_step' => null, 'error_code' => null]);
                if (is_array($repository = $plan['repository'] ?? null)) {
                    $source = AptRepository::query()->updateOrCreate(['node_id' => $nodeId, 'name' => $repository['name']], ['definition' => $repository]);
                    $tool->apt_repository_id = $source->id;
                }
            }
            if ($tool->isDirty()) {
                $tool->save();
            }
        }
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('operation.result_invalid', 'The converge Tool observations are incomplete or outside scope.', 422);
    }
}
