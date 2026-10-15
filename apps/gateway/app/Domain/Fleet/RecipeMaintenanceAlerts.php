<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Releases\ReleaseAlert;
use App\Domain\Releases\ReleaseAlertKind;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertSubject;
use Illuminate\Support\Facades\Log;

final readonly class RecipeMaintenanceAlerts
{
    public function __construct(private ReleaseAlertNotifier $notifier, private FleetServingRelease $serving) {}

    /** @return array<string, mixed> */
    public function halted(string $summary, ?int $buildId = null): array
    {
        Log::error($summary, ['recipe_build_id' => $buildId]);
        $commit = $this->serving->read()['commit'];
        if ($commit === null) {
            return ['outcome' => 'skipped', 'reason' => 'no_deployed_release'];
        }

        return $this->notifier->alert(new ReleaseAlert(
            ReleaseAlertKind::RolloutHalted,
            new ReleaseAlertSubject('machine-recipe', FleetRolloutAlerts::repository(), $commit, $buildId === null ? null : 'recipe-build-'.$buildId),
            $summary,
        ))->toArray();
    }
}
