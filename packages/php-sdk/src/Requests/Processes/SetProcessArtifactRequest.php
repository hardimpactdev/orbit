<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Processes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Processes\ProcessArtifactResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

/** Records the release artifact of a systemd Process. A Gateway write only: it changes nothing on the Node. */
final class SetProcessArtifactRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PUT;

    /** @param list<string>|null $versionArgs */
    public function __construct(
        private readonly int $processId,
        private readonly ?string $preset = null,
        private readonly ?string $repository = null,
        private readonly ?string $asset = null,
        private readonly ?string $binary = null,
        private readonly ?string $checksums = null,
        private readonly ?array $versionArgs = null,
        private readonly ?string $version = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/processes/{$this->processId}/artifact";
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): ProcessArtifactResponse
    {
        return ProcessArtifactResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return array_filter([
            'preset' => $this->preset,
            'repository' => $this->repository,
            'asset' => $this->asset,
            'binary' => $this->binary,
            'checksums' => $this->checksums,
            'version_args' => $this->versionArgs,
            'version' => $this->version,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
