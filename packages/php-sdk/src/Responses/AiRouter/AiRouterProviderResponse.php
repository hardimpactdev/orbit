<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\AiRouter;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

final readonly class AiRouterProviderResponse
{
    /**
     * @param  list<AiRouterWindowResponse>  $windows
     * @param  list<AiRouterAccountResponse>  $accounts
     */
    public function __construct(
        public string $provider,
        public array $windows,
        public array $accounts,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $provider = $data['provider'] ?? null;
        $windows = $data['windows'] ?? [];
        $accounts = $data['accounts'] ?? [];

        if (
            ! is_string($provider)
            || $provider === ''
            || strlen($provider) > 64
            || ! is_array($windows)
            || ! array_is_list($windows)
            || count($windows) > 16
            || ! is_array($accounts)
            || ! array_is_list($accounts)
            || count($accounts) > 10_000
        ) {
            throw new GatewayApiException('Gateway response contains invalid ai-router provider.', requestId: $requestId);
        }

        return new self(
            $provider,
            array_map(
                static function (mixed $window) use ($requestId): AiRouterWindowResponse {
                    if (! is_array($window)) {
                        throw new GatewayApiException(
                            'Gateway response contains invalid ai-router window.',
                            requestId: $requestId,
                        );
                    }

                    return AiRouterWindowResponse::fromGatewayData(self::stringKeyed($window), $requestId);
                },
                $windows,
            ),
            array_map(
                static function (mixed $account) use ($requestId): AiRouterAccountResponse {
                    if (! is_array($account)) {
                        throw new GatewayApiException(
                            'Gateway response contains invalid ai-router account.',
                            requestId: $requestId,
                        );
                    }

                    return AiRouterAccountResponse::fromGatewayData(self::stringKeyed($account), $requestId);
                },
                $accounts,
            ),
            $requestId,
        );
    }

    /**
     * @param  array<mixed, mixed>  $data
     * @return array<string, mixed>
     */
    private static function stringKeyed(#[SensitiveParameter] array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @return array{
     *     provider: string,
     *     windows: list<array{label: string, used_percent: float, remaining_percent: float, resets_at: string|null}>,
     *     accounts: list<array<string, mixed>>,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'windows' => array_map(
                static fn (AiRouterWindowResponse $window): array => $window->toArray(),
                $this->windows,
            ),
            'accounts' => array_map(
                static function (AiRouterAccountResponse $account): array {
                    $data = $account->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->accounts,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
