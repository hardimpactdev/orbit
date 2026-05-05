<?php

declare(strict_types=1);

namespace App\Services\Gateway\Requests;

use App\Services\Gateway\GatewayRequest;

final readonly class ListNodesRequest implements GatewayRequest
{
    public function __construct(
        private ?string $role = null,
        private ?string $environment = null,
        private bool $doctor = false,
    ) {}

    public function method(): string
    {
        return 'GET';
    }

    public function path(): string
    {
        return '/api/nodes';
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return array_filter([
            'role' => $this->role,
            'environment' => $this->environment,
            'doctor' => $this->doctor ? true : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [];
    }
}
