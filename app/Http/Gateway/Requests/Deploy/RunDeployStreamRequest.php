<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Deploy;

use App\Http\Gateway\GatewayStreamRequest;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Traits\Body\HasJsonBody;

final class RunDeployStreamRequest extends GatewayStreamRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $app,
        public readonly bool $detach = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/deploy/run';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'app' => $this->app,
            'detach' => $this->detach,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'text/event-stream',
        ];
    }
}
