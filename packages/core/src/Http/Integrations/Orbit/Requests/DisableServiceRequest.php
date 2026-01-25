<?php

namespace HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class DisableServiceRequest extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(
        protected string $service,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/services/{$this->service}";
    }
}
