<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Services\Solo\SoloUpstreamClient;
use App\Services\Solo\SoloUpstreamResponse;
use App\Services\Solo\SoloUpstreamTarget;

final class FakeSoloUpstreamClient implements SoloUpstreamClient
{
    /**
     * @var list<array{target: SoloUpstreamTarget, method: string, path: string, payload: array<string, mixed>}>
     */
    public array $calls = [];

    /**
     * @param  array<string, SoloUpstreamResponse>  $responses
     */
    public function __construct(
        private array $responses = [],
    ) {}

    public function get(SoloUpstreamTarget $target, string $path): SoloUpstreamResponse
    {
        return $this->record($target, 'GET', $path);
    }

    public function post(SoloUpstreamTarget $target, string $path, array $payload): SoloUpstreamResponse
    {
        return $this->record($target, 'POST', $path, $payload);
    }

    public function put(SoloUpstreamTarget $target, string $path, array $payload): SoloUpstreamResponse
    {
        return $this->record($target, 'PUT', $path, $payload);
    }

    public function patch(SoloUpstreamTarget $target, string $path, array $payload): SoloUpstreamResponse
    {
        return $this->record($target, 'PATCH', $path, $payload);
    }

    public function delete(SoloUpstreamTarget $target, string $path, array $payload): SoloUpstreamResponse
    {
        return $this->record($target, 'DELETE', $path, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(
        SoloUpstreamTarget $target,
        string $method,
        string $path,
        array $payload = [],
    ): SoloUpstreamResponse {
        $this->calls[] = [
            'target' => $target,
            'method' => $method,
            'path' => $path,
            'payload' => $payload,
        ];

        return (
            $this->responses["{$method} {$path}"] ?? $this->responses[$path] ?? SoloUpstreamResponse::success(data: [])
        );
    }
}
