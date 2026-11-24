<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\AppsSettings;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodeUpdateResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/** Changes a Node's name, platform, LAN address, reboot hold, apps folder, or CI places; only an apps folder change starts a run. */
final class UpdateNodeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly int $nodeId,
        private readonly ?string $name = null,
        private readonly ?string $platform = null,
        private readonly bool $hasApps = false,
        private readonly ?AppsSettings $apps = null,
        private readonly ?string $idempotencyKey = null,
        private readonly bool $hasLanIp = false,
        private readonly ?string $lanIp = null,
        private readonly ?bool $rebootHold = null,
        private readonly bool $hasCi = false,
        private readonly ?CiPlacesInput $ci = null,
        private readonly ?bool $primaryAgent = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): NodeUpdateResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        if ($response->status() === 202) {
            return new NodeUpdateResponse(OperationResponse::fromGatewayData($data, $requestId));
        }
        $meta = $this->successMeta($response);

        return new NodeUpdateResponse(NodeResponse::fromGatewayData($data, $requestId), is_string($meta['skipped'] ?? null) ? $meta['skipped'] : null);
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

        if ($this->name !== null) {
            $body['name'] = $this->name;
        }

        if ($this->platform !== null) {
            $body['platform'] = $this->platform;
        }

        if ($this->hasLanIp) {
            $body['lan_ip'] = $this->lanIp;
        }

        if ($this->rebootHold !== null) {
            $body['reboot_hold'] = $this->rebootHold;
        }

        if ($this->primaryAgent !== null) {
            $body['primary_agent'] = $this->primaryAgent;
        }

        if ($this->hasApps) {
            $body['apps'] = $this->apps?->toArray();
        }

        if ($this->hasCi) {
            $body['ci'] = $this->ci?->toArray();
        }

        return $body;
    }
}
