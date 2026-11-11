<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Instances\ReadyContainerDefault;
use App\Domain\SourceControl\RemoteBranchHeads;
use Throwable;

/** One head read per eligible Project. Quiet or disabled defaults start no policy run. */
final readonly class DevelopmentDeployPrecheck
{
    public function __construct(private RemoteBranchHeads $heads, private DevelopmentDeployPolicy $policy) {}

    public function check(): void
    {
        $heads = [];
        foreach (ReadyContainerDefault::query()->with(['project', 'node.roles', 'runtime'])->where('auto_deploy', true)->orderBy('id')->get() as $instance) {
            if (AutomaticDeployPolicyWriter::Gateway->developmentEligibilityRefusal($instance) !== null) {
                continue;
            }
            try {
                // Terminal accounting is independent of whether this head is already the seed.
                app(DevelopmentDeployRetries::class)->observe($instance);
                $head = $heads[$instance->project_id] ??= $this->heads->head($instance->project, (string) $instance->project->default_branch);
                if ($head !== DevelopmentSeed::commit($instance)) {
                    $this->policy->check($instance, $head);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
