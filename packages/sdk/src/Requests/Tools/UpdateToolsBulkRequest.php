<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Tools;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Tools\ToolUpdateBulkResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateToolsBulkRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly ?string $app = null,
        public readonly ?string $node = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/tools/update';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter([
            'app' => $this->app,
            'node' => $this->node,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function createDtoFromResponse(Response $response): ToolUpdateBulkResponse
    {
        $data = $this->unwrapData($response);

        return new ToolUpdateBulkResponse(
            updated: $data['updated'] ?? [],
            skipped: $data['skipped'] ?? [],
            failed: $data['failed'] ?? [],
        );
    }
}
