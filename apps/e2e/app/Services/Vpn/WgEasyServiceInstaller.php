<?php

declare(strict_types=1);

namespace App\Services\Vpn;

/**
 * E2E-local mirror of the gateway wg-easy container image identity.
 *
 * The Incus host builder stages this image archive when preparing prepared
 * topologies. Only the image reference is needed by the harness; kept in sync
 * with apps/gateway/app/Services/Vpn/WgEasyServiceInstaller::Image.
 */
final class WgEasyServiceInstaller
{
    public const string Image = 'ghcr.io/wg-easy/wg-easy:15';
}
