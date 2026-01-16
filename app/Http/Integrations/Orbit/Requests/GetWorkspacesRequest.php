<?php

namespace App\Http\Integrations\Orbit\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetWorkspacesRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/workspaces';
    }
}
