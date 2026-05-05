<?php

declare(strict_types=1);

namespace App\Services\Gateway\Requests;

use App\Services\Gateway\GatewayRequest;

final readonly class RemoveNodeRequest implements GatewayRequest
{
    public function __construct(
        private string $name,
        private string $destructiveConsentSource = 'force',
    ) {}

    public function method(): string
    {
        return 'DELETE';
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
        return [
            'destructive_consent' => true,
            'destructive_consent_source' => $this->destructiveConsentSource,
        ];
    }
}
