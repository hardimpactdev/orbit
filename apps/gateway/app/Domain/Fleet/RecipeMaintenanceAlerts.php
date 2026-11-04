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
    private const string ImageIncidentPrefix = 'recipe-image:';

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

    public static function imageIncident(TaskVmImageBuild $build): ReleaseIncident
    {
        return new ReleaseIncident(self::ImageIncidentPrefix.$build->id, (string) $build->attempt, 'build_failed');
    }

    public static function parseImageIncident(string $resource): ?int
    {
        return str_starts_with($resource, self::ImageIncidentPrefix)
            ? (int) substr($resource, strlen(self::ImageIncidentPrefix))
            : null;
    }

    private function buildIncident(string $target, ?int $buildId): ReleaseIncident
    {
        if ($target === 'machine-recipe-upcloud' && $buildId !== null) {
            $build = UpCloudTaskVmImageBuild::query()->findOrFail($buildId);

            return new ReleaseIncident('upcloud:'.$build->recipe_identity, (string) $build->id, 'template_unverified');
        }

        throw new InvalidArgumentException('A recipe alert without a build needs an explicit incident.');
    }
}
