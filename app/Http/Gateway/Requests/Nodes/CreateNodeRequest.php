<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeCreateResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CreateNodeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $name,
        public readonly string $role,
        public readonly ?string $host,
        public readonly ?string $environment,
        public readonly ?string $tld,
        public readonly ?string $sshUser,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/nodes';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'name' => $this->name,
            'role' => $this->role,
            'host' => $this->host,
            'environment' => $this->environment,
            'tld' => $this->tld,
            'ssh_user' => $this->sshUser,
        ];
    }

    public function createDtoFromResponse(Response $response): NodeCreateResponse
    {
        return new NodeCreateResponse(data: $this->unwrapData($response));
    }
}
