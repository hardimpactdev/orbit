<?php

namespace HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class DeleteWorkspaceRequest extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(
        protected string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/workspaces/{$this->name}";
    }
}
