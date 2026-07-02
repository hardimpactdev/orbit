<?php

declare(strict_types=1);

namespace App\Services\Solo;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 */
final readonly class HttpSoloUpstreamClient implements SoloUpstreamClient
{
    public function __construct(
        private NodeRoleAssignments $roles,
        private RemoteShell $remoteShell,
        private SoloRemoteCurlScript $remoteCurlScript,
    ) {}

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
        if (! $this->roles->nodeIsGateway($target->node)) {
            return $this->remoteRequest($target, $method, $path, $payload);
        }

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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function remoteRequest(
        SoloUpstreamTarget $target,
        string $method,
        string $path,
        array $payload,
    ): SoloUpstreamResponse {
        $input = $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR);
        $result = $this->remoteShell->run(
            $target->node,
            $this->remoteCurlScript->forRequest($target, $method, $this->url($target, $path), $payload),
            array_filter(
                [
                    'timeout' => 10,
                    'throw' => false,
                    'input' => $input,
                ],
                static fn (mixed $value): bool => $value !== null,
            ),
        );

        if (! $result->successful()) {
            return SoloUpstreamResponse::failure(
                code: 'solo_upstream_unavailable',
                message: "Solo API is unavailable on {$target->node->name}.",
                meta: ['node' => $target->node->name],
                status: 503,
            );
        }

        $remoteResponse = $this->remoteResponse($result);

        if ($remoteResponse === null) {
            return SoloUpstreamResponse::failure(
                code: 'solo_upstream_error',
                message: "Solo API response was invalid on {$target->node->name}.",
                meta: ['node' => $target->node->name],
                status: 502,
            );
        }

        [$status, $responsePayload] = $remoteResponse;

        if ($status >= 200 && $status < 300) {
            return $this->success($responsePayload);
        }

        return $this->failure($target, $status, $responsePayload);
    }

    private function url(SoloUpstreamTarget $target, string $path): string
    {
        return rtrim($target->url, characters: '/').'/'.ltrim($path, characters: '/');
    }

    /**
     * @return array{int, array<string, mixed>}|null
     */
    private function remoteResponse(RemoteShellResult $result): ?array
    {
        [$status, $body] = array_pad(
            array: explode(separator: "\n", string: $result->stdout, limit: 2),
            length: 2,
            value: '',
        );

        if (! ctype_digit($status)) {
            return null;
        }

        try {
            $payload = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return [(int) $status, $this->stringKeyedArray($payload)];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function success(array $payload): SoloUpstreamResponse
    {
        if (($payload['ok'] ?? null) === true) {
            return SoloUpstreamResponse::success(
                data: $this->normalizeSuccessData($this->stringKeyedArray($payload['data'] ?? [])),
                meta: $this->stringKeyedArray($payload['meta'] ?? []),
            );
        }

        $success = $this->stringKeyedArray($payload['success'] ?? []);

        if ($success === []) {
            return SoloUpstreamResponse::success(data: $payload);
        }

        return SoloUpstreamResponse::success(
            data: $this->normalizeSuccessData($this->stringKeyedArray($success['data'] ?? [])),
            meta: $this->stringKeyedArray($success['meta'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeSuccessData(array $data): array
    {
        if (! array_key_exists('tools', $data) && is_array($data['agentTools'] ?? null)) {
            $data['tools'] = $data['agentTools'];
        }

        if (
            ! array_key_exists('todo', $data)
            && array_key_exists('id', $data)
            && array_key_exists('projectId', $data)
            && array_key_exists('deleted', $data)
        ) {
            $data['todo'] = $data;
        }

        return $data;
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
