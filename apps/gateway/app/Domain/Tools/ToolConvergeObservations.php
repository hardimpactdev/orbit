<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AptRepository;
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
     * Records a convergence's Tool observations. Each field changes only if no newer receipt changed it (Tool::receive); the desired state
     * is the admission's (ToolConvergeIntent::admitDesired). An installed observation acquires the package only for a plan admitted under
     * the ownership contract, whose helper refuses a present package for a Tool Orbit does not own (the plan names `owned`); for any other
     * plan, a preceding-release one read after the upgrade, it is an observation.
     *
     * @param  array<int, array<string, mixed>>  $plans
     * @param  list<array<string, mixed>>  $observations
     */
    public function record(Operation $operation, array $plans, array $observations): void
    {
        $nodeId = ValidatedData::integer($operation->node_id);
        foreach ($observations as $observation) {
            $id = ValidatedData::integer($observation['id']);
            $plan = $plans[$id];
            $tool = Tool::query()->whereKey($id)->where('node_id', $nodeId)->lockForUpdate()->first();
            if ($tool === null || $tool->package !== $plan['package'] || $tool->tool_manager_id !== $plan['manager_id']) {
                continue;
            }
            $installed = $observation['status'] === 'installed';
            $repair = $tool->installed_version !== null || ($plan['tool_status'] ?? null) === ToolStatus::Installed->value
                || $tool->failed_operation === ToolOperation::Update;
            $state = ['status' => $installed ? ToolStatus::Installed : ToolStatus::Failed,
                'failed_operation' => $installed ? null : ($repair ? ToolOperation::Update : ToolOperation::Install),
                'error_code' => $installed ? null : (($plan['manager_unavailable'] ?? false) ? 'tool.manager_unsupported' : ($repair ? 'tool.update_failed' : 'tool.install_failed'))];
            if ($installed) {
                $state['apt_repository_id'] = is_int($plan['repository_id'] ?? null) && AptRepository::query()->whereKey($plan['repository_id'])->exists() ? $plan['repository_id'] : null;
            }
            $tool->receive($operation->id, $state, owned: $installed && array_key_exists('owned', $plan) ? true : null,
                version: ValidatedData::nullableString($observation['installed_version']), observed: is_string($observation['installed_version']));
            if ($installed) {
                ToolManagerRecord::query()->find($tool->tool_manager_id)?->receive($operation->id, ['status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);
            }
        }
    }

    /**
     * How a convergence's Tools phase ended, the same on time or late (the Operation then stays timed out). Every valid observation it
     * reports counts, installed or failed, whatever the phase, and so does each repository outcome: the phase published each repository its
     * Tools reference before their packages, as that repository's own add.
     *
     * @param  array<int, array<string, mixed>>  $plans
     * @param  list<array<string, mixed>>  $observations  validated observations
     * @param  list<array{name: string, status: string, error_code: ?string}>  $repositories  validated repository outcomes (AptRepositoryOutcomes::validate)
     */
    public function settle(Operation $operation, array $plans, array $observations, array $repositories): void
    {
        $this->record($operation, $plans, $observations);
        AptRepositoryOutcomes::record(ValidatedData::integer($operation->node_id), $operation->id, $repositories);
    }

    /**
     * The phase of a convergence whose report could not be read: the run's own history shows whether its Tools step ran. A step it
     * recorded as skipped wrote nothing; missing evidence is no proof.
     *
     * @param  array<string, mixed>  $run
     */
    public static function phaseFromRun(array $run): string
    {
        foreach (is_array($run['jobs'] ?? null) ? $run['jobs'] : [] as $job) {
            foreach (is_array($job) && is_array($job['steps'] ?? null) ? $job['steps'] : [] as $step) {
                if (is_array($step) && ($step['name'] ?? null) === 'Converge tools') {
                    return ($step['status'] ?? null) === 'skipped' ? 'skipped' : 'partial';
                }
            }
        }

        return 'partial';
    }

    /**
     * A late report: its observations as validated for its phase, or none when it cannot be read.
     *
     * @param  array<int, array<string, mixed>>  $plans
     * @return list<array<string, mixed>>
     */
    public function lateObservations(mixed $observations, array $plans, string $phase): array
    {
        try {
            return $this->validate($observations ?? [], $plans, $phase === 'completed');
        } catch (ResourceOperationException) {
            return [];
        }
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('operation.result_invalid', 'The converge Tool observations are incomplete or outside scope.', 422);
    }
}
