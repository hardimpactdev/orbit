<?php

namespace HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class StopServicesRequest extends Request
{
    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/stop';
    }

    protected function defaultConfig(): array
    {
        return [
            'timeout' => 60,
        ];
    }
}
