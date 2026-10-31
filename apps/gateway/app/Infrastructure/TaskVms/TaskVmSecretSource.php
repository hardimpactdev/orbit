<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskParkIntent;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Models\Operation;
use App\Models\Project;
use App\Models\TaskVm;
use App\Models\UpCloudTaskVmImageBuild;

/** Tagged family sources. Ownership checks never read credentials. */
final readonly class TaskVmSecretSource implements OperationSecretSource
{
    public function __construct(private string $source) {}

    public function name(): string
    {
        return $this->source;
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return match ($this->source) {
            'upcloud.api' => ['task_vm', 'task_vm_upcloud_image', 'task_vm_sweep', 'task_group'],
            'task_vm.package_cache_github' => ['task_vm_cache'],
            'task_vm.image_bootstrap' => ['task_vm_upcloud_image'],
            default => ['task_vm'],
        };
    }

    public function ownerId(Operation $operation): ?int
    {
        if (! in_array($operation->target_type, $this->targetTypes(), true)) {
            return null;
        }
        $id = $operation->target_id;
        if ($operation->target_type === 'task_group') {
            if ($this->source !== 'upcloud.api' || $operation->kind !== 'tasks.park') {
                return null;
            }
            $vm = app(TaskParkIntent::class)->owned($operation);

            return $vm->provider === TaskVmProviderKind::UpCloud ? $vm->id : null;
        }
        if ($operation->target_type === 'task_vm') {
            $vm = TaskVm::query()->find($id);
            if ($vm === null || ($this->source === 'upcloud.api' && $vm->provider !== TaskVmProviderKind::UpCloud)) {
                return null;
            }
        } elseif ($operation->target_type === 'task_vm_upcloud_image') {
            $build = UpCloudTaskVmImageBuild::query()->find($id);
            if ($build?->intent === null || $build->intent['node_id'] !== $operation->node_id) {
                return null;
            }
        } elseif ($operation->target_type === 'task_vm_sweep') {
            if ($operation->kind !== 'task_vm.sweep_host' || ($operation->input['provider'] ?? null) !== 'upcloud'
                || ($operation->input['node_id'] ?? null) !== $id || $operation->node_id !== $id) {
                return null;
            }
        } elseif ($operation->target_type === 'task_vm_cache' && ($operation->kind !== 'task_vm.fill_package_cache' || $operation->node_id !== $id || ($operation->input['host_node_id'] ?? null) !== $id)) {
            return null;
        }

        return $id;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The secret reference is not owned by this target.', 422);
        }
        if ($this->source === 'upcloud.api') {
            return new OperationSecretValue(app(UpCloudApi::class)->token());
        }
        if ($this->source === 'task_vm.image_bootstrap') {
            return new OperationSecretValue(UpCloudTaskVmImageBuild::query()->findOrFail($id)->bootstrap);
        }
        if ($this->source === 'task_vm.package_cache_github') {
            if (array_any(TaskVmInputs::artifacts($operation->input['artifacts']), static fn (array $artifact): bool => $artifact['manager'] === 'composer') && app(GitHubAppStore::class)->credentials() !== null) {
                if ($operation->kind !== 'task_vm.fill_package_cache' || $operation->node_id !== $id || $operation->target_id !== $id) {
                    throw new ResourceOperationException('operation.execution_forbidden', 'Cache credentials require the owning fill parent.', 422);
                }
                $projects = array_column(TaskVmInputs::snapshots($operation->input['snapshots']), 'project_id');
                foreach (Project::query()->whereIn('id', $projects)->orderBy('id')->get() as $project) {
                    $repository = GitHubRepository::fromOrigin($project->repository_url);
                    if ($repository instanceof GitHubRepository) {
                        try {
                            return new OperationSecretValue(app(RepositoryPullRequestAccess::class)->metadataToken($repository));
                        } catch (GitHubApiException) {
                            continue;
                        }
                    }
                }
            }

            return new OperationSecretValue(null);
        }
        $vm = TaskVm::query()->findOrFail($id);
        $value = $this->source === 'task_vm.model_key' ? $vm->model_key : $vm->pi_token;
        if (! is_string($value) || $value === '') {
            throw new ResourceOperationException('operation.secret_unavailable', 'The task VM credential is unavailable.', 409);
        }

        return new OperationSecretValue($value);
    }
}
