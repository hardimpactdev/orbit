<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\AppsSettings;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodeSettings;
use Orbit\Sdk\Responses\Nodes\NodeSettingsMutationResponse;
use Orbit\Sdk\Responses\Nodes\TaskVmsSettings;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateNodeSettingsRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly int $nodeId,
        private readonly bool $hasApps = false,
        private readonly ?AppsSettings $apps = null,
        private readonly bool $hasTaskVms = false,
        private readonly ?TaskVmsSettings $taskVms = null,
        private readonly ?string $idempotencyKey = null,
    ) {}

    public static function fromSettings(int $nodeId, NodeSettings $settings): self
    {
        return new self(
            nodeId: $nodeId,
            hasApps: true,
            apps: $settings->apps,
            hasTaskVms: $settings->task_vms !== null,
            taskVms: $settings->task_vms,
        );
    }

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}/settings";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): NodeSettingsMutationResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return new NodeSettingsMutationResponse($response->status() === 202
            ? OperationResponse::fromGatewayData($data, $requestId)
            : NodeResponse::fromGatewayData($data, $requestId));
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        $body = [];

        if ($this->hasApps) {
            $body['apps'] = $this->apps?->toArray();
        }

        if ($this->hasTaskVms) {
            $body['task_vms'] = $this->taskVms?->toArray();
        }

        return $body;
    }
}
