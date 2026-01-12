<?php

namespace App\Http\Integrations\Launchpad\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class LinkPackageRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        protected string $app,
        protected string $package,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/packages/{$this->app}/link";
    }

    protected function defaultBody(): array
    {
        return [
            'package' => $this->package,
        ];
    }
}
