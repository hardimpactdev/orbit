<?php

declare(strict_types=1);

use App\Domain\Fleet\CliConvergeResult;
use App\Domain\Fleet\FleetProbeCommand;
use App\Domain\Fleet\FleetVerificationCommand;
use App\Domain\Fleet\RecipeBeginCommand;
use App\Domain\Fleet\RecipePrCommand;

return [
    'fleet.probe' => FleetProbeCommand::class,
    'fleet.cli-converge-result' => CliConvergeResult::class,
    'fleet.recipe-begin' => RecipeBeginCommand::class,
    'fleet.verify' => FleetVerificationCommand::class,
    'github.recipe-pr' => RecipePrCommand::class,
];
