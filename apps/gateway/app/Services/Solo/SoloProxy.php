<?php

declare(strict_types=1);

namespace App\Services\Solo;

use App\Models\Node;

final readonly class SoloProxy
{
    public function __construct(
        private SoloUpstreamTargetResolver $targets,
        private SoloUpstreamClient $client,
    ) {}

    public function tools(Node $node): SoloUpstreamResponse
    {
        return $this->client->get($this->targets->gatewayTarget($node), '/tools');
    }

    public function projects(Node $node): SoloUpstreamResponse
    {
        return $this->client->get($this->targets->gatewayTarget($node), '/projects');
    }

    public function read(Node $node, string $upstreamPath): SoloUpstreamResponse
    {
        return $this->client->get($this->targets->gatewayTarget($node), $upstreamPath);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function mutate(
        Node $node,
        SoloMutationOperation $operation,
        string $upstreamPath,
        array $payload,
    ): SoloUpstreamResponse {
        $target = $this->targets->gatewayTarget($node);

        return match ($operation->method) {
            'DELETE' => $this->client->delete($target, $upstreamPath, $payload),
            'PATCH' => $this->client->patch($target, $upstreamPath, $payload),
            'PUT' => $this->client->put($target, $upstreamPath, $payload),
            default => $this->client->post($target, $upstreamPath, $payload),
        };
    }
}
