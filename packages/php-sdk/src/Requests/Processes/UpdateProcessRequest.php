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
     * @param  list<string>|null  $command
     * @param  array<string, string>|null  $environment
     * @param  list<string>|null  $ports
     * @param  list<array{source: string, target: string, read_only?: bool}>|null  $volumes
     */
    public function __construct(
        private readonly int $processId,
        private readonly ?string $runtime = null,
        private readonly ?array $command = null,
        private readonly ?string $image = null,
        private readonly ?string $workingDirectory = null,
        #[\SensitiveParameter] private readonly ?array $environment = null,
        private readonly ?array $ports = null,
        private readonly ?array $volumes = null,
        private readonly ?string $restartPolicy = null,
        private readonly ?bool $keepAlive = null,
        private readonly ?string $user = null,
        private readonly ?bool $restartOnDeploy = null,
        private readonly bool $clearWorkingDirectory = false,
        private readonly bool $clearImage = false,
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
        $body = array_filter([
            'runtime' => $this->runtime,
            'command' => $this->command,
            'image' => $this->image,
            'working_directory' => $this->workingDirectory,
            'environment' => $this->environment === null ? null : (object) $this->environment,
            'ports' => $this->ports,
            'volumes' => $this->volumes,
            'restart_policy' => $this->restartPolicy,
            'keep_alive' => $this->keepAlive,
            'restart_on_deploy' => $this->restartOnDeploy,
            'user' => $this->user,
        ], static fn (mixed $value): bool => $value !== null);

        if ($this->clearWorkingDirectory) {
            $body['working_directory'] = null;
        }
        if ($this->clearImage) {
            $body['image'] = null;
        }

        return $body;
    }
}
