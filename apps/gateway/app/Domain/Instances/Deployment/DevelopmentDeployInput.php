<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubTokenGrant;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectDevelopmentDeployStepStore;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerIntent;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Captured development placement and names-only commands; no execution or root credentials. */
final readonly class DevelopmentDeployInput
{
    public function __construct(private ProjectDevelopmentDeployStepStore $steps, private SwampCommandStep $copies) {}

    /** @return array<string,mixed> */
    public function capture(Instance $instance, bool $needsVp): array
    {
        app(InstanceDeploymentIntent::class)->assertDevelopment($instance);
        if (! $instance->node->roles()->where('role', RoleName::AppDev)->where('status', 'active')->exists()) {
            throw $this->forbidden();
        }
        if ($instance->project->source_access !== ProjectSourceAccess::GitHubApp || $instance->node->platform !== 'linux') {
            throw new ResourceOperationException('deployment.source_access_unsupported', 'Native development deployment requires Linux and GitHub App or public source access.', 409);
        }

        return ['ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node),
            'manager' => $needsVp ? ['node_id' => $instance->node_id, 'user' => $instance->node->user, 'manager' => 'vp',
                'platform' => 'linux', 'architecture' => $instance->node->architecture,
                'setup' => app(ToolManagerIntent::class)->nativeInput($instance->node, ToolManagerName::Vp)] : null,
            'github_repository' => ($repository = GitHubRepository::fromOrigin($instance->project->repository_url)) === null ? null : $repository->owner.'/'.$repository->name,
            'github_mint' => $repository !== null];
    }

    /** @return array{intent:array<string,mixed>,execution_secrets:array<string,array{vault:string,key:string}>} */
    public function workflowInput(Operation $operation): array
    {
        $input = app(InstanceDeploymentDirectInput::class)->admitted($operation);
        $runtime = ValidatedData::object($operation->input['development_runtime']);
        $instance = $this->owner($operation);
        $commands = $this->steps->ordered($instance->project);
        $captured = ValidatedData::object($input['deployment'])['steps'];
        if (! is_array($captured) || count($commands) !== count($captured)) {
            throw $this->forbidden();
        }
        $copies = [];
        foreach ($commands as $index => $command) {
            $entry = ValidatedData::object($captured[$index]);
            if ($entry !== ['name' => $command->name, 'phase' => 'before_activation', 'required' => $command->required,
                'timeout_seconds' => $command->timeoutSeconds, 'command_digest' => hash('sha256', $command->command)]) {
                throw $this->forbidden();
            }
        }
        foreach ($commands as $index => $command) {
            $copies['step_'.$index] = $this->copies->executionCopy('orbit-control-inputs', 'development-deploy-operation-'.$operation->id.'-step-'.$index, $command->command);
        }

        return ['intent' => [...$input, ...$runtime], 'execution_secrets' => $copies];
    }

    public function githubTokenGrant(Operation $operation): GitHubTokenGrant
    {
        $instance = $this->owner($operation);
        $repository = GitHubRepository::fromOrigin($instance->project->repository_url);
        if ($repository === null || (ValidatedData::object($operation->input['development_runtime'])['github_mint'] ?? false) !== true) {
            throw $this->forbidden();
        }

        return GitHubTokenGrant::from(['repository' => $repository->owner.'/'.$repository->name, 'permissions' => ['contents' => 'read']]);
    }

    private function owner(Operation $operation): Instance
    {
        $instance = Instance::query()->with(['node', 'project'])->findOrFail($operation->target_id);
        $intent = ValidatedData::object($operation->input['deployment']);
        if ($operation->kind !== 'instance.deploy' || $operation->target_type !== 'instance' || $intent['variant'] !== 'development'
            || $operation->node_id !== $instance->node_id || ! $instance->placedOnAppDev()
            || ! $instance->node->roles()->where('role', RoleName::AppDev)->where('status', 'active')->exists()
            || $intent['home'] !== $instance->checkout_path || $intent['project_id'] !== $instance->project_id
            || $intent['repository'] !== $instance->project->repository_url || $instance->project->source_access !== ProjectSourceAccess::GitHubApp
            || $intent['node_user'] !== $instance->node->user) {
            throw $this->forbidden();
        }

        return $instance;
    }

    private function forbidden(): ResourceOperationException
    {
        return new ResourceOperationException('operation.execution_forbidden', 'The development deployment no longer matches its admitted owner.', 403);
    }
}
