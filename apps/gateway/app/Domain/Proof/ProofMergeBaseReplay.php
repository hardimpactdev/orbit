<?php

declare(strict_types=1);

namespace App\Domain\Proof;

/**
 * The fields of a `proof-fleet-verify` intent that turn on the merge-base replay of new cases (`fails_on_base`): the `can-fail` and
 * `can-fail-batches` jobs and the step `Restore the head checkout` run only when the intent holds `head` and `merge_base`. They switch
 * the checkout at `checkout_path` to the merge base, keep the new cases file of the head, and replay each flagged case there.
 *
 * Only the removed task VM proof run sent these fields. A run in a copy sends none yet (ProofCopies::input), so no copy proof replays.
 * The port of the replay to copies adds these fields to each copy verification, with the copy transport; until then this is the one
 * place that names them, and the Swamp chain stays as it is.
 */
final class ProofMergeBaseReplay
{
    /**
     * @return array{checkout_path:string,head:string,merge_base:string}
     */
    public static function fields(string $checkoutPath, string $head, string $mergeBase): array
    {
        return ['checkout_path' => $checkoutPath, 'head' => $head, 'merge_base' => $mergeBase];
    }
}
