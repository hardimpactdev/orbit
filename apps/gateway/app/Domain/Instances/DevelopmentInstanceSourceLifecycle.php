<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

interface DevelopmentInstanceSourceLifecycle
{
    /**
     * `$template` is a prepared checkout of the Project's default branch next to the Instance's checkout, on the
     * same filesystem, such as a task VM's workspace template. Preparation moves it into place instead of cloning.
     */
    public function prepare(Instance $instance, bool $allowExisting, ?string $template = null): void;

    public function inspectPrepared(Instance $instance): void;

    public function resolve(Instance $instance): DevelopmentSourceResolution;

    public function inspectResolved(Instance $instance): DevelopmentSourceResolution;
}
