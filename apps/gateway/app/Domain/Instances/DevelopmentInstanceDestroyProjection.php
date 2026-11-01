<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\ProjectDirectInstanceDestroyAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Frozen public removal DAG input and terminal record projection only. */
final readonly class DevelopmentInstanceDestroyProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        $input = $operation->input;
        $sourceCapture = InstanceRemoval::query()->findOrFail(ValidatedData::string($input['removal_id']))->native_sources;
        $native = ValidatedData::object($input['native_destroy']);
        $secrets = ValidatedData::object($native['execution_secrets']);
        unset($native['execution_secrets']);
        $detail = ValidatedData::object($input['direct_destroy']);
        $database = ValidatedData::object($detail['database']);
        if (($database['servers'] ?? []) !== []) {
            throw new ResourceOperationException('instance.destroy_unsupported', 'Managed server removal requires its complete native parent.');
        }
        $public = Arr::only($input, ['removal_id', 'node_id', 'instance_id', 'project_id', 'name', 'force', 'inventory_digest', 'repository', 'source_pending', 'protected_source_roots', 'members', 'direct_destroy']);
        $programs = ValidatedData::object(app(InstanceDestroyTeardownSecret::class)->resolve($operation, $operation->target_id, '', 'teardown')->value);
        $commands = [];
        foreach (InstanceLifecycleInput::members($programs) as $member) {
            $instance = Instance::query()->findOrFail(ValidatedData::integer($member['instance_id']));
            foreach (InstanceLifecycleInput::members(['members' => $member['steps']]) as $position => $step) {
                $index = count($commands);
                $field = 'teardown_'.$index;
                $sha = hash('sha256', ValidatedData::string($step['command']));
                $commands[] = ['index' => $position, 'secret_field' => $field, 'timeout_seconds' => $step['timeout_seconds'], 'name' => $step['name']];
                $secrets[$field] = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'instance-destroy-'.$operation->id.'-teardown-'.$index, json_encode([
                    'command' => $step['command'], 'sha256' => $sha, 'directory' => $instance->checkout_path,
                    'instance_id' => $instance->id, 'instance_name' => $instance->name, 'seed_path' => '', 'seed_commit' => '',
                ], JSON_THROW_ON_ERROR));
            }
        }

        return ['intent' => [...$public, ...$native, 'source_capture' => $sourceCapture, 'teardown_commands' => $commands, 'execution_references_json' => json_encode(['git_read' => InstanceGitHubReadTokens::reference($operation)], JSON_THROW_ON_ERROR)],
            'execution_secrets' => [...$secrets, 'git_read' => InstanceGitHubReadTokens::reference($operation)]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->refresh();
            $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
            $result = ValidatedData::object($data['result'] ?? []);
            if (! $operation->status->terminal()) {
                if (isset($result['source_inspection'])) {
                    app(InstanceDestroySourceInspection::class)->capture($operation, ValidatedData::object($result['source_inspection']));
                }
                if ($data['status'] === 'succeeded') {
                    if (($result['removed'] ?? false) !== true) {
                        throw new ResourceOperationException('instance.result_invalid', 'Native removal did not prove its terminal result.');
                    }
                    app(ProjectDirectInstanceDestroyAction::class)->execute(app(InstanceDestroySourceInspection::class)->executable($operation->input), $result);
                } elseif (in_array($data['status'], ['failed', 'cancelled'], true)) {
                    InstanceRemoval::query()->whereKey($operation->input['removal_id'])->update(['status' => InstanceRemovalStatus::Failed,
                        'error_code' => $data['error_code'] ?? 'operation.cancelled', 'current_step' => $this->failedStep($result), 'failed_step' => $this->failedStep($result)]);
                }
            }

            return $this->direct->project($operation, $data, $result);
        });
    }

    /** @param array<string,mixed> $result */
    private function failedStep(array $result): InstanceRemovalStep
    {
        $step = $result['failed_step'] ?? '';
        if (! is_string($step)) {
            return InstanceRemovalStep::SourcePreparation;
        }

        return match (true) {
            str_starts_with($step, 'remove-source') => InstanceRemovalStep::SourceFinalization,
            str_starts_with($step, 'withdraw') => InstanceRemovalStep::RouteTargetClear,
            preg_match('/\A(?:destroy-(?:schedules|processes|runtime|teardown|sqlite|database)|runtime|databases)/', $step) === 1 => InstanceRemovalStep::RuntimeCleanup,
            default => InstanceRemovalStep::SourcePreparation,
        };
    }
}
