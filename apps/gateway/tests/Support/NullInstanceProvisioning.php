<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Models\Instance;

final readonly class NullInstanceProvisioning implements InstanceProvisioning
{
    public function provision(InstanceProvisionIntent $intent): ?Instance
    {
        return null;
    }
}
