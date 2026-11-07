<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubComparisonStatus;
use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmFleetSeed;
use App\Domain\TaskVms\TaskVmImageInputs;

/**
 * The task VM hosts a proof may use: those whose ready copies include every change of the files the image carries (playbooks, pins, lock
 * files: {@see TaskVmImageInputs}, the rule that starts a refresh) up to the merge base of the commit under proof with main. A release
 * that changed other files, such as Gateway PHP or documents, makes no copies stale. A host with no recorded release commit, or with
 * a commit that GitHub does not know, is not one of them. GitHub is read once for each distinct commit, before the admission
 * transaction. See docs/reference/pipeline/2-development-environment.mmd.
 */
final readonly class ProofFreshHosts
{
    public function __construct(private TaskVmCapacity $capacity, private TaskVmFleetSeed $seeds, private ProofCommitSource $source, private TaskVmImageInputs $inputs) {}

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
            $answers[$commit] ??= $this->includes($commit, $mergeBase);
            if ($answers[$commit]) {
                $fresh[] = $host->nodeId;
            }
        }

        return $fresh;
    }

    /** Whether copies built from the release `$copies` include every change of the image inputs up to `$mergeBase`. */
    private function includes(string $copies, string $mergeBase): bool
    {
        $range = $this->source->range($copies, $mergeBase);
        if ($range === null) {
            return false;
        }

        return match ($range->status) {
            // The copies are the merge base or come after it.
            GitHubComparisonStatus::Identical, GitHubComparisonStatus::Behind => true,
            // Main moved on from the copies to the merge base: only a change of what the image carries makes them stale. A cut list proves nothing.
            GitHubComparisonStatus::Ahead => $range->changes->complete && ! array_any($range->changes->paths(), $this->inputs->carries(...)),
            GitHubComparisonStatus::Diverged => false,
        };
    }
}
