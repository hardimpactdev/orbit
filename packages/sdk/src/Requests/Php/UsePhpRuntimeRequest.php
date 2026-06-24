<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Php;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Php\PhpRuntimeUseResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UsePhpRuntimeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly ?string $version = null,
        public readonly ?string $app = null,
        public readonly ?string $workspace = null,
        public readonly ?string $node = null,
        public readonly bool $inherit = false,
        public readonly bool $cli = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/php/use';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter(
            [
                'version' => $this->version,
                'app' => $this->app,
                'workspace' => $this->workspace,
                'node' => $this->node,
                'inherit' => $this->inherit,
                'cli' => $this->cli,
            ],
            static fn (mixed $value): bool => $value !== null,
        );
    }

    public function createDtoFromResponse(Response $response): PhpRuntimeUseResponse
    {
        $data = $this->unwrapData($response);

        return new PhpRuntimeUseResponse(
            php: $this->stringKeyedArray($data['php'] ?? []),
            result: $this->stringKeyedArray($data['result'] ?? []),
            meta: $this->unwrapMeta($response),
        );
    }
}
