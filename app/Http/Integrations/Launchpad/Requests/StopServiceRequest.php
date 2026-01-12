<?php

namespace App\Http\Integrations\Launchpad\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class StopServiceRequest extends Request
{
    protected Method $method = Method::POST;

    public function __construct(
        protected string $service,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/services/{$this->service}/stop";
    }
}
