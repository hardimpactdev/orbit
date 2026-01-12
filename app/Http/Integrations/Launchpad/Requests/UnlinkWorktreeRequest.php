<?php

namespace App\Http\Integrations\Launchpad\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class UnlinkWorktreeRequest extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(
        protected string $site,
        protected string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/worktrees/{$this->site}/{$this->name}";
    }
}
