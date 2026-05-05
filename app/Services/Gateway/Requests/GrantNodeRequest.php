<?php

declare(strict_types=1);

namespace App\Services\Gateway\Requests;

use App\Services\Gateway\GatewayRequest;

final readonly class GrantNodeRequest implements GatewayRequest
{
    public function __construct(
        private string $consumingNode,
        private string $servingNode,
    ) {}

    public function method(): string
    {
        return 'POST';
    }

    public function path(): string
    {
        return '/api/nodes/grant';
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [
            'consuming_node' => $this->consumingNode,
            'serving_node' => $this->servingNode,
        ];
    }
}
