<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Instances\ReadyContainerDefault;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Infrastructure\Swamp\StartSwampRun;
use Throwable;

/** The minute trigger filters unchanged defaults before paying for a Swamp policy run. */
final readonly class DevelopmentDeployPrecheck
{
    public function __construct(private RemoteBranchHeads $heads, private StartSwampRun $runs) {}

    public function check(): void
    {
        $changed = [];
        $heads = [];
        $defaults = ReadyContainerDefault::query()->with(['project', 'node.roles', 'runtime'])->orderBy('id')->get();
        foreach ($defaults as $instance) {
            if (! $instance->placedOnAppDev() || AutomaticDeployPolicyWriter::Swamp->eligibilityRefusal($instance) !== null) {
                continue;
            }
            try {
                $head = $heads[$instance->project_id] ??= $this->heads->head($instance->project, (string) $instance->project->default_branch);
                if ($head !== DevelopmentSeed::commit($instance)) {
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
}
