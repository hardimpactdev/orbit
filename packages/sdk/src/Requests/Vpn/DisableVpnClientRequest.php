<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Vpn;

final class DisableVpnClientRequest extends EnableVpnClientRequest
{
    #[\Override]
    public function resolveEndpoint(): string
    {
        return "/api/vpn/clients/{$this->name}/disable";
    }
}
