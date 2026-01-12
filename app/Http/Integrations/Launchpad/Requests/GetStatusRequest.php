<?php

namespace App\Http\Integrations\Launchpad\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetStatusRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/status';
    }
}
