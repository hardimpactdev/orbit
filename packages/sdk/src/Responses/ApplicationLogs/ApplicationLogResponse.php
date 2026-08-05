<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\ApplicationLogs;

use Saloon\Http\Response;

final readonly class ApplicationLogResponse
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $data,
        public array $meta,
    ) {}

    public static function fromResponse(Response $response): self
    {
        $body = $response->json();
        /** @var array<string, mixed> $meta */
        $meta = [];
        /** @var array<string, mixed> $data */
        $data = [];

        if (is_array($body) && isset($body['success']) && is_array($body['success'])) {
            $rawData = $body['success']['data'] ?? null;
            $rawMeta = $body['success']['meta'] ?? null;
            $data = self::stringKeyed(is_array($rawData) ? $rawData : []);
            $meta = self::stringKeyed(is_array($rawMeta) ? $rawMeta : []);
        }

        return new self(data: $data, meta: $meta);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $value): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
