<?php

declare(strict_types=1);

namespace App\Http\Gateway;

use App\Http\Gateway\Plugins\HasCorrelationHeader;
use App\Models\LocalGatewaySettings;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;

final class GatewayConnector extends Connector
{
    use AlwaysThrowOnErrors;
    use HasCorrelationHeader;

    public function __construct(
        private readonly string $clientName = 'cli',
    ) {}

    public static function forScheduler(): self
    {
        return new self('scheduler');
    }

    public function resolveBaseUrl(): string
    {
        return LocalGatewaySettings::current()->gateway_url ?? '';
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

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        $settings = LocalGatewaySettings::current();

        return [
            'verify' => $settings->ca_pem_path,
            'allow_redirects' => false,
            'timeout' => 900,
            'connect_timeout' => 10,
        ];
    }
}
