<?php

namespace App\Http\Integrations\Launchpad\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetWorktreesRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected ?string $site = null,
    ) {}

    public function resolveEndpoint(): string
    {
        if ($this->site) {
            return "/worktrees/{$this->site}";
        }

        return '/worktrees';
    }
}
