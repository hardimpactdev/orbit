<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;
use App\Services\GatewayApiClient;

final readonly class AppListQueryResolver
{
    public function __construct(
        private ?string $node,
        private ?string $defaultNode,
        private GatewayApiClient $gateway,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $node = $this->node ?? $this->defaultNode ?? $this->callerNodeName();

        if ($node === null || trim($node) === '') {
            throw new GatewayApiException(
                'Gateway response missing caller node identity from /api/me. Configure a default node or pass --node.',
            );
        }

        return $this->filledQuery([
            'node' => trim($node),
        ]);
    }

    private function callerNodeName(): ?string
    {
        $response = $this->gateway->get('/api/me');
        $data = $response['success']['data'] ?? null;

        if (! is_array($data)) {
            return null;
        }

        $self = $data['self'] ?? null;

        if (! is_array($self)) {
            return null;
        }

        $name = $self['name'] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function filledQuery(array $query): array
    {
        return array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
