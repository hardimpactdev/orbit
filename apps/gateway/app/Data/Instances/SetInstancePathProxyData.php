<?php

declare(strict_types=1);

namespace App\Data\Instances;

final readonly class SetInstancePathProxyData
{
    public function __construct(public int $processId, public int $port, public string $prefix = '/powersync') {}
}
