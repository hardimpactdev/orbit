<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
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

    /**
     * Records a convergence's Tool observations. Each field changes only if no newer receipt changed it (Tool::receive), and the desired
     * state the plan captured applies only while no newer request for the Tool was admitted, since every admission sets it.
     *
     * @param  array<int, array<string, mixed>>  $plans
     * @param  list<array<string, mixed>>  $observations
     * @param  bool  $completed  whether the Tools phase finished: only then did every source write, refusal restore included, succeed
     */
    public function record(Operation $operation, array $plans, array $observations, bool $completed): void
    {
        $nodeId = ValidatedData::integer($operation->node_id);
        foreach ($observations as $observation) {
            $id = ValidatedData::integer($observation['id']);
            $plan = $plans[$id];
            $tool = Tool::query()->whereKey($id)->where('node_id', $nodeId)->lockForUpdate()->first();
            if ($tool === null || $tool->package !== $plan['package'] || $tool->tool_manager_id !== $plan['manager_id']) {
                continue;
            }
            $newer = Operation::query()->where('id', '>', $operation->id)->where('node_id', $nodeId)->whereIn('kind', ['tool.install', 'tool.update', 'tool.remove'])
                ->where('input->tool_id', $id)->exists();
            if (! $newer) {
                $tool->update(['desired_version' => ValidatedData::nullableString($plan['desired_version'] ?? null),
                    'version_constraint' => ValidatedData::nullableString($plan['version_constraint']),
                    'desired_repository' => is_array($plan['repository'] ?? null) ? ValidatedData::object($plan['repository']) : null]);
            }
            $installed = $observation['status'] === 'installed';
            $repair = $tool->installed_version !== null || ($plan['tool_status'] ?? null) === ToolStatus::Installed->value
                || $tool->failed_operation === ToolOperation::Update;
            $state = ['status' => $installed ? ToolStatus::Installed : ToolStatus::Failed,
                'failed_operation' => $installed ? null : ($repair ? ToolOperation::Update : ToolOperation::Install),
                'error_code' => $installed ? null : (($plan['manager_unavailable'] ?? false) ? 'tool.manager_unsupported' : ($repair ? 'tool.update_failed' : 'tool.install_failed'))];
            if ($installed && is_array($repository = $plan['repository'] ?? null)) {
                $state['apt_repository_id'] = $completed ? AptSourceTrust::record($nodeId, ValidatedData::object($repository), $operation->id)->id
                    : AptSourceTrust::suspect($nodeId, ValidatedData::object($repository))->id;
            }
            // The helper refuses a present package for a Tool Orbit does not own, so an installed observation is Orbit's package.
            $tool->receive($operation->id, $state, owned: $installed ? true : null,
                version: ValidatedData::nullableString($observation['installed_version']), observed: is_string($observation['installed_version']));
            if ($installed) {
                ToolManagerRecord::query()->whereKey($tool->tool_manager_id)->where('status', '!=', LifecycleStatus::Active->value)
                    ->update(['status' => LifecycleStatus::Active->value, 'failed_step' => null, 'error_code' => null]);
            }
        }
    }

    /**
     * A convergence report that arrived after its timeout counts as it would have on time, the Operation staying timed out: each Tool it
     * verified installed is recorded; a Tools phase that finished proves its sources, one that never ran gives them back the trust they
     * were admitted with, and a partial one proves nothing.
     *
     * @param  array<int, array<string, mixed>>  $plans
     * @param  mixed  $observations  the report's Tool observations
     * @param  string  $phase  `completed`, `skipped` or `partial`
     * @param  list<string>  $orphans  the sources no Tool named at admission, which a finished phase removed
     */
    public function late(Operation $operation, array $plans, mixed $observations, string $phase, array $orphans): void
    {
        if ($phase === 'skipped') {
            AptSourceTrust::restoreAdmitted(ValidatedData::integer($operation->node_id), ValidatedData::object($operation->input['repository_admitted'] ?? []), $operation->id);

            return;
        }
        try {
            $tools = $this->validate($observations, $plans, $phase === 'completed');
        } catch (ResourceOperationException) {
            return;
        }
        $this->record($operation, $plans, $phase === 'completed' ? $tools : array_values(array_filter($tools, static fn (array $tool): bool => $tool['status'] === 'installed')),
            $phase === 'completed');
        if ($phase === 'completed') {
            AptSourceTrust::forgetOrphans(ValidatedData::integer($operation->node_id), $orphans);
        }
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('operation.result_invalid', 'The converge Tool observations are incomplete or outside scope.', 422);
    }
}
