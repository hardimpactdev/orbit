<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Processes;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Processes\ProcessAddResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class AddProcessRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $instance,
        public readonly string $name,
        public readonly string $command,
        public readonly string $restartPolicy = 'never',
        public readonly string $crashNotification = 'none',
        public readonly bool $start = false,
        public readonly ?string $runtime = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/processes';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [
            'instance' => $this->instance,
            'name' => $this->name,
            'command' => $this->command,
            'restart_policy' => $this->restartPolicy,
            'crash_notification' => $this->crashNotification,
            'start' => $this->start,
        ];

        if ($this->runtime !== null) {
            $body['runtime'] = $this->runtime;
        }

        return $body;
    }

    public function createDtoFromResponse(Response $response): ProcessAddResponse
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

        return new ProcessAddResponse(
            data: $data,
            warnings: $warnings,
        );
    }
}
