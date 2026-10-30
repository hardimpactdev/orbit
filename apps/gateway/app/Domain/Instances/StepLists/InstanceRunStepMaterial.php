<?php

declare(strict_types=1);

namespace App\Domain\Instances\StepLists;

use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;

/** Private request-memory material for the exact Node's admitted app-user step. No execution. */
final readonly class InstanceRunStepMaterial
{
    public function __construct(private ProjectLifecycleStepStore $steps, private InstanceEnvironmentStore $environment, private InstanceEnvironmentContextResolver $contexts, private InstanceEnvironmentRenderer $renderer) {}

    /** @return array<string, mixed> */
    public function read(Operation $operation, int $nodeId, string $key, string $digest): array
    {
        $operation->refresh();
        if ($operation->node_id !== $nodeId) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The material belongs to another Node.', 403);
        }
        if ($operation->target_type !== 'instance' || $operation->status !== OperationStatus::Running || ($operation->cancel_requested_at !== null)
            || ($operation->state['instance_writer'] ?? null) !== 'direct'
            || ! is_string($operation->state['swamp_run_id'] ?? null)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/Di', $operation->state['swamp_run_id']) !== 1) {
            throw $this->refused();
        }
        $instance = Instance::query()->with('project')->findOrFail($operation->target_id);
        $user = 'orbit-app-'.$instance->project_id;
        $home = '/home/'.$user;
        if ($instance->node_id !== $nodeId || $instance->production_user !== $user || $instance->production_home !== $home) {
            throw $this->refused();
        }
        $script = null;
        $timeout = null;
        $release = null;
        if ($operation->kind === 'instance.deploy') {
            $intent = InstanceDeploymentIntent::admitted($operation);
            if ($intent['variant'] !== 'production' || $intent['project_id'] !== $instance->project_id || $intent['user'] !== $user || $intent['home'] !== $home
                || $intent['repository'] !== $instance->project->repository_url) {
                throw $this->refused();
            }
            foreach ($intent['steps'] as $step) {
                if ($key === $step['phase'].'.'.$step['name'] && hash_equals($step['command_digest'], $digest)) {
                    $script = $instance->deploySteps()->where('name', $step['name'])->where('phase', $step['phase'])->first()?->command;
                    $timeout = $step['timeout_seconds'];
                }
            }
            $release = InstanceDeploymentIntent::releaseName($operation);
        } elseif ($operation->kind === 'instance.run_steps' && ($operation->input['list'] ?? null) === 'setup'
            && ($operation->input['instance_id'] ?? null) === $instance->id && ($operation->input['source'] ?? null) === 'project'
            && ($operation->input['project_id'] ?? null) === $instance->project_id
            && ($operation->input['repository'] ?? null) === $instance->project->repository_url) {
            $steps = $this->steps->ordered($instance->project, LifecyclePhase::Setup);
            if (($operation->input['configuration_digest'] ?? null) !== hash('sha256', json_encode($steps, JSON_THROW_ON_ERROR))) {
                throw $this->refused();
            }
            $digests = $operation->input['step_digests'] ?? null;
            if (! is_array($digests)) {
                throw $this->refused();
            }
            foreach ($steps as $step) {
                if ($key === 'setup.'.$step->name && ($digests[$step->name] ?? null) === $digest) {
                    $script = $step->command;
                    $timeout = $step->timeoutSeconds;
                }
            }
            $directory = $operation->input['release_directory'] ?? null;
            if (! is_string($directory) || $directory !== $instance->checkout_path || ! str_starts_with($directory, $home.'/releases/')) {
                throw $this->refused();
            }
            $release = substr($directory, strlen($home.'/releases/'));
        }
        if (! is_string($script) || ! hash_equals($digest, hash('sha256', $script))
            || ! is_int($timeout) || $timeout < 1 || $timeout > 300
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $release) !== 1) {
            throw $this->refused();
        }
        $context = $this->contexts->resolve($instance, requireActiveNode: true);
        $values = $this->environment->values($instance);
        $values = $this->renderer->resolvedValues($context, $values);

        return ['operation' => $operation->id, 'step' => $key, 'digest' => $digest, 'instance' => $instance->id, 'project' => $instance->project_id,
            'repository' => $instance->project->repository_url, 'relative_root' => $instance->root ?? $instance->project->root ?? 'public',
            'release' => $release, 'timeout_seconds' => $timeout, 'script' => $script, 'environment' => (object) $values];
    }

    private function refused(): ResourceOperationException
    {
        return new ResourceOperationException('instance.step_not_admitted', 'The app-user step is not admitted for this intent and deadline.', 409);
    }
}
