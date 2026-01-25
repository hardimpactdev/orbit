<?php

namespace HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class RebuildProjectRequest extends Request
{
    protected Method $method = Method::POST;

    public function __construct(
        protected string $slug,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/projects/{$this->slug}/rebuild";
    }

    protected function defaultConfig(): array
    {
        return [
            'timeout' => 300,
        ];
    }
}
