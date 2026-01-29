<?php
declare(strict_types=1);

namespace HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class DeleteProjectRequest extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(
        protected string $slug,
        protected bool $keepDb = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/projects/{$this->slug}";
    }

    protected function defaultQuery(): array
    {
        return [
            'keep_db' => $this->keepDb ? '1' : '0',
        ];
    }
}
