<?php

namespace App\Http\Integrations\Launchpad\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class DeleteProjectRequest extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(
        protected string $slug,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/projects/{$this->slug}";
    }
}
