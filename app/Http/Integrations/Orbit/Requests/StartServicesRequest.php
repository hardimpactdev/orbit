<?php

namespace App\Http\Integrations\Orbit\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class StartServicesRequest extends Request
{
    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/start';
    }

    protected function defaultConfig(): array
    {
        return [
            'timeout' => 120,
        ];
    }
}
