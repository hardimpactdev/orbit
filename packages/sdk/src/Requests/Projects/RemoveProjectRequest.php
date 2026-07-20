<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Projects;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Projects\ProjectRemoveResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RemoveProjectRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        public readonly string $project,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/projects/'.rawurlencode($this->project);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'destructive_consent' => true,
            'destructive_consent_source' => 'force',
        ];
    }

    public function createDtoFromResponse(Response $response): ProjectRemoveResponse
    {
        $data = $this->unwrapData($response);
        $body = $response->json();
        $warnings = [];

        if (is_array($body) && isset($body['success']['meta']) && is_array($body['success']['meta'])) {
            $meta = $body['success']['meta'];

            if (isset($meta['warnings']) && is_array($meta['warnings'])) {
                $warnings = $this->listOfStringKeyedArrays($meta['warnings']);
            }
        }

        return new ProjectRemoveResponse(
            data: $data,
            warnings: $warnings,
        );
    }
}
