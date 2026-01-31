<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetLinkedPackagesRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $app,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/packages/{$this->app}/linked";
    }
}
