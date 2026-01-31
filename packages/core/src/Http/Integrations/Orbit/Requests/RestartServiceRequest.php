<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class RestartServiceRequest extends Request
{
    protected Method $method = Method::POST;

    public function __construct(
        protected string $service,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/services/{$this->service}/restart";
    }

    protected function defaultConfig(): array
    {
        return [
            'timeout' => 60,
        ];
    }
}
