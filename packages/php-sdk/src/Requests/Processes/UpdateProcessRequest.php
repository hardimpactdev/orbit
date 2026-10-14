<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Processes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Processes\ProcessResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateProcessRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     * @param  list<string>  $ports
     * @param  list<array{source: string, target: string, read_only: bool}>  $volumes
     */
    public function __construct(
        private readonly int $processId,
        private readonly string $runtime,
        private readonly array $command,
        private readonly ?string $image = null,
        private readonly ?string $workingDirectory = null,
        #[\SensitiveParameter] private readonly array $environment = [],
        private readonly array $ports = [],
        private readonly array $volumes = [],
        private readonly string $restartPolicy = 'never',
        private readonly bool $keepAlive = false,
        private readonly ?string $user = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/processes/'.$this->processId;
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProcessResponse
    {
        return ProcessResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return [
            'runtime' => $this->runtime,
            'command' => $this->command,
            'working_directory' => $this->workingDirectory,
            'restart_policy' => $this->restartPolicy,
            'keep_alive' => $this->keepAlive,
            ...($this->runtime === 'docker' ? [
                'image' => $this->image,
                'environment' => (object) $this->environment,
                'ports' => $this->ports,
                'volumes' => $this->volumes,
            ] : []),
            ...($this->user === null ? [] : ['user' => $this->user]),
        ];
    }
}
