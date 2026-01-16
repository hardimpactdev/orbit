<?php

namespace App\Http\Integrations\Orbit\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class UnlinkPackageRequest extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(
        protected string $app,
        protected string $package,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/packages/{$this->app}/unlink/{$this->package}";
    }
}
