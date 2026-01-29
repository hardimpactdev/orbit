<?php
declare(strict_types=1);

namespace HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests;

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
