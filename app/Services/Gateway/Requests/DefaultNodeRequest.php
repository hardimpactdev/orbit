<?php

declare(strict_types=1);

namespace App\Services\Gateway\Requests;

use App\Services\Gateway\GatewayRequest;

final readonly class DefaultNodeRequest implements GatewayRequest
{
    private function __construct(
        private string $method,
        private ?string $name = null,
    ) {}

    public static function show(): self
    {
        return new self('GET');
    }

    public static function set(string $name): self
    {
        return new self('PUT', $name);
    }

    public static function clear(): self
    {
        return new self('DELETE');
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return '/api/nodes/default';
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
        if ($this->name === null) {
            return [];
        }

        return [
            'name' => $this->name,
        ];
    }
}
