<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel;

use Orbit\Sdk\Laravel\Plugins\HasCorrelationHeader;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;

final class GatewayConnector extends Connector
{
    use AlwaysThrowOnErrors;
    use HasCorrelationHeader;

    public function __construct(
        private readonly string $clientName = 'cli',
        private readonly ?string $baseUrl = null,
        private readonly string|bool|null $caPemPath = null,
        private readonly int $timeout = 900,
        private readonly mixed $correlationIdResolver = null,
    ) {}

    public static function forScheduler(?string $baseUrl = null, string|bool|null $caPemPath = null, int $timeout = 900, ?callable $correlationIdResolver = null): self
    {
        return new self('scheduler', $baseUrl, $caPemPath, $timeout, $correlationIdResolver);
    }

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Orbit-Client' => $this->orbitClientName(),
        ];
    }

    protected function orbitClientName(): string
    {
        return $this->clientName;
    }

    protected function orbitCorrelationId(): ?string
    {
        if (! is_callable($this->correlationIdResolver)) {
            return null;
        }

        $uuid = ($this->correlationIdResolver)();

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        $config = [
            'allow_redirects' => false,
            'timeout' => $this->timeout,
            'connect_timeout' => 10,
        ];

        if ($this->caPemPath !== null) {
            $config['verify'] = $this->caPemPath;
        }

        return $config;
    }
}
