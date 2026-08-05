<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Instances;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\ApplicationLogs\ApplicationLogStreamResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class StartInstanceApplicationLogStreamRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $instance,
        public readonly int $lines = 100,
        public readonly ?string $node = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/instances/'.rawurlencode($this->instance).'/log-stream';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter(
            [
                'lines' => $this->lines,
                'node' => $this->node,
            ],
            fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    public function createDtoFromResponse(Response $response): ApplicationLogStreamResponse
    {
        return ApplicationLogStreamResponse::fromResponse($response);
    }
}
