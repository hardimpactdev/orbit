<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeUpdateResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateNodeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PUT;

    /**
     * @param  array<string, string|null>  $fields
     */
    public function __construct(
        public readonly string $name,
        public readonly array $fields,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/nodes/{$this->name}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter(
            $this->fields,
            static fn (?string $value): bool => $value !== null,
        );
    }

    public function createDtoFromResponse(Response $response): NodeUpdateResponse
    {
        $data = $this->unwrapData($response);

        $changed = $data['changed'] ?? [];

        return new NodeUpdateResponse(
            name: is_string($data['name'] ?? null) ? $data['name'] : $this->name,
            changed: is_array($changed) ? array_values(array_filter($changed, is_string(...))) : [],
        );
    }
}
