<?php

declare(strict_types=1);

use App\Actions\Fleet\FleetRolloutPlanAction;
use App\Domain\Nodes\RoleName;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Fleet\InstalledFleetPrimitives;

it('keeps a rollout waiting without admitting any Node until its release exists', function (): void {
    InstalledFleetPrimitives::requireSupportedKit();
    InstalledFleetPrimitives::simulate();
    FleetFixtures::bind(published: false);
    FleetFixtures::node('waiting-dev', [RoleName::AppDev]);

    $plan = app(FleetRolloutPlanAction::class)->execute();

    expect($plan['status'])->toBe('waiting')->and(Operation::query()->count())->toBe(0);
});
