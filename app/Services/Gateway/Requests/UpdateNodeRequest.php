<?php

declare(strict_types=1);

namespace App\Services\Gateway\Requests;

use App\Services\Gateway\GatewayRequest;

final readonly class UpdateNodeRequest implements GatewayRequest
{
    /**
     * @param  array<string, string|null>  $fields
     */
    public function __construct(
        private string $name,
        private array $fields,
    ) {}

    public function method(): string
    {
        return 'PUT';
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
        return array_filter(
            $this->fields,
            static fn (?string $value): bool => $value !== null,
        );
    }
}
