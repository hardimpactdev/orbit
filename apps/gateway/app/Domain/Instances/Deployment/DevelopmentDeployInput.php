<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Instances\ContainerProcesses;
use App\Domain\Instances\InstanceGitHubReadTokens;
use App\Domain\Nodes\RoleName;
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
    public function __construct(private DevelopmentDeploySteps $steps) {}

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
            'vp_setup' => $needsVp ? app(ToolManagerIntent::class)->nativeInput($instance->node, ToolManagerName::Vp) : null];
    }

    /** @return array{intent:array<string,mixed>,execution_secrets:array<string,array{vault:string,key:string}>} */
    public function workflowInput(Operation $operation): array
    {
        $input = app(InstanceDeploymentDirectInput::class)->admitted($operation);
        $runtime = ValidatedData::object($operation->input['development_runtime']);
        $instance = $this->owner($operation);
        if ($operation->callerNodeId() !== null || in_array($operation->triggered_by, AutomaticDeployHistory::Triggers, true)) {
            app(AutomaticDeployPolicyWriter::class)->assertSwamp($instance, development: true);
        }
        $intent = ValidatedData::object($operation->input['deployment']);
        $copies = $this->steps->copies($operation, $instance->project, ValidatedData::object($input['deployment'])['steps']);
        // The fetch reads a 0600 credential file: a repository-scoped read token, or an empty line for a public origin.
        $copies['credential'] = app(InstanceGitHubReadTokens::class)->credentialCopy($instance->project->repository_url,
            'development-deploy-operation-'.$operation->id.'-credential');

        // Commands run with the account's own home, as Orbit creates managed accounts.
        $user = $instance->node->user;
        $container = $intent['variant'] === 'container'
            ? [...ValidatedData::object($intent['container']), 'commit' => InstanceDeploymentIntent::commit($operation)] : null;
        if ($container !== null) {
            // The Processes' environment files, made from the records only while they still render the admitted units.
            $copies['process_environments'] = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs',
                'development-deploy-operation-'.$operation->id.'-processes', app(ContainerProcesses::class)->environments($instance, $container['processes'] ?? null));
        }

        return ['intent' => [...$input, ...$runtime, 'container' => $container, 'application' => ValidatedData::object($input['deployment'])['home'],
            'account_home' => $user === 'root' ? '/root' : '/home/'.$user],
            'execution_secrets' => $copies];
    }

    private function owner(Operation $operation): Instance
    {
        $instance = Instance::query()->with(['node', 'project'])->findOrFail($operation->target_id);
        $intent = ValidatedData::object($operation->input['deployment']);
        if ($operation->kind !== 'instance.deploy' || $operation->target_type !== 'instance'
            || $intent['variant'] !== ($instance->runtime()->exists() ? 'container' : 'development')
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
