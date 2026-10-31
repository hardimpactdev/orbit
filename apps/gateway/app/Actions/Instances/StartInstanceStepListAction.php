<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\InstanceState;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Project;

/** Admits one immutable Project setup or teardown list. Parents own rollback policy. */
final readonly class StartInstanceStepListAction
{
    public function __construct(private ProjectLifecycleStepStore $steps, private StartOperationAction $operations) {}

    public function configurationDigest(Project $project, LifecyclePhase $phase): string
    {
        return hash('sha256', json_encode($this->steps->ordered($project, $phase), JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> Safe admission only; programs remain in the Project read API. */
    public function appStepAdmission(Instance $instance, LifecyclePhase $phase): array
    {
        $directory = $instance->checkout_path;
        if (! $instance->placedOnAppDev() || preg_match('/\A[a-z_][a-z0-9_-]{0,63}\z/D', $instance->node->user) !== 1 || $instance->node->user === 'root'
            || preg_match('/\A\/(?:[A-Za-z0-9_.-]+\/)*[A-Za-z0-9_.-]+\z/D', $directory) !== 1
            || array_intersect(explode('/', $directory), ['.', '..']) !== []) {
            throw new ResourceOperationException('instance.step_not_admitted', 'The step execution account and checkout must be admitted non-root paths.', 409);
        }

        return ['project_id' => $instance->project_id, 'environment' => 'development', 'execution' => [
            'variant' => 'checkout', 'user' => $instance->node->user, 'directory' => $instance->checkout_path,
            'seed_path' => $phase === LifecyclePhase::Setup && $instance->seed_path !== $instance->checkout_path ? ($instance->seed_path ?? '') : '',
            'seed_commit' => $phase === LifecyclePhase::Setup && $instance->seed_path !== $instance->checkout_path ? ($instance->seed_commit ?? '') : '',
        ],
            'steps' => array_map(static fn (LifecycleStep $step): array => [
                'name' => $step->name, 'command_digest' => hash('sha256', $step->command), 'timeout_seconds' => $step->timeoutSeconds,
            ], $this->steps->ordered($instance->project, $phase))];
    }

    public function execute(Instance $instance, LifecyclePhase $phase, string $triggeredBy, ?string $requestId): Operation
    {
        $instance->refresh()->loadMissing('project');
        if ($instance->placedOnAppProd() || $instance->status !== InstanceState::Active) {
            throw new ResourceOperationException('instance.setup_unavailable', 'Setup requires an active development Instance.', 409);
        }

        return $this->operations->execute('instance.run_steps', 'instance', $instance->id, $instance->node_id, [
            'instance_id' => $instance->id,
            'source' => 'project', 'list' => $phase->value,
            'configuration_digest' => $this->configurationDigest($instance->project, $phase),
            ...$this->appStepAdmission($instance, $phase),
        ], $triggeredBy, $requestId);
    }
}
