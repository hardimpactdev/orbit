<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Infrastructure\TaskVms\TaskVmVolumes;

/**
 * What a task VM host must not hold beside its base, the stock image and its task VMs (docs/cli/task/vm-image-refresh.mmd). After each
 * refresh the `host-clean` phase removes it. The task VM project keeps only the stock source image; its other images and the volumes of
 * retired image seeds and of the slot-pool baseline go. The retired projects go whole, and `orbit-proto` loses its images.
 */
final class TaskVmHostLeftovers
{
    /** Volume and instance name prefixes in the task VM project that no current path makes. */
    public const array Prefixes = [TaskVmVolumes::SeedPrefix, 'orbit-baseline-'];

    /** Projects of past experiments, removed with what they hold. Drop a name here once no host has it (docs/reference/old-paths.md). */
    public const array RetiredProjects = ['orbit-baselines', 'orbit-measure-nr'];

    /** Projects whose images go; their other content stays. */
    public const array ImageProjects = ['orbit-proto'];

    /** @return array{keep_images: list<string>, prefixes: list<string>, retired_projects: list<string>, image_projects: list<string>} */
    public static function intent(): array
    {
        return ['keep_images' => [TaskVmHost::SourceImage], 'prefixes' => self::Prefixes, 'retired_projects' => self::RetiredProjects,
            'image_projects' => self::ImageProjects];
    }
}
