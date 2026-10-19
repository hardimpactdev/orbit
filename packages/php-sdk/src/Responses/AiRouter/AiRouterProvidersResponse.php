<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\AiRouter;

use InvalidArgumentException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class AiRouterProvidersResponse
{
    /** @var list<AiRouterProviderResponse> */
    public array $providers;

    public string $requestId;

    /**
     * @param  array<array-key, mixed>  $providers
     */
    public function __construct(
        array $providers,
        #[SensitiveParameter]
        string $requestId,
    ) {
        if (! array_is_list($providers)) {
            throw new InvalidArgumentException('Invalid ai-router provider collection.');
        }

        foreach ($providers as $provider) {
            if (! $provider instanceof AiRouterProviderResponse) {
                throw new InvalidArgumentException('Invalid ai-router provider collection member.');
            }
        }

        $this->providers = $providers;
        $this->requestId = GatewayRequestId::fromTransport($requestId) ?? $requestId;
    }

    /**
     * @return array{
     *     providers: list<array<string, mixed>>,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'providers' => array_map(
                static function (AiRouterProviderResponse $provider): array {
                    $data = $provider->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->providers,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
