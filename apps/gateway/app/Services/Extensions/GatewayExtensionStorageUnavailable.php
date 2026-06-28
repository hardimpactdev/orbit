<?php

declare(strict_types=1);

namespace App\Services\Extensions;

use RuntimeException;

final class GatewayExtensionStorageUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Gateway extension state storage is unavailable.');
    }
}
