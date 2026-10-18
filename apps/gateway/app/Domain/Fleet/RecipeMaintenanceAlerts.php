<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Releases\ReleaseAlert;
use App\Domain\Releases\ReleaseAlertKind;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertSubject;
use App\Domain\Releases\ReleaseIncident;
use App\Models\TaskVmImageBuild;
use App\Models\UpCloudTaskVmImageBuild;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final readonly class RecipeMaintenanceAlerts
{
    public function __construct(private ReleaseAlertNotifier $notifier, private FleetServingRelease $serving) {}

    /** @return array<string, mixed> */
    public function halted(string $summary, ?int $buildId = null, string $target = 'machine-recipe', ?ReleaseIncident $incident = null): array
    {
        Log::error($summary, ['recipe_build_id' => $buildId]);
        $commit = $this->serving->read()['commit'];
        if ($commit === null) {
            return ['outcome' => 'skipped', 'reason' => 'no_deployed_release'];
        }

        return $this->notifier->alert(new ReleaseAlert(
            ReleaseAlertKind::RolloutHalted,
            new ReleaseAlertSubject($target, FleetRolloutAlerts::repository(), $commit, $buildId === null ? null : 'recipe-build-'.$buildId),
            $summary,
            $incident ?? $this->buildIncident($target, $buildId),
        ))->toArray();
    }

    public static function repositoryIncident(string $fault): ReleaseIncident
    {
        return new ReleaseIncident('recipe-repository:'.str_replace('/', ':', FleetRolloutAlerts::repository()), 'nightly:'.now()->utc()->format('Ymd'), $fault);
    }

    public static function mergeIncident(string $key): ReleaseIncident
    {
        if ($key === 'list') {
            return self::repositoryIncident('github_list_failed');
        }

        if (preg_match('/\A([0-9]+):([0-9a-f]{40})\z/', $key, $parts) !== 1) {
            throw new InvalidArgumentException('A recipe merge alert needs its PR and head.');
        }

        return self::pullIncident((int) $parts[1], $parts[2], 'merge_blocked');
    }

    public static function pullIncident(int $number, string $head, string $fault): ReleaseIncident
    {
        return new ReleaseIncident('recipe-pr:'.$number, $head, $fault);
    }

    public static function jobIncident(string $type, string $uuid): ReleaseIncident
    {
        return new ReleaseIncident('recipe-maintenance:'.$type, $uuid, 'job_failed');
    }

    private function buildIncident(string $target, ?int $buildId): ReleaseIncident
    {
        if ($target === 'machine-recipe-upcloud' && $buildId !== null) {
            $build = UpCloudTaskVmImageBuild::query()->findOrFail($buildId);

            return new ReleaseIncident('upcloud:'.$build->recipe_identity, (string) $build->id, 'template_unverified');
        }
        if ($buildId !== null) {
            $build = TaskVmImageBuild::query()->findOrFail($buildId);

            return new ReleaseIncident('recipe-fleet:'.$build->recipe_identity, $build->recipe_generation.':'.$build->id.':'.$build->attempt, 'convergence_halted');
        }

        throw new InvalidArgumentException('A recipe alert without a build needs an explicit incident.');
    }
}
