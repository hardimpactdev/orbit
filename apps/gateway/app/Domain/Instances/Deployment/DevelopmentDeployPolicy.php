<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Actions\Instances\ReadDeploymentSourceAction;
use App\Actions\Instances\StartInstanceDeployAction;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\RequiredCheckState;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Development decisions only. The ordinary instance.deploy workflow owns every runtime effect. */
final readonly class DevelopmentDeployPolicy
{
    public function __construct(private ReadDeploymentSourceAction $source, private DevelopmentDeployRetries $retries) {}

    public function check(Instance $instance, string $head): string
    {
        $instance = Instance::query()->with(['project', 'node.roles', 'runtime'])->findOrFail($instance->id);
        if (AutomaticDeployPolicyWriter::Gateway->developmentEligibilityRefusal($instance) !== null) {
            return 'paused';
        }
        $this->retries->observe($instance);
        $instance->refresh();
        $selection = self::selection($instance);
        if (Operation::query()->where('target_type', 'instance')->where('target_id', $instance->id)
            ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
            return $this->publish($instance, 'busy', $head);
        }
        if ($instance->development_projection_pending && $instance->authoritativeRoute() !== null) {
            return $this->publish($instance, 'busy', $head);
        }
        if ($head === DevelopmentSeed::commit($instance)) {
            return $this->publish($instance, 'up_to_date', $head);
        }
        $gate = $instance->project->mergeCheckName();
        if ($gate !== null) {
            $evidence = $this->source->checks($instance, $head);
            $runs = array_map(static fn ($run): GitHubCheckRun => new GitHubCheckRun($run->name, $run->conclusion, null,
                status: $run->status, headSha: $run->headSha, appSlug: $run->app), $evidence->runs);
            $check = RequiredCheckState::of($runs, $head, $gate);
            if ($check !== RequiredCheckState::Passed) {
                return $this->publish($instance, $check === RequiredCheckState::Failed ? 'gate_failed' : 'gate_pending', $head);
            }
        }
        $entry = $this->retries->read($instance, [$head])['commits'][0] ?? null;
        if (is_array($entry) && $entry['attempts'] > 0) {
            if ($entry['stopped']) {
                return $this->publish($instance, 'commit_failed', $head);
            }
            if (now()->isBefore(Carbon::parse($entry['last_failure_at'])->addMinutes(10 * 2 ** ($entry['attempts'] - 1)))) {
                return $this->publish($instance, 'backing_off', $head);
            }
        }
        try {
            app(StartInstanceDeployAction::class)->developmentAutomatic($instance, $head, $selection);
        } catch (ResourceOperationException $exception) {
            if (in_array($exception->errorCode, ['operation.target_busy', 'deployment.projection_pending', 'instance.auto_deploy_observation_changed', 'deployment.paused', AutomaticDeployPolicyWriter::InactiveRole, AutomaticDeployPolicyWriter::ContainerDefaultRequired], true)) {
                return $this->publish($instance, 'busy', $head);
            }
            throw $exception;
        }

        return $this->publish($instance, 'started', $head);
    }

    /** The source read may finish after an operator changes selection; admission compares this under its lock.
     * @return array<string, mixed>
     */
    public static function selection(Instance $instance): array
    {
        return ['instance' => $instance->only(['project_id', 'node_id', 'name', 'status', 'auto_deploy', 'source_layout', 'development_projection_pending']),
            'operator_version' => $instance->auto_deploy_state['operator_version'] ?? 0,
            'runtime' => $instance->runtime?->only(['id', 'seed_commit']),
            'project' => $instance->project->only(['repository_url', 'repository_identity', 'source_access', 'default_branch', 'root', 'type', 'merge_check'])];
    }

    /** @param array<string, mixed> $selection */
    public static function assertSelection(Instance $instance, array $selection): void
    {
        if (($refusal = AutomaticDeployPolicyWriter::Gateway->developmentEligibilityRefusal($instance)) !== null) {
            throw new ResourceOperationException($refusal, 'The development Instance is no longer eligible.', 409);
        }
        if (self::selection($instance) !== $selection) {
            throw new ResourceOperationException('instance.auto_deploy_observation_changed', 'The development selection changed before admission.', 409);
        }
    }

    /** Called before the ordinary workflow receives secrets, never after effects to reinterpret a terminal outcome. */
    public static function assertExecutable(Operation $operation): void
    {
        if (($operation->input['development_policy'] ?? false) !== true) {
            return;
        }
        $instance = Instance::query()->with(['node.roles', 'runtime'])->findOrFail($operation->target_id);
        $cursor = app(DevelopmentDeployRetries::class)->cursor($instance);
        if (AutomaticDeployPolicyWriter::Gateway->developmentEligibilityRefusal($instance) !== null || $operation->id <= $cursor['manual_after']) {
            throw new ResourceOperationException('deployment.paused', 'The automatic development admission is no longer eligible.', 409);
        }
    }

    private function publish(Instance $instance, string $result, string $head): string
    {
        DB::transaction(function () use ($instance, $result, $head): void {
            $locked = Instance::query()->lockForUpdate()->findOrFail($instance->id);
            $state = $locked->auto_deploy_state ?? [];
            $next = [...$state, 'result' => $result, 'commit' => $head, 'head' => $head, 'error_code' => null, 'message' => null,
                'since' => ($state['result'] ?? null) === $result && ($state['commit'] ?? null) === $head ? ($state['since'] ?? now()->toIso8601String()) : now()->toIso8601String()];
            if ($state !== $next) {
                $locked->update(['auto_deploy_state' => $next]);
            }
        });

        return $result;
    }
}
