<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\InstancePhpVersionCatalog;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Logs\LogRedactor;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\TaskVms\TaskVmProvisionWorkflowInputs;
use App\Domain\TaskVms\TaskVmWorkspaceGuard;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskVm;

/** Compose inputs inside the caller's existing admission fence and transaction. Admit no work. */
final readonly class TaskProvisionWorkflowInputs
{
    public function __construct(private TaskVmProvisionWorkflowInputs $vms, private ProjectLifecycleStepStore $steps,
        private InstancePhpVersionCatalog $php, private LogRedactor $redactor) {}

    /** The allocation is the result of the one canonical VM capture, never a second placement.
     * @param  array<string,mixed>  $allocation
     * @return array{intent:array<string,mixed>,execution_secrets:array<string,mixed>}
     */
    public function capture(Task $group, Instance $workspace, TaskVm $vm, array $allocation): array
    {
        $project = $group->project;
        $node = $vm->node;
        $checkout = StoragePath::tryParse($workspace->checkout_path);
        if ($group->parent_id !== null || $vm->group_id !== $group->id || $node === null
            || $workspace->task_workspace_owner_id !== $group->id || $workspace->project_id !== $group->project_id
            || $workspace->node_id !== $node->id || $workspace->source_layout !== InstanceSourceLayout::Checkout->value
            || $workspace->name !== TaskWorkspaceName::for($group) || $workspace->branch_override !== TaskWorkspaceName::for($group)
            || $checkout === null || basename($checkout->value) !== TaskVmWorkspaceGuard::WorkspaceCheckout
            || ! is_string($workspace->source_prepare_id) || $workspace->source_prepare_id === ''
            || ! GitRepositoryOrigin::isValid($project->repository_url) || ! is_string($project->default_branch) || ! GitBranchName::isValid($project->default_branch)
            || ($allocation['task_vm_id'] ?? null) !== $vm->id || ($allocation['guest_node_id'] ?? null) !== $node->id
            || in_array($allocation['node_id'] ?? null, [1, 18], true) || in_array($node->id, [1, 18], true)) {
            throw $this->refused();
        }
        if ($project->task_workspace_routed || $project->processDefinitions()->exists() || $project->scheduleDefinitions()->exists()) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Task workspace publication and runtime definitions need their native parent steps.', 503);
        }
        $values = [...$this->redactor->valuesFor($workspace), ...array_filter([$vm->pi_token, $vm->model_key], is_string(...))];
        $steps = array_map(function (LifecycleStep $step) use ($values): array {
            if ($this->redactor->redact($step->command, $values) !== $step->command) {
                throw new ResourceOperationException('tasks.provision_refused', 'Setup commands must contain no secret values; use execution vault references.', 409);
            }

            return [...$step->toArray(), 'command_digest' => hash('sha256', $step->command)];
        }, $this->steps->ordered($project, LifecyclePhase::Setup));
        $public = ['group_id' => $group->id, 'instance_id' => $workspace->id, 'project_id' => $project->id,
            'repository' => $project->repository_url, 'repository_identity' => GitRepositoryIdentity::derive($project->repository_url),
            'workspace' => ['instance_id' => $workspace->id, 'project_id' => $project->id,
                'node_id' => $node->id, 'user' => $node->user, 'environment' => 'development',
                'checkout' => $checkout->value, 'application' => $checkout->value,
                'project_type' => $project->type->value, 'entry_point' => $project->type->frameworkEntryPoint(),
                'skip_profile' => $project->type === ProjectType::Monorepo, 'php_versions' => $this->php->versions(),
                'source' => ['repository' => $project->repository_url, 'checkout' => $checkout->value,
                    'root' => dirname($checkout->value), 'user' => $node->user,
                    'prepare_id' => $workspace->source_prepare_id, 'seed_repository' => null],
                'resolve' => ['instance_name' => $workspace->name, 'default_branch' => $project->default_branch,
                    'branch_override' => $workspace->branch_override, 'seed_commit' => null, 'network' => true,
                    'protocols' => config('orbit.git.checkout_protocols', ['https'])],
                'setup' => ['instance_id' => $workspace->id, 'project_id' => $project->id,
                    'environment' => 'development', 'list' => 'setup',
                    'execution' => ['variant' => 'checkout', 'user' => $node->user, 'directory' => $checkout->value, 'seed_path' => '', 'seed_commit' => ''],
                    'steps' => $steps]]];
        $envelope = $this->vms->capture($vm, $allocation);

        return ['intent' => [...$envelope['intent'], ...$public], 'execution_secrets' => $envelope['execution_secrets']];
    }

    private function refused(): ResourceOperationException
    {
        return new ResourceOperationException('tasks.provision_refused', 'Native provision inputs require the same admitted group, VM and workspace.', 409);
    }
}
