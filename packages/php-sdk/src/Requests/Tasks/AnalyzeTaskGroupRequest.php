<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasStringBody;

/**
 * Starts tasks.analyze. The complete manifest JSON is transported verbatim so that
 * the Gateway, not a lossy client decoder, validates its native schema and duplicates.
 */
final class AnalyzeTaskGroupRequest extends GatewayRequest implements HasBody
{
    use HasStringBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $groupId,
        private readonly int $subtask,
        #[\SensitiveParameter] private readonly string $planJson,
        private readonly string $planSha256,
        private readonly string $base,
        private readonly string $head,
        private readonly string $tree,
        private readonly string $idempotencyKey,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/task-groups/{$this->groupId}/analyze";
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return ['Content-Type' => 'application/json', 'Idempotency-Key' => $this->idempotencyKey];
    }

    protected function defaultBody(): string
    {
        $fields = json_encode(['subtask' => $this->subtask, 'plan_sha256' => $this->planSha256,
            'base' => $this->base, 'head' => $this->head, 'tree' => $this->tree], JSON_THROW_ON_ERROR);

        return substr($fields, 0, -1).',"plan":'.$this->planJson.'}';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
