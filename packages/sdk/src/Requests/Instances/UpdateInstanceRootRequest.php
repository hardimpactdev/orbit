<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Instances;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Instances\InstanceRootUpdateResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateInstanceRootRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $instance,
        public readonly string $root,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/instances/'.rawurlencode($this->instance).'/root';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'root' => $this->root,
        ];
    }

    public function createDtoFromResponse(Response $response): InstanceRootUpdateResponse
    {
        $data = $this->unwrapData($response);
        $body = $response->json();
        $warnings = [];
        $artifactsReenacted = false;

        if (is_array($body) && isset($body['success']['meta']) && is_array($body['success']['meta'])) {
            $meta = $body['success']['meta'];

            if (isset($meta['warnings']) && is_array($meta['warnings'])) {
                $warnings = array_values($meta['warnings']);
            }

            $artifactsReenacted = $meta['artifacts_reenacted'] ?? false;
            $artifactsReenacted = is_bool($artifactsReenacted) ? $artifactsReenacted : false;
            $warnings = $this->listOfStringKeyedArrays($warnings);
        }

        return new InstanceRootUpdateResponse(
            data: $data,
            warnings: $warnings,
            artifactsReenacted: $artifactsReenacted,
        );
    }
}
