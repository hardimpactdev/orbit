<?php
declare(strict_types=1);

namespace HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class RemoveWorkspaceProjectRequest extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(
        protected string $workspace,
        protected string $project,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/workspaces/{$this->workspace}/projects/{$this->project}";
    }
}
