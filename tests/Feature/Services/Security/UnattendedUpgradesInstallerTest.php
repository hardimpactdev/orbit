<?php

declare(strict_types=1);

use App\Services\Security\UnattendedUpgradesInstaller;
use App\Services\Updates\UnattendedUpgradesAptConfig;

it('renders the shared unattended-upgrades apt configuration', function (): void {
    $config = new UnattendedUpgradesAptConfig;
    $script = app(UnattendedUpgradesInstaller::class)->script();

    expect($script)
        ->toContain($config->autoUpgrades())
        ->toContain($config->unattendedUpgrades());
});
