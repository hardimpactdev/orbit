<?php

namespace App\Http\Integrations\Launchpad\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class CreateProjectRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        protected array $payload,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/projects';
    }

    protected function defaultBody(): array
    {
        return $this->payload;
    }
}
