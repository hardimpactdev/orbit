<?php

namespace App\Http\Integrations\Orbit\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class SetPhpRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        protected string $site,
        protected string $version,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/php/{$this->site}";
    }

    protected function defaultBody(): array
    {
        return [
            'version' => $this->version,
        ];
    }
}
