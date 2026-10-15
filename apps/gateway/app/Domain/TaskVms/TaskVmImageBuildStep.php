<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/** Legacy step values remain readable in historical build rows. */
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
    case Prune = 'prune';
}
