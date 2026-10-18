<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\AiRouter;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

final readonly class AiRouterAccountResponse
{
    /**
     * @param  list<AiRouterWindowResponse>  $windows
     */
    public function __construct(
        public string $id,
        public string $provider,
        public string $label,
        public bool $disabled,
        public ?string $status,
        public array $windows,
        public ?string $error,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $id = $data['id'] ?? null;
        $provider = $data['provider'] ?? null;
        $label = $data['label'] ?? null;
        $status = $data['status'] ?? null;
        $error = $data['error'] ?? null;
        $windows = $data['windows'] ?? [];

        if (
            ! is_string($id)
            || $id === ''
            || strlen($id) > 255
            || ! is_string($provider)
            || $provider === ''
            || strlen($provider) > 64
            || ! is_string($label)
            || $label === ''
            || strlen($label) > 255
            || ! is_bool($data['disabled'] ?? null)
            || ($status !== null && (! is_string($status) || $status === '' || strlen($status) > 64))
            || ($error !== null && (! is_string($error) || $error === '' || strlen($error) > 512))
            || ! is_array($windows)
            || ! array_is_list($windows)
            || count($windows) > 16
        ) {
            throw new GatewayApiException('Gateway response contains invalid ai-router account.', requestId: $requestId);
        }

        return new self(
            $id,
            $provider,
            $label,
            $data['disabled'],
            $status,
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
            $error,
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
     *     id: string,
     *     provider: string,
     *     label: string,
     *     disabled: bool,
     *     status: string|null,
     *     windows: list<array{label: string, used_percent: float, remaining_percent: float, resets_at: string|null}>,
     *     error: string|null,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'label' => $this->label,
            'disabled' => $this->disabled,
            'status' => $this->status,
            'windows' => array_map(
                static fn (AiRouterWindowResponse $window): array => $window->toArray(),
                $this->windows,
            ),
            'error' => $this->error,
            'request_id' => $this->requestId,
        ];
    }
}
