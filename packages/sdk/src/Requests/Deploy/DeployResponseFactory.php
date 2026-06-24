<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Deploy;

use Orbit\Sdk\Laravel\Responses\Deploy\DeployResponse;
use Saloon\Http\Response;

final readonly class DeployResponseFactory
{
    public static function fromResponse(Response $response): DeployResponse
    {
        $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        $success = is_array($body) ? $body['success'] ?? [] : [];
        $data = is_array($success) ? $success['data'] ?? [] : [];
        $meta = is_array($success) ? $success['meta'] ?? [] : [];

        return new DeployResponse(
            data: self::stringKeyedArray($data),
            meta: self::stringKeyedArray($meta),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
