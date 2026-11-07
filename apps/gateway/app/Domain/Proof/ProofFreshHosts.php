<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\GitHub\GitHubApiException;
use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmFleetSeed;

/**
 * The task VM hosts a proof may use: those whose ready copies were built from a release at or after the merge base of the commit under
 * proof with main. A host with no recorded release commit, or with a commit that GitHub does not know, is not one of them. GitHub is read
 * once for each distinct commit, before the admission transaction. See docs/reference/pipeline/2-development-environment.mmd.
 */
final readonly class ProofFreshHosts
{
    public function __construct(private TaskVmCapacity $capacity, private TaskVmFleetSeed $seeds, private ProofCommitSource $source) {}

    /**
     * @return list<int> Node ids
     *
     * @throws GitHubApiException when GitHub cannot answer
     */
    public function hosts(string $mergeBase): array
    {
        $fresh = [];
        $answers = [];
        foreach ($this->capacity->activeHosts() as [$host]) {
            $commit = $this->seeds->commit($host->nodeId);
            if ($commit === null) {
                continue;
            }
            $answers[$commit] ??= $this->source->atOrAfter($mergeBase, $commit);
            if ($answers[$commit]) {
                $fresh[] = $host->nodeId;
            }
        }

        return $fresh;
    }
}
