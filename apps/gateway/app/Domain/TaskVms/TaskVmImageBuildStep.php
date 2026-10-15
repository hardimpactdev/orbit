<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/** A program step also carries the name from TaskVmImageRecipe::programs(). */
enum TaskVmImageBuildStep: string
{
    case Recipe = 'recipe';
    case Launch = 'launch';
    case AwaitBoot = 'await-boot';
    case UpgradePackages = 'upgrade-packages';
    case Program = 'program';
    case PrepareBoot = 'prepare-boot';
    case Clean = 'clean';
    case Publish = 'publish';
    case Smoke = 'smoke';
    case Promote = 'promote';
}
