<?php

declare(strict_types=1);

namespace App\Services\Solo;

interface SoloUpstreamClient
{
    public function get(SoloUpstreamTarget $target, string $path): SoloUpstreamResponse;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function post(SoloUpstreamTarget $target, string $path, array $payload): SoloUpstreamResponse;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function put(SoloUpstreamTarget $target, string $path, array $payload): SoloUpstreamResponse;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function patch(SoloUpstreamTarget $target, string $path, array $payload): SoloUpstreamResponse;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function delete(SoloUpstreamTarget $target, string $path, array $payload): SoloUpstreamResponse;
}
