<?php

namespace App\Http\Integrations\Orbit\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class ConfigureServiceRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PUT;

    public function __construct(
        protected string $service,
        protected array $serviceConfig = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return "/services/{$this->service}/config";
    }

    protected function defaultBody(): array
    {
        return $this->serviceConfig;
    }
}
