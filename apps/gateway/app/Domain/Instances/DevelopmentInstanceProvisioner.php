<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

interface DevelopmentInstanceProvisioner
{
    public function reserve(Instance $instance, ?string $domain): void;

    /**
     * Does what a check in the checkout needs before the Route: it classifies the source, writes the Laravel URL, and
     * installs the PHP packages of the selected version. {@see self::complete()} then projects the Route. Returns the
     * Instance unchanged when it needs no Route or is already active.
     */
    public function prepare(Instance $instance, ?string $domain): Instance;

    public function complete(
        Instance $instance,
        ?string $domain,
        bool $setupPending = false,
    ): Instance;
}
