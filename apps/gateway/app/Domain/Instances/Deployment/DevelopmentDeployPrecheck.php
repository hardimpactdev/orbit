<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\OperationKinds;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Instance;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Throwable;

/** The minute trigger of container defaults: only a default behind its branch enters a Swamp policy run. */
final readonly class DevelopmentDeployPrecheck
{
    /** The policy's first backoff after a failure. */
    public const int FailedWaitMinutes = 10;

    public function __construct(private RemoteBranchHeads $heads, private StartSwampRun $runs) {}

    public function check(): void
    {
        $changed = [];
        $heads = [];
        $defaults = Instance::query()->where('name', 'default')->with(['project', 'node.roles', 'runtime'])->orderBy('id')->get();
        foreach ($defaults as $instance) {
            if (! $instance->placedOnAppDev() || AutomaticDeployPolicyWriter::Swamp->eligibilityRefusal($instance) !== null) {
                continue;
            }
            try {
                $head = $heads[$instance->project_id] ??= $this->heads->head($instance->project, (string) $instance->project->default_branch);
                if ($head !== DevelopmentSeed::commit($instance) && ! $this->held($instance, $head)) {
                    $changed[] = $instance->id;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        if ($changed !== []) {
            $this->runs->submit('automatic-development-deploy', ['instances' => $changed]);
        }
    }

    /**
     * A blocked head starts no run, and a failed one waits its first backoff, so a broken commit or a refused admission does not
     * start a run every minute. A newer head, or a manual deploy after the policy's result, lifts the hold at once.
     */
    private function held(Instance $instance, string $head): bool
    {
        $state = $instance->auto_deploy_state ?? [];
        $result = $state['result'] ?? null;
        if (($state['head'] ?? null) !== $head || ! in_array($result, [AutomaticDeployResult::CommitFailed->value, AutomaticDeployResult::Failed->value], true)) {
            return false;
        }
        $since = is_string($state['since'] ?? null) ? CarbonImmutable::parse($state['since']) : null;
        if ($since === null) {
            return $result === AutomaticDeployResult::CommitFailed->value;
        }
        if ($result === AutomaticDeployResult::Failed->value && $since->lt(now()->subMinutes(self::FailedWaitMinutes))) {
            return false;
        }

        return ! Operation::query()->where('kind', OperationKinds::InstanceDeploy)->where('target_type', 'instance')->where('target_id', $instance->id)
            ->where(static fn ($query) => $query->whereNull('triggered_by')->orWhereNotIn('triggered_by', AutomaticDeployHistory::Triggers))
            ->where('finished_at', '>', $since)->exists();
    }
}
