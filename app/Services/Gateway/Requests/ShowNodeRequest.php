<?php

declare(strict_types=1);

namespace App\Services\Gateway\Requests;

use App\Services\Gateway\GatewayRequest;

final readonly class ShowNodeRequest implements GatewayRequest
{
    public function __construct(
        private string $name,
    ) {}

    public function method(): string
    {
        return 'GET';
    }

    public function path(): string
    {
        return "/api/nodes/{$this->name}";
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
        return [];
    }
}
