<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Models\UpCloudTaskVmImageBuild;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Immutable public image intent and bounded native progress; no UpCloud or guest calls. */
final readonly class TaskVmUpCloudImageProjection implements DirectOperationProjection
{
    public const array Phases = ['prepare', 'launch-builder', 'boot-builder', 'apt-source', 'packages', 'javascript', 'rust', 'agent', 'pi',
        'prepare-image', 'clean', 'stop-builder', 'templatize', 'delete-builder', 'launch-smoke', 'boot-smoke', 'smoke', 'delete-smoke', 'promote', 'prune', 'complete', 'cleanup'];

    public function input(Operation $operation): array
    {
        $build = $this->build($operation);
        $input = Arr::except($operation->input, ['caller_node_id', 'upcloud_token', 'bootstrap', 'pi_artifact_file']);
        if (is_array($input['pi_artifact'] ?? null)) {
            $input['pi_artifact'] = Arr::only($input['pi_artifact'], ['sha256']);
        }
        $input['progress'] = ['resources' => (object) $build->resources, 'template_uuid' => $build->template_uuid];
        $input['pinned_templates'] = TaskVm::query()->live()->where('provider', TaskVmProviderKind::UpCloud)
            ->whereNull('provider_id')->whereNotNull('image_fingerprint')->pluck('image_fingerprint')->all();
        $input['current'] = $this->current($operation, $build);

        return $input;
    }

    private function build(Operation $operation): UpCloudTaskVmImageBuild
    {
        $build = UpCloudTaskVmImageBuild::query()->findOrFail($operation->target_id);
        if ($operation->kind !== 'task_vm.upcloud_image_build' || $operation->target_type !== 'task_vm_upcloud_image'
            || $build->intent === null || ($build->intent['build_id'] ?? null) !== $build->id
            || ($build->intent['node_id'] ?? null) !== $operation->node_id
            || $build->recipe_identity !== ($build->intent['identity'] ?? null)
            || Arr::except($operation->input, ['caller_node_id']) !== $build->intent) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The admitted UpCloud attempt no longer owns this build.', 422);
        }

        return $build;
    }

    private function current(Operation $operation, UpCloudTaskVmImageBuild $build): bool
    {
        $desired = DB::table('task_vm_image_recipe')->where('id', 1)->firstOrFail();

        return $desired->identity === $build->recipe_identity && (int) $desired->generation === $operation->input['generation']
            && app(AgentDelivery::class)->pin(Node::query()->findOrFail($operation->node_id)) === $operation->input['agent_sha256'];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $build = $this->build($operation);
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            $result = ValidatedData::object($data['result'] ?? []);
            $code = ValidatedData::nullableString($data['error_code'] ?? null);
            $valid = match ($status) {
                OperationStatus::Running => $code === null && in_array($result['phase'] ?? null, self::Phases, true)
                    && array_diff(array_keys($result), ['phase', 'resources', 'template_uuid', 'promoted']) === []
                    && $this->validProgress($build, $result),
                OperationStatus::Succeeded => $code === null && count($result) === 2 && ($result['ready'] ?? null) === true
                    && is_string($result['template_uuid'] ?? null) && Str::isUuid($result['template_uuid'])
                    && $result['template_uuid'] === $build->template_uuid && ($build->resources['promoted'] ?? null) === true,
                OperationStatus::Failed => $code !== null && preg_match('/\A[a-z0-9_]+\.[a-z0-9_.-]{1,80}\z/D', $code) === 1 && $result === ['error_code' => $code],
                OperationStatus::Cancelled => $code === null && $result === [],
                default => false,
            };
            if (! $valid) {
                throw new ResourceOperationException('operation.result_invalid', 'The image result needs a bounded native phase or outcome.', 422);
            }
            if (in_array($status, [OperationStatus::Running, OperationStatus::Succeeded], true) && ($result['phase'] ?? null) !== 'cleanup') {
                if (! $this->current($operation, $build)) {
                    throw new ResourceOperationException('task_vm.image_build_superseded', 'The desired image recipe or serving delivery changed.', 409);
                }
            }
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result, 'upcloud-image-build');
            if (! $terminal && $status === OperationStatus::Running) {
                $build->forceFill(['resources' => [...$build->resources, ...ValidatedData::object($result['resources'] ?? []),
                    ...(($result['promoted'] ?? false) === true ? ['promoted' => true] : [])],
                    'template_uuid' => $result['template_uuid'] ?? $build->template_uuid])->save();
            }
            if (! $terminal && $status->terminal()) {
                app(TaskVmUpCloudImageCompletion::class)->complete($projected, match ($status) {
                    OperationStatus::Succeeded => OperationOutcome::succeeded($result),
                    OperationStatus::Failed => OperationOutcome::failed(ValidatedData::string($code), $result),
                    default => OperationOutcome::cancelled(),
                });
            }

            return $projected;
        });
    }

    /** @param array<string,mixed> $result */
    private function validProgress(UpCloudTaskVmImageBuild $build, array $result): bool
    {
        $resources = $result['resources'] ?? [];
        if (! is_array($resources) || array_diff(array_keys($resources), ['builder', 'smoke']) !== []) {
            return false;
        }
        foreach ($resources as $slot => $resource) {
            if (! is_array($resource) || array_keys($resource) !== ['uuid'] || ! is_string($resource['uuid']) || ! Str::isUuid($resource['uuid'])
                || (isset($build->resources[$slot]) && $build->resources[$slot] !== $resource)) {
                return false;
            }
        }
        if (isset($result['template_uuid']) && (! is_string($result['template_uuid']) || ! Str::isUuid($result['template_uuid'])
            || ($build->template_uuid !== null && $build->template_uuid !== $result['template_uuid']))) {
            return false;
        }

        return ! array_key_exists('promoted', $result) || ($result['promoted'] === true && $result['phase'] === 'promote' && $build->template_uuid !== null);
    }
}
