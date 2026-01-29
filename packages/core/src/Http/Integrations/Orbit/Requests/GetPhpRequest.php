<?php
declare(strict_types=1);

namespace HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetPhpRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $site,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/php/{$this->site}";
    }
}
