<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Models\Instance;
use App\Models\InstanceDeployment;

/**
 * The releases a production home retains, from the Instance's deployment history. Clone selects `initial`; a deploy
 * adds the release it prepared, and a successful one keeps the new selection, the selection before it and the newest
 * other release; a rollback only moves the selection (docs/reference/deployments.md#retained-releases). Admission reads
 * these records instead of the Node, and the Instance lock keeps a deploy's cleanup away while a rollback runs.
 */
final readonly class RecordedReleases
{
    /** The Node's retention limit for a successful deployment. */
    private const int Retained = 3;

    /** @return array<string, string|null> each retained release name with its commit, when known */
    public static function of(Instance $instance): array
    {
        $retained = ['initial' => null];
        $selected = 'initial';
        $rows = InstanceDeployment::query()->where('instance_id', $instance->id)->orderBy('id')
            ->get(['id', 'kind', 'status', 'release', 'selected_release', 'commit']);
        foreach ($rows as $row) {
            if ($row->kind === InstanceDeployment::Rollback) {
                $selected = is_string($row->selected_release) && isset($retained[$row->selected_release]) ? $row->selected_release : $selected;

                continue;
            }
            if (! is_string($row->release)) {
                continue;
            }
            $retained[$row->release] = $row->commit;
            if ($row->status !== 'succeeded') {
                // A failed deployment removes nothing; it moved `current` only when it activated its release.
                $selected = $row->selected_release === $row->release ? $row->release : $selected;

                continue;
            }
            $previous = $selected;
            $selected = $row->release;
            $others = array_values(array_diff(array_keys($retained), [$selected, $previous]));
            // `initial` counts as the oldest; later names sort by the creation time they start with.
            usort($others, static fn (string $a, string $b): int => [$a !== 'initial', $a] <=> [$b !== 'initial', $b]);
            $keep = [$selected, $previous, ...array_slice($others, -max(0, self::Retained - count(array_unique([$selected, $previous]))))];
            $retained = array_intersect_key($retained, array_flip($keep));
        }

        return $retained;
    }
}
