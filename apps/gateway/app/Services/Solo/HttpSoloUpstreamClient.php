<?php

declare(strict_types=1);

namespace App\Services\Solo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 */
final class HttpSoloUpstreamClient implements SoloUpstreamClient
{
    public function get(SoloUpstreamTarget $target, string $path): SoloUpstreamResponse
    {
        return $this->request($target, 'GET', $path);
    }

    public function post(SoloUpstreamTarget $target, string $path, array $payload): SoloUpstreamResponse
    {
        return $this->request($target, 'POST', $path, $payload);
    }

    public function put(SoloUpstreamTarget $target, string $path, array $payload): SoloUpstreamResponse
    {
        return $this->request($target, 'PUT', $path, $payload);
    }

    public function patch(SoloUpstreamTarget $target, string $path, array $payload): SoloUpstreamResponse
    {
        return $this->request($target, 'PATCH', $path, $payload);
    }

    public function delete(SoloUpstreamTarget $target, string $path, array $payload): SoloUpstreamResponse
    {
        return $this->request($target, 'DELETE', $path, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function request(
        SoloUpstreamTarget $target,
        string $method,
        string $path,
        array $payload = [],
    ): SoloUpstreamResponse {
        try {
            $pending = Http::acceptJson()
                ->withHeader('X-Orbit-Node', $target->identity)
                ->timeout(5);

            if ($target->bearerToken !== null && $target->bearerToken !== '') {
                $pending = $pending->withToken($target->bearerToken);
            }

            $response = match ($method) {
                'DELETE' => $pending->delete($this->url($target, $path), $payload),
                'PATCH' => $pending->patch($this->url($target, $path), $payload),
                'POST' => $pending->post($this->url($target, $path), $payload),
                'PUT' => $pending->put($this->url($target, $path), $payload),
                default => $pending->get($this->url($target, $path)),
            };
        } catch (ConnectionException) {
            return SoloUpstreamResponse::failure(
                code: 'solo_upstream_unavailable',
                message: "Solo API is unavailable on {$target->node->name}.",
                meta: ['node' => $target->node->name],
                status: 503,
            );
        }

        $payload = $this->stringKeyedArray($response->json());

        if ($response->successful()) {
            return $this->success($payload);
        }

        return $this->failure($target, $response->status(), $payload);
    }

    private function url(SoloUpstreamTarget $target, string $path): string
    {
        return rtrim($target->url, characters: '/').'/'.ltrim($path, characters: '/');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function success(array $payload): SoloUpstreamResponse
    {
        if (($payload['ok'] ?? null) === true) {
            return SoloUpstreamResponse::success(
                data: $this->stringKeyedArray($payload['data'] ?? []),
                meta: $this->stringKeyedArray($payload['meta'] ?? []),
            );
        }

        $success = $this->stringKeyedArray($payload['success'] ?? []);

        if ($success === []) {
            return SoloUpstreamResponse::success(data: $payload);
        }

        return SoloUpstreamResponse::success(
            data: $this->stringKeyedArray($success['data'] ?? []),
            meta: $this->stringKeyedArray($success['meta'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function failure(SoloUpstreamTarget $target, int $status, array $payload): SoloUpstreamResponse
    {
        $error = $this->stringKeyedArray($payload['error'] ?? []);

        return SoloUpstreamResponse::failure(
            code: is_string($error['code'] ?? null) ? $error['code'] : 'solo_upstream_error',
            message: is_string($error['message'] ?? null)
                ? $error['message']
                : "Solo API request failed on {$target->node->name}.",
            meta: $this->upstreamErrorMeta($target, $error),
            status: $status,
        );
    }

    /**
     * @param  array<string, mixed>  $error
     * @return array<string, mixed>
     */
    private function upstreamErrorMeta(SoloUpstreamTarget $target, array $error): array
    {
        $meta = $this->stringKeyedArray($error['meta'] ?? []);

        if ($meta !== []) {
            return $meta;
        }

        $details = $this->stringKeyedArray($error['details'] ?? []);

        return $details !== [] ? $details : ['node' => $target->node->name];
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                continue;
            }

            $result[$key] = $value[$key];
        }

        return $result;
    }
}
