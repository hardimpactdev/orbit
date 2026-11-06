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
        return self::walk($instance)[0];
    }

    /** The retained releases sorted by name, and the selected one. */
    public static function state(Instance $instance): DeploymentReleaseState
    {
        [$retained, $selected] = self::walk($instance);
        $names = array_map(strval(...), array_keys($retained));
        sort($names);

        return new DeploymentReleaseState($names, $selected);
    }

    /**
     * What a deploy of `$release` changes when it succeeds: the selection it replaces, and the releases its cleanup
     * removes because the home no longer keeps them.
     *
     * @return array{previous:string, remove:list<string>}
     */
    public static function afterDeploy(Instance $instance, string $release): array
    {
        [$retained, $selected] = self::walk($instance);
        $kept = self::succeed([...$retained, $release => null], $selected, $release);

        return ['previous' => $selected, 'remove' => array_values(array_diff(array_keys($retained), array_keys($kept)))];
    }

    /** @return array{0: array<string, string|null>, 1: string} the retained releases and the selected one */
    private static function walk(Instance $instance): array
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
            $retained = self::succeed($retained, $selected, $row->release);
            $selected = $row->release;
        }

        return [$retained, $selected];
    }

    /**
     * @param  array<string, string|null>  $retained
     * @return array<string, string|null> the releases a successful deploy of `$release` keeps
     */
    private static function succeed(array $retained, string $previous, string $release): array
    {
        $others = array_values(array_diff(array_keys($retained), [$release, $previous]));
        // `initial` counts as the oldest; later names sort by the creation time they start with.
        usort($others, static fn (string $a, string $b): int => [$a !== 'initial', $a] <=> [$b !== 'initial', $b]);
        $keep = [$release, $previous, ...array_slice($others, -max(0, self::Retained - count(array_unique([$release, $previous]))))];

        return array_intersect_key($retained, array_flip($keep));
    }
}
