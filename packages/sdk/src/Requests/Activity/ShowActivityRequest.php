<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Activity;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Activity\ActivityShowResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowActivityRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly int $id,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/activity/{$this->id}";
    }

    public function createDtoFromResponse(Response $response): ActivityShowResponse
    {
        $data = $this->unwrapData($response);
        $activity = $data['activity'] ?? [];
        $related = $data['related'] ?? [];

        return new ActivityShowResponse(
            activity: is_array($activity) ? $activity : [],
            related: is_array($related) ? array_values($related) : [],
            meta: $this->envelopeMeta($response),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function envelopeMeta(Response $response): array
    {
        $body = json_decode($response->body(), true);

        if (! is_array($body)) {
            return [];
        }

        $success = $body['success'] ?? [];

        if (! is_array($success)) {
            return [];
        }

        $meta = $success['meta'] ?? [];

        return is_array($meta) ? $meta : [];
    }
}
