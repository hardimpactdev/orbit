<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

interface PrivateDnsManager
{
    /** Publishes a fresh render of the private DNS and returns when the vpn Node answers it. */
    public function converge(): void;
}
