<?php
declare(strict_types=1);

namespace HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetServiceLogsRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $service,
        protected int $lines = 100,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/services/{$this->service}/logs";
    }

    protected function defaultQuery(): array
    {
        return [
            'lines' => $this->lines,
        ];
    }
}
