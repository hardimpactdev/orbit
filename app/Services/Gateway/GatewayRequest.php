<?php

declare(strict_types=1);

namespace App\Services\Gateway;

interface GatewayRequest
{
    public function method(): string;

    public function path(): string;

    /**
     * @return array<string, mixed>
     */
    public function query(): array;

    /**
     * @return array<string, mixed>
     */
    public function data(): array;
}
