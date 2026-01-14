<?php

namespace App\Http\Integrations\Launchpad\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class EnableServiceRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        protected string $service,
        protected array $options = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return "/services/{$this->service}/enable";
    }

    protected function defaultBody(): array
    {
        return $this->options;
    }
}
