<?php

declare(strict_types=1);

namespace App\Domain\Instances\StepLists;

use App\Actions\Instances\StartInstanceStepListAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Safe admission and bounded result projection. Swamp owns the ordered app-user steps. */
final readonly class InstanceSetupProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private StartInstanceStepListAction $admission) {}

    public function input(Operation $operation): array
    {
        $this->assertOperation($operation);
        if ($operation->status->terminal()) {
            $projectId = $operation->input['project_id'] ?? null;
            $directory = $operation->input['release_directory'] ?? null;
            if (! is_int($projectId) || ! is_string($directory) || ! str_starts_with($directory, '/home/orbit-app-'.$projectId.'/releases/')) {
                throw $this->refused();
            }

            return ['instance_id' => $operation->target_id, 'project_id' => $projectId,
                'release' => substr($directory, strlen('/home/orbit-app-'.$projectId.'/releases/')), 'steps' => $operation->input['steps'] ?? []];
        }
        $instance = Instance::query()->with('project')->findOrFail($operation->target_id);
        $input = $operation->input;
        $home = '/home/orbit-app-'.$instance->project_id;
        $directory = $input['release_directory'] ?? null;
        if ($operation->node_id !== $instance->node_id || ($input['project_id'] ?? null) !== $instance->project_id
            || ($input['instance_id'] ?? null) !== $instance->id || ($input['source'] ?? null) !== 'project'
            || $instance->production_user !== 'orbit-app-'.$instance->project_id || $instance->production_home !== $home
            || ! is_string($directory) || $directory !== $instance->checkout_path
            || ! str_starts_with($directory, $home.'/releases/')
            || ($input['configuration_digest'] ?? null) !== $this->admission->configurationDigest($instance->project, LifecyclePhase::Setup)
            || ($input['steps'] ?? null) !== $this->admission->appStepAdmission($instance, LifecyclePhase::Setup)['steps']) {
            throw $this->refused();
        }
        $release = substr($directory, strlen($home.'/releases/'));
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $release) !== 1) {
            throw $this->refused();
        }

        return ['instance_id' => $instance->id, 'project_id' => $instance->project_id, 'release' => $release, 'steps' => $input['steps']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->assertOperation($operation);
        $status = $data['status'] ?? null;
        $result = ValidatedData::object($data['result'] ?? []);
        $code = $data['error_code'] ?? null;
        if (($status === 'succeeded' && ($result !== ['instance_id' => $operation->target_id, 'outcome' => 'succeeded', 'no_op' => false]
                && $result !== ['instance_id' => $operation->target_id, 'outcome' => 'succeeded', 'no_op' => true]))
            || ($status === 'failed' && (! is_string($code) || preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $code) !== 1
                || array_diff(array_keys($result), ['error_code', 'failed_step']) !== [] || ($result['error_code'] ?? null) !== $code
                || ! is_string($result['failed_step'] ?? null)
                || ! is_array($operation->input['steps'] ?? null)
                || ! in_array($result['failed_step'], array_column($operation->input['steps'], 'name'), true)))
            || (in_array($status, ['running', 'cancelled'], true) && $result !== []) || ($status !== 'failed' && $code !== null)) {
            throw new ResourceOperationException('operation.result_invalid', 'The setup result does not match the admitted native outcome.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $result, $code): Operation {
            $terminal = $operation->refresh()->status->terminal();
            $projected = $this->direct->project($operation, $data, $result, 'setup');
            if (! $terminal) {
                app(InstanceSetupState::class)->project($projected, $projected->status, $code);
            }

            return $projected;
        });
    }

    private function assertOperation(Operation $operation): void
    {
        if ($operation->kind !== 'instance.run_steps' || ! DirectOperation::owns($operation)
            || $operation->target_type !== 'instance' || $operation->node_id === null
            || ($operation->input['list'] ?? null) !== 'setup' || $operation->getAttribute('parent_operation_id') !== null) {
            throw $this->refused();
        }
    }

    private function refused(): ResourceOperationException
    {
        return new ResourceOperationException('instance.step_not_admitted', 'The app-user setup does not match its admitted target and steps.', 409);
    }
}
