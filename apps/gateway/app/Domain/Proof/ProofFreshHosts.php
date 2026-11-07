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
 * transaction. For every other host the answer says why, so that the refusal can name it. See docs/reference/pipeline/2-development-environment.mmd.
 */
final readonly class ProofFreshHosts
{
    public function __construct(private TaskVmCapacity $capacity, private TaskVmFleetSeed $seeds, private ProofCommitSource $source, private TaskVmImageInputs $inputs) {}

    /**
     * @throws GitHubApiException when GitHub cannot answer
     */
    public function assess(string $mergeBase): ProofHostFreshness
    {
        $fresh = [];
        $reasons = [];
        $answers = [];
        foreach ($this->capacity->activeHosts() as [$host]) {
            $commit = $this->seeds->commit($host->nodeId);
            if ($commit === null) {
                $reasons[$host->nodeId] = 'its ready copies record no release';

                continue;
            }
            if (! array_key_exists($commit, $answers)) {
                $answers[$commit] = $this->staleness($commit, $mergeBase);
            }
            if ($answers[$commit] === null) {
                $fresh[] = $host->nodeId;
            } else {
                $reasons[$host->nodeId] = $answers[$commit];
            }
        }

        return new ProofHostFreshness($fresh, $reasons);
    }

    /** Why copies built from the release `$copies` miss something up to `$mergeBase`, or null when they include every change of the image inputs. */
    private function staleness(string $copies, string $mergeBase): ?string
    {
        $release = substr($copies, 0, 12);
        $range = $this->source->range($copies, $mergeBase);
        if ($range === null) {
            return "its ready copies come from release {$release}, which GitHub does not know";
        }

        return match ($range->status) {
            // The copies are the merge base or come after it.
            GitHubComparisonStatus::Identical, GitHubComparisonStatus::Behind => null,
            // Main moved on from the copies to the merge base: only a change of what the image carries makes them stale. A cut list proves nothing.
            GitHubComparisonStatus::Ahead => $this->aheadReason($release, $range->changes),
            GitHubComparisonStatus::Diverged => "its ready copies come from release {$release}, which is not on the history of the merge base",
        };
    }

    private function aheadReason(string $release, ProofChangedFiles $changes): ?string
    {
        if (! $changes->complete) {
            return "its ready copies come from release {$release}, and too many files changed since to check them";
        }
        $carried = array_values(array_filter($changes->paths(), $this->inputs->carries(...)));

        return $carried === [] ? null : "its ready copies come from release {$release} and lack a change of {$carried[0]}".(count($carried) > 1 ? ' and '.(count($carried) - 1).' more' : '');
    }
}
